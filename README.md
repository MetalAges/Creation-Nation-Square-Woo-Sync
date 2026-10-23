# Creation Nation Square Sync

**Selective, two-way Square ↔ WooCommerce sync for shops with large catalogs and modest servers.**

Creation Nation Square Sync brings the Square categories *you choose* onto your WooCommerce website, keeps stock matched between the two, and (optionally) records website orders in Square so all of your sales and stock live in one place. Square stays the master catalog; the website follows it.

Version 1.2.0 · Square API version `2025-01-23` · GPL-2.0-or-later

> Built for, and running in production on, two independent WooCommerce shops that share one Square account. Not affiliated with or endorsed by Square (Block, Inc.) or WooCommerce (Automattic).

---

## Why it exists

The official WooCommerce Square integration can only sync the entire inventory, with no way to pick categories, which assumes the whole Square catalog belongs on the website. Real shops often don't work that way: some items are register-only, some categories are consignment or in-store only, and some catalogs are big enough to knock a shared host over.

This plugin was written to solve that:

- **Pick exactly which Square categories go online**, and which stay off, with a tick-box tree.
- **Work gently.** Everything runs in small background batches with pauses, backs off when the server is slow, checkpoints its progress, and resumes by itself after a crash or "Service unavailable".
- **Keep stock honest in both directions**, in near real time.

---

## Features

### Catalog: Square → WooCommerce

- **Category-selective sync.** Tick **Sync** on a Square category to bring it, and all of its sub-categories, onto the website. Tick **Skip** to keep a category (and its sub-categories) off, even when it sits inside a synced one.
- **Mirrors your category tree.** Square categories become WooCommerce product categories with the same hierarchy; renames and moves in Square follow through.
- **Simple and variable products.** A Square item with one variation becomes a simple product; an item with several becomes a variable product with an `Option` attribute. Blank variation names become "Regular"; duplicate names are numbered. Switching between the two types in Square is handled.
- **Price, SKU, barcode, tax and description.** Fixed prices sync as the regular price (variable-priced items sync with no price). SKUs are copied with a duplicate-SKU guard that logs a conflict instead of failing. Barcodes (UPC/GTIN) are saved to the product and to WooCommerce's GTIN field where supported. Taxable/non-taxable status and the item description (HTML sanitised) come across.
- **Photos.** Square images are downloaded into the media library, the first becomes the featured image and the rest the gallery. Alt text is set to the product name. Photos are fetched one product at a time, spaced apart, and only when the image set changes.
- **Register-only items stay off.** An optional pattern (regular expression, blank by default) skips price-point buttons such as `CD 1.95`, `LP 9.95` or `Video 4.95`.
- **Archived and deleted items stay off**, and products whose Square item leaves a synced category are hidden (set to draft) automatically, then come back if the item returns.
- **Cheap repeat syncs.** Items whose Square version hasn't changed are skipped.
- **Publish or review.** Choose whether new and updated products go live immediately or arrive as drafts for review.
- **"Keep hidden" memory.** If you move a synced product from Published to Draft by hand, the plugin remembers and keeps it hidden through every future sync. Publishing it again clears the flag.

### Inventory: Square ⇄ WooCommerce

- **Square is the source of truth for stock**, read for one chosen Square location.
- **Instant updates** via a Square webhook (`inventory.count.updated`) change stock the moment Square does.
- **Safety nets:** an incremental stock check every 15 minutes, plus a full stock check on demand and after every full catalog sync (anything Square has no count for becomes 0).
- Respects Square's *track inventory* and *sold out* settings per location, including location overrides.
- **Website sales lower Square stock** (see *Website orders* below).

### Website orders: WooCommerce → Square

Choose one of three modes:

| Mode | Use when | What happens |
|---|---|---|
| **Lower Square stock only** (default) | Customers pay through Square | The sale is already in Square's reports through the payment; each paid order just reduces Square stock. |
| **Record as a paid Square order** | Customers pay through Stripe, PayPal or another processor | Each paid order becomes a paid Square order so it appears in Square's sales and item reports. |
| **Do nothing in Square** | You manage this yourself | No order or stock activity is sent. |

In *record as a paid order* mode:

