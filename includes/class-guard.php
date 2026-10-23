<?php
/**
 * Creation Nation Square Sync: keeps the sync gentle on the server and recovers
 * from outages ("Service unavailable", crashes, restarts).
 *
 * - busy():     pauses sync work for a few minutes after a slow step.
 * - watchdog(): runs every 5 minutes; resumes an interrupted catalog sync from
 *               its last checkpoint, clears stuck locks, and re-sends any paid
 *               website order that never reached Square.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DZSQ_Guard {

	const BACKOFF = 120; // seconds to wait when the server is busy

	public static function init() {
		add_action( 'dzsq_watchdog', array( __CLASS__, 'watchdog' ) );
	}

	/**
	 * True while the server is struggling. Your hosting shares the machine with
	 * other sites, so the load average says little about this site; instead the
	 * sync times its own steps, and a slow step means "back off for a while".
	 */
	public static function busy() {
		return time() < (int) get_option( 'dzsq_slow_until', 0 );
	}

	/** Record how long a sync step took; slow steps pause the sync. */
	public static function record( $seconds ) {
		if ( $seconds < 45 ) {
			return;
		}
		$pause = $seconds > 120 ? 600 : 180;
		update_option( 'dzsq_slow_until', time() + $pause, false );
		DZSQ_Log::info( sprintf( 'The server is responding slowly (a step took %d seconds), so syncing is pausing for %d minutes.', $seconds, $pause / 60 ) );
	}

	public static function watchdog() {
		update_option( 'dzsq_watchdog', time(), false );
		if ( '' === DZSQ_Settings::token() ) {
			return;
		}

		// 1. Resume an interrupted catalog sync from its last checkpoint.
		$run = get_option( 'dzsq_run' );
		if ( is_array( $run ) ) {
			$beat    = (int) ( $run['beat'] ?? $run['started'] ?? 0 );
			$pending = as_has_scheduled_action( 'dzsq_sync_batch', null, DZSQ_GROUP );
			if ( ! $pending && ( time() - $beat ) > 10 * MINUTE_IN_SECONDS ) {
				$offset = (int) ( $run['offset'] ?? 0 );
				DZSQ_Log::info( sprintf( 'The catalog sync was interrupted (the server may have gone down). Resuming at item %d of %d.', $offset + 1, count( $run['todo'] ) ) );
				update_option( 'dzsq_lock', time(), false );
				DZSQ_Catalog::queue_batch( $offset, 5 );
			}
		} elseif ( get_option( 'dzsq_lock' ) && ( time() - (int) get_option( 'dzsq_lock' ) ) > 10 * MINUTE_IN_SECONDS
			&& ! as_has_scheduled_action( 'dzsq_full_sync', null, DZSQ_GROUP ) ) {
			// A sync died before it got going: clear the lock and start again.
			delete_option( 'dzsq_lock' );
			DZSQ_Log::info( 'A sync was interrupted before it started. Starting it again.' );
			DZSQ_Catalog::request_sync( 60 );
		}

		// 2b. Stock-only mode: paid orders whose Square stock was never lowered.
		if ( ! DZSQ_Settings::get( 'push_orders' ) && DZSQ_Settings::get( 'stock_on_sale' ) ) {
			$orders = wc_get_orders(
				array(
					'status'       => array( 'processing', 'completed' ),
					'date_created' => '>' . ( time() - 14 * DAY_IN_SECONDS ),
					'limit'        => 20,
					'return'       => 'ids',
					'meta_query'   => array( // phpcs:ignore WordPress.DB.SlowDBQuery
						array(
							'key'     => '_dzsq_stock_done',
							'compare' => 'NOT EXISTS',
						),
					),
				)
			);
			foreach ( (array) $orders as $order_id ) {
				$order = wc_get_order( $order_id );
				if ( $order && (int) $order->get_meta( '_dzsq_attempts' ) < DZSQ_Orders::MAX_ATTEMPTS ) {
					DZSQ_Orders::queue_stock( $order_id );
				}
			}
		}

		// 2. Paid website orders that never made it to Square.
		if ( DZSQ_Settings::get( 'push_orders' ) ) {
			$orders = wc_get_orders(
				array(
					'status'       => array( 'processing', 'completed' ),
					'date_created' => '>' . ( time() - 14 * DAY_IN_SECONDS ),
					'limit'        => 20,
					'return'       => 'ids',
					'meta_query'   => array( // phpcs:ignore WordPress.DB.SlowDBQuery
						array(
							'key'     => '_dzsq_square_payment_id',
							'compare' => 'NOT EXISTS',
						),
					),
				)
			);
			foreach ( (array) $orders as $order_id ) {
				$order = wc_get_order( $order_id );
				if ( $order && (int) $order->get_meta( '_dzsq_attempts' ) < DZSQ_Orders::MAX_ATTEMPTS ) {
					DZSQ_Orders::queue_push( $order_id );
				}
			}
		}
	}
}
