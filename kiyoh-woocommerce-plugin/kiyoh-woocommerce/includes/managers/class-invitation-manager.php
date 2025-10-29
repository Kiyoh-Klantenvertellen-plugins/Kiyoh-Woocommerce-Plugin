<?php

class Kiyoh_Invitation_Manager {

    private $api_client;
    private $settings;

    public function __construct() {
        $this->settings = get_option('kiyoh_woocommerce_settings', array());
        $this->init_hooks();
    }

    private function init_hooks() {
        if (!$this->is_invitation_enabled()) {
            return;
        }

        add_action('woocommerce_order_status_changed', array($this, 'handle_order_status_change'), 10, 4);
    }

    private function is_invitation_enabled() {
        return !empty($this->settings['general']['enabled']) && 
               !empty($this->settings['general']['api_key']) &&
               !empty($this->settings['general']['location_id']) &&
               !empty($this->settings['invitations']['trigger_status']);
    }

    public function handle_order_status_change($order_id, $old_status, $new_status, $order) {
        error_log("Kiyoh Invitation: Order {$order_id} status changed from {$old_status} to {$new_status}");

        $trigger_statuses = $this->get_trigger_statuses();
        
        if (!in_array('wc-' . $new_status, $trigger_statuses) && !in_array($new_status, $trigger_statuses)) {
            error_log("Kiyoh Invitation: Status {$new_status} not in trigger list - skipping");
            return;
        }

        if (!$this->should_send_invitation($order)) {
            error_log("Kiyoh Invitation: Order {$order_id} failed invitation criteria - skipping");
            return;
        }

        $this->process_order_invitations($order);
    }

    public function should_send_invitation($order) {
        if (!$order || !is_a($order, 'WC_Order')) {
            error_log("Kiyoh Invitation: Invalid order object");
            return false;
        }

        $customer_email = $order->get_billing_email();
        if (empty($customer_email)) {
            error_log("Kiyoh Invitation: Order {$order->get_id()} has no customer email");
            return false;
        }

        if ($this->is_customer_excluded($order)) {
            error_log("Kiyoh Invitation: Customer excluded for order {$order->get_id()}");
            return false;
        }

        return true;
    }

    private function is_customer_excluded($order) {
        $excluded_groups = $this->get_excluded_customer_groups();
        if (empty($excluded_groups)) {
            return false;
        }

        $customer_id = $order->get_customer_id();
        if (!$customer_id) {
            return false;
        }

        $user = get_user_by('id', $customer_id);
        if (!$user) {
            return false;
        }

        foreach ($excluded_groups as $role) {
            if (in_array($role, $user->roles)) {
                return true;
            }
        }

        return false;
    }



    private function process_order_invitations($order) {
        $invitation_type = $this->get_invitation_type();
        $product_codes = $this->extract_product_codes_from_order($order);

        try {
            if ($invitation_type === 'product_only') {
                if (!empty($product_codes)) {
                    $this->send_product_invitation_with_retry($order, $product_codes);
                } else {
                    error_log("Kiyoh Invitation: No products found for product-only invitation for order {$order->get_id()}");
                }
            } elseif ($invitation_type === 'shop_only') {
                $this->send_shop_invitation($order);
            } else {
                $this->send_combined_invitation_with_retry($order, $product_codes);
            }
        } catch (Exception $e) {
            error_log("Kiyoh Invitation: Failed to process order invitations for order {$order->get_id()}: " . $e->getMessage());
        }
    }

    private function send_combined_invitation_with_retry($order, $product_codes) {
        if (empty($product_codes)) {
            error_log("Kiyoh Invitation: Sending shop-only invitation (no valid products) for order {$order->get_id()}");
            $this->send_shop_invitation($order);
            return;
        }

        error_log("Kiyoh Invitation: Attempting combined shop and product invitation for order {$order->get_id()}");

        $result = $this->send_product_invitation_with_details($order, $product_codes, false);

        if ($result['success']) {
            error_log("Kiyoh Invitation: Combined invitation sent successfully for order {$order->get_id()}");
        } else {
            $error_code = $result['error_code'];
            error_log("Kiyoh Invitation: Combined invitation failed for order {$order->get_id()}: {$error_code}");

            if ($this->should_sync_products_for_error($error_code)) {
                error_log("Kiyoh Invitation: Error indicates missing products, syncing and retrying for order {$order->get_id()}");
                $this->sync_order_products($order);
                
                $retry_result = $this->send_product_invitation_with_details($order, $product_codes, false);
                if ($retry_result['success']) {
                    error_log("Kiyoh Invitation: Combined invitation retry successful for order {$order->get_id()}");
                } else {
                    error_log("Kiyoh Invitation: Combined invitation retry failed for order {$order->get_id()}");
                }
            }
        }
    }

