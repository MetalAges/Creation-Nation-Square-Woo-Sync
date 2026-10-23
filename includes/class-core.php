<?php
/**
 * Creation Nation Square Sync: settings, logging and the Square API client.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DZSQ_Settings {

	const OPTION = 'dzsq_settings';

	public static function defaults() {
		return array(
			// Chosen on the Square Sync screen after connecting.
			'location_id'        => '',
			'include_categories' => array(),
			'exclude_categories' => array(),
			// Optional regular expression: items whose name matches stay off the website.
			'skip_pattern'       => '',
			'push_orders'        => 0,
			'stock_on_sale'      => 1,
			'product_status'     => 'publish',
			// Optional prefix for automatic SKUs (blank = off), and the shop name printed on barcode labels (blank = site name).
			'sku_prefix'         => '',
			'label_brand'        => '',
		);
	}

	public static function all() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	public static function get( $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	public static function update( array $values ) {
		update_option( self::OPTION, array_merge( self::all(), $values ), false );
	}

	public static function token() {
		return self::decrypt( (string) get_option( 'dzsq_token', '' ) );
	}

	public static function set_token( $token ) {
		update_option( 'dzsq_token', self::encrypt( trim( (string) $token ) ), false );
		delete_option( 'dzsq_auth_failed' );
		delete_transient( 'dzsq_categories' );
		delete_transient( 'dzsq_locations' );
	}

	public static function webhook_key() {
		return self::decrypt( (string) get_option( 'dzsq_webhook_key', '' ) );
	}

	public static function set_webhook_key( $key ) {
		update_option( 'dzsq_webhook_key', self::encrypt( (string) $key ), false );
	}

	/** A short, stable code for this website. Keeps Square request keys unique when several sites share one Square account. */
	public static function site_key() {
		return substr( md5( home_url() ), 0, 8 );
	}

	/** The shop name shown in Square (website order source, webhook name). */
	public static function site_name() {
		$name = trim( wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) );
		return '' !== $name ? $name : 'WooCommerce';
	}

	/** Letters and numbers only. Blank means automatic SKUs are off. */
	public static function sku_prefix() {
		return substr( preg_replace( '/[^A-Za-z0-9]/', '', (string) self::get( 'sku_prefix' ) ), 0, 10 );
	}

	/** The shop name printed on barcode labels. */
	public static function label_brand() {
		$brand = trim( (string) self::get( 'label_brand' ) );
		return '' !== $brand ? $brand : self::site_name();
	}

	public static function is_price_point( $name ) {
		$pattern = (string) self::get( 'skip_pattern' );
		if ( '' === $pattern ) {
			return false;
		}
		return 1 === @preg_match( $pattern, trim( (string) $name ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	private static function key() {
		return hash( 'sha256', wp_salt( 'auth' ) . '|dzsq', true );
	}

	private static function encrypt( $plain ) {
		if ( '' === $plain ) {
			return '';
		}
		$iv     = random_bytes( 16 );
		$cipher = openssl_encrypt( $plain, 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, $iv );
		return false === $cipher ? '' : base64_encode( $iv . $cipher );
	}

	private static function decrypt( $stored ) {
		if ( '' === $stored ) {
			return '';
		}
		$raw = base64_decode( $stored, true );
		if ( false === $raw || strlen( $raw ) < 17 ) {
			return '';
		}
		$plain = openssl_decrypt( substr( $raw, 16 ), 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, substr( $raw, 0, 16 ) );
		return false === $plain ? '' : $plain;
	}
}

final class DZSQ_Log {

	const OPTION = 'dzsq_log';
	const MAX    = 300;

	public static function info( $message ) {
		self::add( 'info', $message );
	}

	public static function error( $message ) {
		self::add( 'error', $message );
	}

	public static function add( $level, $message ) {
		$log = get_option( self::OPTION, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		array_unshift(
			$log,
			array(
				't' => time(),
				'l' => $level,
				'm' => wp_strip_all_tags( (string) $message ),
			)
		);
		update_option( self::OPTION, array_slice( $log, 0, self::MAX ), false );
	}

	public static function entries( $limit = 100 ) {
		$log = get_option( self::OPTION, array() );
		return is_array( $log ) ? array_slice( $log, 0, $limit ) : array();
	}
}

final class DZSQ_API {

	const VERSION = '2025-01-23';
	const BASE    = 'https://connect.squareup.com';

	/** True while testing a token that isn't saved yet, so a typo doesn't raise the alarm. */
	public static $quiet = false;

	/**
	 * Call the Square API. Returns the decoded body, or WP_Error.
	 */
	public static function request( $method, $path, $body = null, array $query = array() ) {
		$token = DZSQ_Settings::token();
		if ( '' === $token ) {
			return new WP_Error( 'dzsq_no_token', 'No Square access token is saved yet.' );
		}

		$url = self::BASE . $path;
		if ( $query ) {
			$url .= '?' . http_build_query( $query );
		}

		$args = array(
			'method'  => $method,
			'timeout' => 45,
			'headers' => array(
				'Authorization'  => 'Bearer ' . $token,
				'Square-Version' => self::VERSION,
				'Content-Type'   => 'application/json',
				'Accept'         => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$last_error = null;
		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			$response = wp_remote_request( $url, $args );
			if ( is_wp_error( $response ) ) {
				$last_error = $response;
				self::pause( $attempt );
				continue;
			}
			$code = (int) wp_remote_retrieve_response_code( $response );
			$data = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( 429 === $code || $code >= 500 ) {
				$last_error = new WP_Error( 'dzsq_http_' . $code, self::error_message( $data, $code ) );
				self::pause( $attempt );
				continue;
			}
			if ( 401 === $code ) {
				$error = new WP_Error( 'dzsq_http_401', self::error_message( $data, $code ), $data );
				DZSQ_Auth::failed( $error->get_error_message() );
				return $error;
			}
			if ( $code >= 400 ) {
				return new WP_Error( 'dzsq_http_' . $code, self::error_message( $data, $code ), $data );
			}
			DZSQ_Auth::ok();
			return is_array( $data ) ? $data : array();
		}
		return $last_error ?: new WP_Error( 'dzsq_http', 'Square did not respond.' );
	}

	/** Wait before retrying: about 1s, then 3s, plus up to a second of randomness so two sites don't retry in step. No wait after the last try. */
	private static function pause( $attempt ) {
		if ( $attempt < 2 ) {
			usleep( ( 1 + 2 * $attempt ) * 1000000 + wp_rand( 0, 1000000 ) );
		}
	}

	private static function error_message( $data, $code ) {
		if ( is_array( $data ) && ! empty( $data['errors'] ) ) {
			$parts = array();
			foreach ( $data['errors'] as $e ) {
				$parts[] = trim( ( $e['detail'] ?? '' ) . ( isset( $e['code'] ) ? ' (' . $e['code'] . ')' : '' ) );
			}
			return implode( '; ', $parts );
		}
		return 'Square returned HTTP ' . $code . '.';
	}

	public static function locations() {
		$cached = get_transient( 'dzsq_locations' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$r = self::request( 'GET', '/v2/locations' );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$out = array();
		foreach ( $r['locations'] ?? array() as $loc ) {
			$out[ $loc['id'] ] = array(
				'name'     => $loc['name'] ?? $loc['id'],
				'business' => $loc['business_name'] ?? '',
				'status'   => $loc['status'] ?? '',
				'address'  => $loc['address']['address_line_1'] ?? '',
			);
		}
		set_transient( 'dzsq_locations', $out, HOUR_IN_SECONDS );
		return $out;
	}

	public static function cents( $amount ) {
		return (int) round( (float) $amount * 100 );
	}
}

/**
 * Raises the alarm when Square stops accepting the access token (revoked,
 * regenerated or deleted) instead of letting the sync fail quietly: an error
 * banner on every admin screen, a log entry, and one email per outage.
 * Clears itself as soon as Square accepts a token again.
 */
final class DZSQ_Auth {

	const OPTION = 'dzsq_auth_failed';

	public static function failed( $message ) {
		if ( DZSQ_API::$quiet || get_option( self::OPTION ) ) {
			return;
		}
		update_option( self::OPTION, array( 't' => time(), 'm' => (string) $message ), false );
		DZSQ_Log::error( 'Square rejected the access token (' . $message . '). Nothing will sync until a new token is saved.' );

		$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		wp_mail(
			(string) get_option( 'admin_email' ),
			'[' . $site . '] Square sync needs a new access token',
			"Square stopped accepting the access token used by the Square sync on {$site}, so products, stock and website orders are not syncing.\n\n"
			. "Square said: {$message}\n\n"
			. "To fix it, copy the Production access token from your app in the Square Developer Dashboard (https://developer.squareup.com/apps) and paste it on the Square Sync screen:\n"
			. admin_url( 'admin.php?page=dzsq' ) . "\n\n"
			. "This email is sent once per outage. Syncing picks up again on its own once a working token is saved.\n"
		);
	}

	public static function ok() {
		if ( get_option( self::OPTION ) ) {
			delete_option( self::OPTION );
			DZSQ_Log::info( 'Square is accepting the access token again. Syncing has resumed.' );
		}
	}

	public static function notice() {
		$failed = get_option( self::OPTION );
		if ( ! is_array( $failed ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p><strong>Square sync is stopped.</strong> Square stopped accepting the access token '
			. esc_html( human_time_diff( (int) $failed['t'] ) ) . ' ago (' . esc_html( $failed['m'] ) . '), so products, stock and website orders are not syncing. '
			. '<a href="' . esc_url( admin_url( 'admin.php?page=dzsq' ) ) . '">Paste a new access token</a></p></div>';
	}
}

add_action( 'admin_notices', array( 'DZSQ_Auth', 'notice' ) );
