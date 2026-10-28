<?php
/**
 * Kiyoh-Connect 1.2.0 protocol helpers for the WooCommerce plugin.
 *
 * No WordPress calls, so the rules can be tested without a shop.
 * The client is public. Connect issues a per-shop secret at registration.
 * That secret is stored encrypted by the WordPress layer and is not in this file.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Kiyoh_Connect {

    const CLIENT_ID = 'kiyoh-woocommerce';
    const SCOPE = 'integration:connect';
    const ISSUER_KIYOH = 'https://connect.kiyoh.com';
    const ISSUER_KLANTENVERTELLEN = 'https://connect.klantenvertellen.nl';
    const TENANT_KIYOH = 98;
    const TENANT_KLANTENVERTELLEN = 99;
    const CALLBACK_PATH = '/wp-json/kiyoh/v1/oauth/callback';
    const REGISTER_PATH = '/shops/register';
    const SHOP_REVOKE_PATH = '/shops/revoke';
    const AUTHORIZE_PATH = '/authorize';
    const TOKEN_PATH = '/token';
    const CREDENTIALS_PATH = '/integration/credentials';
    const REVOKE_PATH = '/revoke';
    const OPTION_ACCOUNT = 'kiyoh_connect_account';
    const STATE_TTL = 900;
    const MAX_URL_LENGTH = 500;
    const INTERACTIVE_MAX_ATTEMPTS = 3;
    const INTERACTIVE_WAIT_CAP_SECONDS = 8;
    const RETRY_BASE_SECONDS = 1;
    const RETRY_CAP_SECONDS = 60;

    /**
     * @param mixed $platform kiyoh|klantenvertellen
     * @return string
     */
    public static function issuer_for_platform($platform) {
        if ($platform === 'kiyoh') {
            return self::ISSUER_KIYOH;
        }
        if ($platform === 'klantenvertellen') {
            return self::ISSUER_KLANTENVERTELLEN;
        }
        return '';
    }

    /**
     * @param mixed $issuer
     * @return bool
     */
    public static function is_allowed_issuer($issuer) {
        return $issuer === self::ISSUER_KIYOH || $issuer === self::ISSUER_KLANTENVERTELLEN;
    }

    /**
     * @param mixed $platform
     * @return int 0 when the platform is unknown.
     */
    public static function tenant_for_platform($platform) {
        if ($platform === 'kiyoh') {
            return self::TENANT_KIYOH;
        }
        if ($platform === 'klantenvertellen') {
            return self::TENANT_KLANTENVERTELLEN;
        }
        return 0;
    }

    /**
     * @param mixed $tenant
     * @return string
     */
    public static function platform_for_tenant($tenant) {
        if ((string) $tenant === (string) self::TENANT_KIYOH) {
            return 'kiyoh';
        }
        if ((string) $tenant === (string) self::TENANT_KLANTENVERTELLEN) {
            return 'klantenvertellen';
        }
        return '';
    }

    /**
     * https origin with no path and no trailing slash. Default port 443 is omitted.
     *
     * @param mixed $url
     * @return string
     */
    /**
     * HTTP shops cannot use Connect. Empty when the URL is not http.
     *
     * @param mixed $url
     * @return string http|invalid|''
     */
    public static function connect_block_reason($url) {
        $parts = is_string($url) ? parse_url($url) : false;
        if (!is_array($parts)) {
            return 'invalid';
        }
        $scheme = isset($parts['scheme']) ? strtolower((string) $parts['scheme']) : '';
        if ($scheme === 'http') {
            return 'http';
        }
        return '';
    }

    public static function shop_origin($url) {
        $parts = is_string($url) ? parse_url($url) : false;
        if (!is_array($parts)) {
            return '';
        }
        $scheme = isset($parts['scheme']) ? strtolower((string) $parts['scheme']) : '';
        $host = isset($parts['host']) ? strtolower((string) $parts['host']) : '';
        if ($scheme !== 'https' || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            return '';
        }
        $port = isset($parts['port']) ? (int) $parts['port'] : 443;
        if ($port <= 0 || $port > 65535) {
            return '';
        }
        if ($port === 443) {
            return 'https://' . $host;
        }
        return 'https://' . $host . ':' . $port;
    }

    /**
     * @param mixed $origin
     * @return string
     */
    public static function callback_url($origin) {
        $normalized = self::shop_origin(is_string($origin) ? $origin . '/' : '');
        if ($normalized === '') {
            return '';
        }
        return $normalized . self::CALLBACK_PATH;
    }

    /**
     * Registration has no signature. Connect returns the shop secret once.
     *
     * @param mixed $origin
     * @param mixed $redirect_uri
     * @return array<string,string>
     */
    public static function register_body($origin, $redirect_uri) {
        return array(
            'client_id' => self::CLIENT_ID,
            'shop_url' => is_string($origin) ? $origin : '',
            'redirect_uri' => is_string($redirect_uri) ? $redirect_uri : '',
        );
    }

    /**
     * The secret from a 200 registration is shown once. Reuse it when this
     * issuer and return address are unchanged.
     *
     * @param mixed $account
     * @param mixed $issuer
     * @param mixed $redirect_uri
     * @return bool
     */
    public static function registration_reusable($account, $issuer, $redirect_uri) {
        if (!is_array($account) || !self::is_allowed_issuer($issuer) || !is_string($redirect_uri) || $redirect_uri === '') {
            return false;
        }
        if (($account['issuer'] ?? '') !== $issuer || ($account['redirect_uri'] ?? '') !== $redirect_uri) {
            return false;
        }
        if (($account['shop_status'] ?? '') === 'pending') {
            return false;
        }
        $shop_id = $account['shop_id'] ?? '';
        $shop_secret = $account['shop_secret'] ?? '';
        return is_string($shop_id) && $shop_id !== '' && is_string($shop_secret) && $shop_secret !== '';
    }

    /**
     * @param mixed $data
     * @param mixed $expected_origin
     * @param mixed $expected_redirect
     * @return array{ok:bool,error?:string,shop_id?:string,shop_secret?:string,status?:string}
     */
    public static function parse_registration($data, $expected_origin, $expected_redirect) {
        if (!is_array($data)) {
            return array('ok' => false, 'error' => 'registration_unreadable');
        }
        if (isset($data['client_id']) && $data['client_id'] !== self::CLIENT_ID) {
            return array('ok' => false, 'error' => 'registration_client');
        }
        if (isset($data['shop_origin']) && $data['shop_origin'] !== $expected_origin) {
            return array('ok' => false, 'error' => 'registration_origin');
        }
        if (isset($data['redirect_uri']) && $data['redirect_uri'] !== $expected_redirect) {
            return array('ok' => false, 'error' => 'registration_redirect');
        }
        $status = isset($data['status']) ? (string) $data['status'] : '';
        if ($status !== 'active' && $status !== 'pending') {
            return array('ok' => false, 'error' => 'registration_status');
        }
        $shop_id = isset($data['shop_id']) ? (string) $data['shop_id'] : '';
        $shop_secret = isset($data['shop_secret']) ? (string) $data['shop_secret'] : '';
        if (!preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $shop_id)) {
            return array('ok' => false, 'error' => 'registration_invalid');
        }
        if (strlen($shop_secret) < 16 || strlen($shop_secret) > 256 || preg_match('/[\s\x00-\x1F]/', $shop_secret)) {
            return array('ok' => false, 'error' => 'registration_invalid');
        }
        return array(
            'ok' => true,
            'shop_id' => $shop_id,
            'shop_secret' => $shop_secret,
            'status' => $status,
        );
    }

    /**
     * @param mixed $shop_id
     * @param mixed $shop_secret
     * @return array<string,string>
     */
    public static function shop_revoke_body($shop_id, $shop_secret) {
        return array(
            'client_id' => self::CLIENT_ID,
            'shop_id' => is_string($shop_id) ? $shop_id : '',
            'shop_secret' => is_string($shop_secret) ? $shop_secret : '',
        );
    }

    /**
     * @param mixed $issuer
     * @param mixed $redirect_uri
     * @param mixed $state
     * @param mixed $challenge
     * @return string
     */
    public static function authorize_url($issuer, $redirect_uri, $state, $challenge) {
        if (!self::is_allowed_issuer($issuer)) {
            return '';
        }
        if (!is_string($redirect_uri) || $redirect_uri === '' || !is_string($state) || !is_string($challenge)) {
            return '';
        }
        if ($state === '' || $challenge === '') {
            return '';
        }
        $query = http_build_query(array(
            'response_type' => 'code',
            'client_id' => self::CLIENT_ID,
            'redirect_uri' => $redirect_uri,
            'scope' => self::SCOPE,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'state' => $state,
        ), '', '&', PHP_QUERY_RFC3986);
        return $issuer . self::AUTHORIZE_PATH . '?' . $query;
    }

    /**
     * @return string
     */
    public static function code_verifier() {
        return self::base64url(random_bytes(32));
    }

    /**
     * @param mixed $verifier
     * @return string
     */
    public static function code_challenge($verifier) {
        if (!is_string($verifier) || $verifier === '') {
            return '';
        }
        return self::base64url(hash('sha256', $verifier, true));
    }

    /**
     * @param string $raw
     * @return string
     */
    public static function base64url($raw) {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /**
     * @param mixed $code
     * @param mixed $verifier
     * @param mixed $redirect_uri
     * @param mixed $shop_id
     * @param mixed $shop_secret
     * @return array<string,string>
     */
    public static function authorization_code_form($code, $verifier, $redirect_uri, $shop_id, $shop_secret) {
        return array(
            'grant_type' => 'authorization_code',
            'client_id' => self::CLIENT_ID,
            'shop_id' => is_string($shop_id) ? $shop_id : '',
            'shop_secret' => is_string($shop_secret) ? $shop_secret : '',
            'code' => is_string($code) ? $code : '',
            'code_verifier' => is_string($verifier) ? $verifier : '',
            'redirect_uri' => is_string($redirect_uri) ? $redirect_uri : '',
        );
    }

    /**
     * @param mixed $refresh_token
     * @param mixed $shop_id
     * @param mixed $shop_secret
     * @return array<string,string>
     */
    public static function refresh_token_form($refresh_token, $shop_id, $shop_secret) {
        return array(
            'grant_type' => 'refresh_token',
            'refresh_token' => is_string($refresh_token) ? $refresh_token : '',
            'client_id' => self::CLIENT_ID,
            'shop_id' => is_string($shop_id) ? $shop_id : '',
            'shop_secret' => is_string($shop_secret) ? $shop_secret : '',
        );
    }

    /**
     * @param mixed $token
     * @param mixed $shop_id
     * @param mixed $shop_secret
     * @return array<string,string>
     */
    public static function revoke_token_form($token, $shop_id, $shop_secret) {
        return array(
            'token' => is_string($token) ? $token : '',
            'client_id' => self::CLIENT_ID,
            'shop_id' => is_string($shop_id) ? $shop_id : '',
            'shop_secret' => is_string($shop_secret) ? $shop_secret : '',
        );
    }

    /**
     * @param mixed $state
     * @return bool
     */
    public static function is_plausible_state($state) {
        return is_string($state) && (bool) preg_match('/^[A-Za-z0-9_-]{43,128}$/', $state);
    }

    /**
     * @param mixed $code
     * @return bool
     */
    public static function is_plausible_code($code) {
        return is_string($code) && (bool) preg_match('/^[A-Za-z0-9._~-]{8,512}$/', $code);
    }

    /**
     * @param mixed $state
     * @return string Transient name, or empty when the state is unusable.
     */
    public static function state_storage_key($state) {
        if (!self::is_plausible_state($state)) {
            return '';
        }
        return 'kiyoh_oc_' . substr(hash('sha256', $state), 0, 32);
    }

    /**
     * @param mixed $data
     * @return array{ok:bool,error?:string,access_token?:string,refresh_token?:string,expires_in?:int}
     */
    public static function parse_token($data) {
        if (!is_array($data)) {
            return array('ok' => false, 'error' => 'token_unreadable');
        }
        $access = isset($data['access_token']) ? $data['access_token'] : '';
        if (!self::is_token_value($access)) {
            return array('ok' => false, 'error' => 'token_incomplete');
        }
        $refresh = '';
        if (array_key_exists('refresh_token', $data) && $data['refresh_token'] !== '' && $data['refresh_token'] !== null) {
            if (!self::is_token_value($data['refresh_token'])) {
                return array('ok' => false, 'error' => 'token_incomplete');
            }
            $refresh = $data['refresh_token'];
        }
        if (isset($data['token_type']) && strtolower((string) $data['token_type']) !== 'bearer') {
            return array('ok' => false, 'error' => 'token_type');
        }
        $expires = 3600;
        if (isset($data['expires_in'])) {
            if (!is_numeric($data['expires_in'])) {
                return array('ok' => false, 'error' => 'token_incomplete');
            }
            $expires = (int) $data['expires_in'];
            if ($expires < 0 || $expires > 2592000) {
                return array('ok' => false, 'error' => 'token_incomplete');
            }
        }
        return array(
            'ok' => true,
            'access_token' => $access,
            'refresh_token' => $refresh,
            'expires_in' => $expires,
        );
    }

    /**
     * @param mixed $data
     * @return array{ok:bool,error?:string,location_id?:string,tenant_id?:int,location_name?:string,api_key?:string,platform?:string}
     */
    public static function parse_credentials($data) {
        if (!is_array($data)) {
            return array('ok' => false, 'error' => 'credentials_unreadable');
        }
        $node = $data;
        if (!isset($node['locationId']) && isset($data['data']) && is_array($data['data'])) {
            $node = $data['data'];
        }
        $location = isset($node['locationId']) ? trim((string) $node['locationId']) : '';
        $api_key = isset($node['inviteApiKey']) ? (string) $node['inviteApiKey'] : '';
        $name = isset($node['locationName']) ? (string) $node['locationName'] : '';
        if (!isset($node['tenantId']) || $location === '' || $api_key === '') {
            return array('ok' => false, 'error' => 'credentials_incomplete');
        }
        $platform = self::platform_for_tenant($node['tenantId']);
        if ($platform === '') {
            return array('ok' => false, 'error' => 'credentials_tenant');
        }
        if (!preg_match('/^[A-Za-z0-9._:-]{1,64}$/', $location)) {
            return array('ok' => false, 'error' => 'credentials_invalid');
        }
        if (strlen($api_key) < 8 || strlen($api_key) > 256 || preg_match('/[\s\x00-\x1F]/', $api_key)) {
            return array('ok' => false, 'error' => 'credentials_invalid');
        }
        $name = trim(strip_tags($name));
        if (strlen($name) > 200) {
            $name = substr($name, 0, 200);
        }
        return array(
            'ok' => true,
            'location_id' => $location,
            'tenant_id' => (int) $node['tenantId'],
            'location_name' => $name,
            'api_key' => $api_key,
            'platform' => $platform,
        );
    }

    /**
     * @param mixed $url
     * @param array<int,string>|null $resolved_ips Null skips DNS evidence. An empty array means the name did not resolve.
     * @return array{ok:bool,error:string}
     */
    public static function validate_callback_url($url, $resolved_ips = null) {
        if (!is_string($url) || $url === '' || strlen($url) > self::MAX_URL_LENGTH) {
            return array('ok' => false, 'error' => 'invalid');
        }
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return array('ok' => false, 'error' => 'invalid');
        }
        $scheme = isset($parts['scheme']) ? strtolower((string) $parts['scheme']) : '';
        if ($scheme === 'http') {
            return array('ok' => false, 'error' => 'http');
        }
        if ($scheme !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || isset($parts['query'])) {
            return array('ok' => false, 'error' => 'invalid');
        }
        $path = isset($parts['path']) ? $parts['path'] : '';
        if ($path !== self::CALLBACK_PATH) {
            return array('ok' => false, 'error' => 'invalid');
        }
        $host = isset($parts['host']) ? strtolower((string) $parts['host']) : '';
        if ($host === '' || self::is_disallowed_host($host)) {
            return array('ok' => false, 'error' => 'private_host');
        }
        if (self::is_ip_literal($host)) {
            if (self::is_disallowed_ip($host)) {
                return array('ok' => false, 'error' => 'private_host');
            }
            return array('ok' => true, 'error' => '');
        }
        if (is_array($resolved_ips)) {
            if ($resolved_ips === array()) {
                return array('ok' => false, 'error' => 'unresolved');
            }
            foreach ($resolved_ips as $ip) {
                if (!is_string($ip) || self::is_disallowed_ip($ip)) {
                    return array('ok' => false, 'error' => 'private_host');
                }
            }
        }
        return array('ok' => true, 'error' => '');
    }

    /**
     * @param mixed $error
     * @return string
     */
    public static function validation_message($error) {
        if ($error === 'http') {
            return 'Kiyoh-Connect needs an HTTPS shop address. This shop is using HTTP, so Connect cannot return here. Use the Location ID and API key fields, or serve the shop over HTTPS.';
        }
        if ($error === 'private_host') {
            return 'Kiyoh-Connect only accepts a public HTTPS return address. Local and private shop addresses cannot be registered.';
        }
        if ($error === 'unresolved') {
            return 'The shop address could not be confirmed as a public host. Connect sign-in was stopped.';
        }
        return 'The shop return address is not one Kiyoh-Connect can register.';
    }

    /**
     * @param mixed $error OAuth error code.
     * @return string
     */
    public static function oauth_error_message($error) {
        $map = array(
            'access_denied' => 'The sign-in was cancelled.',
            'invalid_client' => 'Kiyoh-Connect rejected this shop secret. Connect again.',
            'invalid_grant' => 'The sign-in code expired. Start again from WooCommerce, then Kiyoh.',
            'invalid_request' => 'Kiyoh-Connect rejected the sign-in request.',
            'unauthorized_client' => 'This shop is not allowed to use Kiyoh-Connect.',
            'server_error' => 'Kiyoh-Connect is unavailable. Try again shortly.',
            'temporarily_unavailable' => 'Kiyoh-Connect is unavailable. Try again shortly.',
        );
        if (is_string($error) && isset($map[$error])) {
            return $map[$error];
        }
        return 'Kiyoh-Connect could not complete the sign-in.';
    }

    /**
     * @param int $status
     * @return bool
     */
    public static function is_retryable_status($status) {
        return in_array((int) $status, array(0, 408, 425, 429, 500, 502, 503, 504), true);
    }

    /**
     * @param int $failure_number 1-based.
     * @return array{0:int,1:int}
     */
    public static function retry_delay_bounds($failure_number) {
        $failure_number = (int) $failure_number;
        if ($failure_number < 1) {
            $failure_number = 1;
        }
        $exp = self::RETRY_BASE_SECONDS * (2 ** ($failure_number - 1));
        if ($exp > self::RETRY_CAP_SECONDS) {
            $exp = self::RETRY_CAP_SECONDS;
        }
        return array(0, (int) $exp);
    }

    /**
     * @param int $failures_so_far
     * @param int $wait_seconds
     * @return bool
     */
    public static function interactive_should_retry($failures_so_far, $wait_seconds) {
        if ((int) $failures_so_far >= self::INTERACTIVE_MAX_ATTEMPTS) {
            return false;
        }
        if ((int) $wait_seconds > self::INTERACTIVE_WAIT_CAP_SECONDS) {
            return false;
        }
        return true;
    }

    /**
     * @param mixed $header
     * @param mixed $body
     * @param int $now
     * @return int|null
     */
    public static function parse_retry_after($header, $body, $now) {
        if (is_string($header) && preg_match('/^\d+$/', trim($header))) {
            return (int) trim($header);
        }
        if (is_string($header) && trim($header) !== '') {
            $stamp = strtotime(trim($header));
            if ($stamp !== false) {
                return max(0, $stamp - (int) $now);
            }
        }
        if (is_array($body) && isset($body['retryAfterSeconds']) && is_numeric($body['retryAfterSeconds'])) {
            return max(0, (int) $body['retryAfterSeconds']);
        }
        return null;
    }

    /**
     * @param mixed $plain
     * @param mixed $key_material
     * @return string Empty when encryption is not possible. Never returns the plaintext.
     */
    public static function encrypt_secret($plain, $key_material) {
        if (!is_string($plain) || $plain === '' || !is_string($key_material) || $key_material === '') {
            return '';
        }
        if (!function_exists('openssl_encrypt')) {
            return '';
        }
        $key = hash('sha256', $key_material, true);
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if (!is_string($cipher) || $cipher === '' || !is_string($tag) || strlen($tag) !== 16) {
            return '';
        }
        return 'v1:' . self::base64url($iv . $tag . $cipher);
    }

    /**
     * @param mixed $stored
     * @param mixed $key_material
     * @return string
     */
    public static function decrypt_secret($stored, $key_material) {
        if (!is_string($stored) || strpos($stored, 'v1:') !== 0 || !is_string($key_material) || $key_material === '') {
            return '';
        }
        if (!function_exists('openssl_decrypt')) {
            return '';
        }
        $raw = self::base64url_decode(substr($stored, 3));
        if ($raw === '' || strlen($raw) < 29) {
            return '';
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $key = hash('sha256', $key_material, true);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if (!is_string($plain) || $plain === '') {
            return '';
        }
        return $plain;
    }

    /**
     * Result page for the OAuth popup.
     *
     * When window.opener is still the WooCommerce settings tab, the page
     * postMessages that exact https origin (never '*') and closes. The opener
     * reloads itself. If the opener is missing, closed, or blocked (popup
     * blocker sent the shop itself through Connect, or COOP dropped the link),
     * this same window continues to the settings URL.
     *
     * @param mixed $ok
     * @param mixed $message
     * @param mixed $target_origin Opener origin that may receive the result.
     * @param mixed $next_url
     * @return string
     */
    public static function callback_document($ok, $message, $target_origin, $next_url) {
        $message = trim(strip_tags(is_string($message) ? $message : ''));
        if (strlen($message) > 200) {
            $message = substr($message, 0, 200);
        }
        $origin = self::shop_origin(is_string($target_origin) ? $target_origin . '/' : '');
        $next = self::safe_next_url($next_url, $origin);
        $flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES;
        $payload = json_encode(array(
            'source' => 'kiyoh-connect',
            'ok' => (bool) $ok,
            'message' => $message,
        ), $flags);
        $origin_js = json_encode($origin, $flags);
        $next_js = json_encode($next, $flags);
        if (!is_string($payload) || !is_string($origin_js) || !is_string($next_js)) {
            $payload = '{"source":"kiyoh-connect","ok":false,"message":""}';
            $origin_js = '""';
            $next_js = '""';
        }
        $heading = $ok ? 'Kiyoh is connected' : 'Kiyoh could not connect';
        $safe_heading = htmlspecialchars($heading, ENT_QUOTES, 'UTF-8');
        $safe_message = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        $fallback_link = '';
        if ($next !== '') {
            $fallback_link = '<p><a href="' . htmlspecialchars($next, ENT_QUOTES, 'UTF-8') . '">Return to WooCommerce</a></p>';
        }
        return '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="referrer" content="no-referrer"><title>'
            . $safe_heading
            . '</title></head><body><p>'
            . $safe_heading
            . '</p><p>'
            . $safe_message
            . '</p>'
            . $fallback_link
            . '<script>'
            . '(function(){var payload=' . $payload . ';var origin=' . $origin_js . ';var next=' . $next_js . ';'
            . 'var done=false;function fallback(){if(done){return;}done=true;if(next){window.location.replace(next);}}'
            . 'var opener=null;try{opener=window.opener&&!window.opener.closed?window.opener:null;}catch(e){opener=null;}'
            . 'if(!opener||!origin){fallback();return;}'
            . 'window.addEventListener("message",function(event){var data=event.data||{};'
            . 'if(event.origin!==origin||event.source!==opener||!data||data.source!=="kiyoh-connect-ack"){return;}'
            . 'done=true;window.close();});'
            . 'try{opener.postMessage(payload,origin);}catch(e){fallback();return;}'
            . 'window.setTimeout(fallback,500);'
            . '})();'
            . '</script></body></html>';
    }

    /**
     * @param mixed $url
     * @param mixed $origin
     * @return string
     */
    public static function safe_next_url($url, $origin) {
        if (!is_string($url) || !is_string($origin) || $origin === '') {
            return '';
        }
        $parts = parse_url($url);
        $origin_parts = parse_url($origin);
        if (!is_array($parts) || !is_array($origin_parts)) {
            return '';
        }
        $scheme = isset($parts['scheme']) ? strtolower((string) $parts['scheme']) : '';
        $host = isset($parts['host']) ? strtolower((string) $parts['host']) : '';
        $origin_host = isset($origin_parts['host']) ? strtolower((string) $origin_parts['host']) : '';
        if ($scheme !== 'https' || $host === '' || $host !== $origin_host) {
            return '';
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            return '';
        }
        return $url;
    }

    /**
     * @param mixed $host
     * @return bool
     */
    public static function is_disallowed_host($host) {
        $host = strtolower(rtrim((string) $host, '.'));
        if ($host === '' || $host === 'localhost' || $host === 'localhost.localdomain') {
            return true;
        }
        $suffixes = array('.local', '.localhost', '.localdomain', '.internal', '.intranet', '.lan');
        foreach ($suffixes as $suffix) {
            $length = strlen($suffix);
            if (strlen($host) > $length && substr($host, -$length) === $suffix) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param mixed $ip
     * @return bool
     */
    public static function is_disallowed_ip($ip) {
        if (!is_string($ip) || $ip === '') {
            return true;
        }
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return true;
        }
        if (strlen($packed) === 16 && substr($packed, 0, 12) === "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff") {
            $mapped = inet_ntop(substr($packed, 12, 4));
            return self::is_disallowed_ipv4($mapped);
        }
        if (strlen($packed) === 16) {
            if ($packed === inet_pton('::') || $packed === inet_pton('::1')) {
                return true;
            }
            $first = ord($packed[0]);
            $second = ord($packed[1]);
            if (($first & 0xFE) === 0xFC) {
                return true;
            }
            if ($first === 0xFE && ($second & 0xC0) === 0x80) {
                return true;
            }
            if ($first === 0xFF) {
                return true;
            }
            return false;
        }
        if (strlen($packed) === 4) {
            return self::is_disallowed_ipv4($ip);
        }
        return true;
    }

    /**
     * @param mixed $value
     * @return bool
     */
    private static function is_token_value($value) {
        return is_string($value)
            && strlen($value) >= 20
            && strlen($value) <= 4096
            && !preg_match('/[\s\x00-\x1F]/', $value);
    }

    /**
     * @param mixed $host
     * @return bool
     */
    private static function is_ip_literal($host) {
        return is_string($host) && filter_var($host, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * @param string $ip
     * @return bool
     */
    private static function is_disallowed_ipv4($ip) {
        $long = ip2long($ip);
        if ($long === false) {
            return true;
        }
        $long = (int) sprintf('%u', $long);
        $ranges = array(
            array('0.0.0.0', 8),
            array('10.0.0.0', 8),
            array('100.64.0.0', 10),
            array('127.0.0.0', 8),
            array('169.254.0.0', 16),
            array('172.16.0.0', 12),
            array('192.0.2.0', 24),
            array('192.168.0.0', 16),
            array('198.18.0.0', 15),
            array('198.51.100.0', 24),
            array('203.0.113.0', 24),
            array('224.0.0.0', 4),
            array('240.0.0.0', 4),
        );
        foreach ($ranges as $range) {
            $network = (int) sprintf('%u', ip2long($range[0]));
            $bits = (int) $range[1];
            $mask = $bits === 0 ? 0 : ((0xFFFFFFFF << (32 - $bits)) & 0xFFFFFFFF);
            if (($long & $mask) === ($network & $mask)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param string $encoded
     * @return string
     */
    private static function base64url_decode($encoded) {
        $remainder = strlen($encoded) % 4;
        if ($remainder > 0) {
            $encoded .= str_repeat('=', 4 - $remainder);
        }
        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);
        return is_string($decoded) ? $decoded : '';
    }
}
