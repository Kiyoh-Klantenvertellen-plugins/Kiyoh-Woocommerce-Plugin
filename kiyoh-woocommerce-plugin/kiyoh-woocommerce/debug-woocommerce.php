<?php
/**
 * WooCommerce Compatibility Debug Script
 * 
 * Add this to your WordPress site temporarily to debug WooCommerce compatibility issues
 * Access via: yoursite.com/wp-content/plugins/kiyoh-woocommerce/debug-woocommerce.php
 */

// Load WordPress
require_once('../../../wp-load.php');

// Security check
if (!current_user_can('manage_options')) {
    wp_die('Access denied');
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>Kiyoh WooCommerce Debug</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .status { padding: 10px; margin: 10px 0; border-radius: 4px; }
        .success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; }
        .error { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; }
        .warning { background: #fff3cd; border: 1px solid #ffeaa7; color: #856404; }
        .info { background: #d1ecf1; border: 1px solid #bee5eb; color: #0c5460; }
        pre { background: #f8f9fa; padding: 10px; border-radius: 4px; overflow-x: auto; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
    </style>
</head>
<body>
    <h1>🔍 Kiyoh WooCommerce Compatibility Debug</h1>
    
    <h2>WordPress Environment</h2>
    <table>
        <tr><th>WordPress Version</th><td><?php echo get_bloginfo('version'); ?></td></tr>
        <tr><th>PHP Version</th><td><?php echo PHP_VERSION; ?></td></tr>
        <tr><th>Site URL</th><td><?php echo get_site_url(); ?></td></tr>
        <tr><th>Active Theme</th><td><?php echo wp_get_theme()->get('Name') . ' v' . wp_get_theme()->get('Version'); ?></td></tr>
        <tr><th>Multisite</th><td><?php echo is_multisite() ? 'Yes' : 'No'; ?></td></tr>
    </table>

    <h2>WooCommerce Status</h2>
    
    <?php
    // Check if WooCommerce plugin file exists
    $wc_plugin_file = WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
    $wc_file_exists = file_exists($wc_plugin_file);
    ?>
    
    <div class="status <?php echo $wc_file_exists ? 'success' : 'error'; ?>">
        <strong>WooCommerce Plugin File:</strong> <?php echo $wc_file_exists ? '✅ Found' : '❌ Not Found'; ?>
        <br><small><?php echo $wc_plugin_file; ?></small>
    </div>
    
    <?php
    // Check if WooCommerce is in active plugins
    $active_plugins = get_option('active_plugins', array());
    $wc_active = in_array('woocommerce/woocommerce.php', $active_plugins);
    ?>
    
    <div class="status <?php echo $wc_active ? 'success' : 'error'; ?>">
        <strong>WooCommerce Active (Single Site):</strong> <?php echo $wc_active ? '✅ Yes' : '❌ No'; ?>
    </div>
    
    <?php
    // Check multisite activation
    if (is_multisite()) {
        $network_plugins = get_site_option('active_sitewide_plugins', array());
        $wc_network_active = array_key_exists('woocommerce/woocommerce.php', $network_plugins);
        ?>
        <div class="status <?php echo $wc_network_active ? 'success' : 'info'; ?>">
            <strong>WooCommerce Network Active:</strong> <?php echo $wc_network_active ? '✅ Yes' : 'ℹ️ No'; ?>
        </div>
    <?php } ?>
    
    <?php
    // Check if WooCommerce class exists
    $wc_class_exists = class_exists('WooCommerce');
    ?>
    
    <div class="status <?php echo $wc_class_exists ? 'success' : 'error'; ?>">
        <strong>WooCommerce Class Loaded:</strong> <?php echo $wc_class_exists ? '✅ Yes' : '❌ No'; ?>
    </div>
    
    <?php if ($wc_class_exists): ?>
        <div class="status success">
            <strong>WooCommerce Version:</strong> <?php echo defined('WC_VERSION') ? WC_VERSION : 'Unknown'; ?>
        </div>
        
        <?php
        $version_compatible = defined('WC_VERSION') && version_compare(WC_VERSION, '5.0', '>=');
        ?>
        <div class="status <?php echo $version_compatible ? 'success' : 'error'; ?>">
            <strong>Version Compatible (≥5.0):</strong> <?php echo $version_compatible ? '✅ Yes' : '❌ No'; ?>
        </div>
    <?php endif; ?>

    <h2>Kiyoh Plugin Status</h2>
    
    <?php
    // Check if Kiyoh plugin is active
    $kiyoh_active = in_array('kiyoh-woocommerce/kiyoh-woocommerce.php', $active_plugins);
    ?>
    
    <div class="status <?php echo $kiyoh_active ? 'success' : 'info'; ?>">
        <strong>Kiyoh Plugin Active:</strong> <?php echo $kiyoh_active ? '✅ Yes' : 'ℹ️ No'; ?>
    </div>
    
    <?php
    // Check if Kiyoh constants are defined
    $kiyoh_constants = defined('KIYOH_WOOCOMMERCE_VERSION');
    ?>
    
    <div class="status <?php echo $kiyoh_constants ? 'success' : 'info'; ?>">
        <strong>Kiyoh Constants Defined:</strong> <?php echo $kiyoh_constants ? '✅ Yes' : 'ℹ️ No'; ?>
        <?php if ($kiyoh_constants): ?>
            <br><small>Version: <?php echo KIYOH_WOOCOMMERCE_VERSION; ?></small>
        <?php endif; ?>
    </div>

    <h2>Active Plugins</h2>
    <div class="info">
        <strong>Total Active Plugins:</strong> <?php echo count($active_plugins); ?>
    </div>
    
    <table>
        <tr><th>Plugin</th><th>Status</th></tr>
        <?php foreach ($active_plugins as $plugin): ?>
            <tr>
                <td><?php echo esc_html($plugin); ?></td>
                <td><?php echo strpos($plugin, 'woocommerce') !== false ? '🛒 WooCommerce Related' : '📦 Other'; ?></td>
            </tr>
        <?php endforeach; ?>
    </table>

    <h2>Plugin Load Order Test</h2>
    <?php
    // Test the load order
    $load_test_results = array();
    
    // Test 1: plugins_loaded hook
    add_action('plugins_loaded', function() use (&$load_test_results) {
        $load_test_results['plugins_loaded'] = array(
            'wc_class_exists' => class_exists('WooCommerce'),
            'wc_version' => defined('WC_VERSION') ? WC_VERSION : 'Not defined'
        );
    }, 5);
    
    // Test 2: init hook
    add_action('init', function() use (&$load_test_results) {
        $load_test_results['init'] = array(
            'wc_class_exists' => class_exists('WooCommerce'),
            'wc_version' => defined('WC_VERSION') ? WC_VERSION : 'Not defined'
        );
    }, 5);
    
    // Trigger the hooks
    do_action('plugins_loaded');
    do_action('init');
    ?>
    
    <table>
        <tr><th>Hook</th><th>WC Class Exists</th><th>WC Version</th></tr>
        <?php foreach ($load_test_results as $hook => $result): ?>
            <tr>
                <td><?php echo esc_html($hook); ?></td>
                <td><?php echo $result['wc_class_exists'] ? '✅ Yes' : '❌ No'; ?></td>
                <td><?php echo esc_html($result['wc_version']); ?></td>
            </tr>
        <?php endforeach; ?>
    </table>

    <h2>Recommendations</h2>
    
    <?php if (!$wc_file_exists): ?>
        <div class="error">
            <strong>❌ WooCommerce Not Installed</strong><br>
            Install WooCommerce plugin from WordPress admin or WooCommerce.com
        </div>
    <?php elseif (!$wc_active && !$wc_network_active): ?>
        <div class="error">
            <strong>❌ WooCommerce Not Active</strong><br>
            Activate WooCommerce in Plugins → Installed Plugins
        </div>
    <?php elseif (!$wc_class_exists): ?>
        <div class="warning">
            <strong>⚠️ WooCommerce Class Not Loaded</strong><br>
            This might be a plugin load order issue. Try deactivating and reactivating both plugins.
        </div>
    <?php elseif (!$version_compatible): ?>
        <div class="error">
            <strong>❌ WooCommerce Version Too Old</strong><br>
            Update WooCommerce to version 5.0 or higher
        </div>
    <?php else: ?>
        <div class="success">
            <strong>✅ All Checks Passed</strong><br>
            WooCommerce appears to be properly installed and compatible. If you're still seeing errors, try:
            <ul>
                <li>Deactivate and reactivate the Kiyoh plugin</li>
                <li>Clear any caching plugins</li>
                <li>Check for plugin conflicts by deactivating other plugins temporarily</li>
            </ul>
        </div>
    <?php endif; ?>

    <h2>Quick Fixes</h2>
    <div class="info">
        <strong>If you're still having issues, try these steps:</strong>
        <ol>
            <li><strong>Deactivate Kiyoh plugin</strong> → Plugins → Installed Plugins</li>
            <li><strong>Reactivate Kiyoh plugin</strong> → This will re-run the compatibility checks</li>
            <li><strong>Clear cache</strong> → If using caching plugins, clear all caches</li>
            <li><strong>Check error logs</strong> → Look in wp-content/debug.log for detailed errors</li>
            <li><strong>Plugin conflict test</strong> → Temporarily deactivate other plugins to test</li>
        </ol>
    </div>

    <p><small>Generated: <?php echo date('Y-m-d H:i:s'); ?> | <a href="<?php echo admin_url('plugins.php'); ?>">← Back to Plugins</a></small></p>
</body>
</html>