<?php
/**
 * Plugin Name: Kiyoh WooCommerce Integration
 * Plugin URI: https://www.kiyoh.com/
 * Description: Complete WooCommerce integration with Kiyoh/Klantenvertellen review platforms. Sync products, send automated review invitations, and display reviews on your store.
 * Version: 1.1.0
 * Author: Kiyoh
 * Author URI: https://www.kiyoh.com/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: kiyoh-woocommerce
 * Domain Path: /languages
 * Requires at least: 5.8
 * Tested up to: 6.4
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Currently plugin version.
 */
define('KIYOH_WOOCOMMERCE_VERSION', '1.1.0');
define('KIYOH_WOOCOMMERCE_PLUGIN_FILE', __FILE__);
define('KIYOH_WOOCOMMERCE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('KIYOH_WOOCOMMERCE_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * The code that runs during plugin activation.
 */
function activate_kiyoh_woocommerce() {
    require_once KIYOH_WOOCOMMERCE_PLUGIN_DIR . 'includes/class-kiyoh-activator.php';
    Kiyoh_Activator::activate();
}

/**
 * The code that runs during plugin deactivation.
 */
function deactivate_kiyoh_woocommerce() {
    require_once KIYOH_WOOCOMMERCE_PLUGIN_DIR . 'includes/class-kiyoh-deactivator.php';
    Kiyoh_Deactivator::deactivate();
}

register_activation_hook(__FILE__, 'activate_kiyoh_woocommerce');
register_deactivation_hook(__FILE__, 'deactivate_kiyoh_woocommerce');

/**
 * Check if WooCommerce is active and compatible
 */
function kiyoh_woocommerce_check_dependencies() {
    // Check if WooCommerce is active
    if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
        // Also check for multisite
        if (!is_multisite() || !array_key_exists('woocommerce/woocommerce.php', get_site_option('active_sitewide_plugins', array()))) {
            return false;
        }
    }
    
    // Check if WooCommerce class exists (loaded)
    if (!class_exists('WooCommerce')) {
        return false;
    }
    
    // Check WooCommerce version
    if (defined('WC_VERSION') && version_compare(WC_VERSION, '5.0', '<')) {
        add_action('admin_notices', 'kiyoh_woocommerce_version_notice');
        return false;
    }
    
    return true;
}

/**
 * WooCommerce missing notice
 */
function kiyoh_woocommerce_missing_wc_notice() {
    $message = sprintf(
        /* translators: %s: plugin name */
        esc_html__('%s requires WooCommerce to be installed and active.', 'kiyoh-woocommerce'),
        '<strong>' . esc_html__('Kiyoh WooCommerce Integration', 'kiyoh-woocommerce') . '</strong>'
    );
    
    printf('<div class="notice notice-error"><p>%s</p></div>', $message);
}

/**
 * WooCommerce version notice
 */
function kiyoh_woocommerce_version_notice() {
    $message = sprintf(
        /* translators: %1$s: plugin name, %2$s: required version */
        esc_html__('%1$s requires WooCommerce version %2$s or higher.', 'kiyoh-woocommerce'),
        '<strong>' . esc_html__('Kiyoh WooCommerce Integration', 'kiyoh-woocommerce') . '</strong>',
        '5.0'
    );
    
    printf('<div class="notice notice-error"><p>%s</p></div>', $message);
}

/**
 * Initialize the plugin after WooCommerce is loaded
 */
function kiyoh_woocommerce_init() {
    if (!kiyoh_woocommerce_check_dependencies()) {
        add_action('admin_notices', 'kiyoh_woocommerce_missing_wc_notice');
        return;
    }
    
    // Load the core plugin class
    require_once KIYOH_WOOCOMMERCE_PLUGIN_DIR . 'includes/class-kiyoh-plugin.php';
    
    // Initialize the plugin
    $plugin = new Kiyoh_Plugin();
    $plugin->run();
}

// Hook into plugins_loaded to ensure WooCommerce is loaded first
add_action('plugins_loaded', 'kiyoh_woocommerce_init');

/**
 * Declare WooCommerce HPOS compatibility
 */
add_action('before_woocommerce_init', function() {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});