- Catalog items are linked to their Square items; website-only items go in as custom lines.
- Discounts, shipping and the sales tax collected online are recorded as fixed amounts so Square's total matches what the customer actually paid. Square's own tax rules are not applied on top.
- The payment is recorded as an external card payment tagged as a website sale, labelled with your site name. Zero-total orders are recorded without a payment.
- If Square's total differs from the website's, a note on the order says by how much.

For both active modes:

- **Cancelled or fully refunded orders are reversed**: stock is put back in Square and, in paid-order mode, a refund is recorded.
- **Idempotent and self-retrying.** Each order uses a stable idempotency key, so a retry can never double-record. The key includes a short code unique to your site, so several websites can safely share one Square account. Failures retry automatically with growing delays (up to 6 attempts); after that the order note tells you what to do.
- **Orders that slipped through are caught.** Every five minutes a watchdog re-checks paid orders from the last 14 days.
- **Manual "Send website order #".** Push any order to Square by number from the admin screen.
- Every outcome is written to the WooCommerce order notes and the activity log.

### Tools in the admin screen

- **Sync now**: start a full catalog sync.
- **Check all stock now**: start a full stock check.
- **Turn on / Reconnect instant updates**: (re)register the Square webhook with one click.
- **Sync one category**: re-pull every item in a chosen synced category (and its sub-categories) with live progress and a **Stop** button.
- **Find a product in Square**: search Square by name or SKU, see its category, price, variations, SKUs and whether it is on the website, then **Add to website** or **Sync now**. Items that must stay off show the plain-words reason why.
- **Activity log**: the last 100 events are shown (300 kept), errors highlighted.

### Per-product manual sync

Sync a single product with Square on demand, from three places:

- A **Square** box on the product edit screen (shows link status, last manual sync, and whether it is kept hidden).
- A **Sync with Square** link on each row of the Products list.
- A **Sync with Square** bulk action (up to 25 products per click).

The result message tells you what changed (name, price old → new, stock old → new, published/hidden, photo). Photos are fetched immediately rather than queued, so you see them straight away. If the item no longer belongs on the website, you are offered a one-click **Hide this product**.

### Automatic SKUs

When you sync a single product by hand (the product-screen button, the row link, or **Find a product in Square**), any variation that has no SKU in Square is given the next code in a running sequence (for example `SHOP1001`). You choose the prefix in settings (letters and numbers, up to 10); leave it blank and automatic SKUs are off.

- Codes are one above the highest ever issued, are never reused, and are checked against SKUs already on the website.
- The code is written **into Square**, so Square remains the master and the sync copies it back to the website. The result message shows the new SKU.
- The Tools area shows the next code to be issued, so codes you type by hand can follow the same sequence.
- Only items that belong on the website are given codes. The bulk action does not assign SKUs.

### Barcode labels

Print Code 128 barcode labels from the browser on **Dymo 30336** labels (1″ × 2-1/8″). Each label carries the product name, a barcode of the SKU (or the UPC when there is no SKU), the shop name, the code and the price. The shop name is a setting and defaults to your site name.