    private function send_product_invitation_with_retry($order, $product_codes) {
        error_log("Kiyoh Invitation: Attempting product invitation for order {$order->get_id()}");

        $result = $this->send_product_invitation_with_details($order, $product_codes, true);

        if ($result['success']) {
            error_log("Kiyoh Invitation: Product invitation sent successfully for order {$order->get_id()}");
        } else {
            $error_code = $result['error_code'];
            error_log("Kiyoh Invitation: Product invitation failed for order {$order->get_id()}: {$error_code}");

            if ($this->should_sync_products_for_error($error_code)) {
                error_log("Kiyoh Invitation: Error indicates missing products, syncing and retrying for order {$order->get_id()}");
                $this->sync_order_products($order);
                
                $retry_result = $this->send_product_invitation_with_details($order, $product_codes, true);
                if ($retry_result['success']) {
                    error_log("Kiyoh Invitation: Product invitation retry successful for order {$order->get_id()}");
                } else {
                    error_log("Kiyoh Invitation: Product invitation retry failed for order {$order->get_id()}");
                }
            }
        }
    }

    private function send_shop_invitation($order) {
        error_log("Kiyoh Invitation: Sending shop invitation for order {$order->get_id()}");

        $invitation_data = $this->build_invitation_data($order, array());
        $result = $this->send_invitation_request($invitation_data, false);

        if ($result['success']) {
            error_log("Kiyoh Invitation: Shop invitation sent successfully for order {$order->get_id()}");
        } else {
            error_log("Kiyoh Invitation: Shop invitation failed for order {$order->get_id()}: " . $result['error_code']);
        }

        $this->save_invitation_record($order, $result['success'] ? 'sent' : 'error');
    }

    private function send_product_invitation_with_details($order, $product_codes, $product_invite_flag) {
        $invitation_data = $this->build_invitation_data($order, $product_codes);
        $result = $this->send_invitation_request($invitation_data, $product_invite_flag);
        
        $this->save_invitation_record($order, $result['success'] ? 'sent' : 'error');
        
        return $result;
    }

    public function send_invitation($order) {
        $this->process_order_invitations($order);
        return true;
    }

    private function build_invitation_data($order, $product_codes) {
        $delay_days = $this->get_invitation_delay();
        $language = $this->detect_customer_language($order);

        $first_name = $order->get_billing_first_name();
        $last_name = $order->get_billing_last_name();

        $data = array(
            'location_id' => $this->settings['general']['location_id'],
            'invite_email' => $order->get_billing_email(),
            'delay' => $delay_days,
            'language' => $language,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'reference_code' => $order->get_order_number()
        );

        if (!empty($product_codes)) {
            $data['product_code'] = $product_codes;
        }

        return $data;
    }

    private function send_invitation_request($data, $product_invite_flag) {
        try {
            $api_client = $this->get_api_client();
            if (!$api_client) {
                return array('success' => false, 'error_code' => 'API_CLIENT_ERROR', 'message' => 'API client not available');
            }

            $data['product_invite'] = $product_invite_flag;

            $response = $api_client->send_invitation($data);
            
            if ($response->is_success()) {
                return array('success' => true, 'error_code' => null, 'message' => 'Invitation sent successfully');
            } else {
                return array('success' => false, 'error_code' => $response->get_error_code(), 'message' => $response->get_error());
            }
        } catch (Exception $e) {
            return array('success' => false, 'error_code' => 'EXCEPTION', 'message' => $e->getMessage());
        }
    }

