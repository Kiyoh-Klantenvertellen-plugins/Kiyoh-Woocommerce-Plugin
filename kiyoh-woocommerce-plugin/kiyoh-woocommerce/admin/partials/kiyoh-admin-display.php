<?php

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Check if WooCommerce is active
if (!class_exists('WooCommerce')) {
    ?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        <div class="notice notice-error">
            <p><?php _e('WooCommerce is required for this plugin to work. Please install and activate WooCommerce.', 'kiyoh-woocommerce'); ?></p>
        </div>
    </div>
    <?php
    return;
}

$settings = get_option('kiyoh_woocommerce_settings', array());
$active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'general';
?>

<div class="wrap">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
    
    <?php if (isset($_GET['settings-updated']) && $_GET['settings-updated']): ?>
        <div class="notice notice-success is-dismissible">
            <p><?php _e('Settings saved successfully!', 'kiyoh-woocommerce'); ?></p>
        </div>
    <?php endif; ?>
    
    <nav class="nav-tab-wrapper">
        <a href="?page=kiyoh-settings&tab=general" class="nav-tab <?php echo $active_tab == 'general' ? 'nav-tab-active' : ''; ?>">
            <?php _e('General', 'kiyoh-woocommerce'); ?>
        </a>
        <a href="?page=kiyoh-settings&tab=product-sync" class="nav-tab <?php echo $active_tab == 'product-sync' ? 'nav-tab-active' : ''; ?>">
            <?php _e('Product Sync', 'kiyoh-woocommerce'); ?>
        </a>
        <a href="?page=kiyoh-settings&tab=invitations" class="nav-tab <?php echo $active_tab == 'invitations' ? 'nav-tab-active' : ''; ?>">
            <?php _e('Invitations', 'kiyoh-woocommerce'); ?>
        </a>

    </nav>
    
    <form method="post" action="options.php">
        <?php settings_fields('kiyoh_woocommerce_settings'); ?>
        
        <div class="tab-content">
            <!-- General Tab -->
            <div class="tab-panel tab-general" <?php echo $active_tab != 'general' ? 'style="display:none;"' : ''; ?>>
                <h2><?php _e('General Settings', 'kiyoh-woocommerce'); ?></h2>
                <p><?php _e('Configure your Kiyoh/Klantenvertellen account connection.', 'kiyoh-woocommerce'); ?></p>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="enabled"><?php _e('Enable Plugin', 'kiyoh-woocommerce'); ?></label>
                        </th>
                        <td>
                            <input type="checkbox" id="enabled" name="kiyoh_woocommerce_settings[general][enabled]" value="1" <?php checked(!empty($settings['general']['enabled']), true); ?> />
                            <p class="description"><?php _e('Enable Kiyoh WooCommerce integration', 'kiyoh-woocommerce'); ?></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="platform"><?php _e('Platform', 'kiyoh-woocommerce'); ?></label>
                        </th>
                        <td>
                            <select id="platform" name="kiyoh_woocommerce_settings[general][platform]">
                                <option value="kiyoh" <?php selected(isset($settings['general']['platform']) ? $settings['general']['platform'] : 'kiyoh', 'kiyoh'); ?>><?php _e('Kiyoh.com', 'kiyoh-woocommerce'); ?></option>
                                <option value="klantenvertellen" <?php selected(isset($settings['general']['platform']) ? $settings['general']['platform'] : 'kiyoh', 'klantenvertellen'); ?>><?php _e('Klantenvertellen.nl', 'kiyoh-woocommerce'); ?></option>
                            </select>
                            <p class="description"><?php _e('Select your review platform', 'kiyoh-woocommerce'); ?></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="location_id"><?php _e('Location ID', 'kiyoh-woocommerce'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="location_id" name="kiyoh_woocommerce_settings[general][location_id]" value="<?php echo esc_attr(isset($settings['general']['location_id']) ? $settings['general']['location_id'] : ''); ?>" class="regular-text" />
                            <p class="description"><?php _e('Your location ID from your Kiyoh/Klantenvertellen account', 'kiyoh-woocommerce'); ?></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="api_key"><?php _e('API Key', 'kiyoh-woocommerce'); ?></label>
                        </th>
                        <td>
                            <input type="password" id="api_key" name="kiyoh_woocommerce_settings[general][api_key]" value="<?php echo esc_attr(isset($settings['general']['api_key']) ? $settings['general']['api_key'] : ''); ?>" class="regular-text" />
                            <p class="description"><?php _e('Your API key from your Kiyoh/Klantenvertellen account', 'kiyoh-woocommerce'); ?></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label><?php _e('Test Connection', 'kiyoh-woocommerce'); ?></label>
                        </th>
                        <td>
                            <button type="button" id="test-api-connection" class="button button-secondary" disabled>
                                <?php _e('Test API Connection', 'kiyoh-woocommerce'); ?>
                            </button>
                            <p class="description"><?php _e('Please add credentials and click Save Changes before running the test. Test your API credentials by fetching company statistics.', 'kiyoh-woocommerce'); ?></p>
                            <div id="api-test-results" style="margin-top: 10px;"></div>
                        </td>
                    </tr>
                </table>
            </div>
            
            <!-- Product Sync Tab -->
            <div class="tab-panel tab-product-sync" <?php echo $active_tab != 'product-sync' ? 'style="display:none;"' : ''; ?>>
                <h2><?php _e('Product Sync Settings', 'kiyoh-woocommerce'); ?></h2>
                <p><?php _e('Configure how products are synchronized with your review platform.', 'kiyoh-woocommerce'); ?></p>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="auto_sync"><?php _e('Auto Sync Products', 'kiyoh-woocommerce'); ?></label>
                        </th>
                        <td>
                            <input type="checkbox" id="auto_sync" name="kiyoh_woocommerce_settings[product_sync][auto_sync]" value="1" <?php checked(!empty($settings['product_sync']['auto_sync']), true); ?> />
                            <p class="description"><?php _e('Automatically sync products when they are created or updated', 'kiyoh-woocommerce'); ?></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="excluded_types"><?php _e('Excluded Product Types', 'kiyoh-woocommerce'); ?></label>
                        </th>
                        <td>
                            <select id="excluded_types" name="kiyoh_woocommerce_settings[product_sync][excluded_types][]" multiple="multiple" size="5">
                                <?php
                                $product_types = function_exists('wc_get_product_types') ? wc_get_product_types() : array();
                                $excluded_types = isset($settings['product_sync']['excluded_types']) ? (array) $settings['product_sync']['excluded_types'] : array();
                                foreach ($product_types as $type => $label):
                                    $selected = in_array($type, $excluded_types) ? 'selected="selected"' : '';
                                ?>
                                    <option value="<?php echo esc_attr($type); ?>" <?php echo $selected; ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php _e('Select product types to exclude from synchronization (hold Ctrl/Cmd to select multiple)', 'kiyoh-woocommerce'); ?></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="excluded_codes"><?php _e('Excluded Product Codes', 'kiyoh-woocommerce'); ?></label>
                        </th>
                        <td>
                            <textarea id="excluded_codes" name="kiyoh_woocommerce_settings[product_sync][excluded_codes]" rows="5" cols="50" class="large-text"><?php echo esc_textarea(isset($settings['product_sync']['excluded_codes']) ? $settings['product_sync']['excluded_codes'] : ''); ?></textarea>
                            <p class="description"><?php _e('Enter product codes/SKUs to exclude from sync (one per line or comma-separated)', 'kiyoh-woocommerce'); ?></p>
                        </td>
                    </tr>
                </table>
                
                <h3><?php _e('Bulk Actions', 'kiyoh-woocommerce'); ?></h3>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label><?php _e('Bulk Product Sync', 'kiyoh-woocommerce'); ?></label>
                        </th>
                        <td>
                            <button type="button" id="bulk-sync-products" class="button button-secondary" disabled>
                                <?php _e('Sync All Products', 'kiyoh-woocommerce'); ?>
                            </button>
                            <p class="description"><?php _e('Sync all existing products to your review platform. This may take some time for large catalogs. Save API credentials first before activating this button.', 'kiyoh-woocommerce'); ?></p>
                            <p class="description" style="color: #d63638;"><?php _e('Note: Configure your API credentials first before using this tool.', 'kiyoh-woocommerce'); ?></p>
                        </td>
                    </tr>
                </table>
                
                <div id="product-sync-results" style="margin-top: 20px;"></div>
            </div>
            
            <!-- Invitations Tab -->
            <div class="tab-panel tab-invitations" <?php echo $active_tab != 'invitations' ? 'style="display:none;"' : ''; ?>>
                <h2><?php _e('Review Invitation Settings', 'kiyoh-woocommerce'); ?></h2>
                <p><?php _e('Configure when and how review invitations are sent to customers.', 'kiyoh-woocommerce'); ?></p>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="trigger_status"><?php _e('Trigger Order Status', 'kiyoh-woocommerce'); ?></label>
                        </th>
                        <td>
                            <select id="trigger_status" name="kiyoh_woocommerce_settings[invitations][trigger_status][]" multiple="multiple" size="5">
                                <?php
                                $order_statuses = function_exists('wc_get_order_statuses') ? wc_get_order_statuses() : array('completed' => __('Completed', 'kiyoh-woocommerce'));
                                $trigger_statuses = isset($settings['invitations']['trigger_status']) ? (array) $settings['invitations']['trigger_status'] : array('completed');
                                foreach ($order_statuses as $status => $label):
                                    $status_key = str_replace('wc-', '', $status);
                                    $selected = in_array($status_key, $trigger_statuses) ? 'selected="selected"' : '';
                                ?>
                                    <option value="<?php echo esc_attr($status_key); ?>" <?php echo $selected; ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php _e('Select order statuses that trigger review invitations (hold Ctrl/Cmd to select multiple)', 'kiyoh-woocommerce'); ?></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="delay_days"><?php _e('Invitation Delay (Days)', 'kiyoh-woocommerce'); ?></label>
                        </th>
                        <td>
                            <input type="number" id="delay_days" name="kiyoh_woocommerce_settings[invitations][delay_days]" value="<?php echo esc_attr(isset($settings['invitations']['delay_days']) ? $settings['invitations']['delay_days'] : 3); ?>" min="0" max="365" class="small-text" />
                            <p class="description"><?php _e('Number of days to wait before sending invitation (0 for immediate)', 'kiyoh-woocommerce'); ?></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="invitation_type"><?php _e('Invitation Type', 'kiyoh-woocommerce'); ?></label>
                        </th>
                        <td>
                            <select id="invitation_type" name="kiyoh_woocommerce_settings[invitations][invitation_type]">
                                <option value="shop_and_product" <?php selected(isset($settings['invitations']['invitation_type']) ? $settings['invitations']['invitation_type'] : 'shop_and_product', 'shop_and_product'); ?>><?php _e('Shop + Product Reviews', 'kiyoh-woocommerce'); ?></option>
                                <option value="shop_only" <?php selected(isset($settings['invitations']['invitation_type']) ? $settings['invitations']['invitation_type'] : 'shop_and_product', 'shop_only'); ?>><?php _e('Shop Reviews Only', 'kiyoh-woocommerce'); ?></option>
                                <option value="product_only" <?php selected(isset($settings['invitations']['invitation_type']) ? $settings['invitations']['invitation_type'] : 'shop_and_product', 'product_only'); ?>><?php _e('Product Reviews Only', 'kiyoh-woocommerce'); ?></option>
                            </select>
                            <p class="description"><?php _e('Choose what type of reviews to request in invitations', 'kiyoh-woocommerce'); ?></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="max_products"><?php _e('Max Products per Invitation', 'kiyoh-woocommerce'); ?></label>
                        </th>
                        <td>
                            <input type="number" id="max_products" name="kiyoh_woocommerce_settings[invitations][max_products]" value="<?php echo esc_attr(isset($settings['invitations']['max_products']) ? $settings['invitations']['max_products'] : 5); ?>" min="1" max="10" class="small-text" />
                            <p class="description"><?php _e('Maximum number of products to include in each invitation', 'kiyoh-woocommerce'); ?></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="product_sort_order"><?php _e('Product Sort Order', 'kiyoh-woocommerce'); ?></label>
                        </th>
                        <td>
                            <select id="product_sort_order" name="kiyoh_woocommerce_settings[invitations][product_sort_order]">
                                <option value="cart_order" <?php selected(isset($settings['invitations']['product_sort_order']) ? $settings['invitations']['product_sort_order'] : 'cart_order', 'cart_order'); ?>><?php _e('Cart Order (Default)', 'kiyoh-woocommerce'); ?></option>
                                <option value="price_desc" <?php selected(isset($settings['invitations']['product_sort_order']) ? $settings['invitations']['product_sort_order'] : 'cart_order', 'price_desc'); ?>><?php _e('Price (High to Low)', 'kiyoh-woocommerce'); ?></option>
                                <option value="price_asc" <?php selected(isset($settings['invitations']['product_sort_order']) ? $settings['invitations']['product_sort_order'] : 'cart_order', 'price_asc'); ?>><?php _e('Price (Low to High)', 'kiyoh-woocommerce'); ?></option>
                                <option value="name_asc" <?php selected(isset($settings['invitations']['product_sort_order']) ? $settings['invitations']['product_sort_order'] : 'cart_order', 'name_asc'); ?>><?php _e('Name (A to Z)', 'kiyoh-woocommerce'); ?></option>
                                <option value="name_desc" <?php selected(isset($settings['invitations']['product_sort_order']) ? $settings['invitations']['product_sort_order'] : 'cart_order', 'name_desc'); ?>><?php _e('Name (Z to A)', 'kiyoh-woocommerce'); ?></option>
                                <option value="sku_asc" <?php selected(isset($settings['invitations']['product_sort_order']) ? $settings['invitations']['product_sort_order'] : 'cart_order', 'sku_asc'); ?>><?php _e('SKU (A to Z)', 'kiyoh-woocommerce'); ?></option>
                                <option value="sku_desc" <?php selected(isset($settings['invitations']['product_sort_order']) ? $settings['invitations']['product_sort_order'] : 'cart_order', 'sku_desc'); ?>><?php _e('SKU (Z to A)', 'kiyoh-woocommerce'); ?></option>
                            </select>
                            <p class="description"><?php _e('How to prioritize products when there are more products than the maximum allowed', 'kiyoh-woocommerce'); ?></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="excluded_customer_groups"><?php _e('Excluded Customer Groups', 'kiyoh-woocommerce'); ?></label>
                        </th>
                        <td>
                            <select id="excluded_customer_groups" name="kiyoh_woocommerce_settings[invitations][excluded_customer_groups][]" multiple="multiple" size="5">
                                <?php
                                global $wp_roles;
                                if (!isset($wp_roles)) {
                                    $wp_roles = new WP_Roles();
                                }
                                $excluded_groups = isset($settings['invitations']['excluded_customer_groups']) ? (array) $settings['invitations']['excluded_customer_groups'] : array();
                                foreach ($wp_roles->roles as $role_key => $role):
                                    $selected = in_array($role_key, $excluded_groups) ? 'selected="selected"' : '';
                                ?>
                                    <option value="<?php echo esc_attr($role_key); ?>" <?php echo $selected; ?>><?php echo esc_html($role['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php _e('Select customer groups to exclude from invitations (hold Ctrl/Cmd to select multiple)', 'kiyoh-woocommerce'); ?></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="language_auto"><?php _e('Auto-detect Language', 'kiyoh-woocommerce'); ?></label>
                        </th>
                        <td>
                            <input type="checkbox" id="language_auto" name="kiyoh_woocommerce_settings[invitations][language_auto]" value="1" <?php checked(!empty($settings['invitations']['language_auto']), true); ?> />
                            <p class="description"><?php _e('Automatically detect customer language from WordPress locale', 'kiyoh-woocommerce'); ?></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="fallback_language"><?php _e('Fallback Language', 'kiyoh-woocommerce'); ?></label>
                        </th>
                        <td>
                            <select id="fallback_language" name="kiyoh_woocommerce_settings[invitations][fallback_language]">
                                <option value="en" <?php selected(isset($settings['invitations']['fallback_language']) ? $settings['invitations']['fallback_language'] : 'en', 'en'); ?>><?php _e('English', 'kiyoh-woocommerce'); ?></option>
                                <option value="nl" <?php selected(isset($settings['invitations']['fallback_language']) ? $settings['invitations']['fallback_language'] : 'en', 'nl'); ?>><?php _e('Dutch (Nederlands)', 'kiyoh-woocommerce'); ?></option>
                                <option value="de" <?php selected(isset($settings['invitations']['fallback_language']) ? $settings['invitations']['fallback_language'] : 'en', 'de'); ?>><?php _e('German (Deutsch)', 'kiyoh-woocommerce'); ?></option>
                                <option value="fr" <?php selected(isset($settings['invitations']['fallback_language']) ? $settings['invitations']['fallback_language'] : 'en', 'fr'); ?>><?php _e('French (Français)', 'kiyoh-woocommerce'); ?></option>
                                <option value="es" <?php selected(isset($settings['invitations']['fallback_language']) ? $settings['invitations']['fallback_language'] : 'en', 'es'); ?>><?php _e('Spanish (Español)', 'kiyoh-woocommerce'); ?></option>
                                <option value="it" <?php selected(isset($settings['invitations']['fallback_language']) ? $settings['invitations']['fallback_language'] : 'en', 'it'); ?>><?php _e('Italian (Italiano)', 'kiyoh-woocommerce'); ?></option>
                                <option value="pt" <?php selected(isset($settings['invitations']['fallback_language']) ? $settings['invitations']['fallback_language'] : 'en', 'pt'); ?>><?php _e('Portuguese (Português)', 'kiyoh-woocommerce'); ?></option>
                                <option value="da" <?php selected(isset($settings['invitations']['fallback_language']) ? $settings['invitations']['fallback_language'] : 'en', 'da'); ?>><?php _e('Danish (Dansk)', 'kiyoh-woocommerce'); ?></option>
                                <option value="sv" <?php selected(isset($settings['invitations']['fallback_language']) ? $settings['invitations']['fallback_language'] : 'en', 'sv'); ?>><?php _e('Swedish (Svenska)', 'kiyoh-woocommerce'); ?></option>
                                <option value="no" <?php selected(isset($settings['invitations']['fallback_language']) ? $settings['invitations']['fallback_language'] : 'en', 'no'); ?>><?php _e('Norwegian (Norsk)', 'kiyoh-woocommerce'); ?></option>
                                <option value="fi" <?php selected(isset($settings['invitations']['fallback_language']) ? $settings['invitations']['fallback_language'] : 'en', 'fi'); ?>><?php _e('Finnish (Suomi)', 'kiyoh-woocommerce'); ?></option>
                                <option value="pl" <?php selected(isset($settings['invitations']['fallback_language']) ? $settings['invitations']['fallback_language'] : 'en', 'pl'); ?>><?php _e('Polish (Polski)', 'kiyoh-woocommerce'); ?></option>
                                <option value="cs" <?php selected(isset($settings['invitations']['fallback_language']) ? $settings['invitations']['fallback_language'] : 'en', 'cs'); ?>><?php _e('Czech (Čeština)', 'kiyoh-woocommerce'); ?></option>
                                <option value="sk" <?php selected(isset($settings['invitations']['fallback_language']) ? $settings['invitations']['fallback_language'] : 'en', 'sk'); ?>><?php _e('Slovak (Slovenčina)', 'kiyoh-woocommerce'); ?></option>
                                <option value="hu" <?php selected(isset($settings['invitations']['fallback_language']) ? $settings['invitations']['fallback_language'] : 'en', 'hu'); ?>><?php _e('Hungarian (Magyar)', 'kiyoh-woocommerce'); ?></option>
                                <option value="ro" <?php selected(isset($settings['invitations']['fallback_language']) ? $settings['invitations']['fallback_language'] : 'en', 'ro'); ?>><?php _e('Romanian (Română)', 'kiyoh-woocommerce'); ?></option>
                                <option value="bg" <?php selected(isset($settings['invitations']['fallback_language']) ? $settings['invitations']['fallback_language'] : 'en', 'bg'); ?>><?php _e('Bulgarian (Български)', 'kiyoh-woocommerce'); ?></option>
                                <option value="hr" <?php selected(isset($settings['invitations']['fallback_language']) ? $settings['invitations']['fallback_language'] : 'en', 'hr'); ?>><?php _e('Croatian (Hrvatski)', 'kiyoh-woocommerce'); ?></option>
                                <option value="sl" <?php selected(isset($settings['invitations']['fallback_language']) ? $settings['invitations']['fallback_language'] : 'en', 'sl'); ?>><?php _e('Slovenian (Slovenščina)', 'kiyoh-woocommerce'); ?></option>
                                <option value="et" <?php selected(isset($settings['invitations']['fallback_language']) ? $settings['invitations']['fallback_language'] : 'en', 'et'); ?>><?php _e('Estonian (Eesti)', 'kiyoh-woocommerce'); ?></option>
                                <option value="lv" <?php selected(isset($settings['invitations']['fallback_language']) ? $settings['invitations']['fallback_language'] : 'en', 'lv'); ?>><?php _e('Latvian (Latviešu)', 'kiyoh-woocommerce'); ?></option>
                                <option value="lt" <?php selected(isset($settings['invitations']['fallback_language']) ? $settings['invitations']['fallback_language'] : 'en', 'lt'); ?>><?php _e('Lithuanian (Lietuvių)', 'kiyoh-woocommerce'); ?></option>
                                <option value="ru" <?php selected(isset($settings['invitations']['fallback_language']) ? $settings['invitations']['fallback_language'] : 'en', 'ru'); ?>><?php _e('Russian (Русский)', 'kiyoh-woocommerce'); ?></option>
                                <option value="tr" <?php selected(isset($settings['invitations']['fallback_language']) ? $settings['invitations']['fallback_language'] : 'en', 'tr'); ?>><?php _e('Turkish (Türkçe)', 'kiyoh-woocommerce'); ?></option>
                                <option value="gr" <?php selected(isset($settings['invitations']['fallback_language']) ? $settings['invitations']['fallback_language'] : 'en', 'gr'); ?>><?php _e('Greek (Ελληνικά)', 'kiyoh-woocommerce'); ?></option>
                                <option value="zh" <?php selected(isset($settings['invitations']['fallback_language']) ? $settings['invitations']['fallback_language'] : 'en', 'zh'); ?>><?php _e('Chinese (中文)', 'kiyoh-woocommerce'); ?></option>
                                <option value="ja" <?php selected(isset($settings['invitations']['fallback_language']) ? $settings['invitations']['fallback_language'] : 'en', 'ja'); ?>><?php _e('Japanese (日本語)', 'kiyoh-woocommerce'); ?></option>
                            </select>
                            <p class="description"><?php _e('Language to use when customer language cannot be detected', 'kiyoh-woocommerce'); ?></p>
                        </td>
                    </tr>
                </table>
            </div>
            

        </div>
        
        <div class="submit-section" id="submit-section">
            <?php submit_button(); ?>
        </div>
    </form>
</div>

<script type="text/javascript">
// Pass PHP data to JavaScript
window.kiyohAdminData = {
    hasCredentials: <?php echo json_encode(!empty($settings['general']['location_id']) && !empty($settings['general']['api_key'])); ?>,
    activeTab: '<?php echo esc_js($active_tab); ?>'
};
</script>