<?php
/**
 * Kiyoh-Connect 1.2.0 rules for the WooCommerce plugin.
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

expect_same(Kiyoh_Connect::ISSUER_KIYOH, Kiyoh_Connect::issuer_for_platform('kiyoh'), 'Kiyoh uses connect.kiyoh.com');
expect_same(Kiyoh_Connect::ISSUER_KLANTENVERTELLEN, Kiyoh_Connect::issuer_for_platform('klantenvertellen'), 'Klantenvertellen uses connect.klantenvertellen.nl');
expect_same('', Kiyoh_Connect::issuer_for_platform('other'), 'an unknown platform has no issuer');
expect_same(98, Kiyoh_Connect::tenant_for_platform('kiyoh'), 'Kiyoh tenant is 98');
expect_same(99, Kiyoh_Connect::tenant_for_platform('klantenvertellen'), 'Klantenvertellen tenant is 99');
expect_same('kiyoh', Kiyoh_Connect::platform_for_tenant('98'), 'tenant 98 is Kiyoh');
expect_same('klantenvertellen', Kiyoh_Connect::platform_for_tenant(99), 'tenant 99 is Klantenvertellen');

expect_same($origin, Kiyoh_Connect::shop_origin('https://Shop.Example/store'), 'shop origin is scheme and host, lowercased, without a path');
expect_same('https://shop.example:8443', Kiyoh_Connect::shop_origin('https://shop.example:8443/shop'), 'a non-default port stays on the origin');
expect_same('', Kiyoh_Connect::shop_origin('http://shop.example'), 'http is not a Connect origin');
expect_same($callback, Kiyoh_Connect::callback_url($origin), 'the return address is the REST callback on the shop host');

$body = Kiyoh_Connect::register_body($origin, $callback);
expect_same(array('client_id', 'shop_url', 'redirect_uri'), array_keys($body), 'register body has client_id, shop_url, and redirect_uri only');
expect_same('kiyoh-woocommerce', $body['client_id'], 'register body uses client kiyoh-woocommerce');
expect_same($origin, $body['shop_url'], 'shop_url is the https origin');
expect_same($callback, $body['redirect_uri'], 'redirect_uri is the callback');
expect_false(array_key_exists('signature', $body), 'register body has no signature');
expect_false(array_key_exists('issued_at', $body), 'register body has no issued_at');

$active = json_decode(file_get_contents(__DIR__ . '/fixtures/kiyoh-connect/register-active.json'), true);
$parsed_active = Kiyoh_Connect::parse_registration($active, $origin, $callback);
expect_true($parsed_active['ok'], 'an active registration parses');
expect_same('shop_abc12345', $parsed_active['shop_id'], 'active registration keeps shop_id');
expect_same('shop-secret-value-0001', $parsed_active['shop_secret'], 'active registration keeps shop_secret');
expect_same('active', $parsed_active['status'], 'active registration status is active');
$pending = json_decode(file_get_contents(__DIR__ . '/fixtures/kiyoh-connect/register-pending.json'), true);
$parsed_pending = Kiyoh_Connect::parse_registration($pending, $origin, $callback);
expect_true($parsed_pending['ok'], 'a pending registration parses');
expect_same('pending', $parsed_pending['status'], 'pending registration status is pending');
expect_false(Kiyoh_Connect::parse_registration($active, 'https://other.example', $callback)['ok'], 'a mismatched shop origin is rejected');
expect_false(Kiyoh_Connect::parse_registration($active, $origin, $callback . '/extra')['ok'], 'a mismatched redirect_uri is rejected');
$wrong_client = $active;
$wrong_client['client_id'] = 'other-client';
expect_false(Kiyoh_Connect::parse_registration($wrong_client, $origin, $callback)['ok'], 'a different client_id is rejected');
$bad_status = $active;
$bad_status['status'] = 'revoked';
expect_false(Kiyoh_Connect::parse_registration($bad_status, $origin, $callback)['ok'], 'a status other than active or pending is rejected');
$short_secret = $active;
$short_secret['shop_secret'] = 'too-short';
expect_false(Kiyoh_Connect::parse_registration($short_secret, $origin, $callback)['ok'], 'a short shop secret is rejected');

$stored_account = array(
    'issuer' => Kiyoh_Connect::ISSUER_KIYOH,
    'redirect_uri' => $callback,
    'shop_status' => 'active',
    'shop_id' => 'v1:stored-shop-id',
    'shop_secret' => 'v1:stored-shop-secret',
);
expect_true(Kiyoh_Connect::registration_reusable($stored_account, Kiyoh_Connect::ISSUER_KIYOH, $callback), 'the same issuer and return address reuse the shop secret');
expect_false(Kiyoh_Connect::registration_reusable($stored_account, Kiyoh_Connect::ISSUER_KLANTENVERTELLEN, $callback), 'the other issuer does not reuse the secret');
expect_false(Kiyoh_Connect::registration_reusable($stored_account, Kiyoh_Connect::ISSUER_KIYOH, 'https://shop.example:8443/wp-json/kiyoh/v1/oauth/callback'), 'a changed return address does not reuse the secret');
$pending_account = $stored_account;
$pending_account['shop_status'] = 'pending';
expect_false(Kiyoh_Connect::registration_reusable($pending_account, Kiyoh_Connect::ISSUER_KIYOH, $callback), 'a pending secret is not reused');
$empty_secret = $stored_account;
$empty_secret['shop_secret'] = '';
expect_false(Kiyoh_Connect::registration_reusable($empty_secret, Kiyoh_Connect::ISSUER_KIYOH, $callback), 'a missing shop secret is not reused');

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
expect_true(strpos($authorize, 'shop_secret') === false, 'the shop secret stays off the authorize URL');
expect_true(strpos($authorize, 'client_secret') === false, 'the authorize URL has no client secret');
expect_same('', Kiyoh_Connect::authorize_url('https://evil.example', $callback, $generated, 'abc'), 'authorize refuses an unknown issuer');

$shop_id = 'shop_abc12345';
$shop_secret = 'shop-secret-value-0001';
$form = Kiyoh_Connect::authorization_code_form('code-value', $verifier, $callback, $shop_id, $shop_secret);
expect_same(array('grant_type', 'client_id', 'shop_id', 'shop_secret', 'code', 'code_verifier', 'redirect_uri'), array_keys($form), 'token form fields are the 1.2.0 set');
expect_same('authorization_code', $form['grant_type'], 'token grant is authorization_code');
expect_same('kiyoh-woocommerce', $form['client_id'], 'token form sends the client id');
expect_same($shop_id, $form['shop_id'], 'token form sends the shop id');
expect_same($shop_secret, $form['shop_secret'], 'token form sends the shop secret');
expect_same($verifier, $form['code_verifier'], 'token form sends the PKCE verifier');
expect_false(array_key_exists('client_secret', $form), 'token form has no client secret');
$refresh = Kiyoh_Connect::refresh_token_form('refresh-token-value-0001', $shop_id, $shop_secret);
expect_same(array('grant_type', 'refresh_token', 'client_id', 'shop_id', 'shop_secret'), array_keys($refresh), 'refresh form fields are the 1.2.0 set');
expect_same('refresh_token', $refresh['grant_type'], 'refresh uses grant_type refresh_token');
expect_same($shop_secret, $refresh['shop_secret'], 'refresh sends the shop secret');
$revoke = Kiyoh_Connect::revoke_token_form('refresh-token-value-0001', $shop_id, $shop_secret);
expect_same(array('token', 'client_id', 'shop_id', 'shop_secret'), array_keys($revoke), 'revoke form fields are the 1.2.0 set');
expect_same('refresh-token-value-0001', $revoke['token'], 'revoke sends the token');
expect_false(array_key_exists('token_type_hint', $revoke), 'revoke has no token_type_hint');
$shop_revoke = Kiyoh_Connect::shop_revoke_body($shop_id, $shop_secret);
expect_same(array('client_id' => 'kiyoh-woocommerce', 'shop_id' => $shop_id, 'shop_secret' => $shop_secret), $shop_revoke, 'shop revoke sends client_id, shop_id, and shop_secret');

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
expect_same('Kiyoh-Connect rejected this shop secret. Connect again.', Kiyoh_Connect::oauth_error_message('invalid_client'), 'invalid client has a clear admin message');

$stored = Kiyoh_Connect::encrypt_secret('refresh-token-value-0001', 'wp-salt-value');
expect_true($stored !== '' && strpos($stored, 'refresh-token-value-0001') === false, 'refresh token ciphertext does not contain the token');
expect_same('refresh-token-value-0001', Kiyoh_Connect::decrypt_secret($stored, 'wp-salt-value'), 'refresh token round-trips');
expect_same('', Kiyoh_Connect::decrypt_secret($stored, 'other-salt'), 'a different key does not decrypt');
expect_same('', Kiyoh_Connect::encrypt_secret('refresh-token-value-0001', ''), 'encryption refuses an empty key');
$stored_shop = Kiyoh_Connect::encrypt_secret('shop-secret-value-0001', 'wp-salt-value');
expect_true($stored_shop !== '' && strpos($stored_shop, 'shop-secret-value-0001') === false, 'shop secret ciphertext does not contain the secret');
expect_same('shop-secret-value-0001', Kiyoh_Connect::decrypt_secret($stored_shop, 'wp-salt-value'), 'shop secret round-trips');

$html = Kiyoh_Connect::callback_document(false, '<script>alert(1)</script>', $origin, 'https://evil.example/phish');
expect_true(strpos($html, '<script>alert') === false, 'callback HTML escapes the message');
expect_true(strpos($html, 'evil.example') === false, 'callback HTML drops a next URL on another host');
expect_true(strpos($html, 'kiyoh-connect') !== false, 'callback HTML posts a kiyoh-connect message');
expect_same('', Kiyoh_Connect::safe_next_url('http://shop.example/wp-admin/', $origin), 'the popup does not continue to an http admin URL');

$settings = 'https://shop.example/wp-admin/admin.php?page=kiyoh-settings&tab=general&kiyoh_connect=connected';
$connected = Kiyoh_Connect::callback_document(true, 'Connected with Kiyoh-Connect.', $origin, $settings);
expect_true(strpos($connected, 'opener.postMessage(payload,origin)') !== false, 'the popup posts only to the opener origin variable');
expect_true(strpos($connected, 'postMessage(payload,"*")') === false && strpos($connected, "postMessage(payload,'*')") === false, 'the popup never posts to *');
expect_true(strpos($connected, '"https:\\/\\/shop.example"') !== false || strpos($connected, '"https://shop.example"') !== false, 'the postMessage origin is the shop https origin');
$close_ack = strpos($connected, 'data.source!=="kiyoh-connect-ack"');
$close_call = strpos($connected, 'window.close()');
expect_true($close_ack !== false && $close_call !== false && $close_ack < $close_call, 'window.close runs only after the opener ack');
expect_true(strpos($connected, 'if(!opener||!origin){fallback();return;}') !== false, 'a missing opener uses the same-window fallback');
expect_true(strpos($connected, 'if(next){window.location.replace(next);}') !== false, 'the fallback navigates this window to the settings URL');
expect_true(strpos($connected, 'window.opener.postMessage') === false, 'the popup does not keep the old always-redirect script');
expect_true(strpos($connected, 'window.setTimeout(fallback,500)') !== false, 'a lost opener falls back after a short wait');
expect_true(strpos($connected, 'href="' . htmlspecialchars($settings, ENT_QUOTES, 'UTF-8') . '"') !== false, 'the same-origin settings URL is the manual fallback link');
$quoted = 'https://shop.example/wp-admin/admin.php?q="><img';
$quoted_html = Kiyoh_Connect::callback_document(true, 'ok', $origin, $quoted);
expect_true(strpos($quoted_html, 'q="><img') === false, 'the fallback link escapes a quote in the settings URL');

$admin_js = file_get_contents(dirname(__DIR__) . '/kiyoh-woocommerce-plugin/kiyoh-woocommerce/admin/js/kiyoh-admin.js');
expect_true(strpos($admin_js, 'event.origin !== window.kiyohAdminData.callbackOrigin') !== false, 'the settings tab rejects a message from another origin');
expect_true(strpos($admin_js, 'event.source !== KiyohAdmin.connectPopup') !== false, 'the settings tab ignores a message from another frame');
expect_true(strpos($admin_js, "event.source.postMessage({ source: 'kiyoh-connect-ack' }, event.origin)") !== false, 'the ack is sent to the callback origin only');
expect_true(strpos($admin_js, 'connectPopup.close()') !== false, 'the settings tab closes the popup');
expect_true(strpos($admin_js, 'kiyoh_connect=connected') !== false, 'the settings tab reloads as Connected');
expect_true(strpos($admin_js, ", '*'") === false && strpos($admin_js, ', "*"') === false, 'admin script does not postMessage to *');
$session_php = file_get_contents(dirname(__DIR__) . '/kiyoh-woocommerce-plugin/kiyoh-woocommerce/includes/class-kiyoh-connect-session.php');
expect_true(strpos($session_php, "header('Cross-Origin-Opener-Policy: unsafe-none');") !== false, 'the callback response does not sever window.opener');

$plugin_root = dirname(__DIR__) . '/kiyoh-woocommerce-plugin/kiyoh-woocommerce';
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($plugin_root));
$shipped_secret = false;
foreach ($iterator as $file) {
    if (substr($file->getFilename(), -4) !== '.php') {
        continue;
    }
    $text = file_get_contents($file->getPathname());
    if (preg_match('/KIYOH_CONNECT_HMAC_KEY|KIYOH_CONNECT_CLIENT_SECRET/', $text)) {
        $shipped_secret = $file->getPathname();
        break;
    }
    if (preg_match('/(client_secret|hmac_key|shop_secret)\s*[:=]\s*[\'\"][^\'\"\s]{8,}[\'\"]/i', $text)) {
        $shipped_secret = $file->getPathname();
        break;
    }
}
expect_same(false, $shipped_secret, 'the plugin does not name a wp-config Connect secret or ship a shop secret');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} failed\n");
    exit(1);
}
echo "All Kiyoh-Connect checks passed\n";
