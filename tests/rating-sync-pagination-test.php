<?php
/**
 * A bad API key or a broken later publication page must fail the rating sync.
 * A genuinely empty page must still succeed.
 *
 * Run from the repo root:
 *   php tests/rating-sync-pagination-test.php
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

$failures = 0;
$kiyoh_http_queue = array();
$kiyoh_http_seen = array();
$kiyoh_test_skus = array();
$wp_options = array();
$wp_transients = array();

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

function __($text, $domain = 'default') {
    return $text;
}

function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {
}

function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) {
}

function apply_filters($hook, $value, ...$args) {
    return $value;
}

function do_action($hook, ...$args) {
}

function wp_next_scheduled($hook, $args = array()) {
    return false;
}

function wp_schedule_event($timestamp, $recurrence, $hook, $args = array(), $wp_error = false) {
    return true;
}

function wp_schedule_single_event($timestamp, $hook, $args = array(), $wp_error = false) {
    return true;
}

function get_option($name, $default = false) {
    global $wp_options;
    return array_key_exists($name, $wp_options) ? $wp_options[$name] : $default;
}

function update_option($name, $value, $autoload = null) {
    global $wp_options;
    $wp_options[$name] = $value;
    return true;
}

function get_transient($key) {
    global $wp_transients;
    return array_key_exists($key, $wp_transients) ? $wp_transients[$key] : false;
}

function set_transient($key, $value, $expiration = 0) {
    global $wp_transients;
    $wp_transients[$key] = $value;
    return true;
}

function delete_transient($key) {
    global $wp_transients;
    unset($wp_transients[$key]);
    return true;
}

function current_time($type, $gmt = 0) {
    return '2026-11-23 12:00:00';
}

function is_wp_error($thing) {
    return false;
}

function wp_remote_request($url, $args = array()) {
    global $kiyoh_http_queue, $kiyoh_http_seen;
    $kiyoh_http_seen[] = $url;
    if (empty($kiyoh_http_queue)) {
        return array(
            'response' => array('code' => 500),
            'body' => '{"message":"unscripted"}',
        );
    }
    return array_shift($kiyoh_http_queue);
}

function wp_remote_retrieve_response_code($response) {
    return isset($response['response']['code']) ? $response['response']['code'] : 0;
}

function wp_remote_retrieve_body($response) {
    return isset($response['body']) ? $response['body'] : '';
}

function kiyoh_http($code, $body) {
    if (!is_string($body)) {
        $body = json_encode($body);
    }
    return array(
        'response' => array('code' => $code),
        'body' => $body,
    );
}

function kiyoh_script_http(array $responses) {
    global $kiyoh_http_queue, $kiyoh_http_seen;
    $kiyoh_http_queue = $responses;
    $kiyoh_http_seen = array();
}

function kiyoh_review_page($count) {
    $reviews = array();
    for ($i = 0; $i < $count; $i++) {
        $reviews[] = array(
            'reviewId' => 'r' . $i,
            'productCode' => 'SKU-' . $i,
            'rating' => 8,
        );
    }
    return array('reviews' => $reviews);
}

function kiyoh_reset_shop() {
    global $wp_options, $wp_transients, $kiyoh_test_skus;
    $wp_transients = array();
    $kiyoh_test_skus = array();
    $wp_options = array(
        'kiyoh_woocommerce_settings' => array(
            'general' => array(
                'enabled' => true,
                'platform' => 'kiyoh',
                'api_key' => 'test-key',
                'location_id' => 'LOC-1',
            ),
            'ratings' => array('source' => 'kiyoh'),
        ),
        'kiyoh_ratings_sync_state' => array(
            'last_sync_utc' => '2026-11-01T00:00:00.000Z',
            'last_sync_local' => '2026-11-01 01:00:00',
            'requested_since' => '2026-10-01T00:00:00.000Z',
            'last_result' => array('success' => true, 'imported' => 4),
        ),
    );
}

class Kiyoh_Test_Wpdb {
    public $prefix = 'wp_';

    public function prepare($query, ...$args) {
        return $query;
    }

    public function get_var($query) {
        return '';
    }

    public function get_col($query) {
        return array();
    }

    public function esc_like($text) {
        return $text;
    }
}

class Kiyoh_Test_Product {
    private $sku;

    public function __construct($sku) {
        $this->sku = $sku;
    }

    public function get_sku() {
        return $this->sku;
    }
}

function wc_get_products($args = array()) {
    global $kiyoh_test_skus;
    $products = array();
    foreach ($kiyoh_test_skus as $sku) {
        $products[] = new Kiyoh_Test_Product($sku);
    }
    return $products;
}

$wpdb = new Kiyoh_Test_Wpdb();

require_once __DIR__ . '/../kiyoh-woocommerce-plugin/kiyoh-woocommerce/includes/api/class-kiyoh-api-response.php';
require_once __DIR__ . '/../kiyoh-woocommerce-plugin/kiyoh-woocommerce/includes/api/interface-kiyoh-api.php';
require_once __DIR__ . '/../kiyoh-woocommerce-plugin/kiyoh-woocommerce/includes/api/class-kiyoh-api-client.php';
require_once __DIR__ . '/../kiyoh-woocommerce-plugin/kiyoh-woocommerce/includes/api/class-kiyoh-api-factory.php';
require_once __DIR__ . '/../kiyoh-woocommerce-plugin/kiyoh-woocommerce/includes/class-kiyoh-db.php';
require_once __DIR__ . '/../kiyoh-woocommerce-plugin/kiyoh-woocommerce/includes/managers/class-rating-manager.php';

function kiyoh_client() {
    return new Kiyoh_Api_Client('kiyoh', 'test-key', 'LOC-1');
}

function kiyoh_run_full_sync() {
    $manager = new Kiyoh_Rating_Manager();
    $queued = $manager->queue_full_sync();
    if (isset($queued['status']) && $queued['status'] === 'failed') {
        return $manager->get_public_sync_status();
    }
    return $manager->process_job_chunk();
}

kiyoh_script_http(array(kiyoh_http(401, array('message' => 'Unauthorized'))));
$unauthorized = kiyoh_client()->get_reviews();
expect_false($unauthorized->is_success(), 'HTTP 401 is not treated as an empty review page');
expect_same(401, (int) $unauthorized->get_response_code(), 'a rejected API key keeps HTTP 401');
expect_same(1, count($GLOBALS['kiyoh_http_seen']), 'an auth error stops pagination');

kiyoh_script_http(array(kiyoh_http(403, array('message' => 'Forbidden'))));
$forbidden = kiyoh_client()->get_reviews();
expect_false($forbidden->is_success(), 'HTTP 403 is not treated as an empty review page');
expect_same(403, (int) $forbidden->get_response_code(), 'a forbidden API key keeps HTTP 403');

kiyoh_script_http(array(kiyoh_http(200, '<html><body>Invalid token</body></html>')));
$html = kiyoh_client()->get_reviews();
expect_false($html->is_success(), 'a non-JSON publication body is an error, not an empty page');
expect_same(200, (int) $html->get_response_code(), 'the non-JSON response keeps its HTTP status');

kiyoh_script_http(array(kiyoh_http(200, array('message' => 'Unauthorized', 'errorCode' => 'UNAUTHORIZED'))));
$error_document = kiyoh_client()->get_reviews();
expect_false($error_document->is_success(), 'an error document is not an empty review page');
expect_same('Unauthorized', $error_document->get_error(), 'the error document message is kept');

kiyoh_script_http(array(kiyoh_http(200, array('reviews' => array()))));
$empty_reviews = kiyoh_client()->get_reviews();
expect_true($empty_reviews->is_success(), 'a review page with an empty reviews array succeeds');
expect_same(array(), $empty_reviews->get_data(), 'an empty review page contributes no rows');

kiyoh_script_http(array(kiyoh_http(200, array())));
$empty_list = kiyoh_client()->get_reviews();
expect_true($empty_list->is_success(), 'an empty JSON page succeeds');

kiyoh_script_http(array(kiyoh_http(200, array('clusters' => array()))));
$empty_products = kiyoh_client()->get_updated_products('2000-01-01T00:00:00.000Z');
expect_true($empty_products->is_success(), 'a product page with an empty clusters array succeeds');

kiyoh_script_http(array(
    kiyoh_http(404, array('message' => 'missing')),
    kiyoh_http(200, array('clusters' => array())),
));
$fallback = kiyoh_client()->get_updated_products('2000-01-01T00:00:00.000Z');
expect_true($fallback->is_success(), 'a 404 on the first product path still accepts an empty fallback page');
expect_same(2, count($GLOBALS['kiyoh_http_seen']), 'the external product path is tried after a 404');

kiyoh_script_http(array(
    kiyoh_http(200, kiyoh_review_page(100)),
    kiyoh_http(200, array('reviews' => array())),
));
$two_pages = kiyoh_client()->get_reviews();
expect_true($two_pages->is_success(), 'a later empty page ends a successful fetch');
expect_same(100, count($two_pages->get_data()['reviews']), 'reviews from the first page are kept when the next page is empty');
expect_true(isset($GLOBALS['kiyoh_http_seen'][1]) && strpos($GLOBALS['kiyoh_http_seen'][1], 'start=100') !== false, 'the second page requests start=100');

kiyoh_script_http(array(
    kiyoh_http(200, kiyoh_review_page(100)),
    kiyoh_http(200, '<html>bad page</html>'),
));
$bad_page = kiyoh_client()->get_reviews();
expect_false($bad_page->is_success(), 'a non-JSON later page fails the fetch');
expect_true($bad_page->get_data() === null, 'a failed later page is not returned as a partial success');

kiyoh_script_http(array(
    kiyoh_http(200, kiyoh_review_page(100)),
    kiyoh_http(500, array('message' => 'publication page failed')),
));
$server_error = kiyoh_client()->get_reviews();
expect_false($server_error->is_success(), 'HTTP 500 on a later page fails the fetch');
expect_same(500, (int) $server_error->get_response_code(), 'the later-page status is kept');

kiyoh_reset_shop();
$GLOBALS['kiyoh_test_skus'] = array('SKU-PAGE-2');
kiyoh_script_http(array(
    kiyoh_http(401, array('message' => 'Unauthorized')),
    kiyoh_http(401, array('message' => 'Unauthorized')),
    kiyoh_http(401, array('message' => 'Unauthorized')),
));
$before_auth = get_option('kiyoh_ratings_sync_state');
$auth_status = kiyoh_run_full_sync();
expect_same('failed', $auth_status['status'], 'a rejected API key fails the rating sync');
expect_true(strpos($auth_status['error'], 'API key') !== false, 'the Reviews tab notice says the API key was rejected');
expect_true(strpos($auth_status['error'], '401') !== false, 'the Reviews tab notice includes HTTP 401');
expect_false($auth_status['active'], 'a failed sync is not left running');
expect_same($before_auth, get_option('kiyoh_ratings_sync_state'), 'last_sync_utc stays unchanged when the API key is rejected');

$GLOBALS['kiyoh_test_skus'] = array();
kiyoh_script_http(array(
    kiyoh_http(200, array('reviews' => array(array(
        'reviewId' => 'r-later',
        'productCode' => 'SKU-1',
        'rating' => 8,
    )))),
    kiyoh_http(200, array('clusters' => array())),
));
$recovered = kiyoh_run_full_sync();
expect_same('completed', $recovered['status'], 'a later Sync Ratings Now succeeds after the API key works again');
$recovered_state = get_option('kiyoh_ratings_sync_state');
expect_true($recovered_state['last_sync_utc'] !== '2026-11-01T00:00:00.000Z', 'the successful sync moves last_sync_utc');
expect_true(!empty($recovered_state['last_result']['success']), 'the successful sync is stored as a success');

kiyoh_reset_shop();
kiyoh_script_http(array(
    kiyoh_http(200, kiyoh_review_page(100)),
    kiyoh_http(500, array('message' => 'publication page failed')),
    kiyoh_http(200, array('clusters' => array())),
));
$before_page = get_option('kiyoh_ratings_sync_state');
$page_status = kiyoh_run_full_sync();
expect_same('failed', $page_status['status'], 'a failed later page fails the rating sync');
expect_true($page_status['error'] !== '', 'the Reviews tab notice explains the failed page');
expect_same($before_page, get_option('kiyoh_ratings_sync_state'), 'a failed later page does not move last_sync_utc');

kiyoh_reset_shop();
kiyoh_script_http(array(
    kiyoh_http(200, array('reviews' => array())),
    kiyoh_http(200, array('clusters' => array())),
));
$before_empty = get_option('kiyoh_ratings_sync_state');
$empty_status = kiyoh_run_full_sync();
expect_same('completed', $empty_status['status'], 'a valid empty page completes the rating sync');
$empty_state = get_option('kiyoh_ratings_sync_state');
expect_true($empty_state['last_sync_utc'] !== $before_empty['last_sync_utc'], 'an empty successful sync moves last_sync_utc');
expect_true(!empty($empty_state['last_result']['success']), 'an empty successful sync is stored as a success');

kiyoh_reset_shop();
$GLOBALS['kiyoh_test_skus'] = array('SKU-KNOWN');
kiyoh_script_http(array(
    kiyoh_http(200, array('reviews' => array())),
    kiyoh_http(200, array('clusters' => array())),
    kiyoh_http(200, array('reviews' => array())),
));
$before_catalog_empty = get_option('kiyoh_ratings_sync_state');
$catalog_empty = kiyoh_run_full_sync();
expect_same('completed', $catalog_empty['status'], 'an empty catalogue page still succeeds when a product has no reviews');
expect_true(get_option('kiyoh_ratings_sync_state')['last_sync_utc'] !== $before_catalog_empty['last_sync_utc'], 'that empty catalogue sync moves last_sync_utc');

kiyoh_reset_shop();
$GLOBALS['kiyoh_test_skus'] = array('SKU-KNOWN');
kiyoh_script_http(array(
    kiyoh_http(200, array('reviews' => array())),
    kiyoh_http(200, array('clusters' => array())),
    kiyoh_http(404, array('message' => 'not found')),
));
$missing_product = kiyoh_run_full_sync();
expect_same('completed', $missing_product['status'], 'a per-product 404 does not fail an otherwise empty sync');

kiyoh_reset_shop();
$GLOBALS['kiyoh_test_skus'] = array('SKU-KNOWN');
kiyoh_script_http(array(
    kiyoh_http(200, array('reviews' => array())),
    kiyoh_http(200, array('clusters' => array())),
    kiyoh_http(401, array('message' => 'Unauthorized')),
));
$before_catalog_auth = get_option('kiyoh_ratings_sync_state');
$catalog_auth = kiyoh_run_full_sync();
expect_same('failed', $catalog_auth['status'], 'a rejected API key during the catalogue pass fails the sync');
expect_true(strpos($catalog_auth['error'], 'API key') !== false, 'the catalogue auth failure is shown on the Reviews tab');
expect_same($before_catalog_auth, get_option('kiyoh_ratings_sync_state'), 'a catalogue auth failure does not move last_sync_utc');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} failed\n");
    exit(1);
}

echo "All rating sync pagination checks passed\n";
