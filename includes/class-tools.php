<?php
/**
 * Extra Tools on the Square Sync screen:
 * - Sync one category: re-pulls every item in a synced Square category (and its
 *   sub-categories) in small background batches, even if Square says nothing changed.
 * - Find a product in Square: search Square by name or SKU and sync a result to the
 *   website, adding it if it isn't there yet.
 * Both follow the normal rules: only synced categories, Skip categories stay off,
 * price-point buttons stay off, and "Keep hidden" products stay hidden.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DZSQ_Tools {

	const BATCH       = 10;
	const PAUSE       = 5;
	const MAX_RESULTS = 25;

	public static function init() {
		add_action( 'dzsq_cat_batch', array( __CLASS__, 'cat_batch' ), 10, 1 );
		if ( is_admin() ) {
			add_action( 'admin_post_dzsq_cat_sync', array( __CLASS__, 'handle_cat_sync' ) );
			add_action( 'admin_post_dzsq_cat_stop', array( __CLASS__, 'handle_cat_stop' ) );
			add_action( 'admin_post_dzsq_item_sync', array( __CLASS__, 'handle_item_sync' ) );
		}
	}

	/* ---------- Shared ---------- */

	/** Categories, the allowed/excluded sets, and the Square -> Woo category map. */
	private static function context() {
		$cats = DZSQ_Catalog::fetch_categories( true );
		if ( is_wp_error( $cats ) ) {
			return $cats;
		}
		list( $allowed, $excluded ) = DZSQ_Catalog::category_sets( $cats );
		return array( $cats, $allowed, $excluded );
	}

	private static function should_sync( array $item, array $allowed, array $excluded ) {
		$m = new ReflectionMethod( 'DZSQ_Catalog', 'should_sync' );
		$m->setAccessible( true );
		return (bool) $m->invoke( null, $item, $allowed, $excluded );
	}

	private static function term_map( array $cats, array $allowed ) {
		$m = new ReflectionMethod( 'DZSQ_Catalog', 'sync_terms' );
		$m->setAccessible( true );
		$map = $m->invoke( null, $cats, $allowed );
		return is_array( $map ) ? $map : array();
	}

	/** Plain-words reason an item stays off the website. */
	private static function why_not( array $item, array $cats, array $allowed, array $excluded ) {
		$d = $item['item_data'] ?? array();
		if ( ! empty( $item['is_deleted'] ) ) {
			return 'Deleted in Square.';
		}
		if ( ! empty( $d['is_archived'] ) ) {
			return 'Archived in Square.';
		}
		if ( DZSQ_Settings::is_price_point( $d['name'] ?? '' ) ) {
			return 'A register-only price button.';
		}
		$ids = DZSQ_Catalog::item_categories( $d );
		if ( ! empty( $d['reporting_category']['id'] ) ) {
			$ids[] = $d['reporting_category']['id'];
		}
		foreach ( $ids as $cid ) {
			if ( isset( $excluded[ $cid ] ) ) {
				return 'In a skipped category (' . ( $cats[ $cid ]['name'] ?? $cid ) . ').';
			}
		}
		if ( ! array_intersect_key( array_flip( DZSQ_Catalog::item_categories( $d ) ), $allowed ) ) {
			return DZSQ_Catalog::item_categories( $d ) ? 'Not in a category that syncs to the website.' : 'Has no category in Square.';
		}
		return 'Has no variations in Square.';
	}

	private static function cat_path( $id, array $cats ) {
		$names = array();
		$guard = 0;
		while ( $id && isset( $cats[ $id ] ) && $guard++ < 10 ) {
			array_unshift( $names, $cats[ $id ]['name'] );
			$id = $cats[ $id ]['parent'];
		}
		return implode( ' › ', $names );
	}

	private static function back( $type, $message, array $args = array() ) {
		set_transient( 'dzsq_notice_' . get_current_user_id(), array( $type, $message ), 60 );
		wp_safe_redirect( add_query_arg( array_merge( array( 'page' => 'dzsq' ), $args ), admin_url( 'admin.php' ) ) . '#dzsq-tools' );
		exit;
	}

	private static function guard( $action ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'You do not have permission to do this.', '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'dzsq_' . $action );
	}

	/** Pull a batch of Square items by ID and add/update them on the website. */
	private static function sync_ids( array $ids, array $term_map, array $allowed, array $excluded ) {
		$out = array( 'done' => 0, 'skipped' => 0, 'errors' => 0, 'pids' => array() );
		$r   = DZSQ_API::request(
			'POST',
			'/v2/catalog/batch-retrieve',
			array(
				'object_ids'              => array_values( $ids ),
				'include_related_objects' => true,
			)
		);
		if ( is_wp_error( $r ) ) {
			$out['errors'] = count( $ids );
			DZSQ_Log::error( 'Could not load items from Square: ' . $r->get_error_message() );
			return $out;
		}
		$images = array();
		foreach ( $r['related_objects'] ?? array() as $o ) {
			if ( 'IMAGE' === ( $o['type'] ?? '' ) && ! empty( $o['image_data']['url'] ) ) {
				$images[ $o['id'] ] = $o['image_data']['url'];
			}
		}
		$vids                  = array();
		DZSQ_Catalog::$syncing = true;
		foreach ( $r['objects'] ?? array() as $item ) {
			if ( 'ITEM' !== ( $item['type'] ?? '' ) ) {
				continue;
			}
			if ( ! self::should_sync( $item, $allowed, $excluded ) ) {
				$out['skipped']++;
				continue;
			}
			$result = DZSQ_Catalog::upsert_product( $item, $images, $term_map );
			if ( is_wp_error( $result ) ) {
				$out['errors']++;
				DZSQ_Log::error( 'Could not sync "' . ( $item['item_data']['name'] ?? $item['id'] ) . '": ' . $result->get_error_message() );
				continue;
			}
			$out['done']++;
			$out['pids'][ $item['id'] ] = (int) $result;
			clean_post_cache( (int) $result );
			if ( function_exists( 'wc_delete_product_transients' ) ) {
				wc_delete_product_transients( (int) $result );
			}
			foreach ( $item['item_data']['variations'] ?? array() as $v ) {
				if ( empty( $v['is_deleted'] ) ) {
					$vids[] = $v['id'];
				}
			}
		}
		DZSQ_Catalog::$syncing = false;
		if ( $vids ) {
			DZSQ_Inventory::reconcile_ids( $vids );
		}
		return $out;
	}

	/**
	 * Photos are normally fetched by a background job a minute or two later. For a
	 * single product synced by hand, fetch them now so they show straight away.
	 */
	public static function photos_now( $pid ) {
		if ( DZSQ_Guard::busy() ) {
			return;
		}
		$ids = as_get_scheduled_actions( array( 'hook' => 'dzsq_sync_images', 'status' => ActionScheduler_Store::STATUS_PENDING, 'per_page' => 50, 'group' => DZSQ_GROUP ), 'ids' );
		foreach ( $ids as $aid ) {
			$action = ActionScheduler::store()->fetch_action( $aid );
			$args   = $action ? $action->get_args() : array();
			if ( (int) ( $args['product_id'] ?? 0 ) !== (int) $pid ) {
				continue;
			}
			ActionScheduler::store()->cancel_action( $aid );
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			DZSQ_Catalog::sync_images( (int) $pid, $args['images'] ?? array() );
		}
	}

	/** Next unused SKU: one past the highest number ever handed out with the prefix. */
	private static function next_sku() {
		$n = self::peek_sku();
		update_option( 'dzsq_last_sku', $n, false );
		return DZSQ_Settings::sku_prefix() . $n;
	}

	/** The number the next SKU will use, without claiming it (0 when automatic SKUs are off). */
	public static function peek_sku() {
		global $wpdb;
		$prefix = DZSQ_Settings::sku_prefix();
		if ( '' === $prefix ) {
			return 0;
		}
		$max = (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX( CAST( SUBSTRING( meta_value, %d ) AS UNSIGNED ) ) FROM {$wpdb->postmeta} WHERE meta_key = '_sku' AND meta_value REGEXP %s", strlen( $prefix ) + 1, '^' . $prefix . '[0-9]+$' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$n   = max( $max, (int) get_option( 'dzsq_last_sku', 0 ) ) + 1;
		while ( wc_get_product_id_by_sku( $prefix . $n ) ) {
			$n++;
		}
		return $n;
	}

	/**
	 * Single-product syncs: give every variation that has no SKU in Square the next
	 * code in the SKU sequence, written in Square (the sync copies SKUs from Square, so it must live there).
	 *
	 * @return string[] codes handed out
	 */
	public static function assign_missing_skus( $item_id ) {
		if ( '' === DZSQ_Settings::sku_prefix() ) {
			return array(); // Automatic SKUs are off.
		}
		$r = DZSQ_API::request( 'POST', '/v2/catalog/batch-retrieve', array( 'object_ids' => array( (string) $item_id ) ) );
		if ( is_wp_error( $r ) || empty( $r['objects'][0]['item_data'] ) ) {
			return array();
		}
		$item = $r['objects'][0];
		if ( ! empty( $item['is_deleted'] ) || ! empty( $item['item_data']['is_archived'] ) ) {
			return array();
		}
		$ctx = self::context();
		if ( is_wp_error( $ctx ) || ! self::should_sync( $item, $ctx[1], $ctx[2] ) ) {
			return array();
		}
		$objects = array();
		$codes   = array();
		foreach ( $item['item_data']['variations'] ?? array() as $v ) {
			if ( ! empty( $v['is_deleted'] ) || '' !== trim( (string) ( $v['item_variation_data']['sku'] ?? '' ) ) ) {
				continue;
			}
			$code                             = self::next_sku();
			$v['item_variation_data']['sku']  = $code;
			$objects[]                        = $v;
			$codes[]                          = $code;
		}
		if ( ! $objects ) {
			return array();
		}
		$w = DZSQ_API::request(
			'POST',
			'/v2/catalog/batch-upsert',
			array(
				'idempotency_key' => wp_generate_uuid4(),
				'batches'         => array( array( 'objects' => $objects ) ),
			)
		);
		if ( is_wp_error( $w ) ) {
			DZSQ_Log::error( 'Could not give "' . $item['item_data']['name'] . '" a SKU in Square: ' . $w->get_error_message() );
			return array();
		}
		DZSQ_Log::info( 'Gave "' . $item['item_data']['name'] . '" SKU ' . implode( ', ', $codes ) . ' in Square.' );
		return $codes;
	}

	/* ---------- Sync one category ---------- */

	public static function handle_cat_sync() {
		self::guard( 'cat_sync' );
		$cid = isset( $_POST['dzsq_cat'] ) ? sanitize_text_field( wp_unslash( $_POST['dzsq_cat'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( is_array( get_option( 'dzsq_cat_run' ) ) ) {
			self::back( 'info', 'A category sync is already running. Wait for it to finish, or stop it, before starting another.' );
		}
		$ctx = self::context();
		if ( is_wp_error( $ctx ) ) {
			self::back( 'error', 'Could not load Square categories: ' . $ctx->get_error_message() );
		}
		list( $cats, $allowed, $excluded ) = $ctx;
		if ( '' === $cid || ! isset( $allowed[ $cid ] ) ) {
			self::back( 'error', 'Pick one of the categories that syncs to the website.' );
		}

		// The category plus its synced sub-categories.
		$children = array();
		foreach ( $cats as $id => $c ) {
			if ( $c['parent'] ) {
				$children[ $c['parent'] ][] = $id;
			}
		}
		$branch = array();
		$queue  = array( $cid );
		while ( $queue ) {
			$id = array_shift( $queue );
			if ( isset( $branch[ $id ] ) || ! isset( $allowed[ $id ] ) ) {
				continue;
			}
			$branch[ $id ] = true;
			foreach ( $children[ $id ] ?? array() as $child ) {
				$queue[] = $child;
			}
		}

		@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$fetch = new ReflectionMethod( 'DZSQ_Catalog', 'fetch_items' );
		$fetch->setAccessible( true );
		$items = $fetch->invoke( null, array_keys( $branch ) );
		if ( is_wp_error( $items ) ) {
			self::back( 'error', 'Could not load the items in that category from Square: ' . $items->get_error_message() );
		}
		$todo    = array();
		$skipped = 0;
		foreach ( $items as $id => $item ) {
			if ( self::should_sync( $item, $allowed, $excluded ) ) {
				$todo[] = $id;
			} else {
				$skipped++;
			}
		}
		$name = self::cat_path( $cid, $cats );
		if ( ! $todo ) {
			self::back( 'info', 'Nothing in ' . $name . ' belongs on the website' . ( $skipped ? ' (' . $skipped . ' items are skipped by the usual rules).' : '.' ) );
		}

		update_option(
			'dzsq_cat_run',
			array(
				'name'     => $name,
				'todo'     => $todo,
				'offset'   => 0,
				'done'     => 0,
				'errors'   => 0,
				'skipped'  => $skipped,
				'term_map' => self::term_map( $cats, $allowed ),
				'started'  => time(),
				'beat'     => time(),
			),
			false
		);
		DZSQ_Log::info( sprintf( 'Category sync started for %s: %d items to update.', $name, count( $todo ) ) );
		as_enqueue_async_action( 'dzsq_cat_batch', array( 'offset' => 0 ), DZSQ_GROUP );
		self::back( 'success', sprintf( 'Syncing %s: %d items. It runs in the background; refresh this page to see progress.', $name, count( $todo ) ) );
	}

	public static function handle_cat_stop() {
		self::guard( 'cat_stop' );
		$run = get_option( 'dzsq_cat_run' );
		delete_option( 'dzsq_cat_run' );
		as_unschedule_all_actions( 'dzsq_cat_batch', null, DZSQ_GROUP );
		if ( is_array( $run ) ) {
			DZSQ_Log::info( sprintf( 'Category sync for %s stopped by hand after %d of %d items.', $run['name'], $run['done'] + $run['errors'], count( $run['todo'] ) ) );
		}
		self::back( 'success', 'Category sync stopped.' );
	}

	public static function cat_batch( $offset ) {
		$run = get_option( 'dzsq_cat_run' );
		if ( ! is_array( $run ) ) {
			return;
		}
		if ( DZSQ_Guard::busy() ) {
			as_schedule_single_action( time() + DZSQ_Guard::BACKOFF, 'dzsq_cat_batch', array( 'offset' => (int) $offset ), DZSQ_GROUP );
			return;
		}
		@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$ids = array_slice( $run['todo'], (int) $offset, self::BATCH );
		if ( ! $ids ) {
			DZSQ_Log::info( sprintf( 'Category sync finished for %s: %d updated, %d errors.', $run['name'], $run['done'], $run['errors'] ) );
			update_option( 'dzsq_cat_last', array( 'name' => $run['name'], 'time' => time(), 'done' => $run['done'], 'errors' => $run['errors'] ), false );
			delete_option( 'dzsq_cat_run' );
			return;
		}
		$ctx = self::context();
		if ( is_wp_error( $ctx ) ) {
			DZSQ_Log::error( 'Category sync paused: ' . $ctx->get_error_message() );
			as_schedule_single_action( time() + 60, 'dzsq_cat_batch', array( 'offset' => (int) $offset ), DZSQ_GROUP );
			return;
		}
		list( , $allowed, $excluded ) = $ctx;

		$t0              = microtime( true );
		$res             = self::sync_ids( $ids, (array) $run['term_map'], $allowed, $excluded );
		$run['done']    += $res['done'];
		$run['errors']  += $res['errors'];
		$run['skipped'] += $res['skipped'];
		$run['offset']   = (int) $offset + self::BATCH;
		$run['beat']     = time();
		update_option( 'dzsq_cat_run', $run, false );
		DZSQ_Guard::record( microtime( true ) - $t0 );
		as_schedule_single_action( time() + self::PAUSE, 'dzsq_cat_batch', array( 'offset' => (int) $offset + self::BATCH ), DZSQ_GROUP );
	}

	/* ---------- Find one product in Square ---------- */

	/** @return array|WP_Error list of Square ITEM objects */
	private static function search( $text ) {
		$r = DZSQ_API::request(
			'POST',
			'/v2/catalog/search-catalog-items',
			array(
				'text_filter' => $text,
				'limit'       => self::MAX_RESULTS,
			)
		);
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		return array_values( $r['items'] ?? array() );
	}

	public static function handle_item_sync() {
		self::guard( 'item_sync' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in guard().
		$item_id = isset( $_POST['dzsq_item'] ) ? sanitize_text_field( wp_unslash( $_POST['dzsq_item'] ) ) : '';
		$find    = isset( $_POST['dzsq_find'] ) ? sanitize_text_field( wp_unslash( $_POST['dzsq_find'] ) ) : '';
		// phpcs:enable
		$args = '' !== $find ? array( 'dzsq_find' => rawurlencode( $find ) ) : array();
		if ( '' === $item_id ) {
			self::back( 'error', 'No Square item was chosen.', $args );
		}
		$ctx = self::context();
		if ( is_wp_error( $ctx ) ) {
			self::back( 'error', 'Could not load Square categories: ' . $ctx->get_error_message(), $args );
		}
		list( $cats, $allowed, $excluded ) = $ctx;
		$existing                          = DZSQ_Catalog::existing_products();
		$before                            = $existing[ $item_id ]['pid'] ?? 0;

		$codes = self::assign_missing_skus( $item_id );
		$res   = self::sync_ids( array( $item_id ), self::term_map( $cats, $allowed ), $allowed, $excluded );
		if ( $res['done'] ) {
			$pid  = $res['pids'][ $item_id ];
			$name = get_post_field( 'post_title', $pid );
			update_post_meta( $pid, '_dzsq_manual_synced', time() );
			self::photos_now( $pid );
			DZSQ_Log::info( ( $before ? 'Synced "' : 'Added "' ) . $name . '" from Square by hand.' );
			$msg = ( $before ? 'Synced ' : 'Added to the website: ' ) . $name . '.';
			if ( 'publish' !== get_post_status( $pid ) ) {
				$msg .= get_post_meta( $pid, '_dzsq_keep_hidden', true ) ? ' It stays hidden because it is marked Keep hidden.' : ' It was saved as a draft.';
			}
			if ( $codes ) {
				$msg .= ' New SKU: ' . implode( ', ', $codes ) . '.';
			}
			self::back( 'success', $msg, $args );
		}
		if ( $res['skipped'] ) {
			self::back( 'warning', 'That item does not belong on the website under the current sync settings, so nothing changed.', $args );
		}
		self::back( 'error', 'The item could not be synced. The reason is in the activity log below.', $args );
	}

	/* ---------- Screen (inside the Tools card) ---------- */

	public static function render() {
		$ctx = self::context();
		echo '<div id="dzsq-tools" class="dzsq-more">';
		if ( is_wp_error( $ctx ) ) {
			echo '<p class="dzsq-err">Could not load Square categories: ' . esc_html( $ctx->get_error_message() ) . '</p></div>';
			return;
		}
		list( $cats, $allowed, $excluded ) = $ctx;
		self::render_category( $cats, $allowed );
		self::render_find( $cats, $allowed, $excluded );
		if ( '' !== DZSQ_Settings::sku_prefix() ) {
			echo '<h3>Next SKU</h3><p><strong style="font-size:15px">' . esc_html( DZSQ_Settings::sku_prefix() . self::peek_sku() ) . '</strong> <span class="description">The next code a single-product sync will hand out. If you type a code into Square by hand, use this one.</span></p>';
		}
		echo '</div>';
	}

	private static function render_category( array $cats, array $allowed ) {
		$run  = get_option( 'dzsq_cat_run' );
		$last = get_option( 'dzsq_cat_last' );
		echo '<h3>Sync one category</h3>';
		if ( is_array( $run ) ) {
			$stalled = time() - (int) $run['beat'] > 10 * MINUTE_IN_SECONDS;
			echo '<p><strong>' . esc_html( sprintf( 'Syncing %s: %d of %d items done', $run['name'], min( count( $run['todo'] ), (int) $run['offset'] ), count( $run['todo'] ) ) ) . '</strong>';
			if ( $run['errors'] ) {
				echo esc_html( sprintf( ' (%d errors, see the activity log)', $run['errors'] ) );
			}
			echo $stalled ? ' <span class="dzsq-err">It has not moved in a while.</span>' : '';
			echo '</p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="dzsq_cat_stop">';
			wp_nonce_field( 'dzsq_cat_stop' );
			echo '<button class="button">Stop</button></form>';
			return;
		}
		$options = array();
		foreach ( array_keys( $allowed ) as $id ) {
			$options[ $id ] = self::cat_path( $id, $cats );
		}
		natcasesort( $options );
		echo '<p class="description">Re-pulls every item in the category (and its sub-categories) from Square: names, prices, variations, stock and photos. Skipped categories, price buttons and Keep hidden products follow the usual rules.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="dzsq_cat_sync">';
		wp_nonce_field( 'dzsq_cat_sync' );
		echo '<select name="dzsq_cat" required><option value="">Choose a category…</option>';
		foreach ( $options as $id => $label ) {
			echo '<option value="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</option>';
		}
		echo '</select> <button class="button">Sync category</button></form>';
		if ( is_array( $last ) ) {
			echo '<p class="description">' . esc_html( sprintf( 'Last category sync: %s, %s ago (%d updated, %d errors).', $last['name'], human_time_diff( (int) $last['time'] ), $last['done'], $last['errors'] ) ) . '</p>';
		}
	}

	private static function render_find( array $cats, array $allowed, array $excluded ) {
		$find = isset( $_GET['dzsq_find'] ) ? trim( sanitize_text_field( wp_unslash( $_GET['dzsq_find'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<h3>Find a product in Square</h3>';
		echo '<p class="description">Type part of a product name (or a SKU). Square\'s matches are listed below with a button to bring each one onto the website or refresh it.</p>';
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '#dzsq-tools"><input type="hidden" name="page" value="dzsq">';
		echo '<input type="search" name="dzsq_find" value="' . esc_attr( $find ) . '" class="regular-text" placeholder="e.g. a product name"> <button class="button">Search Square</button></form>';
		if ( '' === $find ) {
			return;
		}
		$items = self::search( $find );
		if ( is_wp_error( $items ) ) {
			echo '<p class="dzsq-err">Square search failed: ' . esc_html( $items->get_error_message() ) . '</p>';
			return;
		}
		if ( ! $items ) {
			echo '<p>No Square items match "' . esc_html( $find ) . '".</p>';
			return;
		}
		$existing = DZSQ_Catalog::existing_products();
		echo '<table class="widefat striped dzsq-find"><thead><tr><th>Square item</th><th>Category</th><th>Price</th><th>On the website</th><th></th></tr></thead><tbody>';
		foreach ( $items as $item ) {
			$d     = $item['item_data'] ?? array();
			$vars  = array_filter( $d['variations'] ?? array(), function ( $v ) { return empty( $v['is_deleted'] ); } );
			$skus  = array();
			$cents = array();
			foreach ( $vars as $v ) {
				$vd = $v['item_variation_data'] ?? array();
				if ( ! empty( $vd['sku'] ) ) {
					$skus[] = $vd['sku'];
				}
				if ( isset( $vd['price_money']['amount'] ) ) {
					$cents[] = (int) $vd['price_money']['amount'];
				}
			}
			$price = $cents ? ( min( $cents ) === max( $cents ) ? '$' . number_format( min( $cents ) / 100, 2 ) : '$' . number_format( min( $cents ) / 100, 2 ) . '–$' . number_format( max( $cents ) / 100, 2 ) ) : '—';
			$cat   = array();
			foreach ( DZSQ_Catalog::item_categories( $d ) as $cid ) {
				$cat[] = self::cat_path( $cid, $cats );
			}
			$ok  = self::should_sync( $item, $allowed, $excluded );
			$ex  = $existing[ $item['id'] ] ?? null;
			$pid = $ex['pid'] ?? 0;

			echo '<tr><td><strong>' . esc_html( $d['name'] ?? $item['id'] ) . '</strong>';
			if ( count( $vars ) > 1 ) {
				echo '<br><span class="description">' . esc_html( count( $vars ) . ' variations' ) . '</span>';
			}
			if ( $skus ) {
				echo '<br><span class="description">SKU ' . esc_html( implode( ', ', array_slice( $skus, 0, 4 ) ) . ( count( $skus ) > 4 ? '…' : '' ) ) . '</span>';
			}
			echo '</td><td>' . esc_html( $cat ? implode( '; ', $cat ) : '—' ) . '</td><td>' . esc_html( $price ) . '</td><td>';
			if ( $pid ) {
				$state = 'publish' === $ex['status'] ? 'Published' : ( $ex['hidden'] ? 'Kept hidden' : ucfirst( $ex['status'] ) );
				echo '<a href="' . esc_url( get_edit_post_link( $pid, 'url' ) ) . '">' . esc_html( $state ) . '</a>';
			} else {
				echo 'Not yet';
			}
			echo '</td><td>';
			if ( $ok ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="dzsq_item_sync">';
				wp_nonce_field( 'dzsq_item_sync' );
				echo '<input type="hidden" name="dzsq_item" value="' . esc_attr( $item['id'] ) . '"><input type="hidden" name="dzsq_find" value="' . esc_attr( $find ) . '">';
				echo '<button class="button button-small' . ( $pid ? '' : ' button-primary' ) . '">' . ( $pid ? 'Sync now' : 'Add to website' ) . '</button></form>';
			} else {
				echo '<span class="description">' . esc_html( 'Stays off the website: ' . self::why_not( $item, $cats, $allowed, $excluded ) ) . '</span>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		if ( count( $items ) >= self::MAX_RESULTS ) {
			echo '<p class="description">Showing the first ' . (int) self::MAX_RESULTS . ' matches. Add more words to narrow it down.</p>';
		}
	}
}