    private function extract_product_codes_from_order($order) {
        try {
            $max_products = $this->get_max_products();
            $sort_order = $this->get_product_sort_order();
            $excluded_customer_groups = $this->get_excluded_customer_groups();

            $valid_products = array();
            $total_items = 0;
            $excluded_items = 0;

            // First pass: collect all valid products with their data
            foreach ($order->get_items() as $item) {
                try {
                    $total_items++;
                    $product = $item->get_product();
                    if (!$product) {
                        continue;
                    }

                    // Use the actual purchased product SKU from the order item, not the loaded product
                    // This ensures we get the specific variant that was purchased
                    $product_code = $item->get_meta('_sku') ?: $product->get_sku();
                    if (!$product_code) {
                        continue;
                    }

                    // Check for duplicates
                    $is_duplicate = false;
                    foreach ($valid_products as $valid_product) {
                        if ($valid_product['sku'] === $product_code) {
                            $is_duplicate = true;
                            break;
                        }
                    }

                    if (!$is_duplicate) {
                        $valid_products[] = array(
                            'sku' => $product_code,
                            'name' => $item->get_name() ?: $product->get_name() ?: '',
                            'price' => (float) $item->get_total() / max(1, $item->get_quantity()), // Unit price
                            'cart_position' => count($valid_products), // Original cart order
                            'item_id' => $item->get_id(),
                            'product_type' => $product->get_type()
                        );
                    }
                } catch (Exception $e) {
                    error_log("Kiyoh Invitation: Error processing order item for order {$order->get_id()}: " . $e->getMessage());
                }
            }

            // Sort products based on configuration
            $this->sort_products($valid_products, $sort_order);

            // Extract product codes up to the maximum limit
            $product_codes = array();
            for ($i = 0; $i < min(count($valid_products), $max_products); $i++) {
                $product_codes[] = $valid_products[$i]['sku'];
            }

            error_log("Kiyoh Invitation: Extracted and sorted product codes from order {$order->get_id()}: " . 
                     "total_items={$total_items}, valid_products=" . count($valid_products) . 
                     ", extracted_codes=" . count($product_codes) . ", max_products={$max_products}, " .
                     "sort_order={$sort_order}, product_codes=" . implode(', ', $product_codes));

            return $product_codes;
        } catch (Exception $e) {
            error_log("Kiyoh Invitation: Critical error extracting product codes from order {$order->get_id()}: " . $e->getMessage());
            return array();
        }
    }

    private function sort_products(&$products, $sort_order) {
        switch ($sort_order) {
            case 'price_desc':
                usort($products, function ($a, $b) {
                    return $b['price'] <=> $a['price'];
                });
                break;
            case 'price_asc':
                usort($products, function ($a, $b) {
                    return $a['price'] <=> $b['price'];
                });
                break;
            case 'name_asc':
                usort($products, function ($a, $b) {
                    return strcasecmp($a['name'], $b['name']);
                });
                break;
            case 'name_desc':
                usort($products, function ($a, $b) {
                    return strcasecmp($b['name'], $a['name']);
                });
                break;
            case 'sku_asc':
                usort($products, function ($a, $b) {
                    return strcasecmp($a['sku'], $b['sku']);
                });
                break;
            case 'sku_desc':
                usort($products, function ($a, $b) {
                    return strcasecmp($b['sku'], $a['sku']);
                });
                break;
            case 'cart_order':
            default:
                // Keep original cart order (already sorted by cart_position)
                usort($products, function ($a, $b) {
                    return $a['cart_position'] <=> $b['cart_position'];
                });
                break;
        }
    }

    private function sync_order_products($order) {
        try {
            $max_products = $this->get_max_products();
            $synced_count = 0;
            $failed_count = 0;

            error_log("Kiyoh Invitation: Starting product sync for order {$order->get_id()}");

            // Use the product sync manager for consistency
            $product_sync_manager = new Kiyoh_Product_Sync_Manager();

            foreach ($order->get_items() as $item) {
                if ($synced_count >= $max_products) {
                    break;
                }

                try {
                    $product = $item->get_product();
                    if (!$product) {
                        continue;
                    }

                    $success = $product_sync_manager->sync_single_product($product->get_id());
                    
                    if ($success) {
                        $synced_count++;
                        error_log("Kiyoh Invitation: Product synced successfully: " . $product->get_sku());
                    } else {
                        $failed_count++;
                        error_log("Kiyoh Invitation: Product sync failed: " . $product->get_sku());
                    }
                } catch (Exception $e) {
                    $failed_count++;
                    error_log("Kiyoh Invitation: Product sync exception: " . $e->getMessage());
                }
            }

            error_log("Kiyoh Invitation: Product sync completed for order {$order->get_id()}: synced={$synced_count}, failed={$failed_count}");
        } catch (Exception $e) {
            error_log("Kiyoh Invitation: Critical error during order product sync for order {$order->get_id()}: " . $e->getMessage());
        }
    }



