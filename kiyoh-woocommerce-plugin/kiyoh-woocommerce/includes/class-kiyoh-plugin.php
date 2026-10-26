<?php

/**
 * The file that defines the core plugin class
 *
 * A class definition that includes attributes and functions used across both the
 * public-facing side of the site and the admin area.
 */

/**
 * The core plugin class.
 *
 * This is used to define internationalization, admin-specific hooks, and
 * public-facing site hooks.
 *
 * Also maintains the unique identifier of this plugin as well as the current
 * version of the plugin.
 */
class Kiyoh_Plugin {

    /**
     * The loader that's responsible for maintaining and registering all hooks that power
     * the plugin.
     */
    protected $loader;

    /**
     * The unique identifier of this plugin.
     */
    protected $plugin_name;

    /**
     * The current version of the plugin.
     */
    protected $version;

    /**
     * The product sync manager instance.
     */
    protected $product_sync_manager;

    /**
     * The invitation manager instance.
     */
    protected $invitation_manager;

    /**
     * The rating manager instance.
     */
    protected $rating_manager;

    /**
     * Define the core functionality of the plugin.
     */
    public function __construct() {
        if (defined('KIYOH_WOOCOMMERCE_VERSION')) {
            $this->version = KIYOH_WOOCOMMERCE_VERSION;
        } else {
            $this->version = '1.4.0';
        }
        $this->plugin_name = 'kiyoh-woocommerce';

        $this->load_dependencies();
        $this->set_locale();
        $this->define_admin_hooks();
        $this->init_managers();
    }

    /**
     * Load the required dependencies for this plugin.
     */
    private function load_dependencies() {
        /**
         * The class responsible for orchestrating the actions and filters of the
         * core plugin.
         */
        require_once KIYOH_WOOCOMMERCE_PLUGIN_DIR . 'includes/class-kiyoh-loader.php';

        /**
         * The class responsible for defining internationalization functionality
         * of the plugin.
         */
        require_once KIYOH_WOOCOMMERCE_PLUGIN_DIR . 'includes/class-kiyoh-i18n.php';

        /**
         * The class responsible for defining all actions that occur in the admin area.
         */
        require_once KIYOH_WOOCOMMERCE_PLUGIN_DIR . 'admin/class-kiyoh-admin.php';



        /**
         * API classes for Kiyoh integration
         */
        require_once KIYOH_WOOCOMMERCE_PLUGIN_DIR . 'includes/api/interface-kiyoh-api.php';
        require_once KIYOH_WOOCOMMERCE_PLUGIN_DIR . 'includes/api/class-kiyoh-api-response.php';
        require_once KIYOH_WOOCOMMERCE_PLUGIN_DIR . 'includes/api/class-kiyoh-api-client.php';
        require_once KIYOH_WOOCOMMERCE_PLUGIN_DIR . 'includes/api/class-kiyoh-api-factory.php';

        /**
         * Manager classes for handling business logic
         */
        require_once KIYOH_WOOCOMMERCE_PLUGIN_DIR . 'includes/managers/class-product-sync-manager.php';
        require_once KIYOH_WOOCOMMERCE_PLUGIN_DIR . 'includes/managers/class-invitation-schedule.php';
        require_once KIYOH_WOOCOMMERCE_PLUGIN_DIR . 'includes/managers/class-invitation-manager.php';
        require_once KIYOH_WOOCOMMERCE_PLUGIN_DIR . 'includes/managers/class-rating-manager.php';
        require_once KIYOH_WOOCOMMERCE_PLUGIN_DIR . 'includes/class-kiyoh-db.php';
        require_once KIYOH_WOOCOMMERCE_PLUGIN_DIR . 'includes/class-kiyoh-mobile-health.php';
        require_once KIYOH_WOOCOMMERCE_PLUGIN_DIR . 'includes/class-kiyoh-mobile-health-reporter.php';
        require_once KIYOH_WOOCOMMERCE_PLUGIN_DIR . 'includes/class-kiyoh-connect.php';
        require_once KIYOH_WOOCOMMERCE_PLUGIN_DIR . 'includes/class-kiyoh-connect-session.php';

        $this->loader = new Kiyoh_Loader();
    }

