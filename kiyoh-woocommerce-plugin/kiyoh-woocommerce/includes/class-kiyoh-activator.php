<?php

/**
 * Fired during plugin activation
 *
 * This class defines all code necessary to run during the plugin's activation.
 */
class Kiyoh_Activator {

    /**
     * Plugin activation handler
     */
    public static function activate() {
        // Check if WooCommerce is active
        if (!self::is_woocommerce_active()) {
            deactivate_plugins(plugin_basename(KIYOH_WOOCOMMERCE_PLUGIN_FILE));
            wp_die(
                esc_html__('Kiyoh WooCommerce Integration requires WooCommerce to be installed and active.', 'kiyoh-woocommerce'),
                esc_html__('Plugin Activation Error', 'kiyoh-woocommerce'),
                array('back_link' => true)
            );
        }

        // Check WooCommerce version
        if (!self::is_woocommerce_version_compatible()) {
            deactivate_plugins(plugin_basename(KIYOH_WOOCOMMERCE_PLUGIN_FILE));
            wp_die(
                esc_html__('Kiyoh WooCommerce Integration requires WooCommerce version 5.0 or higher.', 'kiyoh-woocommerce'),
                esc_html__('Plugin Activation Error', 'kiyoh-woocommerce'),
                array('back_link' => true)
            );
        }

        // Create database tables
        self::create_tables();
        
        // Set default options
        self::set_default_options();
        
        // Schedule cron events
        self::schedule_cron_events();
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Check if WooCommerce is active
     */
    private static function is_woocommerce_active() {
        // Check if WooCommerce plugin is active
        if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
            // Also check for multisite
            if (!is_multisite() || !array_key_exists('woocommerce/woocommerce.php', get_site_option('active_sitewide_plugins', array()))) {
                return false;
            }
        }
        
        return true;
    }

    /**
     * Check if WooCommerce version is compatible
     */
    private static function is_woocommerce_version_compatible() {
        if (!defined('WC_VERSION')) {
            return false;
        }
        
        return version_compare(WC_VERSION, '5.0', '>=');
    }

    /**
     * Create custom database tables
     */
    private static function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        // Product sync tracking table
        $table_name = $wpdb->prefix . 'kiyoh_product_sync';
        $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            product_id bigint(20) NOT NULL,
            last_sync datetime DEFAULT NULL,
            sync_status varchar(20) DEFAULT 'pending',
            error_message text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY product_id (product_id),
            KEY sync_status (sync_status)
        ) $charset_collate;";

        // Invitations tracking table
        $table_name = $wpdb->prefix . 'kiyoh_invitations';
        $sql .= "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            order_id bigint(20) NOT NULL,
            customer_email varchar(255) NOT NULL,
            invitation_type varchar(20) DEFAULT 'both',
            sent_at datetime DEFAULT NULL,
            scheduled_for datetime DEFAULT NULL,
            status varchar(20) DEFAULT 'pending',
            response_data text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY order_id (order_id),
            KEY customer_email (customer_email),
            KEY status (status),
            KEY scheduled_for (scheduled_for)
        ) $charset_collate;";

        // Review cache table
        $table_name = $wpdb->prefix . 'kiyoh_review_cache';
        $sql .= "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            cache_key varchar(255) NOT NULL,
            cache_data longtext,
            expires_at datetime NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY cache_key (cache_key),
            KEY expires_at (expires_at)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Set default plugin options
     */
    private static function set_default_options() {
        $default_settings = array(
            'general' => array(
                'enabled' => false,
                'platform' => 'kiyoh',
                'location_id' => '',
                'api_key' => '',
            ),
            'product_sync' => array(
                'auto_sync' => true,
                'excluded_types' => array(),
                'excluded_codes' => '',
                'sync_fields' => array('product_name', 'product_code', 'url', 'image', 'brand', 'sku', 'gtin', 'mpn'),
            ),
            'invitations' => array(
                'trigger_status' => array('completed'),
                'delay_days' => 3,
                'invitation_type' => 'shop_and_product',
                'max_products' => 5,
                'product_sort_order' => 'cart_order',
                'language_auto' => true,
                'fallback_language' => 'en'
            ),
            'frontend' => array(
                'shop_widget_enabled' => true,
                'product_widget_enabled' => true,
                'cache_ttl' => 3600,
                'template' => 'default',
                'items_per_page' => 10,
                'show_aggregates' => true,
            ),
        );

        // Only set defaults if options don't exist
        if (!get_option('kiyoh_woocommerce_settings')) {
            add_option('kiyoh_woocommerce_settings', $default_settings);
        }

        // Set plugin version
        add_option('kiyoh_woocommerce_version', KIYOH_WOOCOMMERCE_VERSION);
    }

    /**
     * Schedule cron events
     */
    private static function schedule_cron_events() {
        // Schedule invitation processing
        if (!wp_next_scheduled('kiyoh_process_scheduled_invitations')) {
            wp_schedule_event(time(), 'hourly', 'kiyoh_process_scheduled_invitations');
        }

        // Schedule cache cleanup
        if (!wp_next_scheduled('kiyoh_cleanup_cache')) {
            wp_schedule_event(time(), 'daily', 'kiyoh_cleanup_cache');
        }
    }
}