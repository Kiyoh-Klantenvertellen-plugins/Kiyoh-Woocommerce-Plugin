# Changelog

## v1.4.2

### Fixed
- **Connect with Kiyoh / Klantenvertellen** closes the sign-in popup after the location is chosen. The callback page postMessages the WooCommerce settings tab (shop origin only, never `*`) and that tab closes the popup and reloads as Connected. If the popup has no opener — the browser blocked it, or the opener link was dropped — the same window opens the Kiyoh settings page instead.

## v1.4.1

### Fixed
- **Kiyoh Mobile URL(s)** help text: a blank field reports only to `https://kiyoh-mobile.com`, so live shops never post to staging. To test against staging, enter `https://kiyohmobileui-staging.up.railway.app` in the field (a non-blank field replaces the default).
- The health body includes `webhooks.lastReceivedAt` (`null`, because WooCommerce does not receive platform webhooks). The staging plugins page reads that field on every card.
- **Connect with Kiyoh / Klantenvertellen** marks the connection ok and sends the report after the location and invite key are saved, including when those values did not change (WordPress then skips the settings-update hook). A failed health call still does not stop Connect, invites, or checkout.

## v1.4.0

### Added
- **Connect with Kiyoh / Klantenvertellen** on WooCommerce → Kiyoh → General. The button talks to Kiyoh-Connect 1.2.0 (`https://connect.kiyoh.com` for Kiyoh, tenant 98, or `https://connect.klantenvertellen.nl` for Klantenvertellen, tenant 99), opens the sign-in popup, and returns to `https://{shop-host}/wp-json/kiyoh/v1/oauth/callback`. Client id `kiyoh-woocommerce`, scope `integration:connect`, PKCE S256 and `state`.
- The first click registers the shop with `POST /shops/register` (`client_id`, `shop_url`, `redirect_uri`, no signature). Connect returns `shop_id` and `shop_secret` once. Both are stored encrypted. The same return address skips registration and reuses that secret. A new registration returns a pending secret that replaces the stored one only after the code exchange succeeds.
- After sign-in, `locationId` and `inviteApiKey` from `GET /integration/credentials` are saved as the Location ID and API key. Token, refresh, and revoke calls send `shop_id` and `shop_secret`. A publication HTTP 401 refreshes once and retries. Disconnect and uninstall revoke the token and the shop registration.
- The Location ID and API key fields stay as the fallback for existing installs. Editing either field switches the shop back to the manual key so a later refresh cannot overwrite it.
- `uninstall.php` revokes the Connect shop and removes plugin options, custom tables, cron, and `kiyoh_` transients. Deactivation still keeps settings, Connect tokens, invitations, and product-sync rows.

### Security
- There is no shared HMAC key and no OAuth client secret. Nothing has to be added to `wp-config.php` for Connect. The per-shop secret stays encrypted and is not sent on the authorize URL.
- The six unprepared SQL calls now use a whitelisted table name. Query values go through `$wpdb->prepare`.

## v1.3.0

### Added
- **Kiyoh Mobile shop health.** The plugin posts to `POST {url}/api/integrations/health` for each base URL in **WooCommerce → Kiyoh → General → Kiyoh Mobile URL(s)** (comma-separated). Leave the field blank to use the `KIYOH_MOBILE_URLS` constant, which defaults to `https://kiyoh-mobile.com,https://kiyohmobileui-staging.up.railway.app`. The `kiyoh_mobile_urls` filter overrides both.
- Headers are `X-Publication-Api-Token` and `X-Location-Id` from the saved API key and Location ID. The body uses `platform=woocommerce`, `shopId` set to the site domain, and `pluginVersion`. Groups are `connection`, `invites` (`lastOkAt`, `failures24h`), `productSync` (including up to 500 product codes), and `widget` (`injected` when Kiyoh ministars are active).
- A report is queued when settings are saved, credentials change, the connection test finishes, an invite is sent or fails, or products are synced. Those events share one HTTP call per URL at the end of the request. A daily WP-Cron event (`kiyoh_report_mobile_health`) sends again when the payload changed or the last success is older than 24 hours, so the server heartbeat stays inside the 48-hour stale window.
- The call is `wp_remote_post` with a 2-second timeout. HTTP 429 stores `Retry-After` (or `retryAfterSeconds`) and does not retry until that time. A health failure is logged and does not change invite or checkout behaviour. Reports are skipped when the API key or Location ID is missing. Clearing credentials stops the heartbeat; Kiyoh Mobile then shows the shop as stale after 48 hours.

## v1.2.1

### Changed
- Plugin headers and `readme.txt` now record compatibility with WordPress 7.1.3 and WooCommerce 11.2.1. Requires PHP 8.2.
- Declared compatibility with the Cart and Checkout blocks, alongside the existing High-Performance Order Storage declaration. Review invites still use `woocommerce_order_status_changed`, which both classic checkout and the Checkout block fire.
- Order status handling reloads the order through `wc_get_order` when WooCommerce does not pass an order object, so invites stay on the order CRUD API under HPOS.
- The WooCommerce 9.2 GTIN helper is only called when it exists, so product sync does not fatal on the stated WooCommerce 5.0 minimum.
- Invitation and product-sync statistics no longer read properties on a missing database row (PHP 8 warning).

### Added
- **Send parent product code for variations** on WooCommerce → Kiyoh → Product Sync. Off by default, so invitations and sync keep using the purchased variation SKU. When on, invites, the order-time product sync, and bulk sync use the parent variable product and its SKU. Two variations of the same parent count as one product. A parent with no SKU is skipped and logged.

