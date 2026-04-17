<?php

class Kiyoh_Product_Sync_Manager {

    private $api_client;
    private $settings;

    public function __construct() {
        $this->settings = get_option('kiyoh_woocommerce_settings', array());
        $this->init_hooks();
    }

    private function init_hooks() {
        if (!$this->is_sync_enabled()) {
            return;
        }

        add_action('woocommerce_new_product', array($this, 'handle_product_creation'), 10, 1);
        add_action('woocommerce_update_product', array($this, 'handle_product_update'), 10, 1);
        add_action('wp_ajax_kiyoh_bulk_sync_products', array($this, 'ajax_bulk_sync_products'));
    }

    private function is_sync_enabled() {
        return !empty($this->settings['general']['enabled']) && 
               !empty($this->settings['product_sync']['auto_sync']) &&
               !empty($this->settings['general']['api_key']) &&
               !empty($this->settings['general']['location_id']);
    }

    public function handle_product_creation($product_id) {
        error_log("Kiyoh Product Sync: Product creation hook triggered for product {$product_id}");
        $this->sync_single_product($product_id);
    }

    public function handle_product_update($product_id) {
        error_log("Kiyoh Product Sync: Product update hook triggered for product {$product_id}");
        $this->sync_single_product($product_id);
    }

    public function sync_single_product($product_id) {
        try {
            if (!$this->should_sync_product($product_id)) {
                error_log("Kiyoh Product Sync: Product {$product_id} excluded from sync");
                return false;
            }

            $product_data = $this->extract_product_data($product_id);
            if (!$product_data) {
                error_log("Kiyoh Product Sync: Failed to extract data for product {$product_id}");
                return false;
            }

            $api_client = $this->get_api_client();
            if (!$api_client) {
                error_log("Kiyoh Product Sync: API client not available for product {$product_id}");
                return false;
            }

            $response = $api_client->sync_product($product_data);
            
            $this->update_sync_status($product_id, $response);
            
            if ($response->is_success()) {
                error_log("Kiyoh Product Sync: Successfully synced product {$product_id} (SKU: {$product_data['product_code']})");
            } else {
                error_log("Kiyoh Product Sync: Failed to sync product {$product_id}: " . $response->get_error());
            }
            
            return $response->is_success();
        } catch (Exception $e) {
            error_log("Kiyoh Product Sync: Exception syncing product {$product_id}: " . $e->getMessage());
            return false;
        }
    }

    public function bulk_sync_products($filters = array()) {
        $products = $this->get_products_for_sync($filters);
        $results = array(
            'total' => count($products),
            'synced' => 0,
            'errors' => 0,
            'skipped' => 0
        );

        if (empty($products)) {
            return $results;
        }

        $api_client = $this->get_api_client();
        if (!$api_client) {
            $results['errors'] = count($products);
            return $results;
        }

        // Process in batches of 50 for better performance
        $batch_size = 50;
        $batches = array_chunk($products, $batch_size);

        foreach ($batches as $batch) {
            $batch_data = array();
            $batch_product_ids = array();

            foreach ($batch as $product_id) {
                if (!$this->should_sync_product($product_id)) {
                    $results['skipped']++;
                    continue;
                }

                $product_data = $this->extract_product_data($product_id);
                if ($product_data) {
                    // Remove location_id from individual product data for bulk sync
                    unset($product_data['location_id']);
                    $batch_data[] = $product_data;
                    $batch_product_ids[] = $product_id;
                }
            }

            if (!empty($batch_data)) {
                try {
                    $response = $api_client->sync_products_bulk($batch_data);
                    
                    if ($response->is_success()) {
                        $results['synced'] += count($batch_data);
                        
                        // Update sync status for all products in batch
                        foreach ($batch_product_ids as $product_id) {
                            $this->update_sync_status($product_id, $response);
                        }
                    } else {
                        $results['errors'] += count($batch_data);
                        
                        // Log error for batch
                        error_log('Kiyoh Bulk Sync Error: ' . $response->get_error());
                        
                        // Update sync status with error for all products in batch
                        foreach ($batch_product_ids as $product_id) {
                            $this->update_sync_status($product_id, $response);
                        }
                    }
                } catch (Exception $e) {
                    $results['errors'] += count($batch_data);
                    error_log('Kiyoh Bulk Sync Exception: ' . $e->getMessage());
                }
            }

            // Add delay between batches to prevent rate limiting
            sleep(3);
        }

        return $results;
    }

