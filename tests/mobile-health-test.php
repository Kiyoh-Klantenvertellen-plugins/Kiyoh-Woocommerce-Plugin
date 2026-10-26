<?php
/**
 * Rules for Kiyoh Mobile shop health reports.
 *
 * Run from the repo root:
 *   php tests/mobile-health-test.php
 */

require_once __DIR__ . '/../kiyoh-woocommerce-plugin/kiyoh-woocommerce/includes/class-kiyoh-mobile-health.php';

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

$now = 1761206400; // 2026-10-23 08:00:00 UTC
$production = 'https://kiyoh-mobile.com';
$staging = 'https://kiyohmobileui-staging.up.railway.app';

expect_same(
    array($production),
    Kiyoh_Mobile_Health::resolve_base_urls('', ''),
    'blank setting and constant report only to production'
);
expect_same(
    'https://kiyoh-mobile.com',
    Kiyoh_Mobile_Health::DEFAULT_URLS,
    'the default never includes staging'
);
expect_same(array($staging), Kiyoh_Mobile_Health::resolve_base_urls($staging, ''), 'testers can set staging in the field');
expect_same(array($production), Kiyoh_Mobile_Health::resolve_base_urls('  ', $production), 'blank setting keeps the constant');
expect_same(
    array($production, $staging),
    Kiyoh_Mobile_Health::resolve_base_urls($production . ', ' . $staging, $production),
    'a non-blank setting overrides the constant'
);
expect_same(
    array($staging),
    Kiyoh_Mobile_Health::apply_url_override(array($production), $staging),
    'the url filter replaces the resolved list'
);
expect_same(
    array($production),
    Kiyoh_Mobile_Health::apply_url_override(array($production), null),
    'a non-string filter value keeps the resolved list'
);

$parsed = Kiyoh_Mobile_Health::parse_urls(' ' . $production . '/, ,' . $staging . '/health, ftp://files.example, not-a-url, ' . $production . ' ');
expect_same(array($production, $staging), $parsed, 'urls are trimmed, deduped, and limited to http(s) origins');
expect_same($production . '/api/integrations/health', Kiyoh_Mobile_Health::endpoint($production . '/'), 'health path is appended to the origin');
expect_same(
    'https://localhost:8443',
    Kiyoh_Mobile_Health::normalize_origin('https://localhost:8443/api'),
    'port is kept and the path is dropped'
);

expect_same('shop.example.com', Kiyoh_Mobile_Health::shop_id_from_url('https://shop.example.com/store'), 'shop id is the site domain');
expect_same('Shop.Example.com', Kiyoh_Mobile_Health::shop_id_from_url('https://Shop.Example.com:8443/'), 'shop id keeps host case and drops the port');
expect_same('', Kiyoh_Mobile_Health::shop_id_from_url(''), 'empty url is not a shop id');
expect_same('', Kiyoh_Mobile_Health::shop_id_from_url('-bad.example'), 'shop id must start with a letter or digit');
expect_same(200, strlen(Kiyoh_Mobile_Health::shop_id_from_url(str_repeat('a', 250) . '.example.com')), 'shop id is capped at 200 characters');

expect_same('1.3.0', Kiyoh_Mobile_Health::normalize_plugin_version(' 1.3.0 '), 'plugin version is trimmed');
expect_same('1.4.0-rc2', Kiyoh_Mobile_Health::normalize_plugin_version('1.4.0-rc2'), 'a release candidate is a valid plugin version');
expect_same('1.3.0-beta+1', Kiyoh_Mobile_Health::normalize_plugin_version('1.3.0-beta+1'), 'plugin version allows . _ + -');
expect_same('', Kiyoh_Mobile_Health::normalize_plugin_version('.1'), 'plugin version must start with a letter or digit');

