<?php
/**
 * Creation Nation Square Sync: record paid website orders in Square.
 *
 * Each paid WooCommerce order becomes a Square order (catalog items are linked
 * so Square's item and category reports include them and Square lowers its
 * stock) paid with an "external" payment, since the customer already paid
 * through the website. Cancelled or refunded orders are reversed in Square.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DZSQ_Orders {

	const MAX_ATTEMPTS = 6;

	public static function init() {
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'queue_push' ), 20, 1 );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'queue_push' ), 20, 1 );
		add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'queue_reverse' ), 20, 1 );
		add_action( 'woocommerce_order_status_refunded', array( __CLASS__, 'queue_reverse' ), 20, 1 );
		add_action( 'dzsq_push_order', array( __CLASS__, 'push' ), 10, 1 );
		add_action( 'dzsq_reverse_order', array( __CLASS__, 'reverse' ), 10, 1 );
		// Stock-only mode (used with Square payments): lower Square stock, don't create a second sale.
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'queue_stock' ), 21, 1 );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'queue_stock' ), 21, 1 );
		add_action( 'dzsq_stock_sale', array( __CLASS__, 'stock_sale' ), 10, 1 );
	}

	public static function queue_stock( $order_id ) {
		if ( DZSQ_Settings::get( 'push_orders' ) || ! DZSQ_Settings::get( 'stock_on_sale' ) || '' === DZSQ_Settings::token() || '' === (string) DZSQ_Settings::get( 'location_id' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_meta( '_dzsq_stock_done' ) ) {
			return;
		}
		$args = array( 'order_id' => (int) $order_id );
		if ( ! as_has_scheduled_action( 'dzsq_stock_sale', $args, DZSQ_GROUP ) ) {
			as_enqueue_async_action( 'dzsq_stock_sale', $args, DZSQ_GROUP );
		}
	}

	/** Lower Square stock for a paid website order (the payment itself is already in Square). */
	public static function stock_sale( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_meta( '_dzsq_stock_done' ) || ! in_array( $order->get_status(), array( 'processing', 'completed' ), true ) ) {
			return;
		}
		$location = (string) DZSQ_Settings::get( 'location_id' );
		$now      = gmdate( 'Y-m-d\TH:i:s\Z' );
		$changes  = array();
		$names    = array();
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$product = $item->get_product();
			$vid     = $product ? (string) $product->get_meta( '_dzsq_variation_id' ) : '';
			$qty     = (int) $item->get_quantity();
			if ( '' === $vid || $qty <= 0 ) {
				continue;
			}
			$changes[] = array(
				'type'       => 'ADJUSTMENT',
				'adjustment' => array(
					'from_state'        => 'IN_STOCK',
					'to_state'          => 'SOLD',
					'location_id'       => $location,
					'catalog_object_id' => $vid,
					'quantity'          => (string) $qty,
					'occurred_at'       => $now,
				),
			);
			$names[]   = $qty . ' x ' . $item->get_name();
		}
		if ( ! $changes ) {
			$order->update_meta_data( '_dzsq_stock_done', 'none' );
			$order->save();
			return;
		}
		$r = DZSQ_API::request(
			'POST',
			'/v2/inventory/changes/batch-create',
			array(
				'idempotency_key'         => 'wc-' . DZSQ_Settings::site_key() . '-sold-' . $order->get_id(),
				'changes'                 => $changes,
				'ignore_unchanged_counts' => true,
			)
		);
		if ( is_wp_error( $r ) ) {
			return self::retry( $order, 'Square stock could not be lowered: ' . $r->get_error_message(), 'dzsq_stock_sale' );
		}
		$order->update_meta_data( '_dzsq_stock_done', 1 );
		$order->delete_meta_data( '_dzsq_attempts' );
		$order->save();
		$order->add_order_note( 'Square stock lowered: ' . implode( ', ', $names ) . '.' );
		DZSQ_Log::info( 'Website order #' . $order->get_order_number() . ': Square stock lowered.' );
	}

	public static function queue_push( $order_id ) {
		if ( ! DZSQ_Settings::get( 'push_orders' ) || '' === DZSQ_Settings::token() || '' === (string) DZSQ_Settings::get( 'location_id' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_meta( '_dzsq_square_payment_id' ) ) {
			return;
		}
		$args = array( 'order_id' => (int) $order_id );
		if ( ! as_has_scheduled_action( 'dzsq_push_order', $args, DZSQ_GROUP ) ) {
			as_enqueue_async_action( 'dzsq_push_order', $args, DZSQ_GROUP );
		}
	}

	public static function queue_reverse( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || ( ! $order->get_meta( '_dzsq_square_order_id' ) && 1 !== (int) $order->get_meta( '_dzsq_stock_done' ) ) || $order->get_meta( '_dzsq_reversed' ) ) {
			return;
		}
		$args = array( 'order_id' => (int) $order_id );
		if ( ! as_has_scheduled_action( 'dzsq_reverse_order', $args, DZSQ_GROUP ) ) {
			as_enqueue_async_action( 'dzsq_reverse_order', $args, DZSQ_GROUP );
		}
	}

	/* ------------------------------------------------------------------ */

	public static function push( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_meta( '_dzsq_square_payment_id' ) ) {
			return;
		}
		if ( ! in_array( $order->get_status(), array( 'processing', 'completed' ), true ) ) {
			return;
		}
		$location = (string) DZSQ_Settings::get( 'location_id' );
		$currency = $order->get_currency();
		$number   = (string) $order->get_order_number();

		// Step 1: the Square order.
		$square_order_id = (string) $order->get_meta( '_dzsq_square_order_id' );
		if ( '' === $square_order_id ) {
			$payload = self::build_order( $order, $location, $currency, $number );
			if ( ! $payload['line_items'] ) {
				return;
			}
			$r = DZSQ_API::request(
				'POST',
				'/v2/orders',
				array(
					'idempotency_key' => 'wc-' . DZSQ_Settings::site_key() . '-order-' . $order->get_id(),
					'order'           => $payload,
				)
			);
			if ( is_wp_error( $r ) ) {
				return self::retry( $order, 'Could not create the Square order: ' . $r->get_error_message() );
			}
			$square_order_id = (string) ( $r['order']['id'] ?? '' );
			$total           = (int) ( $r['order']['total_money']['amount'] ?? 0 );
			$order->update_meta_data( '_dzsq_square_order_id', $square_order_id );
			$order->update_meta_data( '_dzsq_square_total', $total );
			$order->save();
		}

		// Step 2: pay it as an external (website) payment.
		$total = (int) $order->get_meta( '_dzsq_square_total' );
		if ( $total <= 0 ) {
			$order->update_meta_data( '_dzsq_square_payment_id', 'none' );
			$order->save();
			$order->add_order_note( 'Recorded in Square (order ' . $square_order_id . '). No payment was needed because the total was $0.' );
			return;
		}
		$method = $order->get_payment_method_title() ? $order->get_payment_method_title() : 'website checkout';
		$r      = DZSQ_API::request(
			'POST',
			'/v2/payments',
			array(
				'idempotency_key'  => 'wc-' . DZSQ_Settings::site_key() . '-pay-' . $order->get_id(),
				'source_id'        => 'EXTERNAL',
				'external_details' => array(
					'type'   => 'CARD',
					'source' => substr( DZSQ_Settings::site_name() . ' website: ' . $method, 0, 255 ),
				),
				'amount_money'     => array(
					'amount'   => $total,
					'currency' => $currency,
				),
				'order_id'         => $square_order_id,
				'location_id'      => $location,
				'reference_id'     => substr( 'Web order #' . $number, 0, 40 ),
				'note'             => substr( DZSQ_Settings::site_name() . ' website order #' . $number, 0, 500 ),
			)
		);
		if ( is_wp_error( $r ) ) {
			return self::retry( $order, 'Square order ' . $square_order_id . ' was created but could not be marked paid: ' . $r->get_error_message() );
		}

		$payment_id = (string) ( $r['payment']['id'] ?? '' );
		$order->update_meta_data( '_dzsq_square_payment_id', $payment_id );
		$order->delete_meta_data( '_dzsq_attempts' );
		$order->save();

		$note = sprintf( 'Recorded in Square as a paid order (%s) for %s.', $square_order_id, wc_price( $total / 100, array( 'currency' => $currency ) ) );
		$woo  = DZSQ_API::cents( $order->get_total() );
		if ( $woo !== $total ) {
			$note .= sprintf( ' Note: the website total was %s, so the Square total differs by %s (usually tax or rounding).', wc_price( $woo / 100, array( 'currency' => $currency ) ), wc_price( abs( $woo - $total ) / 100, array( 'currency' => $currency ) ) );
		}
		$order->add_order_note( wp_strip_all_tags( $note ) );
		DZSQ_Log::info( 'Website order #' . $number . ' recorded in Square.' );
	}

	private static function build_order( WC_Order $order, $location, $currency, $number ) {
		$lines = array();
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$qty = (int) $item->get_quantity();
			if ( $qty <= 0 ) {
				continue;
			}
			$product = $item->get_product();
			$vid     = $product ? (string) $product->get_meta( '_dzsq_variation_id' ) : '';
			if ( '' !== $vid ) {
				$lines[] = array(
					'catalog_object_id' => $vid,
					'quantity'          => (string) $qty,
				);
			} else {
				// Items that only exist on the website (e.g. used stock) go in as custom lines.
				$lines[] = array(
					'name'             => substr( $item->get_name(), 0, 500 ),
					'quantity'         => (string) $qty,
					'base_price_money' => array(
						'amount'   => DZSQ_API::cents( (float) $item->get_subtotal() / $qty ),
						'currency' => $currency,
					),
				);
			}
		}

		$payload = array(
			'location_id'     => $location,
			'reference_id'    => substr( $number, 0, 40 ),
			'source'          => array( 'name' => DZSQ_Settings::site_name() . ' website' ),
			'line_items'      => $lines,
			// Record what the customer was actually charged, not Square's own tax rules.
			'pricing_options' => array( 'auto_apply_taxes' => false ),
		);

		$discount = (float) $order->get_discount_total();
		if ( $discount > 0 ) {
			$payload['discounts'] = array(
				array(
					'uid'          => 'website-discount',
					'name'         => 'Website discount',
					'type'         => 'FIXED_AMOUNT',
					'amount_money' => array(
						'amount'   => DZSQ_API::cents( $discount ),
						'currency' => $currency,
					),
					'scope'        => 'ORDER',
				),
			);
		}

		$charges  = array();
		$shipping = (float) $order->get_shipping_total();
		if ( $shipping > 0 ) {
			$charges[] = array(
				'uid'               => 'website-shipping',
				'name'              => 'Shipping',
				'amount_money'      => array(
					'amount'   => DZSQ_API::cents( $shipping ),
					'currency' => $currency,
				),
				'calculation_phase' => 'TOTAL_PHASE',
			);
		}
		// Sales tax the website collected, recorded as a fixed charge so totals match exactly.
		$tax = (float) $order->get_total_tax();
		if ( $tax > 0 ) {
			$charges[] = array(
				'uid'               => 'website-sales-tax',
				'name'              => 'Sales tax (collected online)',
				'amount_money'      => array(
					'amount'   => DZSQ_API::cents( $tax ),
					'currency' => $currency,
				),
				'calculation_phase' => 'TOTAL_PHASE',
			);
		}
		if ( $charges ) {
			$payload['service_charges'] = $charges;
		}
		return $payload;
	}

	private static function retry( WC_Order $order, $message, $hook = 'dzsq_push_order' ) {
		$attempts = (int) $order->get_meta( '_dzsq_attempts' ) + 1;
		$order->update_meta_data( '_dzsq_attempts', $attempts );
		$order->save();
		DZSQ_Log::error( 'Website order #' . $order->get_order_number() . ': ' . $message );
		if ( $attempts < self::MAX_ATTEMPTS ) {
			as_schedule_single_action( time() + 300 * $attempts, $hook, array( 'order_id' => (int) $order->get_id() ), DZSQ_GROUP );
			$order->add_order_note( $message . ' Will try again automatically.' );
		} else {
			$order->add_order_note( $message . ' Gave up after several tries. Record this sale in Square by hand, or use "Send to Square" on the Square Sync page.' );
		}
	}

	/* ------------------------------------------------------------------ */

	public static function reverse( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_meta( '_dzsq_reversed' ) ) {
			return;
		}
		$location   = (string) DZSQ_Settings::get( 'location_id' );
		$payment_id = (string) $order->get_meta( '_dzsq_square_payment_id' );
		$total      = (int) $order->get_meta( '_dzsq_square_total' );
		$notes      = array();

		if ( $payment_id && 'none' !== $payment_id && $total > 0 ) {
			$r = DZSQ_API::request(
				'POST',
				'/v2/refunds',
				array(
					'idempotency_key' => 'wc-' . DZSQ_Settings::site_key() . '-refund-' . $order->get_id(),
					'payment_id'      => $payment_id,
					'amount_money'    => array(
						'amount'   => $total,
						'currency' => $order->get_currency(),
					),
					'reason'          => 'Website order #' . $order->get_order_number() . ' ' . $order->get_status(),
				)
			);
			if ( is_wp_error( $r ) ) {
				$notes[] = 'Square could not record the refund automatically (' . $r->get_error_message() . '). Record the refund in Square by hand.';
			} else {
				$notes[] = 'Refund recorded in Square.';
			}
		}

		// Put the items back in Square's stock.
		$changes = array();
		$now     = gmdate( 'Y-m-d\TH:i:s\Z' );
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$product = $item->get_product();
			$vid     = $product ? (string) $product->get_meta( '_dzsq_variation_id' ) : '';
			if ( '' === $vid || (int) $item->get_quantity() <= 0 ) {
				continue;
			}
			$changes[] = array(
				'type'       => 'ADJUSTMENT',
				'adjustment' => array(
					// Square doesn't allow SOLD -> IN_STOCK; returned items are recorded as stock received.
					'from_state'        => 'NONE',
					'to_state'          => 'IN_STOCK',
					'location_id'       => $location,
					'catalog_object_id' => $vid,
					'quantity'          => (string) (int) $item->get_quantity(),
					'occurred_at'       => $now,
				),
			);
		}
		if ( $changes ) {
			$r = DZSQ_API::request(
				'POST',
				'/v2/inventory/changes/batch-create',
				array(
					'idempotency_key'       => 'wc-' . DZSQ_Settings::site_key() . '-restock2-' . $order->get_id(),
					'changes'               => $changes,
					'ignore_unchanged_counts' => true,
				)
			);
			$notes[] = is_wp_error( $r ) ? 'Square stock could not be put back automatically (' . $r->get_error_message() . ').' : 'Items put back into Square stock.';
		}

		$order->update_meta_data( '_dzsq_reversed', 1 );
		$order->save();
		if ( $notes ) {
			$order->add_order_note( implode( ' ', $notes ) );
			DZSQ_Log::info( 'Website order #' . $order->get_order_number() . ' reversed in Square: ' . implode( ' ', $notes ) );
		}
	}
}