    private function should_sync_product($product_id) {
        $product = wc_get_product($product_id);
        if (!$product) {
            error_log("Kiyoh Product Sync: Product {$product_id} not found");
            return false;
        }

        // Must have SKU and name to sync
        $sku = $product->get_sku();
        $name = $product->get_name();
        if (!$sku || !$name || trim($sku) === '' || trim($name) === '') {
            error_log("Kiyoh Product Sync: Product {$product_id} missing SKU ('{$sku}') or name ('{$name}') - skipping");
            return false;
        }

        // Check if product type is excluded
        $excluded_types = $this->get_excluded_product_types();
        if (in_array($product->get_type(), $excluded_types)) {
            error_log("Kiyoh Product Sync: Product {$product_id} type '{$product->get_type()}' is excluded - skipping");
            return false;
        }

        // Check if product code is excluded
        $excluded_codes = $this->get_excluded_product_codes();
        if (in_array($sku, $excluded_codes)) {
            error_log("Kiyoh Product Sync: Product {$product_id} SKU '{$sku}' is excluded - skipping");
            return false;
        }

        error_log("Kiyoh Product Sync: Product {$product_id} (SKU: '{$sku}', Name: '{$name}') passed validation - proceeding with sync");
        return true;
    }

    private function extract_product_data($product_id) {
        $product = wc_get_product($product_id);
        if (!$product) {
            return false;
        }

        // Get SKU directly - don't use fallback product ID like Magento
        $product_code = $product->get_sku();
        $product_name = $product->get_name();
        
        // Both SKU and name are required - no fallbacks
        if (!$product_code || !$product_name || trim($product_code) === '' || trim($product_name) === '') {
            error_log("Kiyoh Product Sync: Product {$product_id} missing required SKU ('{$product_code}') or name ('{$product_name}') - skipping sync");
            return false;
        }
        
        error_log("Kiyoh Product Sync: Extracting data for product {$product_id} - SKU: '{$product_code}', Name: '{$product_name}'");

        // Ensure we have a valid product URL
        $product_url = get_permalink($product_id);
        if (!$product_url || !filter_var($product_url, FILTER_VALIDATE_URL)) {
            $product_url = home_url('/product/' . urlencode(strtolower($product_code)));
        }

        // Ensure we have a valid image URL
        $image_id = $product->get_image_id();
        $image_url = '';
        if ($image_id) {
            $image_url = wp_get_attachment_image_url($image_id, 'full');
        }
        
        // Provide a fallback image URL if none available
        if (!$image_url || !filter_var($image_url, FILTER_VALIDATE_URL)) {
            $image_url = 'https://via.placeholder.com/300x300.png?text=' . urlencode($product_name);
        }

        // Get brand from product attributes or meta
        $brand = $this->get_product_brand($product);

        // Get GTIN and MPN from product meta or attributes
        $gtin = $this->get_product_gtin($product);
        $mpn = $this->get_product_mpn($product);

        // Build the basic required data
        $data = array(
            'location_id' => (string) $this->settings['general']['location_id'],
            'product_code' => (string) $product_code,
            'product_name' => (string) $product_name,
            'source_url' => $product_url,
            'image_url' => $image_url,
            'active' => ($product->get_status() === 'publish')
        );

        // Only add optional fields if they exist and are not empty
        if (!empty($brand)) {
            $data['brand_name'] = (string) $brand;
        }
        
        $sku = $product->get_sku();
        if (!empty($sku)) {
            $data['skus'] = (string) $sku;
        }
        
        if (!empty($gtin)) {
            $data['gtins'] = (string) $gtin;
        }
        
        if (!empty($mpn)) {
            $data['mpns'] = (string) $mpn;
        }

        return $data;
    }

    private function get_product_code($product) {
        // Use SKU as product code, fallback to product ID
        $sku = $product->get_sku();
        return !empty($sku) ? $sku : 'product_' . $product->get_id();
    }

    private function get_product_brand($product) {
        // Check for brand product attribute (Attributes tab)
        $brand_attribute = $product->get_attribute('brand');
        if (!empty($brand_attribute)) {
            return $brand_attribute;
        }

        // Check for brand taxonomy (WooCommerce Brands / Perfect Brands plugins)
        $brand_taxonomies = array('product_brand', 'pwb-brand', 'yith_product_brand');
        foreach ($brand_taxonomies as $taxonomy) {
            $brand_terms = get_the_terms($product->get_id(), $taxonomy);
            if (!empty($brand_terms) && !is_wp_error($brand_terms)) {
                return $brand_terms[0]->name;
            }
        }

        // Check for brand in meta fields
        $brand_fields = array('_brand', 'brand', '_manufacturer', 'manufacturer');
        foreach ($brand_fields as $field) {
            $brand_meta = get_post_meta($product->get_id(), $field, true);
            if (!empty($brand_meta)) {
                return $brand_meta;
            }
        }

        return '';
    }

    private function get_product_gtin($product) {
        // Check WooCommerce built-in GTIN field first (Product → Inventory → "GTIN, UPC, EAN, or ISBN")
        $global_unique_id = $product->get_global_unique_id();
        if (!empty($global_unique_id)) {
            return $global_unique_id;
        }

        // Check for GTIN in various meta fields
        $gtin_fields = array('_global_unique_id', '_gtin', '_gtin14', '_gtin13', '_gtin12', '_gtin8', '_ean', '_upc');
        
        foreach ($gtin_fields as $field) {
            $gtin = get_post_meta($product->get_id(), $field, true);
            if (!empty($gtin)) {
                return $gtin;
            }
        }

        // Check for GTIN attribute
        $gtin_attribute = $product->get_attribute('gtin');
        if (!empty($gtin_attribute)) {
            return $gtin_attribute;
        }

        return '';
    }

