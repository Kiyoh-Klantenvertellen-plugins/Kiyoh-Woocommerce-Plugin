<?php
/**
 * Kiyoh-Connect 1.1.0 rules for the WooCommerce plugin.
 *
 * Run from the repo root:
 *   php tests/kiyoh-connect-test.php
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

require_once __DIR__ . '/../kiyoh-woocommerce-plugin/kiyoh-woocommerce/includes/class-kiyoh-connect.php';

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

$origin = 'https://shop.example';
$callback = 'https://shop.example/wp-json/kiyoh/v1/oauth/callback';
$issued = 1700000000;
$key = 'test-hmac-key';

expect_same(Kiyoh_Connect::ISSUER_KIYOH, Kiyoh_Connect::issuer_for_platform('kiyoh'), 'Kiyoh uses connect.kiyoh.com');
expect_same(Kiyoh_Connect::ISSUER_KLANTENVERTELLEN, Kiyoh_Connect::issuer_for_platform('klantenvertellen'), 'Klantenvertellen uses connect.klantenvertellen.nl');
expect_same('', Kiyoh_Connect::issuer_for_platform('other'), 'an unknown platform has no issuer');
expect_same(98, Kiyoh_Connect::tenant_for_platform('kiyoh'), 'Kiyoh tenant is 98');
expect_same(99, Kiyoh_Connect::tenant_for_platform('klantenvertellen'), 'Klantenvertellen tenant is 99');
expect_same('kiyoh', Kiyoh_Connect::platform_for_tenant('98'), 'tenant 98 is Kiyoh');
expect_same('klantenvertellen', Kiyoh_Connect::platform_for_tenant(99), 'tenant 99 is Klantenvertellen');
expect_same('from-constant', Kiyoh_Connect::configured_secret('from-constant', 'from-env'), 'a wp-config constant wins over the environment');
expect_same('from-env', Kiyoh_Connect::configured_secret('', 'from-env'), 'the environment is used when the constant is empty');

expect_same($origin, Kiyoh_Connect::shop_origin('https://Shop.Example/store'), 'shop origin is scheme and host, lowercased, without a path');
expect_same('https://shop.example:8443', Kiyoh_Connect::shop_origin('https://shop.example:8443/shop'), 'a non-default port stays on the origin');
expect_same('', Kiyoh_Connect::shop_origin('http://shop.example'), 'http is not a Connect origin');
expect_same($callback, Kiyoh_Connect::callback_url($origin), 'the return address is the REST callback on the shop host');

$canonical = "v1\nregister\nkiyoh-woocommerce\n{$origin}\n{$callback}\n{$issued}";
expect_same($canonical, Kiyoh_Connect::canonical_message('register', Kiyoh_Connect::CLIENT_ID, $origin, $callback, $issued), 'register canonical bytes are LF-joined with no trailing newline');
expect_false(str_ends_with(Kiyoh_Connect::canonical_message('register', Kiyoh_Connect::CLIENT_ID, $origin, $callback, $issued), "\n"), 'canonical message has no trailing newline');
$expected_sig = rtrim(strtr(base64_encode(hash_hmac('sha256', $canonical, $key, true)), '+/', '-_'), '=');
expect_same($expected_sig, Kiyoh_Connect::sign('register', Kiyoh_Connect::CLIENT_ID, $origin, $callback, $issued, $key), 'signature is unpadded base64url HMAC-SHA256');
expect_same('', Kiyoh_Connect::sign('register', Kiyoh_Connect::CLIENT_ID, $origin, $callback, $issued, ''), 'an empty HMAC key does not sign');
$revoke = Kiyoh_Connect::canonical_message('revoke', Kiyoh_Connect::CLIENT_ID, $origin, $callback, $issued);
expect_true(strpos($revoke, "\nrevoke\n") !== false, 'uninstall uses the revoke verb on its own line');
expect_true(Kiyoh_Connect::sign('revoke', Kiyoh_Connect::CLIENT_ID, $origin, $callback, $issued, $key) !== $expected_sig, 'revoke signature differs from register');

$body = Kiyoh_Connect::signed_shop_body('register', $origin, $callback, $issued, $key);
expect_same('kiyoh-woocommerce', $body['client_id'], 'register body uses client kiyoh-woocommerce');
expect_same($origin, $body['shop_url'], 'shop_url is the shop origin');
expect_same($callback, $body['redirect_uri'], 'redirect_uri is the callback');
expect_same($issued, $body['issued_at'], 'issued_at is the unix time');
expect_same($expected_sig, $body['signature'], 'register body signature matches the canonical message');
expect_true(Kiyoh_Connect::register_accepted(200), 'a new registration is accepted');
expect_true(Kiyoh_Connect::register_accepted(409), 'registering the same shop again is accepted');
expect_false(Kiyoh_Connect::register_accepted(401), 'a rejected signature is not accepted');

$verifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
expect_same('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', Kiyoh_Connect::code_challenge($verifier), 'PKCE S256 matches the RFC 7636 vector');
$generated = Kiyoh_Connect::code_verifier();
expect_true((bool) preg_match('/^[A-Za-z0-9_-]{43,128}$/', $generated), 'generated verifier is url-safe');
expect_same('', Kiyoh_Connect::state_storage_key('short'), 'a short state is not stored');
expect_true(strpos(Kiyoh_Connect::state_storage_key($generated), $generated) === false, 'the transient name is not the raw state');

$authorize = Kiyoh_Connect::authorize_url(Kiyoh_Connect::ISSUER_KIYOH, $callback, $generated, Kiyoh_Connect::code_challenge($verifier));
expect_true(strpos($authorize, 'https://connect.kiyoh.com/authorize?') === 0, 'authorize starts at the Kiyoh issuer');
expect_true(strpos($authorize, 'code_challenge_method=S256') !== false, 'authorize asks for S256');
expect_true(strpos($authorize, 'client_id=kiyoh-woocommerce') !== false, 'authorize sends the WooCommerce client id');
expect_true(strpos($authorize, 'scope=integration%3Aconnect') !== false, 'authorize requests integration:connect');
expect_true(strpos($authorize, 'code_verifier=') === false, 'the verifier stays off the authorize URL');
expect_true(strpos($authorize, 'client_secret') === false, 'the client secret stays off the authorize URL');
expect_same('', Kiyoh_Connect::authorize_url('https://evil.example', $callback, $generated, 'abc'), 'authorize refuses an unknown issuer');

$form = Kiyoh_Connect::authorization_code_form('code-value', $verifier, $callback, 'test-client-secret');
expect_same('authorization_code', $form['grant_type'], 'token grant is authorization_code');
expect_same('kiyoh-woocommerce', $form['client_id'], 'token form sends the client id');
expect_same('test-client-secret', $form['client_secret'], 'token form can carry a secret supplied by the caller');
expect_same($verifier, $form['code_verifier'], 'token form sends the PKCE verifier');
expect_same('refresh_token', Kiyoh_Connect::refresh_token_form('refresh-token-value-0001', 'test-client-secret')['grant_type'], 'refresh uses grant_type refresh_token');
expect_same('refresh_token', Kiyoh_Connect::revoke_token_form('refresh-token-value-0001', 'test-client-secret')['token_type_hint'], 'revoke hints that the token is a refresh token');

$token = json_decode(file_get_contents(__DIR__ . '/fixtures/kiyoh-connect/token.json'), true);
$parsed_token = Kiyoh_Connect::parse_token($token);
expect_true($parsed_token['ok'], 'fixture token parses');
expect_same('access-token-value-0001', $parsed_token['access_token'], 'fixture access token is kept');
expect_same('refresh-token-value-0001', $parsed_token['refresh_token'], 'fixture refresh token is kept');
expect_same(3600, $parsed_token['expires_in'], 'fixture expiry is kept');
expect_false(Kiyoh_Connect::parse_token(array('access_token' => 'short'))['ok'], 'a short access token is rejected');

$credentials = json_decode(file_get_contents(__DIR__ . '/fixtures/kiyoh-connect/credentials.json'), true);
$parsed_credentials = Kiyoh_Connect::parse_credentials($credentials);
expect_true($parsed_credentials['ok'], 'fixture credentials parse');
expect_same('LOC-100', $parsed_credentials['location_id'], 'locationId is stored as the location id');
expect_same('invite-key-00000001', $parsed_credentials['api_key'], 'inviteApiKey is stored as the plugin API key');
expect_same(98, $parsed_credentials['tenant_id'], 'tenantId is kept');
expect_same('kiyoh', $parsed_credentials['platform'], 'tenant 98 selects the Kiyoh platform');
expect_same('Shop Name', $parsed_credentials['location_name'], 'location name is stripped of HTML');
$wrapped = Kiyoh_Connect::parse_credentials(array('data' => $credentials));
expect_true($wrapped['ok'], 'a data wrapper is accepted when the field names match');
expect_false(Kiyoh_Connect::parse_credentials(array('locationId' => 'LOC-1', 'tenantId' => 97, 'inviteApiKey' => 'invite-key-00000001'))['ok'], 'an unknown tenant is rejected');

$public = Kiyoh_Connect::validate_callback_url($callback, array('93.184.216.34'));
expect_true($public['ok'], 'a public https callback is accepted');
expect_same('http', Kiyoh_Connect::validate_callback_url('http://shop.example/wp-json/kiyoh/v1/oauth/callback')['error'], 'http callback is rejected');
expect_same('private_host', Kiyoh_Connect::validate_callback_url('https://localhost/wp-json/kiyoh/v1/oauth/callback')['error'], 'localhost is rejected');
expect_same('private_host', Kiyoh_Connect::validate_callback_url('https://127.0.0.1/wp-json/kiyoh/v1/oauth/callback')['error'], 'loopback is rejected');
expect_same('private_host', Kiyoh_Connect::validate_callback_url('https://10.1.2.3/wp-json/kiyoh/v1/oauth/callback')['error'], '10/8 is rejected');
expect_same('private_host', Kiyoh_Connect::validate_callback_url('https://192.168.1.9/wp-json/kiyoh/v1/oauth/callback')['error'], '192.168/16 is rejected');
expect_same('private_host', Kiyoh_Connect::validate_callback_url('https://172.16.0.4/wp-json/kiyoh/v1/oauth/callback')['error'], '172.16/12 is rejected');
expect_same('private_host', Kiyoh_Connect::validate_callback_url('https://shop.example/wp-json/kiyoh/v1/oauth/callback', array('10.0.0.8'))['error'], 'a public name that resolves privately is rejected');
expect_same('unresolved', Kiyoh_Connect::validate_callback_url($callback, array())['error'], 'an unresolved name fails closed');
expect_same('invalid', Kiyoh_Connect::validate_callback_url('https://user:pass@shop.example/wp-json/kiyoh/v1/oauth/callback')['error'], 'userinfo is rejected');
expect_same('invalid', Kiyoh_Connect::validate_callback_url($callback . '#frag')['error'], 'a fragment is rejected');
expect_same('invalid', Kiyoh_Connect::validate_callback_url($callback . '?code=1')['error'], 'a query string is rejected');
expect_true(Kiyoh_Connect::is_disallowed_ip('::1'), 'IPv6 loopback is rejected');
expect_false(Kiyoh_Connect::is_disallowed_ip('8.8.8.8'), 'a public IPv4 address is allowed');

expect_true(Kiyoh_Connect::is_retryable_status(429), '429 is retried');
expect_true(Kiyoh_Connect::is_retryable_status(503), '503 is retried');
expect_false(Kiyoh_Connect::is_retryable_status(401), '401 is not retried');
expect_same(array(0, 1), Kiyoh_Connect::retry_delay_bounds(1), 'first retry waits at most 1 second');
expect_same(array(0, 60), Kiyoh_Connect::retry_delay_bounds(10), 'backoff is capped at 60 seconds');
expect_true(Kiyoh_Connect::interactive_should_retry(1, 8), 'an 8 second wait is still interactive');
expect_false(Kiyoh_Connect::interactive_should_retry(1, 9), 'a longer wait stops the browser request');
expect_false(Kiyoh_Connect::interactive_should_retry(3, 1), 'the third failure is the last attempt');
$rate = json_decode(file_get_contents(__DIR__ . '/fixtures/kiyoh-connect/rate-limit.json'), true);
expect_same(30, Kiyoh_Connect::parse_retry_after('', $rate, 1700000000), 'retryAfterSeconds is read from the body');
expect_same(15, Kiyoh_Connect::parse_retry_after('15', array(), 1700000000), 'a numeric Retry-After header is read');
expect_same('Kiyoh-Connect rejected the client secret configured for this shop.', Kiyoh_Connect::oauth_error_message('invalid_client'), 'invalid client has a clear admin message');

$stored = Kiyoh_Connect::encrypt_secret('refresh-token-value-0001', 'wp-salt-value');
expect_true($stored !== '' && strpos($stored, 'refresh-token-value-0001') === false, 'refresh token ciphertext does not contain the token');
expect_same('refresh-token-value-0001', Kiyoh_Connect::decrypt_secret($stored, 'wp-salt-value'), 'refresh token round-trips');
expect_same('', Kiyoh_Connect::decrypt_secret($stored, 'other-salt'), 'a different key does not decrypt');
expect_same('', Kiyoh_Connect::encrypt_secret('refresh-token-value-0001', ''), 'encryption refuses an empty key');

$html = Kiyoh_Connect::callback_document(false, '<script>alert(1)</script>', $origin, 'https://evil.example/phish');
expect_true(strpos($html, '<script>alert') === false, 'callback HTML escapes the message');
expect_true(strpos($html, 'evil.example') === false, 'callback HTML drops a next URL on another host');
expect_true(strpos($html, 'kiyoh-connect') !== false, 'callback HTML posts a kiyoh-connect message');
expect_same('', Kiyoh_Connect::safe_next_url('http://shop.example/wp-admin/', $origin), 'the popup does not continue to an http admin URL');

$plugin_root = dirname(__DIR__) . '/kiyoh-woocommerce-plugin/kiyoh-woocommerce';
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($plugin_root));
$shipped_secret = false;
foreach ($iterator as $file) {
    if (substr($file->getFilename(), -4) !== '.php') {
        continue;
    }
    $text = file_get_contents($file->getPathname());
    if (preg_match('/(client_secret|hmac_key|KIYOH_CONNECT_HMAC_KEY|KIYOH_CONNECT_CLIENT_SECRET)\s*[:=]\s*[\'\"][^\'\"\s]{8,}[\'\"]/i', $text)) {
        $shipped_secret = $file->getPathname();
        break;
    }
}
expect_same(false, $shipped_secret, 'the plugin does not ship an HMAC key or client secret');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} failed\n");
    exit(1);
}
echo "All Kiyoh-Connect checks passed\n";
