# Kiyoh Reviews - User Guide
**Collect more reviews and boost your store's credibility with automated review requests**

## What is Kiyoh Reviews?

Kiyoh Reviews is a WooCommerce plugin that automatically collects customer reviews for your store and products. It integrates seamlessly with Kiyoh and Klantenvertellen review platforms, helping you build trust and increase conversions.

## Key Benefits

✅ **Automated Review Collection**: Automatically send review requests after orders are completed  
✅ **Product Reviews**: Collect reviews for specific products customers purchased  
✅ **Perfect Timing**: Send review requests at the optimal time (e.g., 7 days after delivery)  
✅ **Multi-Language**: Automatically detects customer language for personalized emails  
✅ **Easy Setup**: Configure once and let it run automatically  
✅ **Boost SEO**: Product reviews improve search engine rankings  
✅ **Increase Trust**: Display ratings and reviews to build customer confidence  

## System Requirements

### Minimum Requirements
- **WordPress**: 5.8 or higher
- **WooCommerce**: 5.0 or higher
- **PHP**: 8.2 or higher
- **Server Extensions**: cURL, JSON (usually pre-installed)

### Recommended for Best Performance
- **WordPress**: 7.1.3 (tested)
- **WooCommerce**: 11.2.1 (tested), with High-Performance Order Storage and the Checkout block
- **PHP**: 8.2 or higher
- **SSL Certificate**: For secure API communication

### Compatibility
The plugin is compatible with:
- ✅ WordPress 5.8+ with WooCommerce 5.0+
- ✅ Tested up to WordPress 7.1.3 and WooCommerce 11.2.1
- ✅ High-Performance Order Storage (HPOS) and Cart & Checkout blocks
- ✅ All standard WordPress installations
- ✅ Multi-site installations
- ✅ WPML and Polylang (multilingual sites)
- ✅ Cloud hosting and shared hosting

**Note for older WordPress installations:**
- WordPress versions below 5.8 are not supported
- We recommend keeping WordPress and WooCommerce updated for security and performance
- If you're on older versions, contact your technical team about upgrading

## Getting Started

### Step 1: Get Your API Credentials

Navigate to your Kiyoh/Klantenvertellen dashboard to receive:
- Your Location ID
- Your API Key

Keep these credentials handy for the next step.

### Step 2: Install the Plugin

You can install the plugin yourself using WordPress's built-in upload feature. The installation process typically takes 2-5 minutes.

**Installation steps:**
1. Log into your WordPress admin panel
2. Go to **Plugins > Add New**
3. Click **Upload Plugin** at the top of the page
4. Click **Choose File** and select the `kiyoh-woocommerce.zip` file
5. Click **Install Now**
6. Once uploaded, click **Activate Plugin**
7. Verify WooCommerce compatibility (plugin will show warnings if issues exist)

**Alternative method (for technical users):**
- Upload the plugin files to `/wp-content/plugins/kiyoh-woocommerce/` directory via FTP
- Activate through the WordPress admin **Plugins** screen

Once installed, you'll see a new "Kiyoh" option in your WordPress admin under **WooCommerce**.

### Step 3: Configure the Plugin

1. Log into your WordPress admin panel
2. Go to **WooCommerce > Kiyoh**
3. Configure your settings as described below

#### Connect the shop, or paste a key

In the **General Settings** section:
- **Enable Plugin**: Set to **Yes**
- **Platform**: Select Kiyoh.com or Klantenvertellen.nl
- **Connect with Kiyoh / Klantenvertellen**: signs the merchant in on Kiyoh-Connect and fills the Location ID and API key. The shop must be HTTPS with pretty permalinks. No extra secrets in `wp-config.php`. Connect gives this shop its own secret on the first successful sign-in and the plugin stores that secret encrypted.
- **Existing installs** can skip Connect and keep typing the Location ID and API key. Saving a change to either field keeps that manual key.
- Click **Save Changes** after a manual key. Connect saves the key itself.

#### Test Your Connection

After entering credentials:
- Click the **Test API Connection** button
- Wait for the success message
- If you see an error, double-check your credentials

The system will automatically verify your credentials if successful.

#### Configure Product Synchronization

In the **Product Sync Settings** section:
- **Auto Sync Products**: Set to **Yes** (recommended)
- This automatically updates product information when you edit products
- **Excluded Product Types**: Select product types you don't want to sync (optional)
  - Example: Virtual products, downloadable products, gift cards
- **Excluded Product Codes**: Enter specific SKUs to exclude (optional)
  - Enter one SKU per line or seperated by commas
  - Example: SAMPLE-001, TEST-SKU