$settings = array(
    'general' => array(
        'enabled' => true,
        'api_key' => ' token ',
        'location_id' => ' loc-1 ',
    ),
);
expect_same(
    array('api_token' => 'token', 'location_id' => 'loc-1'),
    Kiyoh_Mobile_Health::credentials_from_settings($settings),
    'credentials are trimmed from the saved settings'
);
expect_true(Kiyoh_Mobile_Health::has_credentials($settings), 'token and location id are both required');
expect_false(Kiyoh_Mobile_Health::has_credentials(array('general' => array('api_key' => 'token'))), 'missing location id skips the report');
expect_true(
    Kiyoh_Mobile_Health::credentials_changed($settings, array('general' => array('api_key' => 'token', 'location_id' => 'loc-2'))),
    'location id change is a credential change'
);
expect_false(Kiyoh_Mobile_Health::credentials_changed($settings, $settings), 'unchanged credentials are not a change');

expect_true(Kiyoh_Mobile_Health::widget_injected_from_settings($settings), 'enabled plugin defaults to injected ministars');
expect_true(
    Kiyoh_Mobile_Health::widget_injected_from_settings(array('general' => array('enabled' => true), 'ratings' => array('source' => 'both'))),
    'both rating source injects ministars'
);
expect_false(
    Kiyoh_Mobile_Health::widget_injected_from_settings(array('general' => array('enabled' => true), 'ratings' => array('source' => 'woocommerce'))),
    'woocommerce-only ratings are not the Kiyoh widget'
);
expect_false(
    Kiyoh_Mobile_Health::widget_injected_from_settings(array('general' => array('enabled' => false), 'ratings' => array('source' => 'kiyoh'))),
    'disabled plugin does not inject the widget'
);

expect_same(null, Kiyoh_Mobile_Health::limit_error('   '), 'blank errors are stored as null');
expect_same(500, strlen(Kiyoh_Mobile_Health::limit_error(str_repeat('e', 501))), 'errors are capped at 500 characters');
expect_same(null, Kiyoh_Mobile_Health::clean_product_code('  '), 'blank product codes are dropped');
expect_same(null, Kiyoh_Mobile_Health::clean_product_code(str_repeat('p', 201)), 'product codes longer than 200 are dropped');
expect_same(null, Kiyoh_Mobile_Health::clean_product_code(array('SKU')), 'non-string product codes are dropped');

$codes = array();
for ($i = 1; $i <= 501; $i++) {
    $codes[] = 'SKU-' . $i;
}
$codes[] = 'SKU-501';
$codes[] = '  ';
$merged = Kiyoh_Mobile_Health::merge_product_codes(array('SKU-1', 'old'), $codes);
expect_same(500, count($merged), 'product codes are capped at 500');
expect_same('SKU-501', $merged[count($merged) - 1], 'the newest product code is kept');
expect_false(in_array('SKU-1', $merged, true), 'the oldest product codes are dropped past 500');
expect_same(1, count(array_keys($merged, 'SKU-501', true)), 'duplicate product codes are stored once');

$state = Kiyoh_Mobile_Health::empty_state();
$state = Kiyoh_Mobile_Health::apply_invite($state, false, 'nope', 400, $now - Kiyoh_Mobile_Health::FAILURE_WINDOW_SECONDS);
$state = Kiyoh_Mobile_Health::apply_invite($state, false, 'still', 400, $now - Kiyoh_Mobile_Health::FAILURE_WINDOW_SECONDS - 1);
$state = Kiyoh_Mobile_Health::apply_invite($state, false, 'unauthorized', 401, $now);
expect_same(2, Kiyoh_Mobile_Health::failures_24h($state, $now), 'a failure at exactly 24h counts and an older one does not');
expect_same('error', $state['connection']['status'], 'HTTP 401 marks the connection as failed');
expect_same('unauthorized', $state['connection']['last_error'], '401 stores the invite error');
expect_same(null, $state['invites']['last_ok_at'], 'a failed invite does not set lastOkAt');

$state = Kiyoh_Mobile_Health::apply_invite($state, true, null, 200, $now);
expect_same($now, $state['invites']['last_ok_at'], 'a sent invite records lastOkAt');
expect_same(2, Kiyoh_Mobile_Health::failures_24h($state, $now), 'a later success keeps failures still inside 24h');
expect_same('ok', $state['connection']['status'], 'HTTP 200 marks the connection ok');
expect_same(null, $state['connection']['last_error'], 'a successful call clears the connection error');