    /**
     * Define the locale for this plugin for internationalization.
     */
    private function set_locale() {
        $plugin_i18n = new Kiyoh_i18n();

        $this->loader->add_action('plugins_loaded', $plugin_i18n, 'load_plugin_textdomain');
    }

    /**
     * Register all of the hooks related to the admin area functionality
     * of the plugin.
     */
    private function define_admin_hooks() {
        $plugin_admin = new Kiyoh_Admin($this->get_plugin_name(), $this->get_version());

        $this->loader->add_action('admin_enqueue_scripts', $plugin_admin, 'enqueue_styles');
        $this->loader->add_action('admin_enqueue_scripts', $plugin_admin, 'enqueue_scripts');
        $this->loader->add_action('admin_menu', $plugin_admin, 'add_plugin_admin_menu');
        $this->loader->add_action('admin_init', $plugin_admin, 'register_settings');
        
        // Add cron hooks
        $this->loader->add_action('kiyoh_process_scheduled_invitations', $this, 'process_scheduled_invitations');
        $this->loader->add_action('kiyoh_cleanup_cache', $this, 'cleanup_cache');

        $health_reporter = Kiyoh_Mobile_Health_Reporter::instance();
        $this->loader->add_action('init', $health_reporter, 'ensure_cron');
        $this->loader->add_action(Kiyoh_Mobile_Health::CRON_HOOK, $health_reporter, 'on_daily_cron');
        $this->loader->add_action('update_option_kiyoh_woocommerce_settings', $health_reporter, 'on_settings_updated', 10, 3);
        $this->loader->add_action('add_option_kiyoh_woocommerce_settings', $health_reporter, 'on_settings_added', 10, 2);

        $this->loader->add_action('rest_api_init', 'Kiyoh_Connect_Session', 'register_routes');
        $this->loader->add_action('wp_ajax_kiyoh_connect_start', 'Kiyoh_Connect_Session', 'handle_start');
        $this->loader->add_action('wp_ajax_kiyoh_connect_disconnect', 'Kiyoh_Connect_Session', 'handle_disconnect');
    }

    /**
     * Initialize manager classes for handling business logic.
     */
    private function init_managers() {
        // Only initialize managers if WooCommerce is active
        if (class_exists('WooCommerce')) {
            $this->product_sync_manager = new Kiyoh_Product_Sync_Manager();
            $this->invitation_manager = new Kiyoh_Invitation_Manager();
            $this->rating_manager = new Kiyoh_Rating_Manager();
        }
    }



    /**
     * Run the loader to execute all of the hooks with WordPress.
     */
    public function run() {
        $this->loader->run();
    }

    /**
     * The name of the plugin used to uniquely identify it within the context of
     * WordPress and to define internationalization functionality.
     */
    public function get_plugin_name() {
        return $this->plugin_name;
    }

    /**
     * The reference to the class that orchestrates the hooks with the plugin.
     */
    public function get_loader() {
        return $this->loader;
    }

    /**
     * Retrieve the version number of the plugin.
     */
    public function get_version() {
        return $this->version;
    }

    /**
     * Hourly fallback for invitations queued for a specific send time.
     * Immediate invitations never go through this hook.
     */
    public function process_scheduled_invitations() {
        if (!class_exists('WooCommerce') || !$this->invitation_manager) {
            return;
        }

        $this->invitation_manager->process_due_scheduled_invitations();
    }

    /**
     * Cleanup expired cache entries via cron
     */
    public function cleanup_cache() {
        global $wpdb;

        $cache_table = Kiyoh_Db::table($wpdb->prefix, 'kiyoh_review_cache');
        if ($cache_table !== '') {
            $wpdb->query('DELETE FROM ' . $cache_table . ' WHERE expires_at < NOW()');
        }

        $invitations_table = Kiyoh_Db::table($wpdb->prefix, 'kiyoh_invitations');
        if ($invitations_table !== '') {
            $wpdb->query('DELETE FROM ' . $invitations_table . ' WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)');
        }

        $sync_table = Kiyoh_Db::table($wpdb->prefix, 'kiyoh_product_sync');
        if ($sync_table !== '') {
            $sql = $wpdb->prepare(
                'DELETE FROM ' . $sync_table . ' WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY) AND sync_status = %s',
                'success'
            );
            if (is_string($sql)) {
                $wpdb->query($sql);
            }
        }
    }
}