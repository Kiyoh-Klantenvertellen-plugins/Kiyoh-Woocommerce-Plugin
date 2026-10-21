=== Kiyoh WooCommerce Integration ===
Contributors: kiyoh
Tags: woocommerce, reviews, kiyoh, klantenvertellen
Requires at least: 5.8
Tested up to: 7.1.3
Requires PHP: 8.2
Stable tag: 1.2.0
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
2. Open WooCommerce → Kiyoh and enter the Location ID and API key.
3. Save, then run Bulk Sync Products once.
4. Choose the order status that should send a review invitation.

== Changelog ==

= Unreleased =
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
