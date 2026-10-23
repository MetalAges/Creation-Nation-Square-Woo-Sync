<?php
/**
 * Creation Nation Square Sync: receives Square's instant notifications.
 *
 * inventory.count.updated  -> update that item's stock right away.
 * catalog.version.updated  -> run a catalog sync a minute later (batched).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DZSQ_Webhook {

	const EVENTS = array( 'inventory.count.updated', 'catalog.version.updated' );

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function url() {
		return rest_url( 'dzsq/v1/webhook' );
	}

	public static function routes() {
		register_rest_route(
			'dzsq/v1',
			'/webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle' ),
				'permission_callback' => '__return_true', // Authenticated by Square's signature below.
			)
		);
	}

	public static function handle( WP_REST_Request $request ) {
		$body      = $request->get_body();
		$signature = (string) $request->get_header( 'x-square-hmacsha256-signature' );
		$key       = DZSQ_Settings::webhook_key();
		$url       = (string) get_option( 'dzsq_webhook_url', self::url() );
		$expected  = base64_encode( hash_hmac( 'sha256', $url . $body, $key, true ) );

		if ( '' === $key || '' === $signature || ! hash_equals( $expected, $signature ) ) {
			return new WP_REST_Response( array( 'ok' => false ), 403 );
		}

		$event = json_decode( $body, true );
		$type  = is_array( $event ) ? (string) ( $event['type'] ?? '' ) : '';
		update_option( 'dzsq_last_webhook', time(), false );

		if ( 'inventory.count.updated' === $type ) {
			$location = (string) DZSQ_Settings::get( 'location_id' );
			foreach ( $event['data']['object']['inventory_counts'] ?? array() as $count ) {
				if ( 'IN_STOCK' !== ( $count['state'] ?? '' ) || ( $count['location_id'] ?? '' ) !== $location ) {
					continue;
				}
				$pid = DZSQ_Inventory::find( (string) ( $count['catalog_object_id'] ?? '' ) );
				if ( $pid ) {
					DZSQ_Inventory::set_stock( $pid, (float) ( $count['quantity'] ?? 0 ) );
				} else {
					/** Fires for a count on an item this plugin did not create, so other code can react to it. */
					do_action( 'dzsq_inventory_count', $count );
				}
			}
		} elseif ( 'catalog.version.updated' === $type ) {
			DZSQ_Catalog::request_sync( 60 );
		}

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/** Create (or re-create) the Square webhook subscription for this site. */
	public static function register() {
		$old = (string) get_option( 'dzsq_webhook_id', '' );
		if ( '' !== $old ) {
			DZSQ_API::request( 'DELETE', '/v2/webhooks/subscriptions/' . rawurlencode( $old ) );
		}
		$url = self::url();
		$r   = DZSQ_API::request(
			'POST',
			'/v2/webhooks/subscriptions',
			array(
				'idempotency_key' => wp_generate_uuid4(),
				'subscription'    => array(
					'name'             => substr( DZSQ_Settings::site_name() . ' website', 0, 255 ),
					'event_types'      => self::EVENTS,
					'notification_url' => $url,
					'api_version'      => DZSQ_API::VERSION,
				),
			)
		);
		if ( is_wp_error( $r ) ) {
			DZSQ_Log::error( 'Could not turn on instant updates from Square: ' . $r->get_error_message() );
			return $r;
		}
		update_option( 'dzsq_webhook_id', (string) ( $r['subscription']['id'] ?? '' ), false );
		update_option( 'dzsq_webhook_url', $url, false );
		DZSQ_Settings::set_webhook_key( (string) ( $r['subscription']['signature_key'] ?? '' ) );
		DZSQ_Log::info( 'Instant updates from Square are on.' );
		return true;
	}

	public static function unregister() {
		$old = (string) get_option( 'dzsq_webhook_id', '' );
		if ( '' !== $old ) {
			DZSQ_API::request( 'DELETE', '/v2/webhooks/subscriptions/' . rawurlencode( $old ) );
		}
		delete_option( 'dzsq_webhook_id' );
		delete_option( 'dzsq_webhook_key' );
		delete_option( 'dzsq_webhook_url' );
	}
}
