<?php
/**
 * Language detection for review invitations.
 *
 * Run from the repo root:
 *   php tests/kiyoh-language-test.php
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

require_once __DIR__ . '/../kiyoh-woocommerce-plugin/kiyoh-woocommerce/includes/helpers/class-kiyoh-language.php';

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

class Kiyoh_Test_Order {
    private $meta = array();

    public function __construct(array $meta = array()) {
        $this->meta = $meta;
    }

    public function get_meta($key) {
        return isset($this->meta[$key]) ? $this->meta[$key] : '';
    }

    public function get_customer_id() {
        return 0;
    }

    public function get_id() {
        return 42;
    }
}

expect_same('fr', Kiyoh_Language::map_wordpress_locale_to_kiyoh_language('fr_FR', 'en'), 'French locale maps to fr');
expect_same('fr', Kiyoh_Language::map_order_language_to_kiyoh('fr', 'en'), 'WPML slug fr maps to fr');
expect_same('nl', Kiyoh_Language::map_order_language_to_kiyoh('nl', 'en'), 'Polylang slug nl maps to nl');

$order = new Kiyoh_Test_Order(array('wpml_language' => 'fr'));
expect_same('fr', Kiyoh_Language::order_multilingual_language($order), 'WPML order meta is read');
expect_same('fr', Kiyoh_Language::resolve_invitation_language($order, 'en'), 'French order language wins over fallback');

$english_order = new Kiyoh_Test_Order(array('wpml_language' => 'en'));
expect_same('en', Kiyoh_Language::resolve_invitation_language($english_order, 'nl'), 'English order language is kept');

expect_same('nl', Kiyoh_Language::sanitize_kiyoh_language('nl', 'en'), 'Known fallback passes through');
expect_same('en', Kiyoh_Language::sanitize_kiyoh_language('xx', 'en'), 'Unknown code falls back');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} failed\n");
    exit(1);
}

echo "All language checks passed\n";