$before_codes = $state['product_sync']['product_codes'];
$state = Kiyoh_Mobile_Health::apply_product_sync($state, false, 'sync broke', array('NOPE'), 400, $now + 20);
expect_same('sync broke', $state['product_sync']['last_error'], 'a failed sync stores lastError');
expect_same($before_codes, $state['product_sync']['product_codes'], 'a failed sync does not add product codes');
expect_same('ok', $state['connection']['status'], 'HTTP 400 does not clear a healthy connection');

$state = Kiyoh_Mobile_Health::apply_product_sync($state, true, 'batch failed', array('SKU-9', 'SKU-9', ''), 200, $now + 30);
expect_same($now + 30, $state['product_sync']['last_ok_at'], 'a successful sync records lastOkAt');
expect_same('batch failed', $state['product_sync']['last_error'], 'a partial sync keeps the batch error');
expect_same(array('SKU-9'), $state['product_sync']['product_codes'], 'only valid successful codes are added');

$previous_codes = $state['product_sync']['product_codes'];
$relocated = Kiyoh_Mobile_Health::on_credentials_changed(
    $state,
    array('general' => array('api_key' => 'token', 'location_id' => 'loc-1')),
    array('general' => array('api_key' => 'token', 'location_id' => 'loc-2')),
    $now + 40
);
expect_same('unknown', $relocated['connection']['status'], 'a credential change resets connection to unknown');
expect_same(array(), $relocated['product_sync']['product_codes'], 'a location change clears product codes');

$state['reports'][$production] = array('digest' => 'abc', 'at' => $now, 'blocked_until' => $now + 100);
$key_only = Kiyoh_Mobile_Health::on_credentials_changed(
    $state,
    array('general' => array('api_key' => 'token', 'location_id' => 'loc-1')),
    array('general' => array('api_key' => 'next', 'location_id' => 'loc-1')),
    $now + 40
);
expect_same($previous_codes, $key_only['product_sync']['product_codes'], 'an api key change keeps product codes for the same location');
expect_same($now + 100, $key_only['reports'][$production]['blocked_until'], 'a credential change keeps an active 429 block');
expect_same(0, $key_only['reports'][$production]['at'], 'a credential change forgets the previous success so the new token is sent');

$state = Kiyoh_Mobile_Health::sync_widget_flag($state, true, $now + 50, true);
expect_true($state['widget']['injected'], 'widget flag follows ministars');
expect_same($now + 50, $state['widget']['last_checked_at'], 'a settings save records when the widget was checked');

$body = Kiyoh_Mobile_Health::build_body($state, 'shop.example.com', '1.3.0', $now + 50);
expect_same('woocommerce', $body['platform'], 'platform is woocommerce');
expect_same('shop.example.com', $body['shopId'], 'body shop id is the site domain');
expect_same('1.3.0', $body['pluginVersion'], 'body carries the plugin version');
expect_same(array('lastOkAt', 'failures24h'), array_keys($body['invites']), 'invites group is lastOkAt and failures24h');
expect_true(isset($body['productSync']['productCodes']), 'product codes are included when present');
expect_same(true, $body['widget']['injected'], 'widget injected is a boolean');
expect_same(
    array('lastReceivedAt' => null),
    $body['webhooks'],
    'webhooks are sent with a null time so the plugins page can render the card'
);
expect_same(gmdate('Y-m-d\TH:i:s\Z', $now + 30), $body['productSync']['lastOkAt'], 'timestamps are UTC ISO-8601');
expect_same('Z', substr($body['productSync']['lastOkAt'], -1), 'timestamps use the Z timezone');

$same = Kiyoh_Mobile_Health::digest($body);
expect_same($same, Kiyoh_Mobile_Health::digest($body), 'digest is stable for the same payload');
$other = $body;
$other['invites']['failures24h'] = 9;
expect_true(Kiyoh_Mobile_Health::digest($other) !== $same, 'digest changes when the payload changes');

