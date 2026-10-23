<?php
/**
 * Manual "Sync with Square" for single products: a box on the product edit page,
 * a row link in the Products list, and a bulk action. Pulls just that item from
 * Square right away (details, price, variations, stock, photos).
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DZSQ_Manual {

	const BULK_LIMIT = 25;

	public static function init() {
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'add_meta_boxes_product', array( __CLASS__, 'meta_box' ) );
		add_action( 'admin_post_dzsq_sync_one', array( __CLASS__, 'handle_one' ) );
		add_action( 'admin_post_dzsq_hide_one', array( __CLASS__, 'handle_hide' ) );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_action' ), 10, 2 );
		add_filter( 'bulk_actions-edit-product', array( __CLASS__, 'bulk_option' ) );
		add_filter( 'handle_bulk_actions-edit-product', array( __CLASS__, 'handle_bulk' ), 10, 3 );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
	}

	/* ---------- The sync itself ---------- */

	/**
	 * Sync one website product from its Square item.
	 *
	 * @return array { status: ok|gone|unlinked|error, message: string }
	 */
	public static function sync_one( $pid ) {
		$pid     = (int) $pid;
		$item_id = (string) get_post_meta( $pid, '_dzsq_item_id', true );
		if ( '' === $item_id ) {
			return array( 'status' => 'unlinked', 'message' => 'This product is not linked to a Square item, so there is nothing to sync.' );
		}
		if ( ! DZSQ_Settings::token() ) {
			return array( 'status' => 'error', 'message' => 'Square is not connected (no access token saved).' );
		}

		$before = self::snapshot( $pid );

		$r = DZSQ_API::request(
			'POST',
			'/v2/catalog/batch-retrieve',
			array(
				'object_ids'              => array( $item_id ),
				'include_related_objects' => true,
			)
		);
		if ( is_wp_error( $r ) ) {
			return array( 'status' => 'error', 'message' => 'Could not reach Square: ' . $r->get_error_message() );
		}

		$item = null;
		foreach ( $r['objects'] ?? array() as $o ) {
			if ( 'ITEM' === ( $o['type'] ?? '' ) && $item_id === ( $o['id'] ?? '' ) ) {
				$item = $o;
			}
		}
		if ( ! $item || ! empty( $item['is_deleted'] ) ) {
			return array( 'status' => 'gone', 'message' => 'This item no longer exists in Square.' );
		}
		if ( ! empty( $item['item_data']['is_archived'] ) ) {
			return array( 'status' => 'gone', 'message' => 'This item is archived in Square.' );
		}

		$cats = DZSQ_Catalog::fetch_categories( true );
		if ( is_wp_error( $cats ) ) {
			return array( 'status' => 'error', 'message' => 'Could not load Square categories: ' . $cats->get_error_message() );
		}
		list( $allowed, $excluded ) = DZSQ_Catalog::category_sets( $cats );

		$should = new ReflectionMethod( 'DZSQ_Catalog', 'should_sync' );
		$should->setAccessible( true );
		if ( ! $should->invoke( null, $item, $allowed, $excluded ) ) {
			return array( 'status' => 'gone', 'message' => 'In Square, this item is not in a category that syncs to this website (it may be uncategorized, a price-point item, or in an excluded category).' );
		}

		$images = array();
		foreach ( $r['related_objects'] ?? array() as $o ) {
			if ( 'IMAGE' === ( $o['type'] ?? '' ) && ! empty( $o['image_data']['url'] ) ) {
				$images[ $o['id'] ] = $o['image_data']['url'];
			}
		}

		$terms = new ReflectionMethod( 'DZSQ_Catalog', 'sync_terms' );
		$terms->setAccessible( true );
		$term_map = $terms->invoke( null, $cats, $allowed );

		DZSQ_Catalog::$syncing = true;
		$result                = DZSQ_Catalog::upsert_product( $item, $images, is_array( $term_map ) ? $term_map : array() );
		DZSQ_Catalog::$syncing = false;
		if ( is_wp_error( $result ) ) {
			return array( 'status' => 'error', 'message' => 'Square sync failed: ' . $result->get_error_message() );
		}

		$vids = array();
		foreach ( $item['item_data']['variations'] ?? array() as $v ) {
			if ( empty( $v['is_deleted'] ) ) {
				$vids[] = $v['id'];
			}
		}
		if ( $vids ) {
			DZSQ_Inventory::reconcile_ids( $vids );
		}

		update_post_meta( $pid, '_dzsq_manual_synced', time() );
		clean_post_cache( $pid );
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $pid );
		}

		$changes = self::diff( $before, self::snapshot( $pid ) );
		return array(
			'status'  => 'ok',
			'message' => $changes ? 'Synced with Square: ' . implode( '; ', $changes ) . '.' : 'Synced with Square: already up to date.',
		);
	}

	private static function snapshot( $pid ) {
		clean_post_cache( $pid );
		$p = wc_get_product( $pid );
		if ( ! $p ) {
			return array();
		}
		$stock = null;
		if ( $p->is_type( 'variable' ) ) {
			$min   = $p->get_variation_price( 'min' );
			$max   = $p->get_variation_price( 'max' );
			$price = ( '' === $min ) ? '' : ( $min === $max ? wc_format_decimal( $min, 2 ) : wc_format_decimal( $min, 2 ) . '-' . wc_format_decimal( $max, 2 ) );
			$stock = 0;
			foreach ( $p->get_children() as $cid ) {
				$c = wc_get_product( $cid );
				if ( $c && $c->managing_stock() ) {
					$stock += (int) $c->get_stock_quantity();
				}
			}
		} else {
			$price = '' === $p->get_price() ? '' : wc_format_decimal( $p->get_price(), 2 );
			$stock = $p->managing_stock() ? (int) $p->get_stock_quantity() : null;
		}
		return array(
			'name'   => $p->get_name(),
			'price'  => (string) $price,
			'stock'  => $stock,
			'status' => $p->get_status(),
			'thumb'  => (int) $p->get_image_id(),
		);
	}

	private static function diff( array $a, array $b ) {
		$out = array();
		if ( ! $a || ! $b ) {
			return $out;
		}
		if ( $a['name'] !== $b['name'] ) {
			$out[] = 'name updated';
		}
		if ( $a['price'] !== $b['price'] ) {
			$out[] = 'price $' . ( '' === $a['price'] ? '0' : $a['price'] ) . ' → $' . ( '' === $b['price'] ? '0' : $b['price'] );
		}
		if ( $a['stock'] !== $b['stock'] && null !== $b['stock'] ) {
			$out[] = 'stock ' . ( null === $a['stock'] ? '—' : $a['stock'] ) . ' → ' . $b['stock'];
		}
		if ( $a['status'] !== $b['status'] ) {
			$out[] = 'publish' === $b['status'] ? 'now published' : 'now hidden';
		}
		if ( $a['thumb'] !== $b['thumb'] ) {
			$out[] = 'photo updated';
		}
		return $out;
	}

	/* ---------- Product edit page box ---------- */

	public static function meta_box() {
		add_meta_box( 'dzsq-manual', 'Square', array( __CLASS__, 'render_box' ), 'product', 'side', 'high' );
	}

	public static function render_box( $post ) {
		$item_id = get_post_meta( $post->ID, '_dzsq_item_id', true );
		if ( ! $item_id ) {
			echo '<p>Not linked to Square.</p>';
			return;
		}
		$last = (int) get_post_meta( $post->ID, '_dzsq_manual_synced', true );
		echo '<p>Linked to Square.';
		if ( $last ) {
			echo '<br>Last manual sync: ' . esc_html( human_time_diff( $last ) ) . ' ago.';
		}
		if ( get_post_meta( $post->ID, '_dzsq_keep_hidden', true ) ) {
			echo '<br><em>Kept hidden on website.</em>';
		}
		echo '</p>';
		echo '<p><a class="button" href="' . esc_url( self::sync_url( $post->ID ) ) . '">Sync now</a></p>';
		echo '<p class="description">Pulls this item from Square right away. Save any unsaved edits first.</p>';
	}

	private static function sync_url( $pid ) {
		return wp_nonce_url( admin_url( 'admin-post.php?action=dzsq_sync_one&product_id=' . (int) $pid ), 'dzsq_sync_one_' . (int) $pid );
	}

	private static function hide_url( $pid ) {
		return wp_nonce_url( admin_url( 'admin-post.php?action=dzsq_hide_one&product_id=' . (int) $pid ), 'dzsq_hide_one_' . (int) $pid );
	}

	/* ---------- Handlers ---------- */

	public static function handle_one() {
		$pid = isset( $_GET['product_id'] ) ? absint( $_GET['product_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		check_admin_referer( 'dzsq_sync_one_' . $pid );
		if ( ! $pid || ! current_user_can( 'edit_product', $pid ) ) {
			wp_die( 'You are not allowed to sync this product.' );
		}
		$codes   = array();
		$item_id = (string) get_post_meta( $pid, '_dzsq_item_id', true );
		if ( '' !== $item_id && class_exists( 'DZSQ_Tools' ) ) {
			$codes = DZSQ_Tools::assign_missing_skus( $item_id ); // No SKU yet: give it the next code in Square first (only when a SKU prefix is set).
		}
		$res = self::sync_one( $pid );
		if ( $codes && 'ok' === $res['status'] ) {
			$res['message'] .= ' New SKU: ' . implode( ', ', $codes ) . '.';
		}
		if ( 'ok' === $res['status'] && class_exists( 'DZSQ_Tools' ) ) {
			DZSQ_Tools::photos_now( $pid ); // Single product: show its photos right away, not a minute later.
		}
		$msg = esc_html( get_the_title( $pid ) ) . ': ' . esc_html( $res['message'] );
		if ( 'gone' === $res['status'] && 'publish' === get_post_status( $pid ) ) {
			$msg .= ' <a href="' . esc_url( self::hide_url( $pid ) ) . '">Hide this product</a>';
		}
		self::set_notice( 'ok' === $res['status'] ? 'success' : ( 'error' === $res['status'] ? 'error' : 'warning' ), $msg );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : get_edit_post_link( $pid, 'url' ) );
		exit;
	}

	public static function handle_hide() {
		$pid = isset( $_GET['product_id'] ) ? absint( $_GET['product_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		check_admin_referer( 'dzsq_hide_one_' . $pid );
		if ( ! $pid || ! current_user_can( 'edit_product', $pid ) ) {
			wp_die( 'You are not allowed to change this product.' );
		}
		// Same as the sync's own housekeeping: hidden, but it comes back if the item returns to a synced category.
		DZSQ_Catalog::$syncing = true;
		wp_update_post( array( 'ID' => $pid, 'post_status' => 'draft' ) );
		DZSQ_Catalog::$syncing = false;
		self::set_notice( 'success', esc_html( get_the_title( $pid ) ) . ': hidden from the website.' );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'edit.php?post_type=product' ) );
		exit;
	}

	/* ---------- Products list ---------- */

	public static function row_action( $actions, $post ) {
		if ( 'product' === $post->post_type && get_post_meta( $post->ID, '_dzsq_item_id', true ) && current_user_can( 'edit_product', $post->ID ) ) {
			$actions['dzsq_sync'] = '<a href="' . esc_url( self::sync_url( $post->ID ) ) . '">Sync with Square</a>';
		}
		return $actions;
	}

	public static function bulk_option( $actions ) {
		$actions['dzsq_bulk_sync'] = 'Sync with Square';
		return $actions;
	}

	public static function handle_bulk( $redirect, $action, $ids ) {
		if ( 'dzsq_bulk_sync' !== $action ) {
			return $redirect;
		}
		@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$ids    = array_slice( array_map( 'absint', (array) $ids ), 0, self::BULK_LIMIT );
		$counts = array( 'ok' => 0, 'gone' => 0, 'unlinked' => 0, 'error' => 0 );
		$notes  = array();
		foreach ( $ids as $pid ) {
			if ( ! current_user_can( 'edit_product', $pid ) ) {
				continue;
			}
			$res = self::sync_one( $pid );
			$counts[ $res['status'] ]++;
			if ( 'ok' !== $res['status'] && count( $notes ) < 10 ) {
				$notes[] = esc_html( get_the_title( $pid ) ) . ': ' . esc_html( $res['message'] );
			}
		}
		$msg = sprintf( 'Square sync: %d synced', $counts['ok'] );
		if ( $counts['gone'] ) {
			$msg .= sprintf( ', %d no longer belong on the website', $counts['gone'] );
		}
		if ( $counts['unlinked'] ) {
			$msg .= sprintf( ', %d not linked to Square', $counts['unlinked'] );
		}
		if ( $counts['error'] ) {
			$msg .= sprintf( ', %d failed', $counts['error'] );
		}
		$msg .= '.';
		if ( count( (array) $ids ) >= self::BULK_LIMIT ) {
			$msg .= ' (Up to ' . self::BULK_LIMIT . ' products are synced per click.)';
		}
		if ( $notes ) {
			$msg .= '<br>' . implode( '<br>', $notes );
		}
		self::set_notice( $counts['error'] ? 'warning' : 'success', $msg );
		return $redirect;
	}

	/* ---------- Notices ---------- */

	private static function set_notice( $type, $html ) {
		set_transient( 'dzsq_manual_notice_' . get_current_user_id(), array( 'type' => $type, 'html' => $html ), 120 );
	}

	public static function notices() {
		$key = 'dzsq_manual_notice_' . get_current_user_id();
		$n   = get_transient( $key );
		if ( ! $n ) {
			return;
		}
		delete_transient( $key );
		echo '<div class="notice notice-' . esc_attr( $n['type'] ) . ' is-dismissible"><p>' . wp_kses( $n['html'], array( 'a' => array( 'href' => array() ), 'br' => array(), 'em' => array() ) ) . '</p></div>';
	}
}
