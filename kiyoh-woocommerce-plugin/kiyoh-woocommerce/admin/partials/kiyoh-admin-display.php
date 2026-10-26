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
$active_tab = isset($_GET['tab']) ? sanitize_text_field(wp_unslash($_GET['tab'])) : 'general'; // preflight: ignore — sanitized, then escaped when printed in the nav
$connect_account = get_option('kiyoh_connect_account', array());
if (!is_array($connect_account)) {
    $connect_account = array();
}
$credential_source = (isset($settings['general']['credential_source']) && $settings['general']['credential_source'] === 'connect') ? 'connect' : 'manual';
$connect_status = isset($connect_account['status']) ? (string) $connect_account['status'] : '';
$connect_flash = get_transient('kiyoh_connect_flash');
if (is_array($connect_flash)) {
    delete_transient('kiyoh_connect_flash');
}
$connect_query = isset($_GET['kiyoh_connect']) ? sanitize_key(wp_unslash($_GET['kiyoh_connect'])) : ''; // preflight: ignore — allow-listed to connected or error before display
$callback_origin = class_exists('Kiyoh_Connect') ? Kiyoh_Connect::shop_origin(home_url('/')) : '';
?>

<div class="wrap kiyoh-admin-settings">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
    
    <?php if (isset($_GET['settings-updated']) && $_GET['settings-updated']): // preflight: ignore — presence check only; the notice text is a fixed translated string ?>
        <div class="notice notice-success is-dismissible">
            <p><?php _e('Settings saved successfully!', 'kiyoh-woocommerce'); ?></p>
        </div>
    <?php endif; ?>

    <?php
    $connect_notice = '';
    $connect_notice_ok = false;
    if ($connect_query === 'connected') {
        $connect_notice_ok = true;
        $connect_notice = (is_array($connect_flash) && !empty($connect_flash['message']))
            ? $connect_flash['message']
            : __('Connected with Kiyoh-Connect. The location and invite key are saved.', 'kiyoh-woocommerce');
    } elseif ($connect_query === 'error') {
        $connect_notice = (is_array($connect_flash) && !empty($connect_flash['message']))
            ? $connect_flash['message']
            : __('Kiyoh-Connect could not complete the sign-in.', 'kiyoh-woocommerce');
    } elseif (is_array($connect_flash) && !empty($connect_flash['message'])) {
        $connect_notice_ok = !empty($connect_flash['ok']);
        $connect_notice = $connect_flash['message'];
    }
    ?>
    <?php if ($connect_notice !== ''): ?>
        <div class="notice <?php echo $connect_notice_ok ? 'notice-success' : 'notice-error'; ?> is-dismissible">
            <p><?php echo esc_html($connect_notice); ?></p>
        </div>
    <?php endif; ?>
    
    <nav class="nav-tab-wrapper">
        <a href="?page=kiyoh-settings&tab=general" class="nav-tab <?php echo $active_tab == 'general' ? 'nav-tab-active' : ''; ?>">
            <?php _e('General', 'kiyoh-woocommerce'); ?>
        </a>
        <a href="?page=kiyoh-settings&tab=product-sync" class="nav-tab <?php echo $active_tab == 'product-sync' ? 'nav-tab-active' : ''; ?>">
            <?php _e('Product Sync', 'kiyoh-woocommerce'); ?>
        </a>
        <a href="?page=kiyoh-settings&tab=reviews" class="nav-tab <?php echo $active_tab == 'reviews' ? 'nav-tab-active' : ''; ?>">
            <?php _e('Reviews', 'kiyoh-woocommerce'); ?>
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
                        <th scope="row"><?php esc_html_e('Kiyoh-Connect', 'kiyoh-woocommerce'); ?></th>
                        <td>
                            <?php if ($credential_source === 'connect' && $connect_status === 'broken'): ?>
                                <p><strong><?php esc_html_e('Kiyoh-Connect could not refresh this shop. Connect again, or paste a key below.', 'kiyoh-woocommerce'); ?></strong></p>
                            <?php elseif ($credential_source === 'connect'): ?>
                                <p><strong><?php esc_html_e('Connected with Kiyoh-Connect.', 'kiyoh-woocommerce'); ?></strong>
                                    <?php if (!empty($connect_account['location_name'])): ?>
                                        <?php echo esc_html($connect_account['location_name']); ?>
                                    <?php endif; ?>
                                </p>
                            <?php else: ?>
                                <p><?php esc_html_e('Using a manually entered API key, or not connected yet.', 'kiyoh-woocommerce'); ?></p>
                            <?php endif; ?>
                            <button type="button" class="button button-primary" id="kiyoh-connect-start">
                                <?php esc_html_e('Connect with Kiyoh / Klantenvertellen', 'kiyoh-woocommerce'); ?>
                            </button>
                            <?php if ($credential_source === 'connect'): ?>
                                <button type="button" class="button" id="kiyoh-connect-disconnect">
                                    <?php esc_html_e('Disconnect Kiyoh-Connect', 'kiyoh-woocommerce'); ?>
                                </button>
                            <?php endif; ?>
                            <div id="kiyoh-connect-results" style="margin-top: 10px;"></div>
                            <p class="description"><?php esc_html_e('Opens Kiyoh-Connect so the merchant can sign in and pick a location. The shop must use HTTPS and pretty permalinks. An existing install can ignore this and keep the Location ID and API key below.', 'kiyoh-woocommerce'); ?></p>
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
                            <p class="description"><?php esc_html_e('Filled by Connect, or paste a key from an existing install. Editing either field switches this shop back to the manual key.', 'kiyoh-woocommerce'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="mobile_urls"><?php esc_html_e('Kiyoh Mobile URL(s)', 'kiyoh-woocommerce'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="mobile_urls" name="kiyoh_woocommerce_settings[general][mobile_urls]" value="<?php echo esc_attr(isset($settings['general']['mobile_urls']) ? $settings['general']['mobile_urls'] : ''); ?>" class="regular-text" />
                            <p class="description"><?php esc_html_e('Comma-separated Kiyoh Mobile base URLs for shop health reports. Leave blank to report only to https://kiyoh-mobile.com. Testers: enter https://kiyohmobileui-staging.up.railway.app.', 'kiyoh-woocommerce'); ?></p>
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
                            <label for="send_parent_product_code"><?php esc_html_e('Send parent product code for variations', 'kiyoh-woocommerce'); ?></label>
                        </th>
                        <td>
                            <input type="checkbox" id="send_parent_product_code" name="kiyoh_woocommerce_settings[product_sync][send_parent_product_code]" value="1" <?php checked(!empty($settings['product_sync']['send_parent_product_code']), true); ?> />
                            <p class="description"><?php esc_html_e('Off by default. When on, invitations and product sync use the parent variable product and its SKU. Two variations of the same parent count as one product. A parent with no SKU is skipped. When off, each purchased variation keeps its own SKU.', 'kiyoh-woocommerce'); ?></p>
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

            <!-- Reviews Tab -->
            <div class="tab-panel tab-reviews" <?php echo $active_tab != 'reviews' ? 'style="display:none;"' : ''; ?>>
                <h2><?php _e('Review Display Settings', 'kiyoh-woocommerce'); ?></h2>
                <p><?php _e('Choose which ratings drive WooCommerce catalog and product-page ministars, and which reviews appear in the product Reviews tab. Kiyoh scores are divided by 2 to match WooCommerce\'s 5-star scale. "Both" is a weighted average by review count.', 'kiyoh-woocommerce'); ?></p>
                <input type="hidden" name="kiyoh_woocommerce_settings[ratings][_section]" value="1" />
                <?php
                $rating_source = 'kiyoh';
                if (!empty($settings['ratings']['source'])) {
                    $rating_source = $settings['ratings']['source'];
                } elseif (isset($settings['ratings']['enabled']) && empty($settings['ratings']['enabled'])) {
                    $rating_source = 'woocommerce';
                }
                ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <?php _e('Review source', 'kiyoh-woocommerce'); ?>
                        </th>
                        <td>
                            <fieldset>
                                <label>
                                    <input type="radio" name="kiyoh_woocommerce_settings[ratings][source]" value="kiyoh" <?php checked($rating_source, 'kiyoh'); ?> />
                                    <?php _e('Kiyoh only', 'kiyoh-woocommerce'); ?>
                                </label><br />
                                <label>
                                    <input type="radio" name="kiyoh_woocommerce_settings[ratings][source]" value="woocommerce" <?php checked($rating_source, 'woocommerce'); ?> />
                                    <?php _e('WooCommerce only', 'kiyoh-woocommerce'); ?>
                                </label><br />
                                <label>
                                    <input type="radio" name="kiyoh_woocommerce_settings[ratings][source]" value="both" <?php checked($rating_source, 'both'); ?> />
                                    <?php _e('Both (aggregate)', 'kiyoh-woocommerce'); ?>
                                </label>
                            </fieldset>
                            <p class="description"><?php _e('This applies to ministars and the product Reviews tab. Kiyoh only shows Kiyoh ratings and comments. WooCommerce only shows native store reviews. Both aggregates the star scores and lists both sets of comments.', 'kiyoh-woocommerce'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label><?php _e('Sync Product Ratings', 'kiyoh-woocommerce'); ?></label>
                        </th>
                        <td>
                            <button type="button" id="sync-product-ratings" class="button button-secondary" data-idle-label="<?php echo esc_attr__('Sync Ratings Now', 'kiyoh-woocommerce'); ?>" disabled>
                                <?php _e('Sync Ratings Now', 'kiyoh-woocommerce'); ?>
                            </button>
                            <p class="description"><?php _e('Fetch Kiyoh product ratings and comments and apply them to matching products (by SKU). Ratings also refresh automatically every hour. Kiyoh comments are imported when the source is Kiyoh only or Both.', 'kiyoh-woocommerce'); ?></p>
                            <p class="description"><?php _e('Sync runs in the background (one product every 3 seconds). You can leave this page and come back; progress is kept.', 'kiyoh-woocommerce'); ?></p>
                            <?php
                            $rating_sync_state = get_option('kiyoh_ratings_sync_state', array());
                            $rating_sync_job = get_option('kiyoh_ratings_sync_job', array());
                            $rating_job_active = !empty($rating_sync_job['status']) && in_array($rating_sync_job['status'], array('queued', 'running'), true);
                            ?>
                            <p class="description" id="rating-sync-last" <?php echo empty($rating_sync_state['last_sync_local']) ? 'style="display:none;"' : ''; ?>>
                                <?php
                                if (!empty($rating_sync_state['last_sync_local'])) {
                                    printf(
                                        /* translators: %s: local datetime of last rating sync */
                                        esc_html__('Last sync: %s', 'kiyoh-woocommerce'),
                                        esc_html($rating_sync_state['last_sync_local'])
                                    );
                                }
                                ?>
                            </p>
                            <div id="rating-sync-progress" class="kiyoh-rating-sync-progress" <?php echo $rating_job_active ? '' : 'style="display:none;"'; ?>>
                                <p>
                                    <span class="kiyoh-spinner"></span>
                                    <span class="kiyoh-rating-sync-progress-text">
                                        <?php
                                        if ($rating_job_active) {
                                            printf(
                                                /* translators: 1: processed count, 2: total count */
                                                esc_html__('Syncing ratings… %1$d of %2$d', 'kiyoh-woocommerce'),
                                                isset($rating_sync_job['processed']) ? (int) $rating_sync_job['processed'] : 0,
                                                isset($rating_sync_job['total']) ? (int) $rating_sync_job['total'] : 1
                                            );
                                        }
                                        ?>
                                    </span>
                                </p>
                                <div class="kiyoh-rating-sync-progress-bar"><span></span></div>
                            </div>
                            <div id="rating-sync-results" style="margin-top: 10px;"></div>
                        </td>
                    </tr>
                </table>
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
                            <p class="description"><?php esc_html_e('Number of days to wait before sending the invitation. With a preferred send time, the delay passes first and the invite goes out at the next allowed time. Otherwise Kiyoh receives this delay and sends the email.', 'kiyoh-woocommerce'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="send_time_enabled"><?php esc_html_e('Preferred send time', 'kiyoh-woocommerce'); ?></label>
                        </th>
                        <td>
                            <?php $send_time_enabled = !empty($settings['invitations']['send_time_enabled']); ?>
                            <input type="checkbox" id="send_time_enabled" name="kiyoh_woocommerce_settings[invitations][send_time_enabled]" value="1" <?php checked($send_time_enabled, true); ?> />
                            <p class="description"><?php esc_html_e('Optional. Turn this on to send the invitation at a specific time of day in the shop timezone (Settings → General). Leave it off, or leave the time empty, to keep the current behaviour: the plugin calls Kiyoh immediately and Kiyoh waits the number of days above.', 'kiyoh-woocommerce'); ?></p>
                        </td>
                    </tr>

                    <tr class="kiyoh-send-time-options">
                        <th scope="row">
                            <label for="send_time"><?php esc_html_e('Send at', 'kiyoh-woocommerce'); ?></label>
                        </th>
                        <td>
                            <input type="time" id="send_time" name="kiyoh_woocommerce_settings[invitations][send_time]" value="<?php echo esc_attr(isset($settings['invitations']['send_time']) ? $settings['invitations']['send_time'] : ''); ?>" step="60" />
                            <p class="description">
                                <?php
                                $shop_timezone = function_exists('wp_timezone_string') ? wp_timezone_string() : 'UTC';
                                echo esc_html(sprintf(
                                    /* translators: %s: WordPress timezone string */
                                    __('Time of day (%s). After the delay, the invitation is sent at the next occurrence of this time.', 'kiyoh-woocommerce'),
                                    $shop_timezone !== '' ? $shop_timezone : 'UTC'
                                ));
                                ?>
                            </p>
                        </td>
                    </tr>

                    <tr class="kiyoh-send-time-options">
                        <th scope="row"><?php esc_html_e('Allowed days', 'kiyoh-woocommerce'); ?></th>
                        <td>
                            <fieldset>
                                <legend class="screen-reader-text"><?php esc_html_e('Allowed days', 'kiyoh-woocommerce'); ?></legend>
                                <?php
                                $weekday_labels = array(
                                    1 => __('Monday', 'kiyoh-woocommerce'),
                                    2 => __('Tuesday', 'kiyoh-woocommerce'),
                                    3 => __('Wednesday', 'kiyoh-woocommerce'),
                                    4 => __('Thursday', 'kiyoh-woocommerce'),
                                    5 => __('Friday', 'kiyoh-woocommerce'),
                                    6 => __('Saturday', 'kiyoh-woocommerce'),
                                    7 => __('Sunday', 'kiyoh-woocommerce'),
                                );
                                $selected_weekdays = isset($settings['invitations']['send_weekdays']) ? array_map('intval', (array) $settings['invitations']['send_weekdays']) : array();
                                foreach ($weekday_labels as $day_number => $day_label) :
                                    $day_id = 'send_weekday_' . $day_number;
                                    ?>
                                    <label for="<?php echo esc_attr($day_id); ?>" class="kiyoh-weekday-option">
                                        <input type="checkbox" id="<?php echo esc_attr($day_id); ?>" name="kiyoh_woocommerce_settings[invitations][send_weekdays][]" value="<?php echo esc_attr($day_number); ?>" <?php checked(in_array($day_number, $selected_weekdays, true)); ?> />
                                        <?php echo esc_html($day_label); ?>
                                    </label>
                                <?php endforeach; ?>
                            </fieldset>
                            <p class="description"><?php esc_html_e('Optional. Limit that time to selected days. Leave every day unchecked to allow all days.', 'kiyoh-woocommerce'); ?></p>
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

                <?php
                if (isset($this) && is_object($this) && method_exists($this, 'render_scheduled_invitations')) {
                    $this->render_scheduled_invitations();
                }
                ?>
            </div>
            

        </div>
        
        <div class="submit-section" id="submit-section">
            <?php submit_button(); ?>
        </div>
    </form>
</div>

<script type="text/javascript">
window.kiyohAdminData = <?php echo wp_json_encode(array(
    'hasCredentials' => !empty($settings['general']['location_id']) && !empty($settings['general']['api_key']),
    'activeTab' => $active_tab,
    'connectStartNonce' => wp_create_nonce('kiyoh_connect_start'),
    'connectDisconnectNonce' => wp_create_nonce('kiyoh_connect_disconnect'),
    'callbackOrigin' => $callback_origin,
    'settingsUrl' => admin_url('admin.php?page=kiyoh-settings&tab=general'),
)); ?>;
</script>