- Start from the **Barcode label** box on a product, the **Print label** row link, or the **Print barcode labels** bulk action (up to 200 products per screen).
- Choose how many of each (up to 500), use **Set all to 1** or **Set all to stock on hand**, and check a live preview of every label.
- Variable products print one label per variation; items with neither a SKU nor a UPC are flagged.
- A **printer calibration** control nudges the print position (up to half an inch each way) if labels land off-centre.
- Barcodes are drawn with [JsBarcode](https://github.com/lindell/JsBarcode), loaded from cdnjs on the label screen only.

### Reliability

- **Small batches with pauses.** Catalog sync runs 10 items per step with a pause between steps; photos are spaced out.
- **Server-strain backoff.** If a step takes longer than 45 seconds the sync pauses itself (3 minutes; 10 minutes if a step took over 2 minutes) and logs why.
- **Checkpoint and resume.** Progress is saved after every batch. If the server dies mid-sync, a watchdog resumes from the last checkpoint and clears stuck locks.
- **Overlap-safe.** If a sync is requested while one is running, it is queued to start right after, not run in parallel.
- **API retries.** Square calls retry on network errors, rate limits (429) and 5xx responses, with jittered waits.

### Security

- **Access token encrypted at rest** (AES-256-CBC, key derived from your WordPress salts) and never displayed again after saving.
- **Token checked before it replaces the old one**; a mistyped token can't break a working connection.
- **Token-failure alarm.** If Square stops accepting the token, you get an error banner on every admin screen, a log entry, and one email per outage. It clears itself when a working token is saved.
- **Webhook signatures verified** (HMAC-SHA256 against Square's signature key); unsigned or wrong-signature requests get a 403.
- Admin actions require the `manage_woocommerce` capability (per-product actions require `edit_product`) and are protected with nonces.

---

## How it works

```
 Square                                   Your WordPress site
 ──────                                   ───────────────────
 Catalog (categories, items,  ──────────▶  WooCommerce products, categories,
 variations, photos)           batches     variations, images, SKUs, barcodes

 Inventory counts             ──────────▶  WooCommerce stock levels
                         webhook + 15-min check + full check

 Square stock                 ◀──────────  Paid website orders
 (+ optional paid order,        stock-only   (and cancellations / refunds)
 refund on cancel)              or full mode
```

### Background jobs

All work runs through [Action Scheduler](https://actionscheduler.org/) (bundled with WooCommerce), not on page loads.

| Job | Runs | Purpose |
|---|---|---|
| Stock check (incremental) | every 15 minutes | Catch any stock change a webhook missed |
| Watchdog | every 5 minutes | Resume interrupted syncs, clear stuck locks, retry unrecorded paid orders |
| Full catalog sync | every 6 hours, on demand, and ~60 s after Square reports a catalog change | Keep products in line with Square |

---

## Requirements

- WordPress 6.0 or newer with **WooCommerce** active (Action Scheduler comes with WooCommerce)
- PHP 7.4 or newer (developed and linted on PHP 8.3) with the **OpenSSL** extension
- A site reachable over **HTTPS** (needed for Square webhooks)
- A **Square account** and a **Production access token** from your own app in the [Square Developer Dashboard](https://developer.squareup.com/apps)

## Installation

**From a release zip (easiest)**

1. Download `creation-nation-square-sync.zip` from the [Releases](../../releases) page.
2. In WordPress go to **Plugins → Add New → Upload Plugin**, choose the zip, install and activate.
3. Click **Settings** under the plugin's name, or go to **WooCommerce → Square Sync**.

**From the repository**

Copy this folder into `wp-content/plugins/` (so you end up with `wp-content/plugins/creation-nation-square-sync/creation-nation-square-sync.php`) and activate it.

To switch the sync off, deactivate the plugin: its queued and recurring background jobs are cancelled. To pause it without deactivating, set the `dzsq_paused` option to a true value; the plugin then stays completely idle.

## Setup

The Square Sync screen walks you through it, and nothing is pre-filled: you supply your own token, location and categories.

1. In the Square Developer Dashboard, open (or create) an application, switch to **Production**, and copy the **Production access token**.
2. In **WooCommerce → Square Sync**, paste the token and click **Connect to Square**. The token is tested first; if Square rejects it, nothing is changed.
3. The plugin registers the Square webhook ("instant updates") and, if your Square account has exactly one active location, selects it for you. If the webhook can't be registered yet, stock updates every 15 minutes until you click **Turn on instant updates**.
4. Choose the **Square location** whose stock the website should use (if it wasn't picked for you).
5. In the category tree, tick **Sync** on the categories to publish and **Skip** on any to keep off, then **Save settings**. Nothing syncs until at least one category is chosen.
6. Optionally set a **price-button pattern**, **new-product status**, **website order mode**, an **automatic SKU prefix** and the **shop name for labels**. Save, then click **Sync now**.

Automatic SKUs write into your Square catalog, so the token's app needs catalog write access as well as read.

## Settings reference

| Setting | What it does |
|---|---|
| Access token | Production token for your Square app. Stored encrypted. Leave blank to keep the saved one; tick *Disconnect* to remove it and unregister the webhook. |
| Square location | The location whose stock counts and sold-out flags drive the website. |
| Categories: Sync / Skip | Which Square categories (with their sub-categories) go to the website, and which are kept off. Skip always wins. |
| Price-button pattern | Optional regular expression; items whose name matches stay off the website. Blank skips nothing. Validated on save. |
| New and updated products are | *Published* or *Saved as drafts for review*. |
| Website orders | *Lower Square stock only*, *Record as a paid Square order*, or *Do nothing in Square*. |
| Automatic SKU prefix | Letters and numbers, up to 10. Variations without a SKU get the next code with this prefix when synced by hand. Blank turns automatic SKUs off. |
| Shop name on labels | Printed on barcode labels. Blank uses your site name. |

## Behaviour worth knowing

- **Square wins on synced fields.** Name, description, price, SKU, barcode, categories and photos are overwritten whenever an item syncs. Edit these in Square, not on the website. Website-only fields (for example extra descriptive meta, or products you create on the site that have no Square link) are left alone.
- **Catalog flows one way** (Square → website). Price or stock edits made directly in WooCommerce are not pushed to Square. The two exceptions are stock reduced by website sales and SKUs assigned automatically (written into Square).
- **Stock is whole numbers.** Fractional Square quantities are rounded down; negatives become 0.

## Known limitations

- One Square account and one location per site.
- Talks to Square's production API only; there is no sandbox mode, so test with a small category and *Saved as drafts for review* turned on.
- Label printing supports the Dymo 30336 size only.
- Partial refunds aren't mirrored; only whole-order cancellation and refund are reversed.
- Square item modifiers and custom attribute fields are not synced.
- Discounts are recorded as a single "Website discount" line rather than itemised per coupon.
- Not formally tested against WooCommerce High-Performance Order Storage (HPOS) or multisite.
- Deleting the plugin removes its settings, saved token, webhook key and activity log, but leaves your products, categories, media and orders (and their sync metadata) in place. Tick **Disconnect** first so the webhook is also removed from Square.

## Data the plugin stores

For transparency and debugging:

| Where | Keys |
|---|---|
| Products | `_dzsq_item_id`, `_dzsq_version`, `_dzsq_variation_id`, `_dzsq_upc`, `_dzsq_image_ids`, `_dzsq_keep_hidden`, `_dzsq_manual_synced` |
| Product categories | `_dzsq_category_id` (term meta) |
| Media | `_dzsq_image_id` (attachment meta) |
| Orders | `_dzsq_square_order_id`, `_dzsq_square_payment_id`, `_dzsq_square_total`, `_dzsq_stock_done`, `_dzsq_attempts`, `_dzsq_reversed` |
| Options | `dzsq_settings`, `dzsq_token` (encrypted), `dzsq_webhook_key` (encrypted), `dzsq_webhook_id`, `dzsq_webhook_url`, `dzsq_log`, `dzsq_last_sku` (last SKU number issued), `dzsq_label_offset` (label calibration), and several short-lived run/lock/status entries |

## For developers

- **Webhook endpoint:** `/wp-json/dzsq/v1/webhook` (POST, signature-verified).
- **Action `dzsq_inventory_count`** fires with the raw Square inventory-count object when a webhook reports a count for a catalog object the plugin did not create. Use it to react to register sales of items that live outside the sync.
- **Filter `dzsq_product_category_ids`** lets you change which WooCommerce categories a synced product is filed under. It receives the category IDs mapped from Square, the product, the Square item and the existing product ID (0 for a new one).
- All background work uses Action Scheduler group `dzsq`.
- Square API calls go through a single client class with retry, error handling and a token-failure alarm.

## Hosting tips

Action Scheduler is triggered by site traffic through WP-Cron by default. On a quiet site or a shared host, point a real server cron job at WP-Cron (or run Action Scheduler through WP-CLI) so stock changes and queued syncs are processed on time.

## Troubleshooting

| Symptom | Likely cause and fix |
|---|---|
| Red banner: *Square sync is stopped* | Square rejected the token (revoked, regenerated or deleted). Paste a new Production token on the Square Sync screen. |
| Token suddenly stops working after a migration or security change | The token is encrypted with a key derived from your WordPress salts. If the salts changed, paste the token again. |
| Status shows *Instant updates: Off* | Click **Turn on instant updates**. Confirm the site is on HTTPS and that nothing blocks `POST /wp-json/dzsq/v1/webhook`. |
| A sync looks stuck | Check the activity log. The watchdog resumes interrupted syncs within about 10 minutes; Action Scheduler (**Tools → Scheduled Actions**) must be running. |
| "SKU … is already used by another website product" | Two products share a SKU. Fix one; the sync leaves the SKU off the second product rather than failing. |
| A product won't come back after being hidden | It's flagged *Keep hidden* (you moved it to Draft by hand). Publish it again to clear the flag. |
| An item you expect isn't on the website | Use **Find a product in Square**; it states exactly why an item stays off (category, price-button pattern, archived, no variations). |

## License

GPL-2.0-or-later.
