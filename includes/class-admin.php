<?php
/**
 * Creation Nation Square Sync: the WooCommerce > Square Sync screen.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DZSQ_Admin {

	const SLUG = 'dzsq';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 60 );
		foreach ( array( 'save', 'sync', 'reconcile', 'webhook', 'push' ) as $action ) {
			add_action( 'admin_post_dzsq_' . $action, array( __CLASS__, 'handle_' . $action ) );
		}
	}

	public static function menu() {
		add_submenu_page( 'woocommerce', 'Square Sync', 'Square Sync', 'manage_woocommerce', self::SLUG, array( __CLASS__, 'render' ) );
	}

	private static function page_url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	private static function guard( $action ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'You do not have permission to do this.', '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'dzsq_' . $action );
	}

	private static function back( $type, $message ) {
		set_transient( 'dzsq_notice_' . get_current_user_id(), array( $type, $message ), 60 );
		wp_safe_redirect( self::page_url() );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Actions                                                             */
	/* ------------------------------------------------------------------ */

	public static function handle_save() {
		self::guard( 'save' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in guard().
		$had_token = '' !== DZSQ_Settings::token();
		$new_token = isset( $_POST['dzsq_token'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['dzsq_token'] ) ) ) : '';

		if ( ! empty( $_POST['dzsq_remove_token'] ) ) {
			DZSQ_Webhook::unregister();
			DZSQ_Settings::set_token( '' );
			self::back( 'success', 'The Square connection was removed. Nothing will sync until a new access token is saved.' );
		}

		if ( '' !== $new_token ) {
			$previous = DZSQ_Settings::token();
			DZSQ_Settings::set_token( $new_token );
			DZSQ_API::$quiet = true;
			$test = DZSQ_API::request( 'GET', '/v2/locations' );
			DZSQ_API::$quiet = false;
			if ( is_wp_error( $test ) ) {
				DZSQ_Settings::set_token( $previous );
				self::back( 'error', 'Square did not accept that access token: ' . $test->get_error_message() . ' Check that you copied the Production access token, then try again.' );
			}
		}

		$values = array();
		if ( isset( $_POST['dzsq_location'] ) ) {
			$values['location_id'] = sanitize_text_field( wp_unslash( $_POST['dzsq_location'] ) );
		}
		if ( isset( $_POST['dzsq_categories_shown'] ) ) {
			$values['include_categories'] = array_values( array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['dzsq_include'] ?? array() ) ) );
			$values['exclude_categories'] = array_values( array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['dzsq_exclude'] ?? array() ) ) );
		}
		if ( isset( $_POST['dzsq_skip_pattern'] ) ) {
			$pattern = trim( wp_unslash( $_POST['dzsq_skip_pattern'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- a regex; validated below.
			if ( '' !== $pattern && false === @preg_match( $pattern, '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				self::back( 'error', 'The price-point pattern is not valid, so nothing was saved.' );
			}
			$values['skip_pattern'] = $pattern;
		}
		if ( isset( $_POST['dzsq_status'] ) ) {
			$values['product_status'] = 'draft' === $_POST['dzsq_status'] ? 'draft' : 'publish';
		}
		if ( isset( $_POST['dzsq_sku_prefix'] ) ) {
			$values['sku_prefix'] = substr( preg_replace( '/[^A-Za-z0-9]/', '', sanitize_text_field( wp_unslash( $_POST['dzsq_sku_prefix'] ) ) ), 0, 10 );
		}
		if ( isset( $_POST['dzsq_label_brand'] ) ) {
			$values['label_brand'] = substr( sanitize_text_field( wp_unslash( $_POST['dzsq_label_brand'] ) ), 0, 40 );
		}
		if ( isset( $_POST['dzsq_form'] ) ) {
			$mode                   = isset( $_POST['dzsq_order_mode'] ) ? sanitize_key( wp_unslash( $_POST['dzsq_order_mode'] ) ) : 'stock';
			$values['push_orders']   = 'full' === $mode ? 1 : 0;
			$values['stock_on_sale'] = 'stock' === $mode ? 1 : 0;
		}
		// phpcs:enable
		DZSQ_Settings::update( $values );

		if ( '' !== $new_token && ! $had_token ) {
			// First connection: turn on instant updates, and pick the location automatically when there is only one.
			$hook = DZSQ_Webhook::register();
			DZSQ_Plugin::schedule();
			$locations = DZSQ_API::locations();
			if ( is_array( $locations ) && '' === (string) DZSQ_Settings::get( 'location_id' ) ) {
				$active = array_keys(
					array_filter(
						$locations,
						function ( $l ) {
							return 'ACTIVE' === $l['status'];
						}
					)
				);
				if ( 1 === count( $active ) ) {
					DZSQ_Settings::update( array( 'location_id' => $active[0] ) );
				}
			}
			$msg = 'Connected to Square. Next, choose your location and the categories to sync below, then click Save settings and Sync now.';
			if ( is_wp_error( $hook ) ) {
				$msg .= ' Instant updates could not be turned on yet (' . $hook->get_error_message() . '), so stock will update every 15 minutes until you click "Turn on instant updates".';
			}
			self::back( 'success', $msg );
		}
		if ( '' !== $new_token ) {
			DZSQ_Webhook::register();
		}
		self::back( 'success', 'Settings saved. Changes to categories apply on the next sync; click "Sync now" to apply them right away.' );
	}

	public static function handle_sync() {
		self::guard( 'sync' );
		if ( DZSQ_Catalog::locked() ) {
			self::back( 'info', 'A sync is already running. Another one will start as soon as it finishes.' );
		}
		DZSQ_Catalog::request_sync();
		self::back( 'success', 'Sync started. It runs in the background; refresh this page to see progress.' );
	}

	public static function handle_reconcile() {
		self::guard( 'reconcile' );
		DZSQ_Inventory::queue_full();
		self::back( 'success', 'A full stock check has started. It runs in small steps in the background and takes a few minutes.' );
	}

	public static function handle_webhook() {
		self::guard( 'webhook' );
		$r = DZSQ_Webhook::register();
		if ( is_wp_error( $r ) ) {
			self::back( 'error', 'Instant updates could not be turned on: ' . $r->get_error_message() );
		}
		self::back( 'success', 'Instant updates from Square are on.' );
	}

	public static function handle_push() {
		self::guard( 'push' );
		$number = isset( $_POST['dzsq_order'] ) ? absint( $_POST['dzsq_order'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$order  = $number ? wc_get_order( $number ) : false;
		if ( ! $order ) {
			self::back( 'error', 'No website order with that number was found.' );
		}
		if ( $order->get_meta( '_dzsq_square_payment_id' ) ) {
			self::back( 'info', 'Order #' . $order->get_order_number() . ' is already recorded in Square.' );
		}
		$order->delete_meta_data( '_dzsq_attempts' );
		$order->save();
		DZSQ_Orders::push( $order->get_id() );
		$order = wc_get_order( $order->get_id() );
		if ( $order->get_meta( '_dzsq_square_payment_id' ) ) {
			self::back( 'success', 'Order #' . $order->get_order_number() . ' was recorded in Square.' );
		}
		self::back( 'error', 'Order #' . $order->get_order_number() . ' could not be sent. The reason is in the activity log below and on the order.' );
	}

	/* ------------------------------------------------------------------ */
	/* Screen                                                              */
	/* ------------------------------------------------------------------ */

	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$connected = '' !== DZSQ_Settings::token();
		$settings  = DZSQ_Settings::all();
		$notice    = get_transient( 'dzsq_notice_' . get_current_user_id() );
		delete_transient( 'dzsq_notice_' . get_current_user_id() );
		?>
		<div class="wrap dzsq">
			<h1>Square Sync</h1>
			<p class="dzsq-lede">Brings the Square categories you choose onto the website, keeps stock matched both ways, and records website orders in Square.</p>

			<?php if ( is_array( $notice ) ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice[0] ); ?> is-dismissible"><p><?php echo esc_html( $notice[1] ); ?></p></div>
			<?php endif; ?>

			<?php
			if ( $connected ) {
				self::render_status();
			}
			self::render_settings( $connected, $settings );
			if ( $connected ) {
				self::render_tools();
				self::render_log();
			}
			?>
		</div>
		<style>
			.dzsq .dzsq-lede{font-size:14px;max-width:720px}
			.dzsq .dzsq-card{background:#fff;border:1px solid #dcdcde;padding:16px 20px;margin:16px 0;max-width:960px}
			.dzsq .dzsq-card h2{margin-top:0}
			.dzsq .dzsq-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px}
			.dzsq .dzsq-stat b{display:block;font-size:13px;color:#50575e;font-weight:600}
			.dzsq .dzsq-stat span{font-size:14px}
			.dzsq .dzsq-tree{list-style:none;margin:0;padding:0}
			.dzsq .dzsq-tree ul{list-style:none;margin:0 0 0 22px;padding:0}
			.dzsq .dzsq-tree li{margin:2px 0}
			.dzsq .dzsq-tree label{margin-right:10px}
			.dzsq .dzsq-tree .dzsq-name{display:inline-block;min-width:260px}
			.dzsq .dzsq-tools form{display:inline-block;margin:0 8px 8px 0}
			.dzsq table.dzsq-log td{vertical-align:top}
			.dzsq .dzsq-err{color:#b32d2e}
			.dzsq .dzsq-more h3{margin:22px 0 4px;padding-top:14px;border-top:1px solid #dcdcde}
			.dzsq .dzsq-more table.dzsq-find{margin-top:10px}
			.dzsq .dzsq-more table.dzsq-find td{vertical-align:middle}
			.dzsq .dzsq-more table.dzsq-find form{margin:0}
		</style>
		<?php
	}

	private static function when( $timestamp ) {
		if ( ! $timestamp ) {
			return 'Never';
		}
		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $timestamp ) . ' (' . human_time_diff( (int) $timestamp ) . ' ago)';
	}

	private static function render_status() {
		$last    = get_option( 'dzsq_last_sync' );
		$run     = get_option( 'dzsq_run' );
		$hook_on = '' !== (string) get_option( 'dzsq_webhook_id', '' );
		?>
		<div class="dzsq-card">
			<h2>Status</h2>
			<div class="dzsq-stats">
				<div class="dzsq-stat"><b>Catalog sync</b><span>
					<?php
					if ( is_array( $run ) ) {
						echo esc_html( sprintf( 'Running: %d of %d items updated so far', (int) $run['done'] + (int) $run['errors'], count( $run['todo'] ) ) );
					} elseif ( is_array( $last ) ) {
						echo esc_html( 'Last finished ' . self::when( $last['time'] ) );
					} else {
						echo 'Not run yet';
					}
					?>
				</span></div>
				<div class="dzsq-stat"><b>Items on the website from Square</b><span><?php echo is_array( $last ) ? esc_html( number_format_i18n( (int) $last['synced'] ) ) : '&mdash;'; ?></span></div>
				<div class="dzsq-stat"><b>Instant updates</b><span><?php echo $hook_on ? esc_html( 'On. Last message from Square: ' . self::when( get_option( 'dzsq_last_webhook' ) ) ) : 'Off (stock updates every 15 minutes)'; ?></span></div>
				<div class="dzsq-stat"><b>Last stock check</b><span><?php echo esc_html( self::when( get_option( 'dzsq_last_reconcile' ) ) ); ?></span></div>
			</div>
			<?php if ( is_array( $last ) && ( $last['errors'] || $last['hidden'] ) ) : ?>
				<p><?php echo esc_html( sprintf( 'Last sync: %d added or updated, %d hidden because they left a synced category, %d errors.', $last['updated'], $last['hidden'], $last['errors'] ) ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_settings( $connected, array $settings ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="dzsq_save">
			<input type="hidden" name="dzsq_form" value="1">
			<?php wp_nonce_field( 'dzsq_save' ); ?>

			<div class="dzsq-card">
				<h2>Square connection</h2>
				<?php if ( $connected ) : ?>
					<p>Connected. To replace the access token, paste a new one below.</p>
				<?php else : ?>
					<p>To connect, you need a <strong>Production access token</strong> from Square:</p>
					<ol>
						<li>Open the <a href="https://developer.squareup.com/apps" target="_blank" rel="noopener noreferrer">Square Developer Dashboard</a> and sign in with your Square account.</li>
						<li>Create an application (any name), open it, and switch to <strong>Production</strong>.</li>
						<li>Copy the <strong>Production access token</strong> and paste it below. It is stored encrypted and never shown again.</li>
					</ol>
				<?php endif; ?>
				<p>
					<label for="dzsq_token"><strong>Access token</strong></label><br>
					<input type="password" id="dzsq_token" name="dzsq_token" class="regular-text" autocomplete="off" placeholder="<?php echo $connected ? esc_attr( 'Saved (leave blank to keep it)' ) : 'EAAA…'; ?>">
				</p>
				<?php if ( $connected ) : ?>
					<p><label><input type="checkbox" name="dzsq_remove_token" value="1"> Disconnect from Square</label></p>
				<?php endif; ?>
			</div>

			<?php if ( $connected ) : ?>
				<div class="dzsq-card">
					<h2>What to sync</h2>
					<?php if ( ! (array) $settings['include_categories'] ) : ?>
						<p class="dzsq-err"><strong>Nothing is selected yet.</strong> Choose your location, tick <em>Sync</em> on the categories to put on the website, then click Save settings and Sync now.</p>
					<?php endif; ?>
					<?php self::render_location( $settings ); ?>
					<p><strong>Categories.</strong> Ticking <em>Sync</em> brings that category and all its sub-categories onto the website. Tick <em>Skip</em> to keep a category (and its sub-categories) off, even inside a synced one.</p>
					<?php self::render_tree( $settings ); ?>
					<p style="margin-top:16px">
						<label for="dzsq_skip_pattern"><strong>Keep register-only price buttons off the website</strong></label><br>
						<input type="text" id="dzsq_skip_pattern" name="dzsq_skip_pattern" class="large-text code" value="<?php echo esc_attr( $settings['skip_pattern'] ); ?>">
						<span class="description">Items whose name matches this pattern are skipped. Leave blank to skip nothing. For example, <code>^(LP|CD)\s*\d+(\.\d{1,2})?$</code> keeps register-only price buttons named like "CD 1.95" or "LP 9.95" off the website.</span>
					</p>
					<p>
						<label for="dzsq_status"><strong>New and updated products are</strong></label>
						<select id="dzsq_status" name="dzsq_status">
							<option value="publish" <?php selected( $settings['product_status'], 'publish' ); ?>>Published</option>
							<option value="draft" <?php selected( $settings['product_status'], 'draft' ); ?>>Saved as drafts for review</option>
						</select>
					</p>
				</div>

				<div class="dzsq-card">
					<h2>Website orders</h2>
					<?php $dzsq_mode = ! empty( $settings['push_orders'] ) ? 'full' : ( ! empty( $settings['stock_on_sale'] ) ? 'stock' : 'off' ); ?>
					<p><label><input type="radio" name="dzsq_order_mode" value="stock" <?php checked( 'stock', $dzsq_mode ); ?>> <strong>Lower Square stock only</strong> (use with Square payments)</label></p>
					<p class="description" style="margin-left:24px">Square payments already put the sale in Square's reports, so each paid order just lowers Square's stock. Cancelled or refunded orders put the items back.</p>
					<p><label><input type="radio" name="dzsq_order_mode" value="full" <?php checked( 'full', $dzsq_mode ); ?>> <strong>Record as a paid Square order</strong> (use if payments go through Stripe or another processor)</label></p>
					<p class="description" style="margin-left:24px">Each paid order becomes a paid Square order, so it appears in Square's sales reports and Square lowers its stock. Don't use this with Square payments or sales count twice.</p>
					<p><label><input type="radio" name="dzsq_order_mode" value="off" <?php checked( 'off', $dzsq_mode ); ?>> Do nothing in Square</label></p>
				</div>
				<div class="dzsq-card">
					<h2>SKUs and labels</h2>
					<p>
						<label for="dzsq_sku_prefix"><strong>Automatic SKU prefix</strong></label><br>
						<input type="text" id="dzsq_sku_prefix" name="dzsq_sku_prefix" class="regular-text" maxlength="10" value="<?php echo esc_attr( DZSQ_Settings::sku_prefix() ); ?>" placeholder="e.g. SHOP">
						<span class="description">Letters and numbers only. When you sync a single product by hand, any variation without a SKU is given the next code in this sequence (for example SHOP1001) and the code is written into Square. Leave blank to switch automatic SKUs off.</span>
					</p>
					<p>
						<label for="dzsq_label_brand"><strong>Shop name on barcode labels</strong></label><br>
						<input type="text" id="dzsq_label_brand" name="dzsq_label_brand" class="regular-text" maxlength="40" value="<?php echo esc_attr( (string) $settings['label_brand'] ); ?>" placeholder="<?php echo esc_attr( DZSQ_Settings::site_name() ); ?>">
						<span class="description">Printed under the barcode. Leave blank to use your site name.</span>
					</p>
				</div>
			<?php endif; ?>

			<?php submit_button( $connected ? 'Save settings' : 'Connect to Square' ); ?>
		</form>
		<?php
	}

	private static function render_location( array $settings ) {
		$locations = DZSQ_API::locations();
		if ( is_wp_error( $locations ) || ! $locations ) {
			echo '<p class="dzsq-err">Could not load your Square locations right now.</p>';
			echo '<input type="hidden" name="dzsq_location" value="' . esc_attr( $settings['location_id'] ) . '">';
			return;
		}
		?>
		<p>
			<label for="dzsq_location"><strong>Sell stock from this Square location</strong></label><br>
			<select id="dzsq_location" name="dzsq_location">
				<?php if ( '' === (string) $settings['location_id'] ) : ?>
					<option value="">Choose a location&hellip;</option>
				<?php endif; ?>
				<?php foreach ( $locations as $id => $loc ) : ?>
					<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $settings['location_id'], $id ); ?>>
						<?php echo esc_html( $loc['name'] . ( $loc['address'] ? ' — ' . $loc['address'] : '' ) . ( 'ACTIVE' !== $loc['status'] ? ' (inactive)' : '' ) ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>
		<?php
	}

	private static function render_tree( array $settings ) {
		$cats = DZSQ_Catalog::fetch_categories( true );
		if ( is_wp_error( $cats ) ) {
			echo '<p class="dzsq-err">Could not load your Square categories: ' . esc_html( $cats->get_error_message() ) . '</p>';
			return;
		}
		$children = array();
		foreach ( $cats as $id => $c ) {
			$parent                = ( $c['parent'] && isset( $cats[ $c['parent'] ] ) ) ? $c['parent'] : '';
			$children[ $parent ][] = $id;
		}
		echo '<input type="hidden" name="dzsq_categories_shown" value="1">';
		echo '<div style="max-height:460px;overflow:auto;border:1px solid #dcdcde;padding:10px 12px">';
		self::render_branch( '', $children, $cats, array_flip( (array) $settings['include_categories'] ), array_flip( (array) $settings['exclude_categories'] ), 0 );
		echo '</div>';
	}

	private static function render_branch( $parent, array $children, array $cats, array $include, array $exclude, $depth ) {
		if ( empty( $children[ $parent ] ) || $depth > 8 ) {
			return;
		}
		echo '<ul class="' . ( 0 === $depth ? 'dzsq-tree' : '' ) . '">';
		foreach ( $children[ $parent ] as $id ) {
			echo '<li><span class="dzsq-name">' . esc_html( $cats[ $id ]['name'] ) . '</span>';
			echo '<label><input type="checkbox" name="dzsq_include[]" value="' . esc_attr( $id ) . '" ' . checked( isset( $include[ $id ] ), true, false ) . '> Sync</label>';
			echo '<label><input type="checkbox" name="dzsq_exclude[]" value="' . esc_attr( $id ) . '" ' . checked( isset( $exclude[ $id ] ), true, false ) . '> Skip</label>';
			self::render_branch( $id, $children, $cats, $include, $exclude, $depth + 1 );
			echo '</li>';
		}
		echo '</ul>';
	}

	private static function render_tools() {
		$hook_on = '' !== (string) get_option( 'dzsq_webhook_id', '' );
		?>
		<div class="dzsq-card dzsq-tools">
			<h2>Tools</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="dzsq_sync"><?php wp_nonce_field( 'dzsq_sync' ); ?>
				<button class="button button-primary">Sync now</button>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="dzsq_reconcile"><?php wp_nonce_field( 'dzsq_reconcile' ); ?>
				<button class="button">Check all stock now</button>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="dzsq_webhook"><?php wp_nonce_field( 'dzsq_webhook' ); ?>
				<button class="button"><?php echo $hook_on ? 'Reconnect instant updates' : 'Turn on instant updates'; ?></button>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="dzsq_push"><?php wp_nonce_field( 'dzsq_push' ); ?>
				<label for="dzsq_order">Send website order #</label>
				<input type="number" id="dzsq_order" name="dzsq_order" min="1" style="width:90px">
				<button class="button">Send to Square</button>
			</form>
			<?php
			if ( class_exists( 'DZSQ_Tools' ) ) {
				DZSQ_Tools::render();
			}
			?>
		</div>
		<?php
	}

	private static function render_log() {
		$entries = DZSQ_Log::entries( 100 );
		?>
		<div class="dzsq-card">
			<h2>Activity</h2>
			<?php if ( ! $entries ) : ?>
				<p>Nothing yet.</p>
			<?php else : ?>
				<table class="widefat striped dzsq-log">
					<thead><tr><th style="width:200px">When</th><th>What happened</th></tr></thead>
					<tbody>
					<?php foreach ( $entries as $e ) : ?>
						<tr>
							<td><?php echo esc_html( wp_date( 'M j, g:i a', (int) $e['t'] ) ); ?></td>
							<td class="<?php echo 'error' === $e['l'] ? 'dzsq-err' : ''; ?>"><?php echo esc_html( $e['m'] ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}
