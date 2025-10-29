<?php

class Kiyoh_Admin {

    private $plugin_name;
    private $version;

    public function __construct($plugin_name, $version) {
        $this->plugin_name = $plugin_name;
        $this->version = $version;
        
        // Add AJAX handlers
        add_action('wp_ajax_kiyoh_bulk_sync', array($this, 'ajax_bulk_sync'));
        add_action('wp_ajax_kiyoh_bulk_sync_products', array($this, 'ajax_bulk_sync_products'));
        add_action('wp_ajax_kiyoh_test_api_connection', array($this, 'ajax_test_api_connection'));
    }

    public function enqueue_styles() {
        wp_enqueue_style($this->plugin_name, KIYOH_WOOCOMMERCE_PLUGIN_URL . 'admin/css/kiyoh-admin.css', array(), $this->version, 'all');
    }

    public function enqueue_scripts() {
        wp_enqueue_script($this->plugin_name, KIYOH_WOOCOMMERCE_PLUGIN_URL . 'admin/js/kiyoh-admin.js', array('jquery'), $this->version, false);
        
        // Localize script for AJAX
        wp_localize_script($this->plugin_name, 'kiyoh_admin_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('kiyoh_admin_nonce'),
            'strings' => array(
                'syncing' => __('Syncing...', 'kiyoh-woocommerce'),
                'clearing' => __('Clearing...', 'kiyoh-woocommerce'),
                'error' => __('Error occurred', 'kiyoh-woocommerce'),
                'success' => __('Success', 'kiyoh-woocommerce')
            )
        ));
    }

    public function add_plugin_admin_menu() {
        add_submenu_page(
            'woocommerce',
            __('Kiyoh Settings', 'kiyoh-woocommerce'),
            __('Kiyoh', 'kiyoh-woocommerce'),
            'manage_woocommerce',
            'kiyoh-settings',
            array($this, 'display_plugin_admin_page')
        );
    }

    public function display_plugin_admin_page() {
        include_once KIYOH_WOOCOMMERCE_PLUGIN_DIR . 'admin/partials/kiyoh-admin-display.php';
    }

    public function register_settings() {
        register_setting(
            'kiyoh_woocommerce_settings',
            'kiyoh_woocommerce_settings',
            array($this, 'sanitize_settings')
        );

        // General Settings Section
        add_settings_section(
            'kiyoh_general_section',
            __('General Settings', 'kiyoh-woocommerce'),
            array($this, 'general_section_callback'),
            'kiyoh-settings'
        );

        // Product Sync Settings Section
        add_settings_section(
            'kiyoh_product_sync_section',
            __('Product Sync Settings', 'kiyoh-woocommerce'),
            array($this, 'product_sync_section_callback'),
            'kiyoh-settings'
        );

        // Invitation Settings Section
        add_settings_section(
            'kiyoh_invitation_section',
            __('Review Invitation Settings', 'kiyoh-woocommerce'),
            array($this, 'invitation_section_callback'),
            'kiyoh-settings'
        );

        $this->register_general_fields();
        $this->register_product_sync_fields();
        $this->register_invitation_fields();
    }

    public function general_section_callback() {
        echo '<p>' . __('Configure your Kiyoh/Klantenvertellen account connection.', 'kiyoh-woocommerce') . '</p>';
    }

    public function product_sync_section_callback() {
        echo '<p>' . __('Configure how products are synchronized with your review platform.', 'kiyoh-woocommerce') . '</p>';
    }

    public function invitation_section_callback() {
        echo '<p>' . __('Configure when and how review invitations are sent to customers.', 'kiyoh-woocommerce') . '</p>';
    }



    private function register_general_fields() {
        add_settings_field(
            'enabled',
            __('Enable Plugin', 'kiyoh-woocommerce'),
            array($this, 'checkbox_field_callback'),
            'kiyoh-settings',
            'kiyoh_general_section',
            array(
                'name' => 'kiyoh_woocommerce_settings[general][enabled]',
                'value' => $this->get_option('general', 'enabled', false),
                'description' => __('Enable Kiyoh WooCommerce integration', 'kiyoh-woocommerce')
            )
        );

        add_settings_field(
            'platform',
            __('Platform', 'kiyoh-woocommerce'),
            array($this, 'select_field_callback'),
            'kiyoh-settings',
            'kiyoh_general_section',
            array(
                'name' => 'kiyoh_woocommerce_settings[general][platform]',
                'value' => $this->get_option('general', 'platform', 'kiyoh'),
                'options' => array(
                    'kiyoh' => __('Kiyoh.com', 'kiyoh-woocommerce'),
                    'klantenvertellen' => __('Klantenvertellen.nl', 'kiyoh-woocommerce')
                ),
                'description' => __('Select your review platform', 'kiyoh-woocommerce')
            )
        );

        add_settings_field(
            'location_id',
            __('Location ID', 'kiyoh-woocommerce'),
            array($this, 'text_field_callback'),
            'kiyoh-settings',
            'kiyoh_general_section',
            array(
                'name' => 'kiyoh_woocommerce_settings[general][location_id]',
                'value' => $this->get_option('general', 'location_id', ''),
                'description' => __('Your location ID from your Kiyoh/Klantenvertellen account', 'kiyoh-woocommerce')
            )
        );

        add_settings_field(
            'api_key',
            __('API Key', 'kiyoh-woocommerce'),
            array($this, 'password_field_callback'),
            'kiyoh-settings',
            'kiyoh_general_section',
            array(
                'name' => 'kiyoh_woocommerce_settings[general][api_key]',
                'value' => $this->get_option('general', 'api_key', ''),
                'description' => __('Your API key from your Kiyoh/Klantenvertellen account', 'kiyoh-woocommerce')
            )
        );
    }

    private function register_product_sync_fields() {
        add_settings_field(
            'auto_sync',
            __('Auto Sync Products', 'kiyoh-woocommerce'),
            array($this, 'checkbox_field_callback'),
            'kiyoh-settings',
            'kiyoh_product_sync_section',
            array(
                'name' => 'kiyoh_woocommerce_settings[product_sync][auto_sync]',
                'value' => $this->get_option('product_sync', 'auto_sync', true),
                'description' => __('Automatically sync products when they are created or updated', 'kiyoh-woocommerce')
            )
        );

        add_settings_field(
            'excluded_types',
            __('Excluded Product Types', 'kiyoh-woocommerce'),
            array($this, 'multiselect_field_callback'),
            'kiyoh-settings',
            'kiyoh_product_sync_section',
            array(
                'name' => 'kiyoh_woocommerce_settings[product_sync][excluded_types]',
                'value' => $this->get_option('product_sync', 'excluded_types', array()),
                'options' => $this->get_product_types(),
                'description' => __('Select product types to exclude from synchronization', 'kiyoh-woocommerce')
            )
        );

        add_settings_field(
            'excluded_codes',
            __('Excluded Product Codes', 'kiyoh-woocommerce'),
            array($this, 'textarea_field_callback'),
            'kiyoh-settings',
            'kiyoh_product_sync_section',
            array(
                'name' => 'kiyoh_woocommerce_settings[product_sync][excluded_codes]',
                'value' => $this->get_option('product_sync', 'excluded_codes', ''),
                'description' => __('Enter product codes to exclude (one per line)', 'kiyoh-woocommerce')
            )
        );
    }

    private function register_invitation_fields() {
        add_settings_field(
            'trigger_status',
            __('Trigger Order Status', 'kiyoh-woocommerce'),
            array($this, 'multiselect_field_callback'),
            'kiyoh-settings',
            'kiyoh_invitation_section',
            array(
                'name' => 'kiyoh_woocommerce_settings[invitations][trigger_status]',
                'value' => $this->get_option('invitations', 'trigger_status', array('completed')),
                'options' => $this->get_order_statuses(),
                'description' => __('Select order statuses that trigger review invitations', 'kiyoh-woocommerce')
            )
        );

        add_settings_field(
            'delay_days',
            __('Invitation Delay (Days)', 'kiyoh-woocommerce'),
            array($this, 'number_field_callback'),
            'kiyoh-settings',
            'kiyoh_invitation_section',
            array(
                'name' => 'kiyoh_woocommerce_settings[invitations][delay_days]',
                'value' => $this->get_option('invitations', 'delay_days', 3),
                'min' => 0,
                'max' => 365,
                'description' => __('Number of days to wait before sending invitation (0 for immediate)', 'kiyoh-woocommerce')
            )
        );

        add_settings_field(
            'invitation_type',
            __('Invitation Type', 'kiyoh-woocommerce'),
            array($this, 'select_field_callback'),
            'kiyoh-settings',
            'kiyoh_invitation_section',
            array(
                'name' => 'kiyoh_woocommerce_settings[invitations][invitation_type]',
                'value' => $this->get_option('invitations', 'invitation_type', 'shop_and_product'),
                'options' => array(
                    'shop_and_product' => __('Shop + Product Reviews', 'kiyoh-woocommerce'),
                    'shop_only' => __('Shop Reviews Only', 'kiyoh-woocommerce'),
                    'product_only' => __('Product Reviews Only', 'kiyoh-woocommerce')
                ),
                'description' => __('Choose what type of reviews to request in invitations', 'kiyoh-woocommerce')
            )
        );

        add_settings_field(
            'max_products',
            __('Max Products per Invitation', 'kiyoh-woocommerce'),
            array($this, 'number_field_callback'),
            'kiyoh-settings',
            'kiyoh_invitation_section',
            array(
                'name' => 'kiyoh_woocommerce_settings[invitations][max_products]',
                'value' => $this->get_option('invitations', 'max_products', 5),
                'min' => 1,
                'max' => 10,
                'description' => __('Maximum number of products to include in each invitation', 'kiyoh-woocommerce')
            )
        );

        add_settings_field(
            'product_sort_order',
            __('Product Sort Order', 'kiyoh-woocommerce'),
            array($this, 'select_field_callback'),
            'kiyoh-settings',
            'kiyoh_invitation_section',
            array(
                'name' => 'kiyoh_woocommerce_settings[invitations][product_sort_order]',
                'value' => $this->get_option('invitations', 'product_sort_order', 'price_desc'),
                'options' => array(
                    'price_desc' => __('Price: High to Low', 'kiyoh-woocommerce'),
                    'price_asc' => __('Price: Low to High', 'kiyoh-woocommerce'),
                    'quantity_desc' => __('Quantity: High to Low', 'kiyoh-woocommerce'),
                    'quantity_asc' => __('Quantity: Low to High', 'kiyoh-woocommerce'),
                    'order_added' => __('Order Added (First to Last)', 'kiyoh-woocommerce'),
                    'alphabetical' => __('Product Name (A-Z)', 'kiyoh-woocommerce'),
                    'random' => __('Random Selection', 'kiyoh-woocommerce')
                ),
                'description' => __('How to prioritize products when there are more products than the maximum allowed', 'kiyoh-woocommerce')
            )
        );

        add_settings_field(
            'excluded_customer_groups',
            __('Excluded Customer Groups', 'kiyoh-woocommerce'),
            array($this, 'multiselect_field_callback'),
            'kiyoh-settings',
            'kiyoh_invitation_section',
            array(
                'name' => 'kiyoh_woocommerce_settings[invitations][excluded_customer_groups]',
                'value' => $this->get_option('invitations', 'excluded_customer_groups', array()),
                'options' => $this->get_customer_groups(),
                'description' => __('Select customer groups to exclude from invitations', 'kiyoh-woocommerce')
            )
        );

        add_settings_field(
            'language_auto',
            __('Auto-detect Language', 'kiyoh-woocommerce'),
            array($this, 'checkbox_field_callback'),
            'kiyoh-settings',
            'kiyoh_invitation_section',
            array(
                'name' => 'kiyoh_woocommerce_settings[invitations][language_auto]',
                'value' => $this->get_option('invitations', 'language_auto', true),
                'description' => __('Automatically detect customer language from WordPress locale', 'kiyoh-woocommerce')
            )
        );

        add_settings_field(
            'fallback_language',
            __('Fallback Language', 'kiyoh-woocommerce'),
            array($this, 'select_field_callback'),
            'kiyoh-settings',
            'kiyoh_invitation_section',
            array(
                'name' => 'kiyoh_woocommerce_settings[invitations][fallback_language]',
                'value' => $this->get_option('invitations', 'fallback_language', 'en'),
                'options' => array(
                    'en' => 'English',
                    'nl' => 'Dutch (Nederlands)',
                    'de' => 'German (Deutsch)',
                    'fr' => 'French (Français)',
                    'es' => 'Spanish (Español)',
                    'it' => 'Italian (Italiano)',
                    'pt' => 'Portuguese (Português)',
                    'da' => 'Danish (Dansk)',
                    'sv' => 'Swedish (Svenska)',
                    'no' => 'Norwegian (Norsk)',
                    'fi' => 'Finnish (Suomi)',
                    'pl' => 'Polish (Polski)',
                    'cs' => 'Czech (Čeština)',
                    'sk' => 'Slovak (Slovenčina)',
                    'hu' => 'Hungarian (Magyar)',
                    'ro' => 'Romanian (Română)',
                    'bg' => 'Bulgarian (Български)',
                    'hr' => 'Croatian (Hrvatski)',
                    'sl' => 'Slovenian (Slovenščina)',
                    'et' => 'Estonian (Eesti)',
                    'lv' => 'Latvian (Latviešu)',
                    'lt' => 'Lithuanian (Lietuvių)',
                    'ru' => 'Russian (Русский)',
                    'tr' => 'Turkish (Türkçe)',
                    'gr' => 'Greek (Ελληνικά)',
                    'zh' => 'Chinese (中文)',
                    'ja' => 'Japanese (日本語)'
                ),
                'description' => __('Language to use when customer language cannot be detected', 'kiyoh-woocommerce')
            )
        );
    }



    public function text_field_callback($args) {
        printf(
            '<input type="text" id="%s" name="%s" value="%s" class="regular-text" />',
            esc_attr($args['name']),
            esc_attr($args['name']),
            esc_attr($args['value'])
        );
        if (!empty($args['description'])) {
            printf('<p class="description">%s</p>', esc_html($args['description']));
        }
    }

    public function password_field_callback($args) {
        printf(
            '<input type="password" id="%s" name="%s" value="%s" class="regular-text" />',
            esc_attr($args['name']),
            esc_attr($args['name']),
            esc_attr($args['value'])
        );
        if (!empty($args['description'])) {
            printf('<p class="description">%s</p>', esc_html($args['description']));
        }
    }

    public function number_field_callback($args) {
        printf(
            '<input type="number" id="%s" name="%s" value="%s" min="%s" max="%s" class="small-text" />',
            esc_attr($args['name']),
            esc_attr($args['name']),
            esc_attr($args['value']),
            esc_attr($args['min']),
            esc_attr($args['max'])
        );
        if (!empty($args['description'])) {
            printf('<p class="description">%s</p>', esc_html($args['description']));
        }
    }

    public function checkbox_field_callback($args) {
        printf(
            '<input type="checkbox" id="%s" name="%s" value="1" %s />',
            esc_attr($args['name']),
            esc_attr($args['name']),
            checked($args['value'], true, false)
        );
        if (!empty($args['description'])) {
            printf('<p class="description">%s</p>', esc_html($args['description']));
        }
    }

    public function select_field_callback($args) {
        printf('<select id="%s" name="%s">', esc_attr($args['name']), esc_attr($args['name']));
        foreach ($args['options'] as $value => $label) {
            printf(
                '<option value="%s" %s>%s</option>',
                esc_attr($value),
                selected($args['value'], $value, false),
                esc_html($label)
            );
        }
        echo '</select>';
        if (!empty($args['description'])) {
            printf('<p class="description">%s</p>', esc_html($args['description']));
        }
    }

    public function multiselect_field_callback($args) {
        printf('<select id="%s" name="%s[]" multiple="multiple" size="5">', esc_attr($args['name']), esc_attr($args['name']));
        foreach ($args['options'] as $value => $label) {
            $selected = in_array($value, (array) $args['value']) ? 'selected="selected"' : '';
            printf(
                '<option value="%s" %s>%s</option>',
                esc_attr($value),
                $selected,
                esc_html($label)
            );
        }
        echo '</select>';
        if (!empty($args['description'])) {
            printf('<p class="description">%s</p>', esc_html($args['description']));
        }
    }

    public function textarea_field_callback($args) {
        printf(
            '<textarea id="%s" name="%s" rows="5" cols="50" class="large-text">%s</textarea>',
            esc_attr($args['name']),
            esc_attr($args['name']),
            esc_textarea($args['value'])
        );
        if (!empty($args['description'])) {
            printf('<p class="description">%s</p>', esc_html($args['description']));
        }
    }

    public function sanitize_settings($input) {
        $sanitized = array();

        if (isset($input['general'])) {
            $sanitized['general'] = array(
                'enabled' => !empty($input['general']['enabled']),
                'platform' => sanitize_text_field($input['general']['platform'] ?? ''),
                'location_id' => sanitize_text_field($input['general']['location_id'] ?? ''),
                'api_key' => sanitize_text_field($input['general']['api_key'] ?? '')
            );
        }

        if (isset($input['product_sync'])) {
            $sanitized['product_sync'] = array(
                'auto_sync' => !empty($input['product_sync']['auto_sync']),
                'excluded_types' => array_map('sanitize_text_field', (array) ($input['product_sync']['excluded_types'] ?? array())),
                'excluded_codes' => sanitize_textarea_field($input['product_sync']['excluded_codes'] ?? '')
            );
        }

        if (isset($input['invitations'])) {
            $sanitized['invitations'] = array(
                'trigger_status' => array_map('sanitize_text_field', (array) ($input['invitations']['trigger_status'] ?? array())),
                'delay_days' => absint($input['invitations']['delay_days'] ?? 0),
                'invitation_type' => sanitize_text_field($input['invitations']['invitation_type'] ?? ''),
                'max_products' => absint($input['invitations']['max_products'] ?? 0),
                'product_sort_order' => sanitize_text_field($input['invitations']['product_sort_order'] ?? ''),
                'excluded_customer_groups' => array_map('sanitize_text_field', (array) ($input['invitations']['excluded_customer_groups'] ?? array())),
                'language_auto' => !empty($input['invitations']['language_auto']),
                'fallback_language' => sanitize_text_field($input['invitations']['fallback_language'] ?? 'en')
            );
        }



        return $sanitized;
    }

    private function get_option($section, $key, $default = null) {
        $settings = get_option('kiyoh_woocommerce_settings', array());
        return isset($settings[$section][$key]) ? $settings[$section][$key] : $default;
    }

    private function get_product_types() {
        if (!function_exists('wc_get_product_types')) {
            return array();
        }
        return wc_get_product_types();
    }

    private function get_order_statuses() {
        if (!function_exists('wc_get_order_statuses')) {
            return array(
                'completed' => __('Completed', 'kiyoh-woocommerce'),
                'processing' => __('Processing', 'kiyoh-woocommerce')
            );
        }
        return wc_get_order_statuses();
    }

    private function get_customer_groups() {
        global $wp_roles;
        
        if (!isset($wp_roles)) {
            $wp_roles = new WP_Roles();
        }
        
        $roles = array();
        foreach ($wp_roles->roles as $role_key => $role) {
            $roles[$role_key] = $role['name'];
        }
        
        return $roles;
    }



    /**
     * AJAX handler for bulk product sync
     */
    public function ajax_bulk_sync() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'kiyoh_admin_nonce')) {
            wp_die('Security check failed');
        }

        // Check user capabilities
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Insufficient permissions');
        }

        // Initialize product sync manager
        $sync_manager = new Kiyoh_Product_Sync_Manager();
        
        // Run bulk sync
        $results = $sync_manager->bulk_sync_products();

        $message = sprintf(
            __('Bulk sync completed! Synced: %d, Errors: %d, Skipped: %d out of %d total products.', 'kiyoh-woocommerce'),
            $results['synced'],
            $results['errors'],
            $results['skipped'],
            $results['total']
        );

        wp_send_json_success(array(
            'message' => $message,
            'data' => $results
        ));
    }

    /**
     * AJAX handler for batch product sync (used by JavaScript for progress tracking)
     */
    public function ajax_bulk_sync_products() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'kiyoh_admin_nonce')) {
            wp_die('Security check failed');
        }

        // Check user capabilities
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Insufficient permissions');
        }

        // Initialize product sync manager and delegate to it
        $sync_manager = new Kiyoh_Product_Sync_Manager();
        $sync_manager->ajax_bulk_sync_products();
    }

    /**
     * AJAX handler for testing API connection
     */
    public function ajax_test_api_connection() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'kiyoh_admin_nonce')) {
            wp_die('Security check failed');
        }

        // Check user capabilities
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Insufficient permissions');
        }

        $result = $this->test_api_connection();
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * Test API connection by fetching company stats
     */
    public static function test_api_connection() {
        try {
            if (!Kiyoh_Api_Factory::is_configured()) {
                return array(
                    'success' => false,
                    'message' => 'API not configured. Please set API token and location ID.'
                );
            }

            $client = Kiyoh_Api_Factory::create_client();
            $response = $client->get_company_stats();

            if ($response->is_success()) {
                $data = $response->get_data();
                return array(
                    'success' => true,
                    'message' => 'API connection successful',
                    'stats' => $data
                );
            } else {
                return array(
                    'success' => false,
                    'message' => 'API connection failed: ' . $response->get_error(),
                    'error_code' => $response->get_error_code()
                );
            }
        } catch (Exception $e) {
            return array(
                'success' => false,
                'message' => 'Test failed: ' . $e->getMessage()
            );
        }
    }

}