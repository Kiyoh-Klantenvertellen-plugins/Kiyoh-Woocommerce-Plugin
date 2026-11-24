<?php
/**
 * Shop review payload normalization.
 *
 * Run from the repo root:
 *   php tests/shop-reviews-test.php
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

if (!function_exists('get_option')) {
    function get_option($key, $default = false) {
        return $default;
    }
}

if (!function_exists('add_shortcode')) {
    function add_shortcode() {
    }
}

if (!function_exists('add_action')) {
    function add_action() {
    }
}

if (!function_exists('__')) {
    function __($text, $domain = 'default') {
        return $text;
    }
}

if (!function_exists('esc_html')) {
    function esc_html($text) {
        return $text;
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr($text) {
        return $text;
    }
}

if (!function_exists('esc_html__')) {
    function esc_html__($text, $domain = 'default') {
        return $text;
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($text) {
        return trim((string) $text);
    }
}

if (!function_exists('number_format_i18n')) {
    function number_format_i18n($number, $decimals = 0) {
        return number_format($number, $decimals, '.', '');
    }
}

if (!defined('KIYOH_WOOCOMMERCE_PLUGIN_URL')) {
    define('KIYOH_WOOCOMMERCE_PLUGIN_URL', 'https://example.test/wp-content/plugins/kiyoh-woocommerce/');
}

if (!defined('KIYOH_WOOCOMMERCE_VERSION')) {
    define('KIYOH_WOOCOMMERCE_VERSION', '1.4.6');
}

if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

require_once __DIR__ . '/../kiyoh-woocommerce-plugin/kiyoh-woocommerce/includes/managers/class-shop-reviews-manager.php';

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

$fixture = json_decode(file_get_contents(__DIR__ . '/fixtures/shop-reviews/external.json'), true);
$manager = new Kiyoh_Shop_Reviews_Manager();
$bundle = $manager->normalize_publication_payload($fixture, 5);

expect_same(8.6, $bundle['average'], 'average rating is parsed');
expect_same(142, $bundle['count'], 'review count is parsed');
expect_same(3, count($bundle['reviews']), 'reviews are normalized');
expect_same('Marie', $bundle['reviews'][0]['author'], 'first review author');
expect_same(9.0, $bundle['reviews'][0]['rating'], 'first review rating from reviewContent');
expect_same('Great service', $bundle['reviews'][0]['title'], 'first review oneliner from reviewContent');
expect_same('Fast delivery and friendly support.', $bundle['reviews'][0]['body'], 'first review opinion from reviewContent');
expect_same('Top shop', $bundle['reviews'][1]['title'], 'oneliner may use string rating field');
expect_same('', $bundle['reviews'][1]['body'], 'missing opinion stays empty');
expect_same('Plain fields still work', $bundle['reviews'][2]['title'], 'legacy oneliner field');

expect_same('★★★★☆', Kiyoh_Shop_Reviews_Manager::star_string_for_kiyoh_rating(7.6), '7.6/10 rounds to 4 stars');
expect_same('★★★½☆', Kiyoh_Shop_Reviews_Manager::star_string_for_kiyoh_rating(7.4), '7.4/10 rounds to 3.5 stars');
expect_same('★★★½☆', Kiyoh_Shop_Reviews_Manager::star_string_for_kiyoh_rating(7.48), '7.48/10 rounds to 3.5 stars');
expect_same('★★★★½', Kiyoh_Shop_Reviews_Manager::star_string_for_kiyoh_rating(8.6), 'summary stars use same rounding');

$block_meta = Kiyoh_Shop_Reviews_Manager::block_type_metadata();
expect_same('Kiyoh shop reviews', $block_meta['title'], 'block has title for inserter');
expect_same('widgets', $block_meta['category'], 'block has category for inserter');
expect_true(in_array('kiyoh', $block_meta['keywords'], true), 'block keywords include kiyoh');
expect_true(in_array('reviews', $block_meta['keywords'], true), 'block keywords include reviews');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} failed\n");
    exit(1);
}

echo "All shop review checks passed\n";