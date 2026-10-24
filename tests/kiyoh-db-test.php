<?php
/**
 * SQL identifiers and the uninstall inventory.
 *
 * Run from the repo root:
 *   php tests/kiyoh-db-test.php
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

require_once __DIR__ . '/../kiyoh-woocommerce-plugin/kiyoh-woocommerce/includes/class-kiyoh-db.php';

$failures = 0;

function expect_same($expected, $actual, $message) {
    global $failures;
    if ($expected !== $actual) {
        $failures++;
        fwrite(STDERR, "FAIL: {$message}\n  expected " . var_export($expected, true) . "\n  actual   " . var_export($actual, true) . "\n");
        return;
    }
    echo "OK: {$message}\n";
}

function expect_true($actual, $message) {
    expect_same(true, $actual, $message);
}

function expect_false($actual, $message) {
    expect_same(false, $actual, $message);
}

expect_same('wp_kiyoh_review_cache', Kiyoh_Db::table('wp_', 'kiyoh_review_cache'), 'a known table is prefix plus suffix');
expect_same('', Kiyoh_Db::table('wp_', 'users'), 'unknown tables are refused');
expect_same('', Kiyoh_Db::table('wp_;drop', 'kiyoh_review_cache'), 'a prefix with SQL is refused');
expect_same('', Kiyoh_Db::table('wp_', 'kiyoh_review_cache;drop'), 'a suffix with SQL is refused');
expect_same('wp_options', Kiyoh_Db::options_table('wp_options'), 'the options table name passes when it is a bare identifier');
expect_same('', Kiyoh_Db::options_table('wp_options where 1=1'), 'the options table name cannot carry a clause');

$options = Kiyoh_Db::option_names();
expect_true(in_array('kiyoh_woocommerce_settings', $options, true), 'uninstall removes settings');
expect_true(in_array('kiyoh_connect_account', $options, true), 'uninstall removes the Connect account');
expect_true(in_array('kiyoh_mobile_health', $options, true), 'uninstall removes mobile health state');
expect_false(in_array('siteurl', $options, true), 'uninstall does not list WordPress core options');

$hooks = Kiyoh_Db::cron_hooks();
expect_true(in_array('kiyoh_report_mobile_health', $hooks, true), 'uninstall clears the mobile health cron');
expect_true(in_array('kiyoh_send_scheduled_invitation', $hooks, true), 'uninstall clears scheduled invitation jobs');
expect_true(in_array('kiyoh_cleanup_cache', $hooks, true), 'uninstall clears the cache cleanup cron');

$plugin = file_get_contents(dirname(__DIR__) . '/kiyoh-woocommerce-plugin/kiyoh-woocommerce/uninstall.php');
$deactivator = file_get_contents(dirname(__DIR__) . '/kiyoh-woocommerce-plugin/kiyoh-woocommerce/includes/class-kiyoh-deactivator.php');
expect_true(strpos($plugin, 'WP_UNINSTALL_PLUGIN') !== false, 'uninstall.php refuses a direct request');
expect_true(strpos($plugin, 'revoke_for_uninstall') !== false, 'uninstall revokes the shop at Kiyoh-Connect');
expect_true(strpos($plugin, 'DROP TABLE IF EXISTS') !== false, 'uninstall drops the plugin tables');
expect_false(strpos($deactivator, "delete_option('kiyoh_woocommerce_settings')") !== false, 'deactivation does not delete settings');
expect_false(strpos($deactivator, 'DROP TABLE') !== false, 'deactivation does not drop tables');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} failed\n");
    exit(1);
}
echo "All database inventory checks passed\n";