    private function should_sync_products_for_error($error_code) {
        $product_sync_errors = array(
            'INVALID_PRODUCT_ID',
            'PRODUCT_NOT_FOUND',
            'UNKNOWN_PRODUCT',
            'MISSING_PRODUCT',
            'PRODUCT_DOES_NOT_EXIST',
            'INVALID_PRODUCT_CODE',
            'PRODUCT_NOT_AVAILABLE'
        );

        return in_array($error_code, $product_sync_errors);
    }

    private function detect_customer_language($order) {
        $fallback_language = $this->get_fallback_language();

        try {
            // Try user-specific locale for logged-in customers
            $customer_id = $order->get_customer_id();
            if ($customer_id) {
                $user_locale = get_user_locale($customer_id);
                if ($user_locale) {
                    return $this->map_wordpress_locale_to_kiyoh_language($user_locale, $fallback_language);
                }
            }

            // Try current request locale (WordPress 5.0+, context-aware)
            if (function_exists('determine_locale')) {
                $request_locale = determine_locale();
                if ($request_locale) {
                    return $this->map_wordpress_locale_to_kiyoh_language($request_locale, $fallback_language);
                }
            }

            // Fallback to site default locale
            $site_locale = get_locale();
            if ($site_locale) {
                return $this->map_wordpress_locale_to_kiyoh_language($site_locale, $fallback_language);
            }
        } catch (Exception $e) {
            error_log("Kiyoh Invitation: Could not detect language from order, using fallback: " . $e->getMessage());
        }

        return $fallback_language;
    }

    private function map_wordpress_locale_to_kiyoh_language($wp_locale, $fallback = 'en') {
        // Complete locale mapping matching Magento plugin
        $locale_to_kiyoh_map = array(
            'nl_NL' => 'nl',
            'fr_FR' => 'fr', 'fr_CA' => 'fr',
            'de_DE' => 'de', 'de_AT' => 'de', 'de_CH' => 'de',
            'en_US' => 'en', 'en_GB' => 'en', 'en_AU' => 'en', 'en_CA' => 'en', 'en_NZ' => 'en',
            'da_DK' => 'da',
            'hu_HU' => 'hu',
            'bg_BG' => 'bg',
            'ro_RO' => 'ro',
            'hr_HR' => 'hr',
            'ja_JP' => 'ja',
            'es_ES' => 'es', 'es_AR' => 'es', 'es_CL' => 'es', 'es_CO' => 'es', 'es_MX' => 'es', 'es_PE' => 'es', 'es_VE' => 'es',
            'it_IT' => 'it', 'it_CH' => 'it',
            'pt_PT' => 'pt',
            'tr_TR' => 'tr',
            'nb_NO' => 'no', 'nn_NO' => 'no',
            'sv_SE' => 'sv',
            'fi_FI' => 'fi',
            'pt_BR' => 'pt',
            'pl_PL' => 'pl',
            'sl_SI' => 'sl',
            'zh_Hans_CN' => 'zh', 'zh_Hant_HK' => 'zh', 'zh_Hant_TW' => 'zh',
            'zh_CN' => 'zh', 'zh_TW' => 'zh', // WordPress variants
            'ru_RU' => 'ru',
            'el_GR' => 'gr',
            'cs_CZ' => 'cs',
            'et_EE' => 'et',
            'lt_LT' => 'lt',
            'lv_LV' => 'lv',
            'sk_SK' => 'sk'
        );

        if (isset($locale_to_kiyoh_map[$wp_locale])) {
            return $locale_to_kiyoh_map[$wp_locale];
        }

        // Extract language code and check if supported
        $language_code = substr($wp_locale, 0, 2);
        $supported_kiyoh_languages = array_unique(array_values($locale_to_kiyoh_map));
        
        if (in_array($language_code, $supported_kiyoh_languages)) {
            return $language_code;
        }

        error_log("Kiyoh Invitation: Unsupported language detected, using fallback: wp_locale={$wp_locale}, language_code={$language_code}, fallback={$fallback}");

        return $fallback;
    }

