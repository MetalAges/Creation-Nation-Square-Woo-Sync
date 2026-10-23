<?php
/**
 * Creation Nation Square Sync: Square catalog -> WooCommerce products.
 *
 * A full sync runs in small background batches (Action Scheduler) so it never
 * hits the host's time limit. Items whose Square version hasn't changed are
 * skipped, so repeat syncs are cheap.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DZSQ_Catalog {

	const BATCH = 10;
	const PAUSE = 15; // seconds between batches, so the server gets breathing room

	/** True while the sync itself is changing products, so its own status changes don't set the Keep hidden flag. */
	public static $syncing = false;

	public static function init() {
		add_action( 'dzsq_full_sync', array( __CLASS__, 'full_sync' ) );
		add_action( 'dzsq_full_sync_recurring', array( __CLASS__, 'full_sync' ) );
		add_action( 'dzsq_sync_batch', array( __CLASS__, 'sync_batch' ), 10, 1 );
		add_action( 'dzsq_sync_images', array( __CLASS__, 'sync_images' ), 10, 2 );
		add_action( 'transition_post_status', array( __CLASS__, 'track_hidden' ), 10, 3 );
	}

	/**
	 * Keep hidden on website: a product someone moves from published to draft stays
	 * hidden through every sync; publishing it again by hand clears the flag.
	 */
	public static function track_hidden( $new, $old, $post ) {
		if ( self::$syncing || ! $post || 'product' !== $post->post_type || $new === $old ) {
			return;
		}
		if ( 'draft' === $new && 'publish' === $old ) {
			update_post_meta( $post->ID, '_dzsq_keep_hidden', 1 );
		} elseif ( 'publish' === $new ) {
			delete_post_meta( $post->ID, '_dzsq_keep_hidden' );
		}
	}

	/** Schedule the next batch after a short pause. */
	public static function queue_batch( $offset, $delay = self::PAUSE ) {
		$args = array( 'offset' => (int) $offset );
		if ( ! as_has_scheduled_action( 'dzsq_sync_batch', $args, DZSQ_GROUP ) ) {
			as_schedule_single_action( time() + $delay, 'dzsq_sync_batch', $args, DZSQ_GROUP );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Scheduling                                                          */
	/* ------------------------------------------------------------------ */

	public static function request_sync( $delay = 0 ) {
		if ( self::locked() ) {
			update_option( 'dzsq_resync_pending', 1, false );
			return;
		}
		if ( as_has_scheduled_action( 'dzsq_full_sync', null, DZSQ_GROUP ) ) {
			return;
		}
		if ( $delay > 0 ) {
			as_schedule_single_action( time() + $delay, 'dzsq_full_sync', array(), DZSQ_GROUP );
		} else {
			as_enqueue_async_action( 'dzsq_full_sync', array(), DZSQ_GROUP );
		}
	}

	public static function locked() {
		$lock = (int) get_option( 'dzsq_lock', 0 );
		return $lock && ( time() - $lock ) < 30 * MINUTE_IN_SECONDS;
	}

	/* ------------------------------------------------------------------ */
	/* Square reads                                                        */
	/* ------------------------------------------------------------------ */

	/** @return array|WP_Error id => [id, name, parent] */
	public static function fetch_categories( $use_cache = false ) {
		if ( $use_cache ) {
			$cached = get_transient( 'dzsq_categories' );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}
		$out    = array();
		$cursor = null;
		do {
			$query = array( 'types' => 'CATEGORY' );
			if ( $cursor ) {
				$query['cursor'] = $cursor;
			}
			$r = DZSQ_API::request( 'GET', '/v2/catalog/list', null, $query );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			foreach ( $r['objects'] ?? array() as $o ) {
				if ( ! empty( $o['is_deleted'] ) ) {
					continue;
				}
				$out[ $o['id'] ] = array(
					'id'     => $o['id'],
					'name'   => $o['category_data']['name'] ?? $o['id'],
					'parent' => $o['category_data']['parent_category']['id'] ?? '',
				);
			}
			$cursor = $r['cursor'] ?? null;
		} while ( $cursor );

		uasort(
			$out,
			function ( $a, $b ) {
				return strcasecmp( $a['name'], $b['name'] );
			}
		);
		set_transient( 'dzsq_categories', $out, HOUR_IN_SECONDS );
		return $out;
	}

	/**
	 * Expand the chosen categories to include sub-categories, minus anything
	 * marked Skip (and its sub-categories).
	 *
	 * @return array [ allowed id=>true, excluded id=>true ]
	 */
	public static function category_sets( array $cats ) {
		$children = array();
		foreach ( $cats as $id => $c ) {
			if ( $c['parent'] ) {
				$children[ $c['parent'] ][] = $id;
			}
		}

		$excluded = array();
		$queue    = array_values( (array) DZSQ_Settings::get( 'exclude_categories' ) );
		while ( $queue ) {
			$id = array_shift( $queue );
			if ( isset( $excluded[ $id ] ) ) {
				continue;
			}
			$excluded[ $id ] = true;
			foreach ( $children[ $id ] ?? array() as $child ) {
				$queue[] = $child;
			}
		}

		$allowed = array();
		$queue   = array_values( (array) DZSQ_Settings::get( 'include_categories' ) );
		while ( $queue ) {
			$id = array_shift( $queue );
			if ( isset( $allowed[ $id ] ) || isset( $excluded[ $id ] ) || ! isset( $cats[ $id ] ) ) {
				continue;
			}
			$allowed[ $id ] = true;
			foreach ( $children[ $id ] ?? array() as $child ) {
				$queue[] = $child;
			}
		}

		return array( $allowed, $excluded );
	}

	private static function fetch_items( array $category_ids ) {
		$items = array();
		foreach ( array_chunk( $category_ids, 10 ) as $chunk ) {
			$cursor = null;
			do {
				$body = array(
					'category_ids' => array_values( $chunk ),
					'limit'        => 100,
				);
				if ( $cursor ) {
					$body['cursor'] = $cursor;
				}
				$r = DZSQ_API::request( 'POST', '/v2/catalog/search-catalog-items', $body );
				if ( is_wp_error( $r ) ) {
					return $r;
				}
				foreach ( $r['items'] ?? array() as $item ) {
					$items[ $item['id'] ] = $item;
				}
				$cursor = $r['cursor'] ?? null;
			} while ( $cursor );
		}
		return $items;
	}

	public static function item_categories( array $data ) {
		$ids = array();
		foreach ( $data['categories'] ?? array() as $c ) {
			if ( ! empty( $c['id'] ) ) {
				$ids[] = $c['id'];
			}
		}
		if ( ! empty( $data['category_id'] ) ) {
			$ids[] = $data['category_id'];
		}
		return array_values( array_unique( $ids ) );
	}

	private static function should_sync( array $item, array $allowed, array $excluded ) {
		$d = $item['item_data'] ?? array();
		if ( ! empty( $item['is_deleted'] ) || ! empty( $d['is_archived'] ) ) {
			return false;
		}
		if ( DZSQ_Settings::is_price_point( $d['name'] ?? '' ) ) {
			return false;
		}
		$cats = array_flip( self::item_categories( $d ) );
		if ( ! empty( $d['reporting_category']['id'] ) && isset( $excluded[ $d['reporting_category']['id'] ] ) ) {
			return false;
		}
		if ( array_intersect_key( $cats, $excluded ) || ! array_intersect_key( $cats, $allowed ) ) {
			return false;
		}
		foreach ( $d['variations'] ?? array() as $v ) {
			if ( empty( $v['is_deleted'] ) ) {
				return true;
			}
		}
		return false;
	}

	/* ------------------------------------------------------------------ */
	/* Full sync                                                           */
	/* ------------------------------------------------------------------ */

	public static function full_sync() {
		if ( self::locked() ) {
			update_option( 'dzsq_resync_pending', 1, false );
			return;
		}
		if ( '' === DZSQ_Settings::token() ) {
			return;
		}
		if ( ! (array) DZSQ_Settings::get( 'include_categories' ) ) {
			return; // Nothing chosen yet: pick categories on the Square Sync screen.
		}
		@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		update_option( 'dzsq_lock', time(), false );
		delete_option( 'dzsq_resync_pending' );

		$cats = self::fetch_categories();
		if ( is_wp_error( $cats ) ) {
			return self::abort( $cats );
		}
		list( $allowed, $excluded ) = self::category_sets( $cats );
		if ( ! $allowed ) {
			return self::abort( new WP_Error( 'dzsq_no_categories', 'None of the chosen Square categories exist any more.' ) );
		}

		$items = self::fetch_items( array_keys( $allowed ) );
		if ( is_wp_error( $items ) ) {
			return self::abort( $items );
		}

		$term_map = self::sync_terms( $cats, $allowed );
		$existing = self::existing_products();
		$status   = (string) DZSQ_Settings::get( 'product_status' );

		$keep    = array();
		$todo    = array();
		$skipped = 0;
		foreach ( $items as $id => $item ) {
			if ( ! self::should_sync( $item, $allowed, $excluded ) ) {
				$skipped++;
				continue;
			}
			$keep[] = $id;
			$ex     = $existing[ $id ] ?? null;
			if ( ! $ex || $ex['version'] !== (string) $item['version'] || $ex['status'] !== ( $ex['hidden'] ? 'draft' : $status ) ) {
				$todo[] = $id;
			}
		}

		update_option(
			'dzsq_run',
			array(
				'keep'     => $keep,
				'todo'     => $todo,
				'term_map' => $term_map,
				'started'  => time(),
				'done'     => 0,
				'errors'   => 0,
				'skipped'  => $skipped,
			),
			false
		);
		DZSQ_Log::info( sprintf( 'Catalog sync started: %d Square items belong on the website, %d need adding or updating.', count( $keep ), count( $todo ) ) );
		self::queue_batch( 0, 5 );
	}

	private static function abort( WP_Error $error ) {
		DZSQ_Log::error( 'Catalog sync stopped: ' . $error->get_error_message() );
		delete_option( 'dzsq_lock' );
		delete_option( 'dzsq_run' );
	}

	public static function sync_batch( $offset ) {
		self::$syncing = true;
		@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$run = get_option( 'dzsq_run' );
		if ( ! is_array( $run ) ) {
			return;
		}
		if ( DZSQ_Guard::busy() ) {
			// Server is struggling: wait and try the same batch again.
			update_option( 'dzsq_lock', time(), false );
			self::queue_batch( $offset, DZSQ_Guard::BACKOFF );
			return;
		}
		$t0 = microtime( true );
		// Checkpoint: if the server dies mid-batch, the watchdog resumes from here.
		$run['offset'] = (int) $offset;
		$run['beat']   = time();
		update_option( 'dzsq_run', $run, false );
		update_option( 'dzsq_lock', time(), false );

		$ids = array_slice( $run['todo'], (int) $offset, self::BATCH );
		if ( ! $ids ) {
			self::finish( $run );
			return;
		}

		$r = DZSQ_API::request(
			'POST',
			'/v2/catalog/batch-retrieve',
			array(
				'object_ids'              => array_values( $ids ),
				'include_related_objects' => true,
			)
		);
		if ( is_wp_error( $r ) ) {
			$run['errors'] += count( $ids );
			DZSQ_Log::error( 'Could not load a batch of items from Square: ' . $r->get_error_message() );
		} else {
			$images = array();
			foreach ( $r['related_objects'] ?? array() as $o ) {
				if ( 'IMAGE' === ( $o['type'] ?? '' ) && ! empty( $o['image_data']['url'] ) ) {
					$images[ $o['id'] ] = $o['image_data']['url'];
				}
			}
			$batch_vids = array();
			foreach ( $r['objects'] ?? array() as $item ) {
				if ( 'ITEM' !== ( $item['type'] ?? '' ) ) {
					continue;
				}
				$result = self::upsert_product( $item, $images, $run['term_map'] );
				foreach ( $item['item_data']['variations'] ?? array() as $v ) {
					if ( empty( $v['is_deleted'] ) ) {
						$batch_vids[] = $v['id'];
					}
				}
				if ( is_wp_error( $result ) ) {
					$run['errors']++;
					DZSQ_Log::error( 'Could not sync "' . ( $item['item_data']['name'] ?? $item['id'] ) . '": ' . $result->get_error_message() );
				} else {
					$run['done']++;
				}
			}
			// Set stock for this batch right away, so items appear in stock as they arrive.
			if ( $batch_vids ) {
				DZSQ_Inventory::reconcile_ids( $batch_vids );
			}
		}

		DZSQ_Guard::record( microtime( true ) - $t0 );
		$run['offset'] = (int) $offset + self::BATCH;
		$run['beat']   = time();
		update_option( 'dzsq_run', $run, false );
		self::queue_batch( (int) $offset + self::BATCH );
	}

	private static function finish( array $run ) {
		$keep    = array_flip( $run['keep'] );
		$hidden  = (int) ( $run['hidden'] ?? 0 );
		$started = microtime( true );
		foreach ( self::existing_products() as $item_id => $ex ) {
			if ( isset( $keep[ $item_id ] ) || 'publish' !== $ex['status'] ) {
				continue;
			}
			// Lots to hide on a slow server: save progress and carry on in a moment, so the step never times out.
			if ( microtime( true ) - $started > 20 ) {
				$run['hidden'] = $hidden;
				$run['beat']   = time();
				update_option( 'dzsq_run', $run, false );
				update_option( 'dzsq_lock', time(), false );
				as_schedule_single_action( time() + 15, 'dzsq_sync_batch', array( 'offset' => (int) ( $run['offset'] ?? 0 ) ), DZSQ_GROUP );
				return;
			}
			wp_update_post(
				array(
					'ID'          => $ex['pid'],
					'post_status' => 'draft',
				)
			);
			update_post_meta( $ex['pid'], '_dzsq_version', '' );
			$hidden++;
		}

		DZSQ_Inventory::queue_full( 30 );

		update_option(
			'dzsq_last_sync',
			array(
				'time'    => time(),
				'synced'  => count( $run['keep'] ),
				'updated' => (int) $run['done'],
				'errors'  => (int) $run['errors'],
				'hidden'  => $hidden,
				'skipped' => (int) $run['skipped'],
			),
			false
		);
		DZSQ_Log::info( sprintf( 'Catalog sync finished: %d items on the website, %d added or updated, %d hidden (no longer in a synced category), %d errors.', count( $run['keep'] ), $run['done'], $hidden, $run['errors'] ) );

		delete_option( 'dzsq_run' );
		delete_option( 'dzsq_lock' );
		if ( get_option( 'dzsq_resync_pending' ) ) {
			delete_option( 'dzsq_resync_pending' );
			self::request_sync( 60 );
		}
	}

	/** @return array item_id => [pid, status, version] */
	public static function existing_products() {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT p.ID, p.post_status, m.meta_value AS item_id, v.meta_value AS version, h.meta_value AS hidden
			FROM {$wpdb->posts} p
			JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_dzsq_item_id'
			LEFT JOIN {$wpdb->postmeta} v ON v.post_id = p.ID AND v.meta_key = '_dzsq_version'
			LEFT JOIN {$wpdb->postmeta} h ON h.post_id = p.ID AND h.meta_key = '_dzsq_keep_hidden'
			WHERE p.post_type = 'product' AND p.post_status <> 'trash'"
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ $row->item_id ] = array(
				'pid'     => (int) $row->ID,
				'status'  => $row->post_status,
				'version' => (string) $row->version,
				'hidden'  => ! empty( $row->hidden ),
			);
		}
		return $out;
	}

	private static function find_product( $item_id ) {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
				WHERE m.meta_key = '_dzsq_item_id' AND m.meta_value = %s AND p.post_type = 'product' AND p.post_status <> 'trash' LIMIT 1",
				$item_id
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/* ------------------------------------------------------------------ */
	/* Categories                                                          */
	/* ------------------------------------------------------------------ */

	private static function sync_terms( array $cats, array $allowed ) {
		$map = array();
		foreach ( array_keys( $allowed ) as $sid ) {
			self::ensure_term( $sid, $cats, $allowed, $map );
		}
		return $map;
	}

	private static function ensure_term( $sid, array $cats, array $allowed, array &$map ) {
		if ( isset( $map[ $sid ] ) ) {
			return $map[ $sid ];
		}
		$map[ $sid ] = 0; // Guards against a loop in bad data.
		$c           = $cats[ $sid ];
		$parent      = 0;
		if ( $c['parent'] && isset( $allowed[ $c['parent'] ] ) ) {
			$parent = (int) self::ensure_term( $c['parent'], $cats, $allowed, $map );
		}

		// Direct lookup: WooCommerce's term ordering replaces meta_key in get_terms(), and a
		// hide-categories plugin filters some terms out, so get_terms() missed existing terms.
		global $wpdb;
		$tid = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT tm.term_id FROM {$wpdb->termmeta} tm
				JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id AND tt.taxonomy = 'product_cat'
				WHERE tm.meta_key = '_dzsq_category_id' AND tm.meta_value = %s ORDER BY tm.term_id ASC LIMIT 1",
				$sid
			)
		);

		if ( ! $tid ) {
			$r = wp_insert_term( $c['name'], 'product_cat', array( 'parent' => $parent ) );
			if ( is_wp_error( $r ) ) {
				if ( 'term_exists' === $r->get_error_code() ) {
					$tid = (int) $r->get_error_data();
				} else {
					DZSQ_Log::error( 'Could not create the category "' . $c['name'] . '": ' . $r->get_error_message() );
					return 0;
				}
			} else {
				$tid = (int) $r['term_id'];
			}
			update_term_meta( $tid, '_dzsq_category_id', $sid );
		} else {
			$term = get_term( $tid, 'product_cat' );
			if ( $term && ! is_wp_error( $term ) && ( html_entity_decode( $term->name ) !== $c['name'] || (int) $term->parent !== $parent ) ) {
				wp_update_term(
					$tid,
					'product_cat',
					array(
						'name'   => $c['name'],
						'parent' => $parent,
					)
				);
			}
		}
		$map[ $sid ] = $tid;
		return $tid;
	}

	/* ------------------------------------------------------------------ */
	/* Products                                                            */
	/* ------------------------------------------------------------------ */

	public static function upsert_product( array $item, array $images, array $term_map ) {
		$d    = $item['item_data'] ?? array();
		$vars = array_values(
			array_filter(
				$d['variations'] ?? array(),
				function ( $v ) {
					return empty( $v['is_deleted'] );
				}
			)
		);
		if ( ! $vars ) {
			return new WP_Error( 'dzsq_no_variations', 'The item has no variations in Square.' );
		}
		$is_variable = count( $vars ) > 1;
		$want_type   = $is_variable ? 'variable' : 'simple';
		$pid         = self::find_product( $item['id'] );

		try {
			if ( $pid ) {
				$product = wc_get_product( $pid );
				if ( ! $product || ! $product->is_type( $want_type ) ) {
					if ( $product && $product->is_type( 'variable' ) ) {
						foreach ( $product->get_children() as $child_id ) {
							$child = wc_get_product( $child_id );
							if ( $child ) {
								$child->delete( true );
							}
						}
					}
					wp_set_object_terms( $pid, $want_type, 'product_type' );
					$product = $is_variable ? new WC_Product_Variable( $pid ) : new WC_Product_Simple( $pid );
				}
			} else {
				$product = $is_variable ? new WC_Product_Variable() : new WC_Product_Simple();
			}

			$product->set_name( $d['name'] ?? 'Untitled' );
			if ( isset( $d['description_html'] ) ) {
				$product->set_description( wp_kses_post( $d['description_html'] ) );
			} elseif ( isset( $d['description'] ) ) {
				$product->set_description( wpautop( esc_html( $d['description'] ) ) );
			}
			$keep_hidden = $pid && get_post_meta( $pid, '_dzsq_keep_hidden', true );
			$product->set_status( $keep_hidden ? 'draft' : ( (string) DZSQ_Settings::get( 'product_status' ) ?: 'publish' ) );
			$product->set_tax_status( empty( $d['is_taxable'] ) ? 'none' : 'taxable' );

			$term_ids = array();
			foreach ( self::item_categories( $d ) as $cid ) {
				if ( ! empty( $term_map[ $cid ] ) ) {
					$term_ids[] = (int) $term_map[ $cid ];
				}
			}
			if ( $term_ids ) {
				$product->set_category_ids( array_values( array_unique( $term_ids ) ) );
			}
			/**
			 * Filter the WooCommerce category IDs a synced product is filed under.
			 *
			 * @param int[]      $category_ids Category IDs mapped from Square.
			 * @param WC_Product $product      The product being saved.
			 * @param array      $item         The Square catalog item.
			 * @param int        $pid          The existing product ID, or 0 for a new product.
			 */
			$product->set_category_ids( array_values( array_unique( array_map( 'intval', (array) apply_filters( 'dzsq_product_category_ids', $product->get_category_ids(), $product, $item, (int) $pid ) ) ) ) );

			if ( ! $is_variable ) {
				self::apply_variation( $product, $vars[0] );
			} else {
				$product->delete_meta_data( '_dzsq_variation_id' );
				$product->set_sku( '' );
			}

			$product->update_meta_data( '_dzsq_item_id', $item['id'] );
			$product->update_meta_data( '_dzsq_version', (string) $item['version'] );
			$pid = $product->save();

			if ( $is_variable ) {
				self::sync_variations( $product, $vars );
			}

			self::queue_images( $pid, $d['image_ids'] ?? array(), $images );
			return $pid;
		} catch ( Exception $e ) {
			return new WP_Error( 'dzsq_save', $e->getMessage() );
		}
	}

	/**
	 * Copy price, SKU and stock settings from a Square variation onto a
	 * WooCommerce simple product or product variation.
	 */
	private static function apply_variation( $obj, array $v ) {
		$vd    = $v['item_variation_data'] ?? array();
		$fixed = 'VARIABLE_PRICING' !== ( $vd['pricing_type'] ?? 'FIXED_PRICING' );
		$price = ( $fixed && isset( $vd['price_money']['amount'] ) ) ? wc_format_decimal( $vd['price_money']['amount'] / 100, 2 ) : '';
		$obj->set_regular_price( $price );

		$sku = trim( (string) ( $vd['sku'] ?? '' ) );
		if ( $sku !== $obj->get_sku( 'edit' ) ) {
			if ( '' === $sku ) {
				$obj->set_sku( '' );
			} else {
				$other = (int) wc_get_product_id_by_sku( $sku );
				if ( $other && $other !== (int) $obj->get_id() ) {
					DZSQ_Log::error( 'SKU "' . $sku . '" is already used by another website product, so it was left off "' . $obj->get_name() . '".' );
				} else {
					try {
						$obj->set_sku( $sku );
					} catch ( WC_Data_Exception $e ) {
						DZSQ_Log::error( 'SKU "' . $sku . '" could not be set: ' . $e->getMessage() );
					}
				}
			}
		}

		// Barcode (UPC/GTIN) from Square, for the record lookup and Google Shopping.
		$upc = preg_replace( '/\D/', '', (string) ( $vd['upc'] ?? '' ) );
		$obj->update_meta_data( '_dzsq_upc', $upc );
		if ( '' !== $upc && method_exists( $obj, 'set_global_unique_id' ) && $upc !== $obj->get_global_unique_id( 'edit' ) ) {
			try {
				$obj->set_global_unique_id( $upc );
			} catch ( Exception $e ) {
				DZSQ_Log::error( 'Barcode "' . $upc . '" could not be saved on "' . $obj->get_name() . '": ' . $e->getMessage() );
			}
		}

		$location = (string) DZSQ_Settings::get( 'location_id' );
		$track    = ! empty( $vd['track_inventory'] );
		$sold_out = false;
		foreach ( $vd['location_overrides'] ?? array() as $ov ) {
			if ( ( $ov['location_id'] ?? '' ) !== $location ) {
				continue;
			}
			if ( isset( $ov['track_inventory'] ) ) {
				$track = (bool) $ov['track_inventory'];
			}
			if ( ! empty( $ov['sold_out'] ) ) {
				$sold_out = true;
			}
		}
		$obj->set_manage_stock( $track );
		if ( ! $track ) {
			$obj->set_stock_status( $sold_out ? 'outofstock' : 'instock' );
		}
		$obj->update_meta_data( '_dzsq_variation_id', $v['id'] );
	}

	private static function sync_variations( WC_Product_Variable $product, array $vars ) {
		$names = array();
		$seen  = array();
		foreach ( $vars as $v ) {
			$name = trim( (string) ( $v['item_variation_data']['name'] ?? '' ) );
			if ( '' === $name ) {
				$name = 'Regular';
			}
			$base = $name;
			$n    = 2;
			while ( isset( $seen[ strtolower( $name ) ] ) ) {
				$name = $base . ' ' . $n++;
			}
			$seen[ strtolower( $name ) ] = true;
			$names[ $v['id'] ]           = $name;
		}

		$attribute = new WC_Product_Attribute();
		$attribute->set_id( 0 );
		$attribute->set_name( 'Option' );
		$attribute->set_options( array_values( $names ) );
		$attribute->set_position( 0 );
		$attribute->set_visible( true );
		$attribute->set_variation( true );
		$product->set_attributes( array( $attribute ) );
		$product->save();

		$map = array();
		foreach ( $product->get_children() as $child_id ) {
			$vid = get_post_meta( $child_id, '_dzsq_variation_id', true );
			if ( $vid ) {
				$map[ $vid ] = $child_id;
			}
		}

		foreach ( $vars as $v ) {
			$variation = isset( $map[ $v['id'] ] ) ? wc_get_product( $map[ $v['id'] ] ) : null;
			if ( ! $variation ) {
				$variation = new WC_Product_Variation();
			}
			$variation->set_parent_id( $product->get_id() );
			$variation->set_attributes( array( 'option' => $names[ $v['id'] ] ) );
			$variation->set_status( 'publish' );
			self::apply_variation( $variation, $v );
			$variation->save();
			unset( $map[ $v['id'] ] );
		}

		foreach ( $map as $child_id ) {
			$child = wc_get_product( $child_id );
			if ( $child ) {
				$child->delete( true );
			}
		}
		WC_Product_Variable::sync( $product->get_id() );
	}

	/* ------------------------------------------------------------------ */
	/* Images                                                              */
	/* ------------------------------------------------------------------ */

	private static function queue_images( $pid, array $image_ids, array $images ) {
		$list = array();
		foreach ( $image_ids as $image_id ) {
			if ( isset( $images[ $image_id ] ) ) {
				$list[] = array(
					'id'  => $image_id,
					'url' => $images[ $image_id ],
				);
			}
		}
		$signature = implode( ',', array_column( $list, 'id' ) );
		if ( (string) get_post_meta( $pid, '_dzsq_image_ids', true ) === $signature ) {
			return;
		}
		if ( ! $list ) {
			$product = wc_get_product( $pid );
			if ( $product ) {
				$product->set_image_id( 0 );
				$product->set_gallery_image_ids( array() );
				$product->update_meta_data( '_dzsq_image_ids', '' );
				$product->save();
			}
			return;
		}
		// Photos are the heaviest part (resizing + optimising), so space them out.
		$when = max( time() + 30, (int) get_option( 'dzsq_image_slot', 0 ) + 20 );
		update_option( 'dzsq_image_slot', $when, false );
		as_schedule_single_action(
			$when,
			'dzsq_sync_images',
			array(
				'product_id' => (int) $pid,
				'images'     => $list,
			),
			DZSQ_GROUP
		);
	}

	public static function sync_images( $product_id, $images ) {
		if ( DZSQ_Guard::busy() ) {
			as_schedule_single_action( time() + DZSQ_Guard::BACKOFF, 'dzsq_sync_images', array( 'product_id' => (int) $product_id, 'images' => $images ), DZSQ_GROUP );
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return;
		}
		$title = $product->get_name();
		$ids   = array();
		$t0    = microtime( true );

		foreach ( (array) $images as $image ) {
			$aid = self::find_attachment( $image['id'] );
			if ( ! $aid ) {
				$tmp = download_url( $image['url'], 60 );
				if ( is_wp_error( $tmp ) ) {
					DZSQ_Log::error( 'Could not download a photo for "' . $title . '": ' . $tmp->get_error_message() );
					continue;
				}
				$ext = strtolower( (string) pathinfo( (string) wp_parse_url( $image['url'], PHP_URL_PATH ), PATHINFO_EXTENSION ) );
				if ( ! in_array( $ext, array( 'jpg', 'jpeg', 'png', 'gif', 'webp' ), true ) ) {
					$ext = 'jpg';
				}
				$file = array(
					'name'     => sanitize_file_name( substr( sanitize_title( $title ), 0, 60 ) . '-' . strtolower( $image['id'] ) . '.' . $ext ),
					'tmp_name' => $tmp,
				);
				$aid  = media_handle_sideload( $file, $product_id, $title );
				if ( is_wp_error( $aid ) ) {
					@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
					DZSQ_Log::error( 'Could not save a photo for "' . $title . '": ' . $aid->get_error_message() );
					continue;
				}
				update_post_meta( $aid, '_dzsq_image_id', $image['id'] );
				update_post_meta( $aid, '_wp_attachment_image_alt', $title );
			}
			$ids[] = (int) $aid;
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return;
		}
		if ( $ids ) {
			$product->set_image_id( array_shift( $ids ) );
			$product->set_gallery_image_ids( $ids );
		}
		$product->update_meta_data( '_dzsq_image_ids', implode( ',', array_column( (array) $images, 'id' ) ) );
		$product->save();
		DZSQ_Guard::record( microtime( true ) - $t0 );
	}

	private static function find_attachment( $square_image_id ) {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_dzsq_image_id' AND meta_value = %s LIMIT 1",
				$square_image_id
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