**Initial Product Sync:**
- **Important**: First save your API credentials, then proceed with bulk sync
- Click the **Bulk Sync Products** button to sync all existing products
- **Do not leave the page while the sync is in progress**
- This may take several minutes depending on your catalog size
- You'll see a loading indicator during the sync
- You only need to do this once

Click **Save Changes** when done.

#### Configure Review Invitations

In the **Review Invitation Settings** section:

**Basic Settings:**
- **Trigger Order Status**: Select when to send invitations (usually "Completed")
- **Invitation Delay (Days)**: How many days to wait before sending
- **Preferred send time** (optional): Send at a specific time of day in your shop timezone, after the delay. You can also limit the weekdays (for example weekdays only). Leave this off to keep the previous behaviour, where Kiyoh receives the delay in whole days and chooses the time. The Invitations tab lists invitations that are waiting for that time.
- **Invitation Type**: Choose what to request:
  - **Shop + Product Reviews**: Ask for both store and product reviews (recommended)
  - **Product Reviews Only**: Only ask for product reviews
  - **Shop Reviews Only**: Only ask for store reviews (no product-specific reviews)

**Advanced Settings:**
- **Max Products per Invitation**: How many products to include (recommended: 3-5)
  - Too many products can overwhelm customers
  - Focus on the most important items
- **Product Sort Order**: Choose how to prioritize products when multiple items are in an order:
  - **Cart Order (Default)**: Use the order items appear in the cart
  - **Price (High to Low)**: Prioritize expensive items first
  - **Price (Low to High)**: Prioritize cheaper items first
  - **Name (A to Z)**: Sort products alphabetically
  - **Name (Z to A)**: Sort products reverse alphabetically
  - **SKU (A to Z)**: Sort by product code alphabetically
  - **SKU (Z to A)**: Sort by product code reverse alphabetically

**Customer Settings:**
- **Excluded Customer Groups**: Skip certain user roles (e.g., wholesale customers)
- **Auto-detect Language**: Automatically detect customer language from WordPress locale
- **Fallback Language**: Default language if customer language can't be detected (e.g., "en" for English)

**Choosing the Right Invitation Type:**
- **Shop + Product Reviews**: Best for most stores - gives comprehensive feedback
- **Product Reviews Only**: Good for stores focused on product quality and SEO
- **Shop Reviews Only**: Ideal for service-based businesses or when you want to focus on overall customer experience

Click **Save Changes** when done.

## How It Works

### Automatic Review Requests

Once configured, the plugin works automatically:

1. **Customer Places Order**: A customer completes a purchase
2. **Order is Completed**: You mark the order as "Completed" (or your configured status)
3. **Waiting Period**: The system waits the configured delay (e.g., 7 days). If you set a preferred send time, it then waits until that time on an allowed day.
4. **Review Request Sent**: Customer receives an email from Kiyoh/Klantenvertellen
5. **Customer Leaves Review**: Customer clicks the link and writes a review
6. **Review Published**: Review appears on your Kiyoh profile and can be displayed on your website

### What Customers Receive

Customers receive a personalized email in their language with:
- A friendly greeting using their name
- A link to leave a review
- The products they purchased (with images) - if product reviews are enabled
- A simple rating system

**Note**: If you choose "Shop Reviews Only", customers will only be asked to review your store, not specific products. This is useful if you prefer to focus on overall service quality rather than individual product feedback.

## Language Support

The plugin supports 25+ languages including:
- English, Dutch, German, French, Spanish, Italian
- Portuguese, Danish, Swedish, Norwegian, Finnish
- Polish, Czech, Slovak, Hungarian, Romanian
- Chinese, Japanese, Turkish, Greek, Russian
- And many more...

## Managing Reviews

### Where to See Your Reviews

1. **Kiyoh Dashboard**: Log into your Kiyoh/Klantenvertellen account to see all reviews
2. **Email Notifications**: You'll receive email alerts for new reviews
3. **WordPress Logs**: Your technical team can check logs for invitation status

### Responding to Reviews

- Log into your Kiyoh/Klantenvertellen dashboard
- Navigate to the reviews section
- Click on a review to respond
- Your response appears publicly below the review

### Handling Negative Reviews

1. **Respond Quickly**: Address concerns within 24-48 hours
2. **Be Professional**: Stay calm and courteous
3. **Offer Solutions**: Provide ways to resolve the issue
4. **Take It Offline**: Invite them to contact you directly
5. **Learn and Improve**: Use feedback to improve your service

## Troubleshooting

### Common Issues

**Plugin not working:**
- Verify WooCommerce is active and compatible
- Check API credentials are correct
- Test API connection in settings

