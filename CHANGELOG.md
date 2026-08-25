# Changelog

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
