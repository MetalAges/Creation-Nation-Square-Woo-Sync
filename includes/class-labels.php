<?php
/**
 * Barcode labels: a Print barcode labels screen reached from a box on the
 * product edit page, a row link in the Products list, and a bulk action.
 * Prints through the browser on Dymo 30336 labels (1 in x 2-1/8 in): a Code 128
 * barcode of the SKU (or the UPC when there is no SKU), the name, the shop name,
 * the code and the price.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DZSQ_Labels {

	const PAGE      = 'dzsq-labels';
	const OPTION    = 'dzsq_label_offset';
	const JSBARCODE = 'https://cdnjs.cloudflare.com/ajax/libs/jsbarcode/3.12.3/JsBarcode.all.min.js';
	const MAX       = 200;

	public static function init() {
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 61 );
		add_action( 'add_meta_boxes_product', array( __CLASS__, 'meta_box' ) );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_action' ), 11, 2 );
		add_filter( 'bulk_actions-edit-product', array( __CLASS__, 'bulk_option' ) );
		add_filter( 'handle_bulk_actions-edit-product', array( __CLASS__, 'handle_bulk' ), 10, 3 );
		add_action( 'admin_post_dzsq_label_offset', array( __CLASS__, 'handle_offset' ) );
	}

	/** Hidden screen (no menu entry): admin.php?page=dzsq-labels&ids=1,2,3 */
	public static function menu() {
		add_submenu_page( '', 'Print barcode labels', 'Print barcode labels', 'edit_products', self::PAGE, array( __CLASS__, 'render' ) );
	}

	public static function url( array $ids ) {
		return admin_url( 'admin.php?page=' . self::PAGE . '&ids=' . implode( ',', array_map( 'absint', $ids ) ) );
	}

	/* ---------- Entry points ---------- */

	public static function meta_box() {
		add_meta_box( 'dzsq-labels', 'Barcode label', array( __CLASS__, 'render_box' ), 'product', 'side', 'high' );
	}

	public static function render_box( $post ) {
		$product = wc_get_product( $post->ID );
		if ( ! $product ) {
			return;
		}
		if ( $product->is_type( 'variable' ) ) {
			echo '<p>Prints a label for each variation.</p>';
		} else {
			$code = self::code( $product );
			echo '<p>' . ( '' !== $code ? 'Barcode: <code>' . esc_html( $code ) . '</code>' : '<em>No SKU or UPC yet, so there is nothing to put in a barcode.</em>' ) . '</p>';
		}
		echo '<p><a class="button" href="' . esc_url( self::url( array( $post->ID ) ) ) . '">Print barcode label</a></p>';
	}

	public static function row_action( $actions, $post ) {
		if ( 'product' === $post->post_type && current_user_can( 'edit_product', $post->ID ) ) {
			$actions['dzsq_label'] = '<a href="' . esc_url( self::url( array( $post->ID ) ) ) . '">Print label</a>';
		}
		return $actions;
	}

	public static function bulk_option( $actions ) {
		$actions['dzsq_bulk_labels'] = 'Print barcode labels';
		return $actions;
	}

	public static function handle_bulk( $redirect, $action, $ids ) {
		if ( 'dzsq_bulk_labels' !== $action ) {
			return $redirect;
		}
		return self::url( (array) $ids );
	}

	public static function handle_offset() {
		check_admin_referer( 'dzsq_label_offset' );
		if ( ! current_user_can( 'edit_products' ) ) {
			wp_die( 'You are not allowed to change this.' );
		}
		$clamp = function ( $v ) {
			return round( max( -0.5, min( 0.5, (float) $v ) ), 3 );
		};
		update_option(
			self::OPTION,
			array(
				'x' => $clamp( $_POST['x'] ?? 0 ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				'y' => $clamp( $_POST['y'] ?? 0 ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			),
			false
		);
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'edit.php?post_type=product' ) );
		exit;
	}

	/* ---------- Label data ---------- */

	/** What the barcode encodes: the SKU, or the UPC when there is no SKU. */
	public static function code( $product ) {
		$sku = trim( (string) $product->get_sku( 'edit' ) );
		if ( '' !== $sku ) {
			return $sku;
		}
		$upc = (string) $product->get_meta( '_dzsq_upc' );
		if ( '' === $upc && method_exists( $product, 'get_global_unique_id' ) ) {
			$upc = (string) $product->get_global_unique_id( 'edit' );
		}
		return trim( $upc );
	}

	/** One row per sellable thing: simple products as-is, variable products expanded. */
	private static function rows( array $ids ) {
		$out = array();
		foreach ( array_slice( array_unique( array_filter( array_map( 'absint', $ids ) ) ), 0, self::MAX ) as $id ) {
			$p = wc_get_product( $id );
			if ( ! $p || ! current_user_can( 'edit_product', $p->get_parent_id() ? $p->get_parent_id() : $id ) ) {
				continue;
			}
			$list = $p->is_type( 'variable' ) ? array_filter( array_map( 'wc_get_product', $p->get_children() ) ) : array( $p );
			foreach ( $list as $item ) {
				$price = $item->get_price( 'edit' );
				$out[] = array(
					'id'    => $item->get_id(),
					'name'  => html_entity_decode( wp_strip_all_tags( $item->get_name() ), ENT_QUOTES, 'UTF-8' ),
					'code'  => self::code( $item ),
					'price' => '' === (string) $price ? '' : '$' . number_format( (float) $price, 2 ),
					'stock' => $item->managing_stock() ? (int) $item->get_stock_quantity() : null,
				);
			}
		}
		return $out;
	}

	/* ---------- Screen ---------- */

	public static function render() {
		if ( ! current_user_can( 'edit_products' ) ) {
			wp_die( 'You are not allowed to print labels.' );
		}
		$ids    = isset( $_GET['ids'] ) ? explode( ',', sanitize_text_field( wp_unslash( $_GET['ids'] ) ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification
		$rows   = self::rows( $ids );
		$offset = wp_parse_args( (array) get_option( self::OPTION, array() ), array( 'x' => 0.125, 'y' => 0 ) );
		wp_enqueue_script( 'dzsq-jsbarcode', self::JSBARCODE, array(), '3.12.3', true );
		?>
		<div class="wrap">
			<h1>Print barcode labels</h1>
			<p>Dymo 30336 labels (1&Prime; &times; 2-1/8&Prime;). Set how many of each, then print. In the print dialog pick the Dymo printer and the 30336 label size.</p>
			<div id="dzsq-labels-app"></div>
			<details style="margin-top:24px;max-width:760px">
				<summary>Printer calibration</summary>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="dzsq_label_offset">
					<?php wp_nonce_field( 'dzsq_label_offset' ); ?>
					<p>If the print lands off-center, nudge it. Positive numbers move it right and down.</p>
					<p><label>Right <input type="number" name="x" step="0.005" min="-0.5" max="0.5" value="<?php echo esc_attr( $offset['x'] ); ?>" style="width:90px"> in</label>
					&nbsp; <label>Down <input type="number" name="y" step="0.005" min="-0.5" max="0.5" value="<?php echo esc_attr( $offset['y'] ); ?>" style="width:90px"> in</label>
					&nbsp; <button class="button">Save</button></p>
				</form>
			</details>
		</div>
		<script>
		window.DZSQ_LABELS = <?php echo wp_json_encode( array( 'rows' => $rows, 'x' => (float) $offset['x'], 'y' => (float) $offset['y'], 'back' => admin_url( 'edit.php?post_type=product' ), 'brand' => function_exists( 'mb_strtoupper' ) ? mb_strtoupper( DZSQ_Settings::label_brand() ) : strtoupper( DZSQ_Settings::label_brand() ) ) ); ?>;
		</script>
		<?php
		add_action( 'admin_print_footer_scripts', array( __CLASS__, 'script' ), 99 );
	}

	public static function script() {
		?>
		<script>
		(function () {
			'use strict';
			var C = window.DZSQ_LABELS || { rows: [] };
			var root = document.getElementById('dzsq-labels-app');
			if (!root) { return; }

			var LABEL_CSS =
				'.lbl{width:2.125in;height:1in;padding:.05in .08in .04in;box-sizing:border-box;display:flex;flex-direction:column;overflow:hidden;font-family:Arial,Helvetica,sans-serif;color:#000;background:#fff;break-after:page;page-break-after:always}' +
				'.lbl:last-child{break-after:auto;page-break-after:auto}' +
				'.lbl .t{font-size:7pt;font-weight:700;line-height:1.1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}' +
				'.lbl .bc{display:block;width:100%;height:.36in;margin-top:.03in}' +
				'.lbl .brand{text-align:center;font-size:5pt;letter-spacing:.06em;line-height:1;margin-top:1px}' +
				'.lbl .bot{display:flex;align-items:flex-end;justify-content:space-between;gap:.04in;margin-top:auto;line-height:1}' +
				'.lbl .sku{font-family:"Courier New",monospace;font-size:7.5pt;font-weight:700;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}' +
				'.lbl .p{flex:0 0 auto;font-size:15pt;font-weight:900;letter-spacing:-.02em}' +
				'.lbl .bcerr{height:.36in;font-size:6pt;color:#b00}';

			function esc(s) {
				return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
					return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
				});
			}

			/** Code 128 as a scalable SVG (10-module quiet zone each side). */
			function barcodeSvg(code) {
				if (!window.JsBarcode) { return '<div class="bcerr">Barcode library didn\'t load</div>'; }
				var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
				try {
					window.JsBarcode(svg, code, { format: 'CODE128', displayValue: false, margin: 0, marginLeft: 10, marginRight: 10, width: 1, height: 30, background: '#ffffff', lineColor: '#000000' });
				} catch (e) {
					return '<div class="bcerr">Can\'t encode ' + esc(code) + '</div>';
				}
				var w = parseFloat(svg.getAttribute('width')), h = parseFloat(svg.getAttribute('height'));
				svg.setAttribute('viewBox', '0 0 ' + w + ' ' + h);
				svg.removeAttribute('width');
				svg.removeAttribute('height');
				svg.removeAttribute('style');
				svg.setAttribute('preserveAspectRatio', 'none');
				svg.setAttribute('shape-rendering', 'crispEdges');
				svg.setAttribute('class', 'bc');
				return svg.outerHTML;
			}

			function labelHtml(d) {
				return '<div class="lbl"><div class="t">' + esc(d.name) + '</div>' + barcodeSvg(d.code) +
					'<div class="brand">' + esc(C.brand) + '</div>' +
					'<div class="bot"><span class="sku">' + esc(d.code) + '</span><span class="p">' + esc(d.price) + '</span></div></div>';
			}

			function offsetCss() {
				var x = parseFloat(C.x) || 0, y = parseFloat(C.y) || 0;
				return (x || y) ? '.lbl{transform:translate(' + x + 'in,' + y + 'in)}' : '';
			}

			function printLabels(list) {
				if (!list.length) { window.alert('Set at least one label to print.'); return; }
				var html = '<!doctype html><html><head><meta charset="utf-8"><title>' + esc(C.brand) + ' labels</title><style>' +
					'@page{size:2.125in 1in;margin:0}html,body{margin:0;padding:0;background:#fff}' + LABEL_CSS + offsetCss() +
					'</style></head><body>' + list.map(labelHtml).join('') + '</body></html>';
				var f = document.createElement('iframe');
				f.setAttribute('aria-hidden', 'true');
				f.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0';
				document.body.appendChild(f);
				var doc = f.contentWindow.document;
				doc.open();
				doc.write(html);
				doc.close();
				setTimeout(function () {
					f.contentWindow.focus();
					f.contentWindow.print();
					setTimeout(function () { f.remove(); }, 1000);
				}, 300);
			}

			function draw() {
				var printable = C.rows.filter(function (d) { return d.code; });
				var h = '';
				if (!C.rows.length) {
					root.innerHTML = '<p>No products picked. Go to <a href="' + esc(C.back) + '">Products</a>, tick some, and choose "Print barcode labels" from Bulk actions.</p>';
					return;
				}
				h += '<div class="dzl-bar"><button class="button button-primary button-hero" data-act="print">Print labels</button>' +
					'<button class="button" data-act="all1">Set all to 1</button>' +
					'<button class="button" data-act="stock">Set all to stock on hand</button>' +
					'<span class="dzl-total"></span></div>';
				h += '<table class="widefat striped dzl-table"><thead><tr><th>Label</th><th>Product</th><th>How many</th></tr></thead><tbody>';
				C.rows.forEach(function (d, i) {
					h += '<tr><td>' + (d.code ? '<div class="dzl-prev">' + labelHtml(d) + '</div>' : '<em>No SKU or UPC yet</em>') + '</td>' +
						'<td><strong>' + esc(d.name) + '</strong><br><span class="dzl-muted">' + (d.code ? esc(d.code) + ' · ' : '') + esc(d.price) +
						(d.stock !== null ? ' · ' + d.stock + ' in stock' : '') + '</span></td>' +
						'<td>' + (d.code ? '<input type="number" min="0" max="500" value="1" data-i="' + i + '" style="width:70px">' : '') + '</td></tr>';
				});
				h += '</tbody></table>';
				if (printable.length < C.rows.length) {
					h += '<p class="dzl-muted">' + (C.rows.length - printable.length) + ' item(s) have no SKU or UPC yet. Add a SKU in Square and it will sync here.</p>';
				}
				root.innerHTML = h;
				total();
			}

			function inputs() { return Array.prototype.slice.call(root.querySelectorAll('input[data-i]')); }

			function total() {
				var n = inputs().reduce(function (s, el) { return s + Math.max(0, parseInt(el.value, 10) || 0); }, 0);
				var t = root.querySelector('.dzl-total');
				if (t) { t.textContent = n + ' label' + (n === 1 ? '' : 's'); }
			}

			root.addEventListener('input', total);
			root.addEventListener('click', function (e) {
				var el = e.target.closest('[data-act]');
				if (!el) { return; }
				var act = el.getAttribute('data-act');
				if (act === 'all1') { inputs().forEach(function (x) { x.value = 1; }); total(); }
				if (act === 'stock') { inputs().forEach(function (x) { var s = C.rows[+x.getAttribute('data-i')].stock; x.value = s && s > 0 ? s : 0; }); total(); }
				if (act === 'print') {
					var list = [];
					inputs().forEach(function (x) {
						var n = Math.min(500, Math.max(0, parseInt(x.value, 10) || 0));
						for (var k = 0; k < n; k++) { list.push(C.rows[+x.getAttribute('data-i')]); }
					});
					printLabels(list);
				}
			});

			var style = document.createElement('style');
			style.textContent = LABEL_CSS +
				'.dzl-bar{display:flex;flex-wrap:wrap;align-items:center;gap:10px;margin:16px 0}' +
				'.dzl-total{font-size:14px;color:#646970}' +
				'.dzl-table{max-width:980px}.dzl-table td{vertical-align:middle}' +
				'.dzl-prev{zoom:1.35;display:inline-block;border:1px solid #c3c4c7;box-shadow:0 1px 2px rgba(0,0,0,.08)}' +
				'.dzl-muted{color:#646970}';
			document.head.appendChild(style);

			// JsBarcode loads in the footer just before this; wait for it if needed.
			if (window.JsBarcode) { draw(); } else { window.addEventListener('load', draw); }
		}());
		</script>
		<?php
	}
}
