<?php
/**
 * Plugin Name:       Creation Nation Square Sync
 * Description:       Selective, two-way Square and WooCommerce sync: choose which Square categories go on your website, keep stock matched both ways, and record website orders in Square.
 * Version:           1.2.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       creation-nation-square-sync
 *
 * Settings: WooCommerce > Square Sync.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( defined( 'DZSQ_VERSION' ) ) {
	return;
}

define( 'DZSQ_VERSION', '1.2.0' );
define( 'DZSQ_DIR', plugin_dir_path( __FILE__ ) . 'includes/' );
define( 'DZSQ_GROUP', 'dzsq' );

require_once DZSQ_DIR . 'class-core.php';
require_once DZSQ_DIR . 'class-catalog.php';
require_once DZSQ_DIR . 'class-inventory.php';
require_once DZSQ_DIR . 'class-orders.php';
require_once DZSQ_DIR . 'class-webhook.php';
require_once DZSQ_DIR . 'class-admin.php';
require_once DZSQ_DIR . 'class-guard.php';
require_once DZSQ_DIR . 'class-manual.php';
require_once DZSQ_DIR . 'class-labels.php';
require_once DZSQ_DIR . 'class-tools.php';

final class DZSQ_Plugin {

	public static function boot() {
		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'as_enqueue_async_action' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'missing_woocommerce' ) );
			return;
		}
		// Stay completely idle while paused (set the dzsq_paused option to a true value; off by default).
		if ( get_option( 'dzsq_paused' ) ) {
			return;
		}
		DZSQ_Catalog::init();
		DZSQ_Inventory::init();
		DZSQ_Orders::init();
		DZSQ_Webhook::init();
		DZSQ_Guard::init();
		DZSQ_Manual::init();
		DZSQ_Labels::init();
		DZSQ_Tools::init();
		if ( is_admin() ) {
			DZSQ_Admin::init();
		}
		add_action( 'init', array( __CLASS__, 'schedule' ), 20 );
	}

	public static function missing_woocommerce() {
		if ( current_user_can( 'activate_plugins' ) ) {
			echo '<div class="notice notice-warning"><p><strong>Creation Nation Square Sync</strong> needs WooCommerce to be installed and active.</p></div>';
		}
	}

	/** Keep the recurring safety-net jobs scheduled once Square is connected. */
	public static function schedule() {
		if ( '' === DZSQ_Settings::token() ) {
			return;
		}
		if ( get_transient( 'dzsq_scheduled_check' ) ) {
			return;
		}
		set_transient( 'dzsq_scheduled_check', 1, 10 * MINUTE_IN_SECONDS );
		if ( ! as_has_scheduled_action( 'dzsq_reconcile', null, DZSQ_GROUP ) ) {
			as_schedule_recurring_action( time() + 300, 15 * MINUTE_IN_SECONDS, 'dzsq_reconcile', array(), DZSQ_GROUP );
		}
		if ( ! as_has_scheduled_action( 'dzsq_watchdog', null, DZSQ_GROUP ) ) {
			as_schedule_recurring_action( time() + 60, 5 * MINUTE_IN_SECONDS, 'dzsq_watchdog', array(), DZSQ_GROUP );
		}
		if ( ! as_has_scheduled_action( 'dzsq_full_sync_recurring', null, DZSQ_GROUP ) ) {
			as_schedule_recurring_action( time() + HOUR_IN_SECONDS, 6 * HOUR_IN_SECONDS, 'dzsq_full_sync_recurring', array(), DZSQ_GROUP );
		}
	}

	/** On deactivation, cancel this plugin's queued and recurring background jobs. */
	public static function deactivate() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), DZSQ_GROUP );
		}
	}
}

register_deactivation_hook( __FILE__, array( 'DZSQ_Plugin', 'deactivate' ) );

add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	function ( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=dzsq' ) ) . '">Settings</a>' );
		return $links;
	}
);

// Boot once WooCommerce is ready.
if ( doing_action( 'plugins_loaded' ) || ! did_action( 'plugins_loaded' ) ) {
	add_action( 'plugins_loaded', array( 'DZSQ_Plugin', 'boot' ), 20 );
} else {
	DZSQ_Plugin::boot();
}
