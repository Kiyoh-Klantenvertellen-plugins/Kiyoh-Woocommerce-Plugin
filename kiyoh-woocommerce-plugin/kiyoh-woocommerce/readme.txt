=== Kiyoh WooCommerce Integration ===
Contributors: kiyoh
Tags: woocommerce, reviews, kiyoh, klantenvertellen
Requires at least: 5.8
Tested up to: 7.1.3
Requires PHP: 8.2
Stable tag: 1.4.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
WC requires at least: 5.0
WC tested up to: 11.2.1

Sync products, send review invitations, and show Kiyoh or Klantenvertellen ratings in WooCommerce.

== Description ==

Kiyoh WooCommerce Integration connects a WooCommerce store to Kiyoh and Klantenvertellen.

* Send a review invitation when an order reaches a chosen status. An optional shop-local send time waits until that clock time and then calls the Kiyoh API with delay 0.
* Sync product data, including a length-capped product code derived from the SKU.
* Show Kiyoh ratings on WooCommerce stars, or combine them with native WooCommerce reviews.

Compatible with High-Performance Order Storage and the Cart and Checkout blocks. Invitations use WooCommerce order status changes, so classic checkout and the Checkout block both send invites.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/kiyoh-woocommerce/` and activate it. WooCommerce must be active. PHP 8.2 or newer is required.
2. Open WooCommerce → Kiyoh. Click **Connect with Kiyoh / Klantenvertellen**, or enter the Location ID and API key for an existing install. Connect does not need extra values in `wp-config.php`. The shop must use HTTPS and pretty permalinks.
3. Save, then run Bulk Sync Products once.
4. Choose the order status that should send a review invitation.

== Permissions and data ==

* Settings, Connect, disconnect, the connection test, and sync require `manage_woocommerce`.
* The plugin stores the Location ID, invite API key, invitation rules, product-sync rows, a review cache, and the encrypted Connect shop secret and refresh token.
* Deactivate keeps that data and stops scheduled jobs. Delete removes options, tables, cron, and transients, and revokes the shop at Kiyoh-Connect.

== Changelog ==

= 1.4.1 =
* A blank Kiyoh Mobile URL(s) field reports only to https://kiyoh-mobile.com. Live shops never report to staging; testers enter https://kiyohmobileui-staging.up.railway.app in the field.
* The health report includes webhooks.lastReceivedAt (null). Connect sends the report after the location and invite key are saved and marks the connection ok.

= 1.4.0 =
* Connect with Kiyoh / Klantenvertellen via Kiyoh-Connect 1.2.0 (public client, per-shop secret, return to /wp-json/kiyoh/v1/oauth/callback). The manual Location ID and API key remain for existing installs.
* No wp-config secret is required. The shop secret from registration is stored encrypted and reused while the return address stays the same.
* Uninstall removes plugin data and revokes the Connect shop. Deactivate keeps settings and tokens.
* Remaining direct SQL uses whitelisted table names and $wpdb->prepare for values.

= 1.3.0 =
* Shop health is reported to Kiyoh Mobile (connection, invites, product sync, ministars).
* Kiyoh Mobile URL(s) on WooCommerce → Kiyoh → General accepts a comma-separated list. Blank uses https://kiyoh-mobile.com (changed in 1.4.1; staging was included in 1.3.0–1.4.0).
* Reports are sent on settings save, connection test, invite sent or failed, product sync, and a daily cron. Unchanged reports are sent again after 24 hours.
* The health request uses a 2-second timeout, honors HTTP 429, and does not block invitations or checkout when it fails.

= 1.2.1 =
* Tested up to WordPress 7.1.3 and WooCommerce 11.2.1.
* Requires PHP 8.2.
* Declared compatible with High-Performance Order Storage and the Cart and Checkout blocks.
* Optional "Send parent product code for variations" setting, off by default. When on, invitations and sync use the parent product. A parent with no SKU is skipped.

= 1.2.0 =
* Optional preferred send time for review invitations, queued with Action Scheduler.

= 1.1.4 =
* Admin scripts load only on the Kiyoh settings screen, so WooCommerce Settings tabs keep working.

= 1.1.3 =
* Ministars in "both" mode keep native WooCommerce reviews after a Kiyoh resync.
* Deactivation hides imported Kiyoh reviews and restores native averages.

= 1.1.2 =
* Product ratings from the Kiyoh publication API can replace WooCommerce ministars.

= 1.1.1 =
* Bulk product sync sends location_id on every product in the batch.
