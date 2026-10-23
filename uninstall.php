<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 *
 * Removes the plugin's own settings, saved (encrypted) Square token, webhook key and activity log.
 * Your products, categories, media and orders are left exactly as they are.
 *
 * Tip: before deleting, open WooCommerce > Square Sync and tick Disconnect so the webhook is removed from Square.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

foreach ( array(
	'dzsq_settings',
	'dzsq_token',
	'dzsq_webhook_key',
	'dzsq_webhook_id',
	'dzsq_webhook_url',
	'dzsq_log',
	'dzsq_auth_failed',
	'dzsq_run',
	'dzsq_cat_run',
	'dzsq_cat_last',
	'dzsq_lock',
	'dzsq_resync_pending',
	'dzsq_last_sync',
	'dzsq_last_reconcile',
	'dzsq_last_webhook',
	'dzsq_inventory_since',
	'dzsq_image_slot',
	'dzsq_slow_until',
	'dzsq_watchdog',
	'dzsq_paused',
	'dzsq_last_sku',
	'dzsq_label_offset',
) as $dzsq_option ) {
	delete_option( $dzsq_option );
}
