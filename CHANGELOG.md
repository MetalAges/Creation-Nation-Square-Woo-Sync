# Changelog

## 1.2.0: first public release

- Standard WordPress plugin header, so the plugin installs from a zip like any other.
- Nothing is pre-filled. You enter your own Square token, then choose your location and categories on the Square Sync screen. If your Square account has exactly one active location, it is selected for you.
- Sync, stock and order work stays idle until a location and at least one category are chosen.
- Automatic SKU prefix and the shop name on barcode labels are now settings. Automatic SKUs are off until you set a prefix.
- Square request keys, the order source label and the webhook name are derived from your site, so several websites can safely share one Square account.
- New filter `dzsq_product_category_ids` lets you change which WooCommerce categories a synced product is filed under. The `dzsq_inventory_count` action is included.
- Background jobs are cancelled when the plugin is deactivated. Settings, the saved token and the activity log are removed when the plugin is deleted (products and orders are left alone).

## 1.1.0: internal

- Version used on two shops before the public release.