expect_true(Kiyoh_Mobile_Health::should_send(null, $same, $now), 'a url with no report is due');
$fresh = array('digest' => $same, 'at' => $now, 'blocked_until' => 0);
expect_false(Kiyoh_Mobile_Health::should_send($fresh, $same, $now + Kiyoh_Mobile_Health::MAX_AGE_SECONDS), 'an unchanged payload at exactly 24h waits');
expect_true(Kiyoh_Mobile_Health::should_send($fresh, $same, $now + Kiyoh_Mobile_Health::MAX_AGE_SECONDS + 1), 'an unchanged payload older than 24h is resent');
expect_true(Kiyoh_Mobile_Health::should_send($fresh, 'different', $now + 5), 'a changed payload is sent inside 24h');
$blocked = array('digest' => 'old', 'at' => 0, 'blocked_until' => $now + 12);
expect_false(Kiyoh_Mobile_Health::should_send($blocked, 'different', $now + 11), '429 blocks a changed payload until retry-after');
expect_true(Kiyoh_Mobile_Health::should_send($blocked, 'different', $now + 12), 'a 429 block allows a send when the wait elapses');

$state = Kiyoh_Mobile_Health::apply_post_result($state, $production, $same, $now, 429, '12', '');
expect_same($now + 12, $state['reports'][$production]['blocked_until'], 'Retry-After seconds set the block');
expect_same($now, $state['reports'][$production]['at'], '429 keeps the previous success time');
expect_same('abc', $state['reports'][$production]['digest'], '429 does not store the unsent digest');

$state = Kiyoh_Mobile_Health::apply_post_result($state, $staging, $same, $now, 429, '', '{"error":"rate_limited","retryAfterSeconds":30}');
expect_same($now + 30, $state['reports'][$staging]['blocked_until'], 'retryAfterSeconds is used when Retry-After is absent');

$state = Kiyoh_Mobile_Health::apply_post_result($state, $production, $same, $now, 429, 'soon', '{}');
expect_same($now + Kiyoh_Mobile_Health::DEFAULT_RETRY_AFTER_SECONDS, $state['reports'][$production]['blocked_until'], 'a 429 without a wait uses 60 seconds');

$http_date = gmdate('D, d M Y H:i:s', $now + 15) . ' GMT';
expect_same(15, Kiyoh_Mobile_Health::parse_retry_after_header($http_date, $now), 'an HTTP-date Retry-After is converted to seconds');

$missed = Kiyoh_Mobile_Health::apply_post_result(Kiyoh_Mobile_Health::empty_state(), $production, $same, $now + 20, 500, '', '');
expect_same(0, $missed['reports'][$production]['at'], 'HTTP 500 is not stored as success');
expect_true(Kiyoh_Mobile_Health::should_send($missed['reports'][$production], $same, $now + 20), 'HTTP 500 is retried on the next send');
$state = Kiyoh_Mobile_Health::apply_post_result($state, $production, $same, $now + 20, 500, '', '');

$state = Kiyoh_Mobile_Health::apply_post_result($state, $production, $same, $now + 21, 200, '', '');
expect_same($same, $state['reports'][$production]['digest'], 'HTTP 200 stores the payload digest');
expect_same($now + 21, $state['reports'][$production]['at'], 'HTTP 200 stores the success time');
expect_same(0, $state['reports'][$production]['blocked_until'], 'HTTP 200 clears a 429 block');
expect_false(Kiyoh_Mobile_Health::should_send($state['reports'][$production], $same, $now + 21), 'the same payload is not sent again immediately');

$weird = Kiyoh_Mobile_Health::normalize_state(array('connection' => array('status' => 'bogus')), $now);
expect_same('unknown', $weird['connection']['status'], 'an unknown connection status is coerced to unknown');

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} failed.\n");
    exit(1);
}

echo "\nAll mobile health tests passed.\n";
exit(0);
