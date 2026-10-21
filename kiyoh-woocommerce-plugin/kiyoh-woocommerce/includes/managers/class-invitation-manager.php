<?php

class Kiyoh_Invitation_Manager {

    private $api_client;
    private $settings;

    /**
     * When set, the API `delay` field uses this value instead of delay_days.
     * Scheduled sends pass 0 so Kiyoh delivers as soon as the job runs.
     *
     * @var int|null
     */
    private $delay_override = null;

    /**
     * Existing kiyoh_invitations row to update, instead of inserting another.
     *
     * @var int|null
     */
    private $invitation_record_id = null;

    public function __construct() {
        $this->settings = get_option('kiyoh_woocommerce_settings', array());
        $this->init_hooks();
    }

    private function init_hooks() {
        // Registered even when invitations are off, so a job queued earlier can
        // no-op (or wait) instead of failing inside Action Scheduler.
        add_action(Kiyoh_Invitation_Schedule::HOOK, array($this, 'send_scheduled_invitation'), 10, 2);

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

    public function handle_order_status_change($order_id, $old_status, $new_status, $order = null) {
        // HPOS and the Checkout block pass a WC_Order. Fall back to the CRUD
        // loader so a missing object is never read as a shop_order post.
        if (!$order instanceof WC_Order) {
            $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
        }

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

        $invitations = isset($this->settings['invitations']) ? $this->settings['invitations'] : array();
        if (Kiyoh_Invitation_Schedule::is_schedule_mode_active($invitations)) {
            error_log("Kiyoh Invitation: Preferred send time is on — scheduling order {$order_id} instead of calling the API now");
            $this->schedule_order_invitation($order);
            return;
        }

        if (!empty($invitations['send_time_enabled'])) {
            error_log("Kiyoh Invitation: Preferred send time is enabled but empty or invalid — order {$order_id} uses the existing day-delay API call");
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

        // Sync products before sending invitation to prevent PRODUCT_NOT_FOUND errors
        if (!empty($product_codes) && $invitation_type !== 'shop_only') {
            $this->sync_order_products_before_invitation($order, $product_codes);
        }

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
                    $raw_sku = $item->get_meta('_sku') ?: $product->get_sku();
                    if (!$raw_sku) {
                        continue;
                    }

                    // The invitation must reference the product by the SAME
                    // product_code that Product Sync registered in Kiyoh. That code
                    // is a sanitised/length-capped derivation of the SKU (Kiyoh
                    // limits product_code to <50 chars), NOT the raw SKU. Using the
                    // raw SKU here would cause PRODUCT_NOT_FOUND for any product
                    // whose SKU was sanitised or truncated during sync.
                    $product_code = Kiyoh_Product_Sync_Manager::sanitize_product_code($raw_sku);
                    if (!$product_code) {
                        continue;
                    }

                    // Check for duplicates (compare on the derived code).
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



    private function sync_order_products_before_invitation($order, $product_codes) {
        if (empty($this->settings['product_sync']['auto_sync'])) {
            return;
        }

        error_log("Kiyoh Invitation: Syncing order products before invitation for order {$order->get_id()}");
        $this->sync_order_products($order);
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
        if ($this->invitation_record_id) {
            $this->update_invitation_status($this->invitation_record_id, $status);
            return;
        }

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
        if ($this->delay_override !== null) {
            return (int) $this->delay_override;
        }

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

        if (!$stats) {
            return array(
                'total_invitations' => 0,
                'sent' => 0,
                'errors' => 0,
                'last_sent_time' => null,
                'success_rate' => 0
            );
        }

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
             AND scheduled_for IS NULL
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

    /**
     * Queue one Action Scheduler job (WP-Cron if Action Scheduler is missing)
     * for the next allowed shop-local day and time after delay_days.
     * The API is not called here. The job sends with delay 0.
     *
     * @param WC_Order $order
     */
    private function schedule_order_invitation($order) {
        $order_id = $order->get_id();

        if ($this->has_pending_or_sent_invitation($order_id)) {
            error_log("Kiyoh Invitation: Order {$order_id} already has a sent or scheduled invitation — not scheduling another");
            return;
        }

        if (!$this->acquire_order_lock($order_id)) {
            error_log("Kiyoh Invitation: Could not lock order {$order_id} to schedule an invitation");
            return;
        }

        try {
            if ($this->has_pending_or_sent_invitation($order_id)) {
                error_log("Kiyoh Invitation: Order {$order_id} already has a sent or scheduled invitation — not scheduling another");
                return;
            }

            $invitations = isset($this->settings['invitations']) ? $this->settings['invitations'] : array();
            $timezone = $this->get_shop_timezone();
            $when = Kiyoh_Invitation_Schedule::next_send_at(
                new DateTimeImmutable('now', $timezone),
                $this->get_invitation_delay(),
                $invitations['send_time'],
                isset($invitations['send_weekdays']) ? (array) $invitations['send_weekdays'] : array(),
                $timezone
            );

            $invitation_id = $this->insert_scheduled_invitation($order, $when);
            if (!$invitation_id) {
                error_log("Kiyoh Invitation: Could not store scheduled invitation for order {$order_id}");
                return;
            }

            $via = self::enqueue_scheduled_action($order_id, $invitation_id, $when->getTimestamp());
            $local = $when->format('Y-m-d H:i');
            $tz_name = $timezone->getName();
            $weekdays = isset($invitations['send_weekdays']) ? $invitations['send_weekdays'] : array();
            $weekday_label = empty($weekdays) ? 'all' : implode(',', Kiyoh_Invitation_Schedule::sanitize_weekdays($weekdays));

            error_log("Kiyoh Invitation: Scheduled invitation {$invitation_id} for order {$order_id} at {$local} {$tz_name} via {$via}. Delay was {$this->get_invitation_delay()} day(s), send time {$invitations['send_time']}, weekdays {$weekday_label}. API delay will be 0 when the job runs.");

            $order->add_order_note(sprintf(
                /* translators: 1: local date and time, 2: timezone name */
                __('Kiyoh review invitation scheduled for %1$s (%2$s).', 'kiyoh-woocommerce'),
                $local,
                $tz_name
            ));
        } finally {
            $this->release_order_lock($order_id);
        }
    }

    /**
     * Action Scheduler / WP-Cron callback. Claims the row, re-checks the order,
     * then sends with delay 0. A second run for the same row does nothing.
     *
     * @param int $order_id
     * @param int $invitation_id
     */
    public function send_scheduled_invitation($order_id, $invitation_id = 0) {
        $this->settings = get_option('kiyoh_woocommerce_settings', array());
        $order_id = absint($order_id);
        $invitation_id = absint($invitation_id);

        error_log("Kiyoh Invitation: Running scheduled invitation for order {$order_id} (record {$invitation_id})");

        if (!$this->acquire_order_lock($order_id)) {
            error_log("Kiyoh Invitation: Could not lock order {$order_id}; scheduled invitation stays pending");
            return;
        }

        try {
            $this->deliver_scheduled_invitation($order_id, $invitation_id);
        } finally {
            $this->release_order_lock($order_id);
        }
    }

    /**
     * Claim and send one scheduled row. Caller holds the per-order lock.
     *
     * @param int $order_id
     * @param int $invitation_id
     */
    private function deliver_scheduled_invitation($order_id, $invitation_id) {
        $invitation_id = $this->claim_scheduled_invitation($order_id, $invitation_id);
        if (!$invitation_id) {
            error_log("Kiyoh Invitation: Scheduled invitation for order {$order_id} was not pending — skipped to stay idempotent");
            return;
        }

        $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
        if (!$order || !is_a($order, 'WC_Order')) {
            $this->update_invitation_status($invitation_id, 'skipped', 'order_missing');
            error_log("Kiyoh Invitation: Order {$order_id} no longer exists — scheduled invitation {$invitation_id} skipped");
            return;
        }

        $reason = $this->scheduled_ineligibility_reason($order);
        if ($reason !== '') {
            $this->update_invitation_status($invitation_id, 'skipped', $reason);
            error_log("Kiyoh Invitation: Order {$order_id} is no longer eligible ({$reason}) — scheduled invitation {$invitation_id} skipped");
            $order->add_order_note(sprintf(
                /* translators: %s: reason the invitation was not sent */
                __('Kiyoh review invitation was not sent: %s.', 'kiyoh-woocommerce'),
                $this->ineligibility_note($reason)
            ));
            return;
        }

        if ($this->order_has_sent_invitation($order_id, $invitation_id)) {
            $this->update_invitation_status($invitation_id, 'skipped', 'already_sent');
            error_log("Kiyoh Invitation: Order {$order_id} already has a sent invitation — scheduled invitation {$invitation_id} skipped");
            $order->add_order_note(__('Kiyoh review invitation was not sent: an invitation was already sent for this order.', 'kiyoh-woocommerce'));
            return;
        }

        if (!$this->is_invitation_enabled()) {
            $this->update_invitation_status($invitation_id, 'scheduled');
            $retry_at = time() + (12 * HOUR_IN_SECONDS);
            self::enqueue_scheduled_action($order_id, $invitation_id, $retry_at);
            error_log("Kiyoh Invitation: Invitations are disabled — invitation {$invitation_id} for order {$order_id} stays scheduled and will be retried later");
            return;
        }

        $this->delay_override = 0;
        $this->invitation_record_id = $invitation_id;
        try {
            error_log("Kiyoh Invitation: Sending scheduled invitation {$invitation_id} for order {$order_id} to the Kiyoh API with delay 0");
            $this->process_order_invitations($order);
        } catch (Exception $e) {
            error_log("Kiyoh Invitation: Scheduled invitation {$invitation_id} for order {$order_id} failed: " . $e->getMessage());
            $this->update_invitation_status($invitation_id, 'error', 'send_did_not_complete');
        } finally {
            $this->close_sending_invitation($invitation_id, $order);
            $this->delay_override = null;
            $this->invitation_record_id = null;
        }
    }

    /**
     * Hourly fallback for due rows, plus a re-queue of future rows whose
     * Action Scheduler job was lost (for example after deactivation).
     *
     * @param int $limit
     */
    public function process_due_scheduled_invitations($limit = 50) {
        $this->settings = get_option('kiyoh_woocommerce_settings', array());

        if (!$this->is_invitation_enabled()) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'kiyoh_invitations';
        if (!self::invitations_table_exists($table)) {
            return;
        }

        $limit = max(1, min(100, (int) $limit));

        // A worker that died after the claim leaves the row in "sending".
        // The API call itself times out in seconds, so 15 minutes is stale.
        $reset = $wpdb->query(
            "UPDATE {$table} SET status = 'scheduled' WHERE status = 'sending' AND scheduled_for IS NOT NULL AND scheduled_for <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 15 MINUTE)"
        );
        if ($reset) {
            error_log("Kiyoh Invitation: Reset {$reset} invitation(s) stuck in sending so they can be retried");
        }

        $now = gmdate('Y-m-d H:i:s');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, order_id FROM {$table} WHERE status = 'scheduled' AND scheduled_for IS NOT NULL AND scheduled_for <= %s ORDER BY scheduled_for ASC LIMIT %d",
            $now,
            $limit
        ));

        if (!empty($rows)) {
            error_log('Kiyoh Invitation: Hourly fallback is sending ' . count($rows) . ' due scheduled invitation(s)');
        }

        foreach ($rows as $row) {
            $this->send_scheduled_invitation((int) $row->order_id, (int) $row->id);
        }

        self::queue_outstanding_schedules();
    }

    /**
     * Make sure every still-scheduled row has a pending job. Safe to call more
     * than once: Action Scheduler uniqueness and the status claim stop dupes.
     */
    public static function queue_outstanding_schedules() {
        global $wpdb;

        $table = $wpdb->prefix . 'kiyoh_invitations';
        if (!self::invitations_table_exists($table)) {
            return;
        }

        $rows = $wpdb->get_results(
            "SELECT id, order_id, scheduled_for FROM {$table} WHERE status = 'scheduled' AND scheduled_for IS NOT NULL AND scheduled_for != '0000-00-00 00:00:00' LIMIT 50"
        );

        if (empty($rows)) {
            return;
        }

        foreach ($rows as $row) {
            $timestamp = strtotime($row->scheduled_for . ' UTC');
            if (!$timestamp) {
                error_log("Kiyoh Invitation: Could not read scheduled_for for invitation {$row->id}");
                continue;
            }
            self::enqueue_scheduled_action((int) $row->order_id, (int) $row->id, $timestamp);
        }
    }

    /**
     * Recent invitations that were queued for a specific send time.
     * scheduled_for is stored in GMT.
     *
     * @param int $limit
     * @return array
     */
    public static function get_recent_scheduled_invitations($limit = 30) {
        global $wpdb;

        $table = $wpdb->prefix . 'kiyoh_invitations';
        $like = $wpdb->esc_like($table);
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $like));
        if ($exists !== $table) {
            return array();
        }

        $limit = max(1, min(100, (int) $limit));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, order_id, customer_email, status, scheduled_for, sent_at, response_data
             FROM {$table}
             WHERE scheduled_for IS NOT NULL AND scheduled_for != '0000-00-00 00:00:00'
             ORDER BY CASE status WHEN 'scheduled' THEN 0 WHEN 'sending' THEN 1 ELSE 2 END ASC, scheduled_for DESC
             LIMIT %d",
            $limit
        ));

        return is_array($rows) ? $rows : array();
    }

    /**
     * @param string $table
     * @return bool
     */
    private static function invitations_table_exists($table) {
        global $wpdb;
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
        return $exists === $table;
    }

    /**
     * @param int $order_id
     * @param int $invitation_id
     * @param int $timestamp UTC unix timestamp.
     * @return string action-scheduler|wp-cron|hourly-cron
     */
    private static function enqueue_scheduled_action($order_id, $invitation_id, $timestamp) {
        $args = array((int) $order_id, (int) $invitation_id);
        if ($timestamp <= time()) {
            $timestamp = time() + 5;
        }

        // A numeric result is a pending action. `true` only means this same job
        // is running right now, which must not block the follow-up.
        if (function_exists('as_next_scheduled_action')) {
            $next = as_next_scheduled_action(Kiyoh_Invitation_Schedule::HOOK, $args, Kiyoh_Invitation_Schedule::GROUP);
            if (is_numeric($next)) {
                return 'action-scheduler';
            }
        }

        if (function_exists('as_schedule_single_action')) {
            // unique=false: the job that is running shares this hook and args,
            // and Action Scheduler would otherwise treat it as a duplicate.
            $action_id = as_schedule_single_action(
                $timestamp,
                Kiyoh_Invitation_Schedule::HOOK,
                $args,
                Kiyoh_Invitation_Schedule::GROUP,
                false
            );

            if (!empty($action_id)) {
                return 'action-scheduler';
            }

            error_log("Kiyoh Invitation: Action Scheduler did not accept invitation {$invitation_id} for order {$order_id}; falling back to WP-Cron and the hourly queue");
        }

        if (function_exists('wp_next_scheduled') && function_exists('wp_schedule_single_event') && !wp_next_scheduled(Kiyoh_Invitation_Schedule::HOOK, $args)) {
            wp_schedule_single_event($timestamp, Kiyoh_Invitation_Schedule::HOOK, $args);
            return 'wp-cron';
        }

        return 'hourly-cron';
    }

    /**
     * @param WC_Order          $order
     * @param DateTimeImmutable $when Shop-local send moment.
     * @return int Invitation row id, or 0.
     */
    private function insert_scheduled_invitation($order, DateTimeImmutable $when) {
        global $wpdb;

        $table = $wpdb->prefix . 'kiyoh_invitations';
        $inserted = $wpdb->insert(
            $table,
            array(
                'order_id' => $order->get_id(),
                'customer_email' => $order->get_billing_email(),
                'invitation_type' => $this->get_invitation_type(),
                'status' => 'scheduled',
                'scheduled_for' => gmdate('Y-m-d H:i:s', $when->getTimestamp()),
                'created_at' => current_time('mysql'),
                'response_data' => wp_json_encode(array(
                    'scheduled_for_local' => $when->format('Y-m-d H:i'),
                    'timezone' => $when->getTimezone()->getName(),
                )),
            ),
            array('%d', '%s', '%s', '%s', '%s', '%s', '%s')
        );

        if (!$inserted) {
            error_log('Kiyoh Invitation: DB insert failed for scheduled invitation: ' . $wpdb->last_error);
            return 0;
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Move a scheduled row to "sending". Returns 0 when another worker already
     * claimed it, or when it is no longer scheduled.
     *
     * @param int $order_id
     * @param int $invitation_id
     * @return int
     */
    private function claim_scheduled_invitation($order_id, $invitation_id) {
        global $wpdb;

        $table = $wpdb->prefix . 'kiyoh_invitations';

        if (!$invitation_id) {
            $invitation_id = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE order_id = %d AND status = 'scheduled' ORDER BY id ASC LIMIT 1",
                $order_id
            ));
        }

        if (!$invitation_id) {
            return 0;
        }

        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET status = 'sending' WHERE id = %d AND order_id = %d AND status = 'scheduled'",
            $invitation_id,
            $order_id
        ));

        if ((int) $updated !== 1) {
            return 0;
        }

        return (int) $invitation_id;
    }

    /**
     * @param int    $invitation_id
     * @param string $status
     * @param string $note      Short machine code stored in response_data.
     */
    private function update_invitation_status($invitation_id, $status, $note = '') {
        global $wpdb;

        $table = $wpdb->prefix . 'kiyoh_invitations';
        $data = array('status' => $status);
        $format = array('%s');

        if ($status === 'sent') {
            $data['sent_at'] = current_time('mysql');
            $format[] = '%s';
        }

        if ($note !== '') {
            $data['response_data'] = wp_json_encode(array('note' => $note));
            $format[] = '%s';
        }

        $wpdb->update(
            $table,
            $data,
            array('id' => (int) $invitation_id),
            $format,
            array('%d')
        );
    }

    /**
     * @param int      $invitation_id
     * @param WC_Order $order
     */
    private function close_sending_invitation($invitation_id, $order) {
        global $wpdb;

        $table = $wpdb->prefix . 'kiyoh_invitations';
        $status = $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM {$table} WHERE id = %d",
            $invitation_id
        ));

        if ($status === 'sending') {
            $this->update_invitation_status($invitation_id, 'error', 'send_did_not_complete');
            error_log("Kiyoh Invitation: Scheduled invitation {$invitation_id} for order {$order->get_id()} did not record a result — marked as error");
            $order->add_order_note(__('Kiyoh review invitation could not be sent. Check the Kiyoh log for this order.', 'kiyoh-woocommerce'));
            return;
        }

        if ($status === 'sent') {
            error_log("Kiyoh Invitation: Scheduled invitation {$invitation_id} for order {$order->get_id()} was sent to the Kiyoh API with delay 0");
            $order->add_order_note(__('Kiyoh review invitation was sent (scheduled shop time, API delay 0).', 'kiyoh-woocommerce'));
        } elseif ($status === 'error') {
            error_log("Kiyoh Invitation: Scheduled invitation {$invitation_id} for order {$order->get_id()} finished with an error");
            $order->add_order_note(__('Kiyoh review invitation failed when the scheduled job ran. See the WooCommerce error log.', 'kiyoh-woocommerce'));
        }
    }

    /**
     * @param WC_Order $order
     * @return string Empty when the order may still receive an invite.
     */
    private function scheduled_ineligibility_reason($order) {
        $status = $order->get_status();
        if (in_array($status, array('cancelled', 'refunded', 'failed', 'trash'), true)) {
            return $status;
        }

        if (empty($order->get_billing_email())) {
            return 'no_email';
        }

        if ($this->is_customer_excluded($order)) {
            return 'excluded';
        }

        return '';
    }

    /**
     * @param string $code
     * @return string
     */
    private function ineligibility_note($code) {
        switch ($code) {
            case 'cancelled':
                return __('the order is cancelled', 'kiyoh-woocommerce');
            case 'refunded':
                return __('the order is refunded', 'kiyoh-woocommerce');
            case 'failed':
                return __('the order has failed', 'kiyoh-woocommerce');
            case 'trash':
                return __('the order is in the trash', 'kiyoh-woocommerce');
            case 'no_email':
                return __('the order has no customer email', 'kiyoh-woocommerce');
            case 'excluded':
                return __('the customer is excluded', 'kiyoh-woocommerce');
            default:
                return $code;
        }
    }

    /**
     * @param int $order_id
     * @return bool
     */
    private function has_pending_or_sent_invitation($order_id) {
        global $wpdb;

        $table = $wpdb->prefix . 'kiyoh_invitations';
        $found = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE order_id = %d AND status IN ('sent', 'scheduled', 'sending') LIMIT 1",
            $order_id
        ));

        return !empty($found);
    }

    /**
     * @param int $order_id
     * @param int $except_id
     * @return bool
     */
    private function order_has_sent_invitation($order_id, $except_id = 0) {
        global $wpdb;

        $table = $wpdb->prefix . 'kiyoh_invitations';
        $found = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE order_id = %d AND status = 'sent' AND id != %d LIMIT 1",
            $order_id,
            $except_id
        ));

        return !empty($found);
    }

    /**
     * MySQL advisory lock so two workers cannot send the same order.
     * GET_LOCK is per connection and waits up to 15 seconds.
     *
     * @param int $order_id
     * @return bool
     */
    private function acquire_order_lock($order_id) {
        global $wpdb;

        $name = 'kiyoh_invite_' . (int) $order_id;
        $got = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 15)', $name));
        return (string) $got === '1';
    }

    /**
     * @param int $order_id
     */
    private function release_order_lock($order_id) {
        global $wpdb;

        $name = 'kiyoh_invite_' . (int) $order_id;
        $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
    }

    /**
     * @return DateTimeZone
     */
    private function get_shop_timezone() {
        if (function_exists('wp_timezone')) {
            return wp_timezone();
        }

        $name = function_exists('wp_timezone_string') ? wp_timezone_string() : 'UTC';
        try {
            return new DateTimeZone($name ? $name : 'UTC');
        } catch (Exception $e) {
            return new DateTimeZone('UTC');
        }
    }
}