    private function get_fallback_language() {
        return isset($this->settings['invitations']['fallback_language']) ? 
               $this->settings['invitations']['fallback_language'] : 'en';
    }

    private function save_invitation_record($order, $status) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'kiyoh_invitations';
        
        $data = array(
            'order_id' => $order->get_id(),
            'customer_email' => $order->get_billing_email(),
            'invitation_type' => $this->get_invitation_type(),
            'status' => $status,
            'created_at' => current_time('mysql')
        );

        if ($status === 'sent') {
            $data['sent_at'] = current_time('mysql');
        }

        $wpdb->insert($table_name, $data);
    }

    private function get_trigger_statuses() {
        $statuses = isset($this->settings['invitations']['trigger_status']) ? 
                   $this->settings['invitations']['trigger_status'] : array('completed');
        return is_array($statuses) ? $statuses : array('completed');
    }

    private function get_invitation_delay() {
        return isset($this->settings['invitations']['delay_days']) ? 
               intval($this->settings['invitations']['delay_days']) : 3;
    }

    private function get_invitation_type() {
        return isset($this->settings['invitations']['invitation_type']) ? 
               $this->settings['invitations']['invitation_type'] : 'shop_and_product';
    }

    private function get_max_products() {
        $max = isset($this->settings['invitations']['max_products']) ? 
               intval($this->settings['invitations']['max_products']) : 5;
        return max(1, min(10, $max));
    }

    private function get_product_sort_order() {
        return isset($this->settings['invitations']['product_sort_order']) ? 
               $this->settings['invitations']['product_sort_order'] : 'cart_order';
    }



    private function get_excluded_customer_groups() {
        $excluded = isset($this->settings['invitations']['excluded_customer_groups']) ? 
                   $this->settings['invitations']['excluded_customer_groups'] : array();
        return is_array($excluded) ? $excluded : array();
    }



    private function get_api_client() {
        if (!$this->api_client) {
            $platform = isset($this->settings['general']['platform']) ? $this->settings['general']['platform'] : 'kiyoh';
            $api_key = isset($this->settings['general']['api_key']) ? $this->settings['general']['api_key'] : '';
            $location_id = isset($this->settings['general']['location_id']) ? $this->settings['general']['location_id'] : '';

            if (empty($api_key) || empty($location_id)) {
                return false;
            }

            $this->api_client = new Kiyoh_Api_Client($platform, $api_key, $location_id);
        }

        return $this->api_client;
    }

    public function get_invitation_statistics() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'kiyoh_invitations';
        
        $stats = $wpdb->get_row("
            SELECT 
                COUNT(*) as total_invitations,
                SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent,
                SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) as errors,
                MAX(sent_at) as last_sent_time
            FROM {$table_name}
        ");

        return array(
            'total_invitations' => $stats->total_invitations ?: 0,
            'sent' => $stats->sent ?: 0,
            'errors' => $stats->errors ?: 0,
            'last_sent_time' => $stats->last_sent_time,
            'success_rate' => $stats->total_invitations > 0 ? 
                            round(($stats->sent / $stats->total_invitations) * 100, 1) : 0
        );
    }

    public function retry_failed_invitations($limit = 10) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'kiyoh_invitations';
        
        $failed_invitations = $wpdb->get_results($wpdb->prepare(
            "SELECT order_id FROM {$table_name} 
             WHERE status = 'error' 
             AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
             ORDER BY created_at DESC 
             LIMIT %d",
            $limit
        ));

        $results = array(
            'attempted' => 0,
            'successful' => 0,
            'failed' => 0
        );

        foreach ($failed_invitations as $invitation) {
            $order = wc_get_order($invitation->order_id);
            if (!$order) {
                continue;
            }

            $results['attempted']++;
            
            if ($this->send_invitation($order)) {
                $results['successful']++;
            } else {
                $results['failed']++;
            }

            sleep(1);
        }

        return $results;
    }
}