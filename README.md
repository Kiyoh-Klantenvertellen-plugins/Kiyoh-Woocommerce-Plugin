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
- **PHP**: 7.4 or higher
- **Server Extensions**: cURL, JSON (usually pre-installed)

### Recommended for Best Performance
- **WordPress**: 6.8 or higher (latest version)
- **WooCommerce**: 10.0 or higher
- **PHP**: 8.1 or higher
- **SSL Certificate**: For secure API communication

### Compatibility
The plugin is compatible with:
- ✅ WordPress 5.8+ with WooCommerce 5.0+
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

#### Enter Your API Credentials

In the **General Settings** section:
- **Enable Plugin**: Set to **Yes**
- **Platform**: Select your platform (Kiyoh.com or Klantenvertellen.nl)
- **Location ID**: Enter your Location ID
- **API Key**: Enter your API Key
- Click **Save Changes**

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
3. **Waiting Period**: The system waits the configured delay (e.g., 7 days)
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

**Bulk sync stuck or failed:**
- **Do not refresh or leave the page during bulk sync**
- If sync fails, wait a few minutes and try again
- Check your internet connection
- Contact support if issues persist

### Debug Information

Enable WordPress debug logging to see detailed information about plugin operations. Check your WordPress error logs for messages starting with "Kiyoh".

## About This Plugin

**Version**: 1.0.0  
**Compatibility**: WordPress 5.8+ with WooCommerce 5.0+  
**Developer**: Kiyoh  
**License**: Proprietary
**Last Updated**: October 2025  

## Support

For technical support:
1. Check your API credentials and connection
2. Review WordPress error logs
3. Contact your review platform support team
4. Consult plugin documentation

## Advanced Configuration

### Multisite Support

The plugin is compatible with WordPress multisite installations. Configure each site independently.

### WPML/Polylang Compatibility

The plugin works with multilingual plugins and will attempt to detect customer language for invitations.

## Security

- API keys are stored securely in WordPress options
- All user inputs are sanitized and validated
- AJAX requests include nonce verification
- User capability checks prevent unauthorized access

---

*This plugin is part of your Kiyoh/Klantenvertellen subscription. For questions about your subscription, pricing, or additional features, contact your account manager.*
