<?php
/**
 * Creation Nation Square Sync: Square stock counts -> WooCommerce stock.
 *
 * Square is the source of truth for stock. Website sales reach Square as
 * orders (see class-orders.php); Square then reports the new count back here.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DZSQ_Inventory {

	public static function init() {
		add_action( 'dzsq_reconcile', array( __CLASS__, 'scheduled_reconcile' ) );
		add_action( 'dzsq_reconcile_chunk', array( __CLASS__, 'reconcile_chunk' ), 10, 1 );
	}

	/** Start a full stock check, done in small chunks so it never overloads the server. */
	public static function queue_full( $delay = 5 ) {
		if ( ! as_has_scheduled_action( 'dzsq_reconcile_chunk', null, DZSQ_GROUP ) ) {
			as_schedule_single_action( time() + $delay, 'dzsq_reconcile_chunk', array( 'offset' => 0 ), DZSQ_GROUP );
		}
	}

	public static function reconcile_chunk( $offset ) {
		if ( DZSQ_Guard::busy() ) {
			as_schedule_single_action( time() + DZSQ_Guard::BACKOFF, 'dzsq_reconcile_chunk', array( 'offset' => (int) $offset ), DZSQ_GROUP );
			return;
		}
		$ids = array_keys( self::variation_map() );
		sort( $ids );
		$slice = array_slice( $ids, (int) $offset, 150 );
		if ( ! $slice ) {
			update_option( 'dzsq_last_reconcile', time(), false );
			DZSQ_Log::info( 'Full stock check finished.' );
			return;
		}
		self::reconcile_ids( $slice );
		as_schedule_single_action( time() + 10, 'dzsq_reconcile_chunk', array( 'offset' => (int) $offset + 150 ), DZSQ_GROUP );
	}

	/** Set exact stock for specific Square variations (missing count = 0). */
	public static function reconcile_ids( array $vids ) {
		$all = self::variation_map();
		$map = array_intersect_key( $all, array_flip( $vids ) );
		if ( ! $map ) {
			return 0;
		}
		$location = (string) DZSQ_Settings::get( 'location_id' );
		if ( '' === $location ) {
			return 0;
		}
		$counts   = array();
		foreach ( array_chunk( array_keys( $map ), 500 ) as $chunk ) {
			$cursor = null;
			do {
				$body = array(
					'catalog_object_ids' => array_values( $chunk ),
					'location_ids'       => array( $location ),
					'states'             => array( 'IN_STOCK' ),
				);
				if ( $cursor ) {
					$body['cursor'] = $cursor;
				}
				$r = DZSQ_API::request( 'POST', '/v2/inventory/counts/batch-retrieve', $body );
				if ( is_wp_error( $r ) ) {
					DZSQ_Log::error( 'Stock check failed: ' . $r->get_error_message() );
					return 0;
				}
				foreach ( $r['counts'] ?? array() as $c ) {
					if ( 'IN_STOCK' === ( $c['state'] ?? '' ) && ( $c['location_id'] ?? '' ) === $location ) {
						$counts[ $c['catalog_object_id'] ] = (float) $c['quantity'];
					}
				}
				$cursor = $r['cursor'] ?? null;
			} while ( $cursor );
		}
		$changed = 0;
		foreach ( $map as $vid => $pid ) {
			$changed += self::set_stock( $pid, $counts[ $vid ] ?? 0 ) ? 1 : 0;
		}
		return $changed;
	}

	public static function scheduled_reconcile() {
		if ( DZSQ_Catalog::locked() || DZSQ_Guard::busy() || '' === (string) DZSQ_Settings::get( 'location_id' ) ) {
			return; // A sync is running, or the server is busy; the next check is 15 minutes away.
		}
		self::reconcile( false );
	}

	/** @return array square variation id => WooCommerce product/variation id */
	public static function variation_map() {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT m.post_id, m.meta_value FROM {$wpdb->postmeta} m
			JOIN {$wpdb->posts} p ON p.ID = m.post_id
			WHERE m.meta_key = '_dzsq_variation_id' AND p.post_type IN ('product','product_variation') AND p.post_status <> 'trash'"
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$map = array();
		foreach ( (array) $rows as $row ) {
			$map[ $row->meta_value ] = (int) $row->post_id;
		}
		return $map;
	}

	public static function find( $square_variation_id ) {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT m.post_id FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id
				WHERE m.meta_key = '_dzsq_variation_id' AND m.meta_value = %s AND p.post_status <> 'trash' LIMIT 1",
				$square_variation_id
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Pull stock counts from Square.
	 *
	 * @param bool $full true = check every synced item (anything Square has no
	 *                   count for is set to 0); false = only counts that changed
	 *                   since the last check.
	 */
	public static function reconcile( $full = false ) {
		$map = self::variation_map();
		if ( ! $map || '' === DZSQ_Settings::token() ) {
			return;
		}
		$location = (string) DZSQ_Settings::get( 'location_id' );
		if ( '' === $location ) {
			return;
		}
		$since    = $full ? '' : (string) get_option( 'dzsq_inventory_since', '' );
		$started  = gmdate( 'Y-m-d\TH:i:s\Z', time() - 120 );
		$counts   = array();

		foreach ( array_chunk( array_keys( $map ), 500 ) as $chunk ) {
			$cursor = null;
			do {
				$body = array(
					'catalog_object_ids' => array_values( $chunk ),
					'location_ids'       => array( $location ),
					'states'             => array( 'IN_STOCK' ),
				);
				if ( '' !== $since ) {
					$body['updated_after'] = $since;
				}
				if ( $cursor ) {
					$body['cursor'] = $cursor;
				}
				$r = DZSQ_API::request( 'POST', '/v2/inventory/counts/batch-retrieve', $body );
				if ( is_wp_error( $r ) ) {
					DZSQ_Log::error( 'Stock check failed: ' . $r->get_error_message() );
					return;
				}
				foreach ( $r['counts'] ?? array() as $c ) {
					if ( 'IN_STOCK' === ( $c['state'] ?? '' ) && ( $c['location_id'] ?? '' ) === $location ) {
						$counts[ $c['catalog_object_id'] ] = (float) $c['quantity'];
					}
				}
				$cursor = $r['cursor'] ?? null;
			} while ( $cursor );
		}

		$changed = 0;
		foreach ( $map as $vid => $pid ) {
			if ( isset( $counts[ $vid ] ) ) {
				$changed += self::set_stock( $pid, $counts[ $vid ] ) ? 1 : 0;
			} elseif ( $full ) {
				$changed += self::set_stock( $pid, 0 ) ? 1 : 0;
			}
		}
		update_option( 'dzsq_inventory_since', $started, false );
		update_option( 'dzsq_last_reconcile', time(), false );
		if ( $changed ) {
			DZSQ_Log::info( sprintf( 'Stock check: updated %d item(s) from Square.', $changed ) );
		}
	}

	/** @return bool whether the stock level changed */
	public static function set_stock( $product_id, $quantity ) {
		$product = wc_get_product( $product_id );
		if ( ! $product || ! $product->managing_stock() ) {
			return false;
		}
		$quantity = max( 0, (int) floor( (float) $quantity ) );
		if ( null !== $product->get_stock_quantity() && (int) $product->get_stock_quantity() === $quantity ) {
			return false;
		}
		wc_update_product_stock( $product, $quantity, 'set' );
		return true;
	}
}
