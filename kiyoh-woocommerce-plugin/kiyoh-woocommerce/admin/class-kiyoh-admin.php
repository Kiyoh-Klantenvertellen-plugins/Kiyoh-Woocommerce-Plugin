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
        add_action('wp_ajax_kiyoh_sync_product_ratings', array($this, 'ajax_sync_product_ratings'));
        add_action('wp_ajax_kiyoh_rating_sync_status', array($this, 'ajax_rating_sync_status'));
    }

    /**
     * Whether the current admin screen is the Kiyoh settings page.
     *
     * Assets must not load on other WooCommerce/admin screens: the admin JS
     * binds to .nav-tab and validates credential fields that only exist here.
     *
     * @param string $hook Current admin page hook from admin_enqueue_scripts.
     */
    private function is_kiyoh_settings_page($hook) {
        if ($hook === 'woocommerce_page_kiyoh-settings') {
            return true;
        }

        return isset($_GET['page']) && $_GET['page'] === 'kiyoh-settings'; // preflight: ignore — compared to a fixed page slug, not printed
    }

    public function enqueue_styles($hook) {
        if (!$this->is_kiyoh_settings_page($hook)) {
            return;
        }

        wp_enqueue_style($this->plugin_name, KIYOH_WOOCOMMERCE_PLUGIN_URL . 'admin/css/kiyoh-admin.css', array(), $this->version, 'all');
    }

    public function enqueue_scripts($hook) {
        if (!$this->is_kiyoh_settings_page($hook)) {
            return;
        }

        wp_enqueue_script($this->plugin_name, KIYOH_WOOCOMMERCE_PLUGIN_URL . 'admin/js/kiyoh-admin.js', array('jquery'), $this->version, false);
        
        // Localize script for AJAX
        $rating_sync = array(
            'status' => 'idle',
            'active' => false,
            'processed' => 0,
            'total' => 0,
            'percent' => 0,
            'message' => '',
            'error' => '',
            'last_sync' => ''
        );
        if (class_exists('Kiyoh_Rating_Manager')) {
            $rating_manager = new Kiyoh_Rating_Manager();
            $rating_sync = $rating_manager->get_public_sync_status();
        }

        wp_localize_script($this->plugin_name, 'kiyoh_admin_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('kiyoh_admin_nonce'),
            'rating_sync' => $rating_sync,
            'strings' => array(
                'syncing' => __('Syncing...', 'kiyoh-woocommerce'),
                'syncing_progress' => __('Syncing ratings… %1$d of %2$d', 'kiyoh-woocommerce'),
                'sync_queued' => __('Rating sync queued…', 'kiyoh-woocommerce'),
                'clearing' => __('Clearing...', 'kiyoh-woocommerce'),
                'error' => __('Error occurred', 'kiyoh-woocommerce'),
                'success' => __('Success', 'kiyoh-woocommerce'),
                'last_sync' => __('Last sync: %s', 'kiyoh-woocommerce')
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

        add_settings_field(
            'mobile_urls',
            __('Kiyoh Mobile URL(s)', 'kiyoh-woocommerce'),
            array($this, 'text_field_callback'),
            'kiyoh-settings',
            'kiyoh_general_section',
            array(
                'name' => 'kiyoh_woocommerce_settings[general][mobile_urls]',
                'value' => $this->get_option('general', 'mobile_urls', ''),
                'description' => __('Comma-separated Kiyoh Mobile base URLs for shop health reports. Leave blank to report only to https://kiyoh-mobile.com. Testers: enter https://kiyohmobileui-staging.up.railway.app.', 'kiyoh-woocommerce')
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
            'send_parent_product_code',
            __('Send parent product code for variations', 'kiyoh-woocommerce'),
            array($this, 'checkbox_field_callback'),
            'kiyoh-settings',
            'kiyoh_product_sync_section',
            array(
                'name' => 'kiyoh_woocommerce_settings[product_sync][send_parent_product_code]',
                'value' => $this->get_option('product_sync', 'send_parent_product_code', false),
                'description' => __('Off by default. When on, invitations and product sync use the parent variable product and its SKU. A parent with no SKU is skipped.', 'kiyoh-woocommerce')
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
                'description' => __('Number of days to wait before sending the invitation. With a preferred send time, the delay passes first and the invite goes out at the next allowed time. Otherwise Kiyoh receives this delay and sends the email.', 'kiyoh-woocommerce')
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
            $existing_settings = get_option('kiyoh_woocommerce_settings', array());
            $previous_urls = '';
            if (is_array($existing_settings) && isset($existing_settings['general']['mobile_urls'])) {
                $previous_urls = $existing_settings['general']['mobile_urls'];
            }
            $mobile_urls = $previous_urls;
            if (is_array($input['general']) && array_key_exists('mobile_urls', $input['general'])) {
                $mobile_urls = sanitize_text_field($input['general']['mobile_urls']);
            }

            $new_location = sanitize_text_field($input['general']['location_id'] ?? '');
            $new_key = sanitize_text_field($input['general']['api_key'] ?? '');
            $credential_touch = sanitize_text_field($input['general']['credential_touch'] ?? '');
            $old_location = '';
            $old_key = '';
            $old_source = 'manual';
            if (is_array($existing_settings) && isset($existing_settings['general']) && is_array($existing_settings['general'])) {
                $old_location = (string) ($existing_settings['general']['location_id'] ?? '');
                $old_key = (string) ($existing_settings['general']['api_key'] ?? '');
                $old_source = (($existing_settings['general']['credential_source'] ?? '') === 'connect') ? 'connect' : 'manual';
            }
            $apply_mode = class_exists('Kiyoh_Connect_Session') ? Kiyoh_Connect_Session::apply_mode() : '';
            if ($apply_mode === 'connect') {
                $source = 'connect';
            } elseif ($apply_mode === 'disconnect') {
                $source = 'manual';
            } elseif ($credential_touch === 'manual' || $new_location !== $old_location || $new_key !== $old_key) {
                $source = 'manual';
                if ($old_source === 'connect' && class_exists('Kiyoh_Connect_Session')) {
                    Kiyoh_Connect_Session::on_manual_override();
                }
            } else {
                $source = $old_source;
            }
            $platform = sanitize_text_field($input['general']['platform'] ?? '');
            if (!in_array($platform, array('kiyoh', 'klantenvertellen'), true)) {
                $platform = 'kiyoh';
            }

            $sanitized['general'] = array(
                'enabled' => !empty($input['general']['enabled']),
                'platform' => $platform,
                'location_id' => $new_location,
                'api_key' => $new_key,
                'mobile_urls' => $mobile_urls,
                'credential_source' => $source,
            );
        }

        if (isset($input['product_sync'])) {
            $sanitized['product_sync'] = array(
                'auto_sync' => !empty($input['product_sync']['auto_sync']),
                'send_parent_product_code' => !empty($input['product_sync']['send_parent_product_code']),
                'excluded_types' => array_map('sanitize_text_field', (array) ($input['product_sync']['excluded_types'] ?? array())),
                'excluded_codes' => sanitize_textarea_field($input['product_sync']['excluded_codes'] ?? '')
            );
        }

        if (isset($input['invitations'])) {
            $raw_send_time = isset($input['invitations']['send_time']) ? sanitize_text_field($input['invitations']['send_time']) : '';
            $raw_weekdays = isset($input['invitations']['send_weekdays']) ? $input['invitations']['send_weekdays'] : array();

            $sanitized['invitations'] = array(
                'trigger_status' => array_map('sanitize_text_field', (array) ($input['invitations']['trigger_status'] ?? array())),
                'delay_days' => absint($input['invitations']['delay_days'] ?? 0),
                'send_time_enabled' => !empty($input['invitations']['send_time_enabled']),
                'send_time' => class_exists('Kiyoh_Invitation_Schedule') ? Kiyoh_Invitation_Schedule::normalize_send_time($raw_send_time) : '',
                'send_weekdays' => class_exists('Kiyoh_Invitation_Schedule') ? Kiyoh_Invitation_Schedule::sanitize_weekdays($raw_weekdays) : array(),
                'invitation_type' => sanitize_text_field($input['invitations']['invitation_type'] ?? ''),
                'max_products' => absint($input['invitations']['max_products'] ?? 0),
                'product_sort_order' => sanitize_text_field($input['invitations']['product_sort_order'] ?? ''),
                'excluded_customer_groups' => array_map('sanitize_text_field', (array) ($input['invitations']['excluded_customer_groups'] ?? array())),
                'language_auto' => !empty($input['invitations']['language_auto']),
                'fallback_language' => sanitize_text_field($input['invitations']['fallback_language'] ?? 'en')
            );
        }

        if (isset($input['ratings'])) {
            $source = isset($input['ratings']['source']) ? sanitize_text_field($input['ratings']['source']) : 'kiyoh';
            if (!in_array($source, array('kiyoh', 'woocommerce', 'both'), true)) {
                $source = 'kiyoh';
            }

            $sanitized['ratings'] = array(
                'enabled' => ($source !== 'woocommerce'),
                'source' => $source
            );
        }

        $existing = get_option('kiyoh_woocommerce_settings', array());
        if (is_array($existing)) {
            foreach ($existing as $section => $values) {
                if (!isset($sanitized[$section])) {
                    $sanitized[$section] = $values;
                }
            }
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
        if (!wp_verify_nonce($_POST['nonce'], 'kiyoh_admin_nonce')) { // preflight: ignore — nonce is verified here; manage_woocommerce is checked next
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

        // Collect verbose, de-duplicated error details for the admin panel so a
        // failed sync explains WHY (e.g. an API validation or auth error) instead
        // of only showing an error count. Each entry now contains the FULL raw
        // API error response (detailedError + raw body), so we cap the list at 3
        // to keep the notice readable while still exposing what really happened.
        $error_messages = isset($results['error_messages']) && is_array($results['error_messages'])
            ? array_values(array_unique($results['error_messages']))
            : array();
        $error_messages = array_slice($error_messages, 0, 3);

        wp_send_json_success(array(
            'message'        => $message,
            'has_errors'     => ($results['errors'] > 0),
            'error_messages' => $error_messages,
            'data'           => $results
        ));
    }

    /**
     * AJAX handler for batch product sync (used by JavaScript for progress tracking)
     */
    public function ajax_bulk_sync_products() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'kiyoh_admin_nonce')) { // preflight: ignore — nonce is verified here; manage_woocommerce is checked next
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
        if (!wp_verify_nonce($_POST['nonce'], 'kiyoh_admin_nonce')) { // preflight: ignore — nonce is verified here; manage_woocommerce is checked next
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
     * AJAX handler: queue a background rating sync and run the first chunk.
     */
    public function ajax_sync_product_ratings() {
        if (!wp_verify_nonce($_POST['nonce'], 'kiyoh_admin_nonce')) { // preflight: ignore — nonce is verified here; manage_woocommerce is checked next
            wp_die('Security check failed');
        }

        if (!current_user_can('manage_woocommerce')) {
            wp_die('Insufficient permissions');
        }

        $rating_manager = new Kiyoh_Rating_Manager();
        $queued = $rating_manager->queue_full_sync();

        if (isset($queued['status']) && $queued['status'] === 'failed' && !empty($queued['error'])) {
            wp_send_json_error(array(
                'message' => $queued['error'],
                'sync' => $rating_manager->get_public_sync_status()
            ));
        }

        $status = $rating_manager->process_job_chunk();

        wp_send_json_success(array(
            'message' => isset($status['message']) ? $status['message'] : '',
            'sync' => $status
        ));
    }

    /**
     * AJAX handler: rating-sync progress. Also advances the job when no worker
     * is currently holding the lock (covers DISABLE_WP_CRON).
     */
    public function ajax_rating_sync_status() {
        if (!wp_verify_nonce($_POST['nonce'], 'kiyoh_admin_nonce')) { // preflight: ignore — nonce is verified here; manage_woocommerce is checked next
            wp_die('Security check failed');
        }

        if (!current_user_can('manage_woocommerce')) {
            wp_die('Insufficient permissions');
        }

        $rating_manager = new Kiyoh_Rating_Manager();
        $status = $rating_manager->get_public_sync_status();

        if (!empty($status['active'])) {
            $status = $rating_manager->process_job_chunk();
        }

        wp_send_json_success(array(
            'sync' => $status
        ));
    }

    /**
     * Invitations queued for a specific shop-local send time.
     * Rendered on WooCommerce → Kiyoh → Invitations, which already requires manage_woocommerce.
     */
    public function render_scheduled_invitations() {
        if (!current_user_can('manage_woocommerce') || !class_exists('Kiyoh_Invitation_Manager')) {
            return;
        }

        $settings = get_option('kiyoh_woocommerce_settings', array());
        $invitations = isset($settings['invitations']) && is_array($settings['invitations']) ? $settings['invitations'] : array();
        $schedule_on = class_exists('Kiyoh_Invitation_Schedule') && Kiyoh_Invitation_Schedule::is_schedule_mode_active($invitations);
        $rows = Kiyoh_Invitation_Manager::get_recent_scheduled_invitations(30);

        if (!$schedule_on && empty($rows)) {
            return;
        }

        $timezone = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
        $timezone_name = function_exists('wp_timezone_string') ? wp_timezone_string() : $timezone->getName();
        if ($timezone_name === '') {
            $timezone_name = 'UTC';
        }

        echo '<h3>' . esc_html__('Scheduled invitations', 'kiyoh-woocommerce') . '</h3>';
        echo '<p class="description">' . sprintf(
            /* translators: %s: WordPress timezone string */
            esc_html__('Times use the shop timezone (%s). A pending invitation is handed to Kiyoh at that moment with no extra delay.', 'kiyoh-woocommerce'),
            esc_html($timezone_name)
        ) . '</p>';

        if (empty($rows)) {
            echo '<p>' . esc_html__('No invitations have been scheduled for a specific time yet.', 'kiyoh-woocommerce') . '</p>';
            return;
        }

        echo '<table class="widefat striped kiyoh-scheduled-invitations"><thead><tr>';
        echo '<th>' . esc_html__('Order', 'kiyoh-woocommerce') . '</th>';
        echo '<th>' . esc_html__('Customer', 'kiyoh-woocommerce') . '</th>';
        echo '<th>' . esc_html__('Send at', 'kiyoh-woocommerce') . '</th>';
        echo '<th>' . esc_html__('Status', 'kiyoh-woocommerce') . '</th>';
        echo '<th>' . esc_html__('Detail', 'kiyoh-woocommerce') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($rows as $row) {
            echo '<tr>';
            echo '<td>' . $this->scheduled_invitation_order_link($row) . '</td>';
            echo '<td>' . esc_html($row->customer_email) . '</td>';
            echo '<td>' . esc_html($this->format_scheduled_invitation_time($row->scheduled_for, $timezone)) . '</td>';
            echo '<td>' . esc_html($this->scheduled_invitation_status_label($row->status)) . '</td>';
            echo '<td>' . esc_html($this->scheduled_invitation_detail($row)) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    /**
     * @param object $row Invitation row.
     * @return string
     */
    private function scheduled_invitation_order_link($row) {
        $order_id = isset($row->order_id) ? (int) $row->order_id : 0;
        $label = '#' . $order_id;
        $url = '';

        if ($order_id && function_exists('wc_get_order')) {
            $order = wc_get_order($order_id);
            if ($order && is_a($order, 'WC_Order')) {
                $label = '#' . $order->get_order_number();
                $url = $order->get_edit_order_url();
            }
        }

        if ($url) {
            return '<a href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
        }

        return esc_html($label);
    }

    /**
     * @param string       $gmt_mysql GMT datetime from scheduled_for.
     * @param DateTimeZone $timezone  Shop timezone.
     * @return string
     */
    private function format_scheduled_invitation_time($gmt_mysql, DateTimeZone $timezone) {
        try {
            $utc = new DateTimeImmutable($gmt_mysql, new DateTimeZone('UTC'));
            return $utc->setTimezone($timezone)->format('Y-m-d H:i');
        } catch (Exception $e) {
            return (string) $gmt_mysql;
        }
    }

    /**
     * @param string $status
     * @return string
     */
    private function scheduled_invitation_status_label($status) {
        $labels = array(
            'scheduled' => __('Scheduled', 'kiyoh-woocommerce'),
            'sending' => __('Sending', 'kiyoh-woocommerce'),
            'sent' => __('Sent', 'kiyoh-woocommerce'),
            'error' => __('Error', 'kiyoh-woocommerce'),
            'skipped' => __('Skipped', 'kiyoh-woocommerce'),
        );

        return isset($labels[$status]) ? $labels[$status] : (string) $status;
    }

    /**
     * @param object $row
     * @return string
     */
    private function scheduled_invitation_detail($row) {
        $decoded = array();
        if (!empty($row->response_data)) {
            $parsed = json_decode($row->response_data, true);
            if (is_array($parsed)) {
                $decoded = $parsed;
            }
        }

        if (!empty($decoded['note'])) {
            $notes = array(
                'cancelled' => __('Order is cancelled', 'kiyoh-woocommerce'),
                'refunded' => __('Order is refunded', 'kiyoh-woocommerce'),
                'failed' => __('Order has failed', 'kiyoh-woocommerce'),
                'trash' => __('Order is in the trash', 'kiyoh-woocommerce'),
                'no_email' => __('Order has no customer email', 'kiyoh-woocommerce'),
                'excluded' => __('Customer is excluded', 'kiyoh-woocommerce'),
                'already_sent' => __('An invitation was already sent', 'kiyoh-woocommerce'),
                'order_missing' => __('Order no longer exists', 'kiyoh-woocommerce'),
                'send_did_not_complete' => __('Send did not complete', 'kiyoh-woocommerce'),
            );
            $code = $decoded['note'];
            return isset($notes[$code]) ? $notes[$code] : sanitize_text_field($code);
        }

        if ($row->status === 'sent' && !empty($row->sent_at)) {
            return sprintf(
                /* translators: %s: local datetime the API call was recorded */
                __('Sent at %s', 'kiyoh-woocommerce'),
                $row->sent_at
            );
        }

        return '';
    }

    /**
     * Test API connection by fetching company stats
     */
    public static function test_api_connection() {
        try {
            if (!Kiyoh_Api_Factory::is_configured()) {
                $result = array(
                    'success' => false,
                    'message' => 'API not configured. Please set API token and location ID.'
                );
            } else {
                $client = Kiyoh_Api_Factory::create_client();
                $response = $client->get_company_stats();
                $payload = $response->is_success() ? $response->get_data() : array(
                    'errorCode' => $response->get_error_code(),
                );
                $assessment = Kiyoh_Api_Client::assess_statistics_payload((int) $response->get_response_code(), $payload);

                if ($assessment['ok']) {
                    $data = $response->get_data();
                    $result = array(
                        'success' => true,
                        'message' => 'API connection successful',
                        'stats' => $data,
                        'http_status' => $response->get_response_code(),
                    );
                } else {
                    $detail = $response->get_error();
                    $message = $assessment['message'];
                    if ($message === 'API connection failed.' && is_string($detail) && $detail !== '') {
                        $message = 'API connection failed: ' . $detail;
                    }
                    $result = array(
                        'success' => false,
                        'message' => $message,
                        'error_code' => $response->get_error_code(),
                        'http_status' => $response->get_response_code(),
                    );
                }
            }
        } catch (Exception $e) {
            $result = array(
                'success' => false,
                'message' => 'Test failed: ' . $e->getMessage()
            );
        }

        self::report_connection_health($result);
        return $result;
    }

    /**
     * A failed health report must not turn a connection test into an error.
     *
     * @param array $result
     */
    private static function report_connection_health($result) {
        try {
            if (!class_exists('Kiyoh_Mobile_Health_Reporter')) {
                return;
            }
            Kiyoh_Mobile_Health_Reporter::instance()->note_connection_test(
                !empty($result['success']),
                isset($result['message']) ? $result['message'] : null,
                isset($result['http_status']) ? $result['http_status'] : null
            );
        } catch (Throwable $e) {
            error_log('Kiyoh Mobile Health: connection note failed: ' . $e->getMessage());
        }
    }

}