    private function get_product_mpn($product) {
        // Check for MPN in meta fields
        $mpn_fields = array('_mpn', '_manufacturer_part_number', '_part_number');
        
        foreach ($mpn_fields as $field) {
            $mpn = get_post_meta($product->get_id(), $field, true);
            if (!empty($mpn)) {
                return $mpn;
            }
        }

        // Check for MPN attribute
        $mpn_attribute = $product->get_attribute('mpn');
        if (!empty($mpn_attribute)) {
            return $mpn_attribute;
        }

        return '';
    }

    private function get_excluded_product_types() {
        $excluded = isset($this->settings['product_sync']['excluded_types']) ? 
                   $this->settings['product_sync']['excluded_types'] : array();
        return is_array($excluded) ? $excluded : array();
    }

    private function get_excluded_product_codes() {
        $excluded_codes_text = isset($this->settings['product_sync']['excluded_codes']) ? 
                              $this->settings['product_sync']['excluded_codes'] : '';
        
        if (empty($excluded_codes_text)) {
            return array();
        }

        // Split by both lines and commas, then clean up
        $codes = preg_split('/[\n,]+/', $excluded_codes_text);
        $codes = array_map('trim', $codes);
        $codes = array_filter($codes); // Remove empty entries
        
        return $codes;
    }

    private function get_products_for_sync($filters = array()) {
        $args = array(
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids'
        );

        // Apply filters if provided
        if (!empty($filters['product_types'])) {
            $args['tax_query'] = array(
                array(
                    'taxonomy' => 'product_type',
                    'field' => 'slug',
                    'terms' => $filters['product_types']
                )
            );
        }

        $query = new WP_Query($args);
        return $query->posts;
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

    private function update_sync_status($product_id, $response) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'kiyoh_product_sync';
        
        $data = array(
            'product_id' => $product_id,
            'last_sync' => current_time('mysql'),
            'sync_status' => $response->is_success() ? 'success' : 'error',
            'error_message' => $response->is_success() ? null : $response->get_error(),
            'updated_at' => current_time('mysql')
        );

        // Check if record exists
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table_name} WHERE product_id = %d",
            $product_id
        ));

        if ($existing) {
            $wpdb->update($table_name, $data, array('product_id' => $product_id));
        } else {
            $data['created_at'] = current_time('mysql');
            $wpdb->insert($table_name, $data);
        }
    }

    public function ajax_bulk_sync_products() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'kiyoh_admin_nonce')) {
            wp_die('Security check failed');
        }

        // Check user capabilities
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Insufficient permissions');
        }

        $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
        $per_page = 50; // Process 50 products per batch
        
        $offset = ($page - 1) * $per_page;
        
        // Get products for this batch
        $args = array(
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => $per_page,
            'offset' => $offset,
            'fields' => 'ids'
        );

        $query = new WP_Query($args);
        $products = $query->posts;
        $total_products = $query->found_posts;

        $results = array(
            'batch_synced' => 0,
            'batch_errors' => 0,
            'batch_skipped' => 0,
            'total_products' => $total_products,
            'current_page' => $page,
            'has_more' => count($products) === $per_page
        );

        foreach ($products as $product_id) {
            if (!$this->should_sync_product($product_id)) {
                $results['batch_skipped']++;
                continue;
            }

            $success = $this->sync_single_product($product_id);
            if ($success) {
                $results['batch_synced']++;
            } else {
                $results['batch_errors']++;
            }

            // Small delay between products
            usleep(500000); // 0.5 seconds
        }

        wp_send_json_success($results);
    }

    public function get_sync_statistics() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'kiyoh_product_sync';
        
        $stats = $wpdb->get_row("
            SELECT 
                COUNT(*) as total_synced,
                SUM(CASE WHEN sync_status = 'success' THEN 1 ELSE 0 END) as successful,
                SUM(CASE WHEN sync_status = 'error' THEN 1 ELSE 0 END) as errors,
                MAX(last_sync) as last_sync_time
            FROM {$table_name}
        ");

        // Get total products count
        $total_products = wp_count_posts('product')->publish;

        return array(
            'total_products' => $total_products,
            'total_synced' => $stats->total_synced ?: 0,
            'successful' => $stats->successful ?: 0,
            'errors' => $stats->errors ?: 0,
            'last_sync_time' => $stats->last_sync_time,
            'sync_percentage' => $total_products > 0 ? round(($stats->total_synced / $total_products) * 100, 1) : 0
        );
    }
}