## v1.2.0

### Added
- **Optional time of day for review invitations.** On **WooCommerce → Kiyoh → Invitations**, turn on **Preferred send time**, set an HH:MM time in the shop timezone (Settings → General), and optionally limit the weekdays. After the existing delay in days, the plugin waits until the next allowed day and time, then asks Kiyoh to send immediately.
- **Pending scheduled invitations** are listed on the Invitations tab (order, customer, shop-local send time, status). The order note and the PHP error log record scheduling, sending, skips, and failures.
- The Kiyoh invite API (`POST /v1/invite/external`) still only accepts a whole-day `delay`. It has no send-at datetime, so the clock time is handled in the plugin with WooCommerce Action Scheduler. The API is called with `delay: 0` when the job runs.

### Notes
- Leave **Preferred send time** off, or leave the time empty, and nothing changes: the plugin still calls Kiyoh as soon as the order hits a trigger status and passes `delay_days` (default 3).
- Before a scheduled invite is sent, the order is checked again. Cancelled, refunded, failed, or trashed orders are skipped, as are excluded customers and orders that already have a sent invite. Running the job twice does not send twice.
- Action Scheduler uses WP-Cron unless the shop has a real server cron. On a low-traffic site the email can arrive late. A send is not more precise than the cron interval.
- `kiyoh_api_base_url` can replace the API host (the default remains `https://www.kiyoh.com` or `https://www.klantenvertellen.nl`). Live shops should leave the filter alone.

## v1.1.4

### Fixed
- **WooCommerce Settings tabs broke with a console TypeError.** Admin JS/CSS were enqueued on every admin screen, so `validateCredentials()` called `.val().trim()` on Kiyoh fields that only exist on **WooCommerce → Kiyoh**. On other screens `.val()` is `undefined`, which threw `TypeError` and stopped other admin scripts. Assets now load only on the Kiyoh settings page; init bails without `.kiyoh-admin-settings`; credential validation guards missing fields; tab click handlers stay scoped to the Kiyoh screen so they no longer intercept WooCommerce Settings tabs.

## v1.1.3

### Fixed
- **“Both” ministars ignored native WooCommerce reviews after a Kiyoh resync.** The native rating snapshot was taken through the same comment filters used on the storefront, so a Kiyoh-only sync stored a zero WooCommerce count and later “both” averages stayed Kiyoh-only. Native ratings are now counted from unfiltered comments (any approved product comment with a 1–5 star rating, excluding imported Kiyoh reviews).
- **Deactivating the plugin left Kiyoh reviews and star overrides in place.** Deactivation now hides imported Kiyoh comments and restores WooCommerce-native averages, while keeping `_kiyoh_*` rating meta. Reactivation unhides those comments and reapplies ministars from the stored source (Kiyoh or both), and starts the hourly rating sync again.
- **Deactivate/reactivate updated review comments but left ministars unchanged.** WooCommerce’s comment-status save was rewriting `_wc_average_rating` (and the lookup table) back to the previous source. Those values are now written explicitly and product rating caches are cleared.

### Changed
- **Rating sync runs in the background** with a 3-second gap between per-product publication API calls. Progress is stored in the database, so the Reviews tab still shows “in progress” after you leave and come back. Hourly sync uses the same queue.

## v1.1.2

### Added
- **Kiyoh ratings on WooCommerce ministars.** Product ratings are fetched from the publication API (`/v1/publication/product` with `updatedSince`, falling back to `/external` on 404) and shown on catalog and product-page star ratings. Kiyoh’s 10-point score is divided by 2. Native WooCommerce stars are used only when Kiyoh has no data for that SKU.
- **Catalog “Sort by average rating” uses Kiyoh scores** by writing the converted rating to `_wc_average_rating` and `wc_product_meta_lookup`.
- Hourly rating refresh plus a **Sync Ratings Now** button on **WooCommerce → Kiyoh → Product Sync**.
- Setting to turn ministar replacement off; disabling restores native WooCommerce ratings from product comments.
- Rating sync now computes averages from the `reviews` array when Kiyoh still reports `averageRating: 0` / `numberReviews: 0` (aggregates lag behind newly published reviews). It also hydrates clusters via `/v1/publication/product/review/external`.
- **Optional import of Kiyoh review content** into the WooCommerce product Reviews tab (deduped by Kiyoh review id). Imported comments can be removed separately.
- Ministars can use **Kiyoh only**, **WooCommerce only**, or **both**. “Both” is a weighted average by review count. Imported Kiyoh comments are excluded from the WooCommerce side so they are not double-counted.

## v1.1.1

### Fixed
- **Bulk product sync failed with "0 synced" and every product reported as an error.** The bulk endpoint (`/v1/location/product/external/bulk`) requires `location_id` on **each product object**, but the plugin only sent it on the request envelope. The API rejected every batch with `VALIDATION_ERROR` (`REQUIRED_FIELD`), and because a failed batch counted all its products as errors, the result was `0 synced`. Each product now includes `location_id`. (Verified live: 50/50 synced, HTTP 200.)

### Added
- **Verbose bulk-sync error reporting in the admin panel.** When a sync fails, the panel now shows the exact API reason, error code, and HTTP status (e.g. `Batch of 50 product(s) failed: Unauthorized request [ERROR_401] (HTTP 401)`) instead of only an error count. Error notices stay on screen so they can be read/copied; messages are HTML-escaped.

### Notes
- No configuration changes required. Re-run **Bulk Sync Products** after updating.
- Single-product (auto) sync was unaffected and continues to work as before.