**Products not syncing:**
- Ensure auto-sync is enabled
- Check if products are excluded by type or code
- Review error logs for specific issues

**Invitations not sending:**
- Verify trigger statuses are configured
- Check if customers are in excluded groups
- Ensure API connection is working
- If a preferred send time is set, open **WooCommerce → Kiyoh → Invitations** and check the scheduled-invitations list. The send runs when WordPress cron or Action Scheduler runs, so a quiet shop can be late unless a real server cron calls `wp-cron.php`. Cancelling or refunding the order before that time skips the invite.

**Bulk sync stuck or failed:**
- **Do not refresh or leave the page during bulk sync**
- If sync fails, wait a few minutes and try again
- Check your internet connection
- Contact support if issues persist

### Debug Information

Enable WordPress debug logging to see detailed information about plugin operations. Check your WordPress error logs for messages starting with "Kiyoh".

## About This Plugin

**Version**: 1.4.0  
**Compatibility**: WordPress 5.8+ (tested up to 7.1.3) with WooCommerce 5.0+ (tested up to 11.2.1). Requires PHP 8.2.  
**Developer**: Kiyoh  
**License**: Proprietary
**Last Updated**: October 2026  

## Support

For technical support:
1. Check your API credentials and connection
2. Review WordPress error logs
3. Contact your review platform support team
4. Consult plugin documentation

## Kiyoh Mobile health

The plugin tells Kiyoh Mobile whether this shop is connected, when invites and product sync last succeeded, and whether Kiyoh ministars are shown. That report is sent when you save settings, test the connection, send or fail an invite, or sync products, and again once a day.

On **WooCommerce → Kiyoh → General**, **Kiyoh Mobile URL(s)** is a comma-separated list of base URLs. Leave it blank to use `https://kiyoh-mobile.com`. To include staging as well, enter:

`https://kiyoh-mobile.com, https://kiyohmobileui-staging.up.railway.app`

The same list can be set with the `KIYOH_MOBILE_URLS` constant (for example in `wp-config.php`) or the `kiyoh_mobile_urls` filter. A non-blank setting overrides the constant. The filter overrides both. The report uses the saved API key and Location ID. Each URL gets one short request at the end of the page load. If Kiyoh Mobile does not answer, invitations and checkout still finish.

## Advanced Configuration

### Multisite Support

The plugin is compatible with WordPress multisite installations. Configure each site independently.

### WPML/Polylang Compatibility

The plugin works with multilingual plugins and will attempt to detect customer language for invitations.

## Permissions

The settings screen, Connect button, disconnect, connection test, product sync, and rating sync all require `manage_woocommerce` (the same capability as managing the shop). Visitors and authors cannot start a sign-in or read the invite key from the admin UI.

Connect asks Kiyoh-Connect for the scope `integration:connect` only. That scope returns the location id and the invite key for the location the merchant picks.

## Data stored

- **Settings** (`kiyoh_woocommerce_settings`): platform, Location ID, API key, whether the key came from Connect or was pasted, mobile URLs, invitation rules, product sync rules, and the rating source.
- **Connect account** (`kiyoh_connect_account`): which Connect host was used, the return address, the location name, and the shop id, shop secret, and refresh token encrypted with the WordPress auth salt. There is no shared HMAC key or client secret.
- **Health** (`kiyoh_mobile_health`): the last report fingerprint and any 429 wait.
- **Tables**: product sync rows, invitation rows, and a review cache.
- **Transients**: short-lived Connect sign-in state (15 minutes) and other `kiyoh_` caches.

## Uninstall and deactivate

- **Deactivate** stops scheduled jobs and clears the review cache. Settings, the Connect token, invitation rows, and product-sync rows stay, so activating again keeps the shop connected.
- **Delete** runs `uninstall.php`. It tells Kiyoh-Connect to revoke this shop's token and shop registration (using the stored shop secret), then deletes the options, tables, cron events, and `kiyoh_` transients. On a multisite network this runs for each site.

## Security

- Kiyoh-Connect is a public client. The per-shop secret is issued at registration, returned once, and stored encrypted. It is not in the plugin zip, the browser, the sign-in link, or `wp-config.php`.
- Connect tokens are encrypted at rest. The sign-in return is checked with a one-time `state` value, and start/disconnect use a nonce plus `manage_woocommerce`.
- API keys are stored in WordPress options. Manual keys are not written into JavaScript.
- AJAX requests include nonce verification.
- User capability checks prevent unauthorized access.

---

*This plugin is part of your Kiyoh/Klantenvertellen subscription. For questions about your subscription, pricing, or additional features, contact your account manager.*
