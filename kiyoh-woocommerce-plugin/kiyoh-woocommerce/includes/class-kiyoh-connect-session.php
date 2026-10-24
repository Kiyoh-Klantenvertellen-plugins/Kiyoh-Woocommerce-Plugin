<?php
/**
 * WordPress side of Kiyoh-Connect sign-in.
 *
 * Start and disconnect are admin AJAX calls with a nonce and manage_woocommerce.
 * The browser returns to a REST callback that has no WP nonce; the OAuth state
 * is the CSRF token, and the code is exchanged on the server with the client secret.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Kiyoh_Connect_Session {

    const START_NONCE = 'kiyoh_connect_start';
    const DISCONNECT_NONCE = 'kiyoh_connect_disconnect';
    const FLASH_TRANSIENT = 'kiyoh_connect_flash';
    const REFRESH_LOCK = 'kiyoh_connect_refresh_lock';

    /**
     * connect while saving a Connect login, disconnect while clearing it.
     * Empty during a normal settings save.
     *
     * @var string
     */
    private static $apply_mode = '';

    /**
     * @return string
     */
    public static function apply_mode() {
        return self::$apply_mode;
    }

    /**
     * @return bool
     */
    public static function secrets_configured() {
        return self::hmac_key() !== '' && self::client_secret() !== '';
    }

    /**
     * @param mixed $server Unused. rest_api_init passes the REST server.
     */
    public static function register_routes($server = null) {
        register_rest_route('kiyoh/v1', '/oauth/callback', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'handle_callback'),
            'permission_callback' => array(__CLASS__, 'callback_permission'),
        ));
        add_filter('rest_pre_serve_request', array(__CLASS__, 'serve_callback_html'), 10, 3);
    }

    /**
     * @param WP_REST_Request $request
     * @return bool|WP_Error
     */
    public static function callback_permission($request) {
        $error = $request->get_param('error');
        if (is_string($error) && $error !== '') {
            return true;
        }
        $state = $request->get_param('state');
        $code = $request->get_param('code');
        if (!Kiyoh_Connect::is_plausible_state($state) || !Kiyoh_Connect::is_plausible_code($code)) {
            return new WP_Error(
                'kiyoh_connect_rejected',
                __('Missing or invalid Connect callback.', 'kiyoh-woocommerce'),
                array('status' => 400)
            );
        }
        return true;
    }

    /**
     * @param bool $served
     * @param mixed $result
     * @param mixed $request
     * @return bool
     */
    public static function serve_callback_html($served, $result, $request) {
        if (!($request instanceof WP_REST_Request) || $request->get_route() !== '/kiyoh/v1/oauth/callback') {
            return $served;
        }
        if (!($result instanceof WP_REST_Response) || !is_string($result->get_data())) {
            return $served;
        }
        if (function_exists('status_header')) {
            status_header((int) $result->get_status());
        }
        header('Content-Type: text/html; charset=utf-8');
        header('Referrer-Policy: no-referrer');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo $result->get_data();
        return true;
    }

    public static function handle_start() {
        if (!self::current_user_may_connect()) {
            wp_send_json_error(array('message' => __('You do not have permission to connect Kiyoh.', 'kiyoh-woocommerce')), 403);
        }
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : ''; // preflight: ignore — wp_verify_nonce and manage_woocommerce run before this value is used
        if (!wp_verify_nonce($nonce, self::START_NONCE)) {
            wp_send_json_error(array('message' => __('The Connect form expired. Reload the Kiyoh settings page and try again.', 'kiyoh-woocommerce')), 403);
        }

        $platform = isset($_POST['platform']) ? sanitize_text_field(wp_unslash($_POST['platform'])) : ''; // preflight: ignore — allow-listed to kiyoh or klantenvertellen after the nonce and capability checks
        $issuer = Kiyoh_Connect::issuer_for_platform($platform);
        if ($issuer === '') {
            wp_send_json_error(array('message' => __('Choose Kiyoh.com or Klantenvertellen.nl before connecting.', 'kiyoh-woocommerce')), 400);
        }
        if (!self::secrets_configured()) {
            wp_send_json_error(array('message' => self::missing_secret_message()), 400);
        }
        if (!function_exists('openssl_encrypt')) {
            wp_send_json_error(array('message' => __('This server cannot protect Connect tokens because OpenSSL is missing.', 'kiyoh-woocommerce')), 500);
        }

        $origin = Kiyoh_Connect::shop_origin(home_url('/'));
        $callback = Kiyoh_Connect::callback_url($origin);
        $resolved = self::resolved_ips($origin);
        $check = Kiyoh_Connect::validate_callback_url($callback, $resolved);
        if (!$check['ok']) {
            wp_send_json_error(array('message' => Kiyoh_Connect::validation_message($check['error'])), 400);
        }
        $actual = self::actual_rest_callback();
        if ($actual !== '' && untrailingslashit($actual) !== untrailingslashit($callback)) {
            wp_send_json_error(array(
                'message' => __('Kiyoh-Connect expects the return address at /wp-json/kiyoh/v1/oauth/callback on this shop. Pretty permalinks are required, and a subdirectory install is not supported. Use the Location ID and API key fields instead.', 'kiyoh-woocommerce'),
            ), 400);
        }

        $issued_at = time();
        $body = Kiyoh_Connect::signed_shop_body('register', $origin, $callback, $issued_at, self::hmac_key());
        if ($body === array()) {
            wp_send_json_error(array('message' => self::missing_secret_message()), 400);
        }
        $registered = self::remote_json('POST', $issuer . Kiyoh_Connect::REGISTER_PATH, array('Content-Type' => 'application/json'), wp_json_encode($body), true);
        if (!Kiyoh_Connect::register_accepted($registered['status'])) {
            $message = (int) $registered['status'] === 401
                ? __('Kiyoh-Connect rejected the shop signature. Check KIYOH_CONNECT_HMAC_KEY.', 'kiyoh-woocommerce')
                : Kiyoh_Connect::oauth_error_message(isset($registered['data']['error']) ? $registered['data']['error'] : '');
            wp_send_json_error(array('message' => $message), 502);
        }

        $verifier = Kiyoh_Connect::code_verifier();
        $state = Kiyoh_Connect::code_verifier();
        $stored_verifier = Kiyoh_Connect::encrypt_secret($verifier, self::crypto_key_material());
        if ($stored_verifier === '') {
            wp_send_json_error(array('message' => __('This server cannot protect Connect tokens.', 'kiyoh-woocommerce')), 500);
        }
        $key = Kiyoh_Connect::state_storage_key($state);
        set_transient($key, array(
            'state_hash' => hash('sha256', $state),
            'verifier' => $stored_verifier,
            'redirect_uri' => $callback,
            'issuer' => $issuer,
            'platform' => $platform,
            'user_id' => get_current_user_id(),
            'origin' => $origin,
            'opener_origin' => Kiyoh_Connect::shop_origin(admin_url('/')),
        ), Kiyoh_Connect::STATE_TTL);

        $authorize = Kiyoh_Connect::authorize_url($issuer, $callback, $state, Kiyoh_Connect::code_challenge($verifier));
        if ($authorize === '') {
            delete_transient($key);
            wp_send_json_error(array('message' => __('The Connect sign-in link could not be built.', 'kiyoh-woocommerce')), 500);
        }
        wp_send_json_success(array('authorize_url' => $authorize));
    }

    public static function handle_disconnect() {
        if (!self::current_user_may_connect()) {
            wp_send_json_error(array('message' => __('You do not have permission to disconnect Kiyoh.', 'kiyoh-woocommerce')), 403);
        }
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : ''; // preflight: ignore — wp_verify_nonce and manage_woocommerce run before this value is used
        if (!wp_verify_nonce($nonce, self::DISCONNECT_NONCE)) {
            wp_send_json_error(array('message' => __('The disconnect form expired. Reload the Kiyoh settings page and try again.', 'kiyoh-woocommerce')), 403);
        }
        self::revoke_current_account();
        self::clear_connect_credentials();
        delete_option(Kiyoh_Connect::OPTION_ACCOUNT);
        wp_send_json_success(array('message' => __('Disconnected from Kiyoh-Connect. Saved invitations and settings are still here. Paste a Location ID and API key if this shop should keep sending invites.', 'kiyoh-woocommerce')));
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function handle_callback($request) {
        $result = self::finish_authorization($request);
        $account_origin = '';
        $next = '';
        if (!empty($result['opener_origin'])) {
            $account_origin = $result['opener_origin'];
            $next = Kiyoh_Connect::safe_next_url(admin_url('admin.php?page=kiyoh-settings&tab=general&kiyoh_connect=' . ($result['ok'] ? 'connected' : 'error')), $account_origin);
        }
        $html = Kiyoh_Connect::callback_document($result['ok'], $result['message'], $account_origin, $next);
        $response = new WP_REST_Response($html, 200);
        return $response;
    }

    /**
     * Called by the publication API client after HTTP 401. One refresh, then one credential fetch.
     *
     * @return array{api_key:string,location_id:string}|null
     */
    public static function refresh_publication_credentials() {
        if (get_transient(self::REFRESH_LOCK)) {
            return null;
        }
        set_transient(self::REFRESH_LOCK, 1, 30);
        try {
            return self::refresh_publication_credentials_unlocked();
        } catch (Throwable $e) {
            return null;
        } finally {
            delete_transient(self::REFRESH_LOCK);
        }
    }

    /**
     * Best-effort shop revoke during uninstall. Local data is deleted either way.
     */
    public static function revoke_for_uninstall() {
        try {
            self::revoke_current_account();
        } catch (Throwable $e) {
            return;
        }
    }

    /**
     * Drop the refresh token when a merchant edits the manual key.
     */
    public static function on_manual_override() {
        $account = get_option(Kiyoh_Connect::OPTION_ACCOUNT, array());
        if (!is_array($account)) {
            return;
        }
        $account['refresh_token'] = '';
        $account['access_token'] = '';
        $account['access_expires_at'] = 0;
        $account['status'] = 'manual';
        update_option(Kiyoh_Connect::OPTION_ACCOUNT, $account, false);
    }

    /**
     * @return string
     */
    private static function hmac_key() {
        $constant = defined('KIYOH_CONNECT_HMAC_KEY') ? KIYOH_CONNECT_HMAC_KEY : '';
        $env = getenv('KIYOH_CONNECT_HMAC_KEY');
        return Kiyoh_Connect::configured_secret($constant, $env === false ? '' : $env);
    }

    /**
     * @return string
     */
    private static function client_secret() {
        $constant = defined('KIYOH_CONNECT_CLIENT_SECRET') ? KIYOH_CONNECT_CLIENT_SECRET : '';
        $env = getenv('KIYOH_CONNECT_CLIENT_SECRET');
        return Kiyoh_Connect::configured_secret($constant, $env === false ? '' : $env);
    }

    /**
     * @return string
     */
    private static function crypto_key_material() {
        if (!function_exists('wp_salt')) {
            return '';
        }
        $salt = wp_salt('auth');
        return is_string($salt) ? $salt : '';
    }

    /**
     * @return string
     */
    private static function missing_secret_message() {
        return __('Kiyoh-Connect signing is not configured on this server. The signing key and client secret are not part of the plugin. Add KIYOH_CONNECT_HMAC_KEY and KIYOH_CONNECT_CLIENT_SECRET in wp-config.php, or paste the Location ID and API key below.', 'kiyoh-woocommerce');
    }

    /**
     * @return bool
     */
    private static function current_user_may_connect() {
        return is_user_logged_in() && current_user_can('manage_woocommerce');
    }

    /**
     * @param string $origin
     * @return string[]
     */
    private static function resolved_ips($origin) {
        $host = (string) parse_url($origin, PHP_URL_HOST);
        if ($host === '') {
            return array();
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return array($host);
        }
        if (!function_exists('gethostbynamel')) {
            return array();
        }
        $records = @gethostbynamel($host);
        if (!is_array($records)) {
            return array();
        }
        $ips = array();
        foreach ($records as $ip) {
            if (is_string($ip) && $ip !== '') {
                $ips[] = $ip;
            }
        }
        return $ips;
    }

    /**
     * @return string
     */
    private static function actual_rest_callback() {
        if (!function_exists('rest_url')) {
            return '';
        }
        return (string) rest_url('kiyoh/v1/oauth/callback');
    }

    /**
     * @param WP_REST_Request $request
     * @return array{ok:bool,message:string,opener_origin:string}
     */
    private static function finish_authorization($request) {
        $failed = array('ok' => false, 'message' => '', 'opener_origin' => Kiyoh_Connect::shop_origin(admin_url('/')));
        $oauth_error = $request->get_param('error');
        if (is_string($oauth_error) && $oauth_error !== '') {
            $failed['message'] = Kiyoh_Connect::oauth_error_message(sanitize_key($oauth_error));
            $error_state = (string) $request->get_param('state');
            $error_key = Kiyoh_Connect::state_storage_key($error_state);
            $error_pending = $error_key === '' ? false : get_transient($error_key);
            if (is_array($error_pending) && !empty($error_pending['state_hash']) && hash_equals((string) $error_pending['state_hash'], hash('sha256', $error_state))) {
                delete_transient($error_key);
                $failed['opener_origin'] = isset($error_pending['opener_origin']) ? (string) $error_pending['opener_origin'] : '';
                self::flash($failed['message']);
            }
            return $failed;
        }

        $state = (string) $request->get_param('state');
        $code = (string) $request->get_param('code');
        $storage_key = Kiyoh_Connect::state_storage_key($state);
        $pending = $storage_key === '' ? false : get_transient($storage_key);
        if (!is_array($pending) || empty($pending['state_hash']) || empty($pending['verifier'])) {
            $failed['message'] = __('This sign-in expired. Start again from WooCommerce, then Kiyoh.', 'kiyoh-woocommerce');
            self::flash($failed['message']);
            return $failed;
        }
        if (!hash_equals((string) $pending['state_hash'], hash('sha256', $state))) {
            delete_transient($storage_key);
            $failed['message'] = __('This sign-in did not match the one started in this shop. Start again.', 'kiyoh-woocommerce');
            self::flash($failed['message']);
            return $failed;
        }
        $current_user = get_current_user_id();
        if ($current_user > 0 && (int) $pending['user_id'] !== $current_user) {
            delete_transient($storage_key);
            $failed['message'] = __('This sign-in was started by a different user. Start again.', 'kiyoh-woocommerce');
            self::flash($failed['message']);
            return $failed;
        }
        delete_transient($storage_key);

        $opener = isset($pending['opener_origin']) ? (string) $pending['opener_origin'] : '';
        $failed['opener_origin'] = $opener;
        $verifier = Kiyoh_Connect::decrypt_secret($pending['verifier'], self::crypto_key_material());
        if ($verifier === '' || !self::secrets_configured()) {
            $failed['message'] = self::missing_secret_message();
            self::flash($failed['message']);
            return $failed;
        }

        $issuer = isset($pending['issuer']) ? (string) $pending['issuer'] : '';
        $redirect_uri = isset($pending['redirect_uri']) ? (string) $pending['redirect_uri'] : '';
        if (!Kiyoh_Connect::is_allowed_issuer($issuer)) {
            $failed['message'] = __('The Connect sign-in link could not be built.', 'kiyoh-woocommerce');
            self::flash($failed['message']);
            return $failed;
        }

        $form = Kiyoh_Connect::authorization_code_form($code, $verifier, $redirect_uri, self::client_secret());
        $token_response = self::remote_json('POST', $issuer . Kiyoh_Connect::TOKEN_PATH, array('Content-Type' => 'application/x-www-form-urlencoded'), $form, true);
        $token = Kiyoh_Connect::parse_token($token_response['data']);
        if (!$token['ok']) {
            $failed['message'] = Kiyoh_Connect::oauth_error_message(isset($token_response['data']['error']) ? $token_response['data']['error'] : '');
            self::flash($failed['message']);
            return $failed;
        }

        $credentials_response = self::remote_json('GET', $issuer . Kiyoh_Connect::CREDENTIALS_PATH, array(
            'Authorization' => 'Bearer ' . $token['access_token'],
            'Accept' => 'application/json',
        ), null, true);
        $credentials = Kiyoh_Connect::parse_credentials($credentials_response['data']);
        if (!$credentials['ok']) {
            self::revoke_access_token($issuer, $token['access_token']);
            $failed['message'] = __('Kiyoh-Connect did not return a location and invite key for this shop.', 'kiyoh-woocommerce');
            self::flash($failed['message']);
            return $failed;
        }
        if ($credentials['platform'] !== $pending['platform']) {
            self::revoke_access_token($issuer, $token['access_token']);
            $failed['message'] = __('The location belongs to the other Kiyoh platform. Choose that platform and connect again.', 'kiyoh-woocommerce');
            self::flash($failed['message']);
            return $failed;
        }

        self::store_connection($pending, $token, $credentials);
        $message = $token['refresh_token'] === ''
            ? __('Connected. Kiyoh-Connect did not return a refresh token, so this shop cannot renew the key automatically.', 'kiyoh-woocommerce')
            : __('Connected with Kiyoh-Connect. The location and invite key are saved.', 'kiyoh-woocommerce');
        self::flash($message, true);
        return array('ok' => true, 'message' => $message, 'opener_origin' => $opener);
    }

    /**
     * @param array<string,mixed> $pending
     * @param array<string,mixed> $token
     * @param array<string,mixed> $credentials
     */
    private static function store_connection($pending, $token, $credentials) {
        $material = self::crypto_key_material();
        $refresh = $token['refresh_token'] === '' ? '' : Kiyoh_Connect::encrypt_secret($token['refresh_token'], $material);
        $access = Kiyoh_Connect::encrypt_secret($token['access_token'], $material);
        $account = array(
            'schema' => 1,
            'status' => 'connected',
            'platform' => $credentials['platform'],
            'issuer' => $pending['issuer'],
            'shop_origin' => $pending['origin'],
            'redirect_uri' => $pending['redirect_uri'],
            'opener_origin' => isset($pending['opener_origin']) ? $pending['opener_origin'] : '',
            'location_name' => $credentials['location_name'],
            'tenant_id' => $credentials['tenant_id'],
            'refresh_token' => $refresh,
            'access_token' => $access,
            'access_expires_at' => time() + (int) $token['expires_in'],
            'connected_at' => time(),
            'registered_at' => time(),
        );
        update_option(Kiyoh_Connect::OPTION_ACCOUNT, $account, false);
        self::apply_credentials_to_settings($credentials['location_id'], $credentials['api_key'], $credentials['platform']);
    }

    /**
     * @param string $location_id
     * @param string $api_key
     * @param string $platform
     */
    private static function apply_credentials_to_settings($location_id, $api_key, $platform) {
        self::$apply_mode = 'connect';
        try {
            $settings = get_option('kiyoh_woocommerce_settings', array());
            if (!is_array($settings)) {
                $settings = array();
            }
            if (!isset($settings['general']) || !is_array($settings['general'])) {
                $settings['general'] = array();
            }
            $settings['general']['location_id'] = $location_id;
            $settings['general']['api_key'] = $api_key;
            $settings['general']['platform'] = $platform;
            if (!isset($settings['general']['enabled'])) {
                $settings['general']['enabled'] = true;
            }
            update_option('kiyoh_woocommerce_settings', $settings);
        } finally {
            self::$apply_mode = '';
        }
    }

    /**
     * @return array{api_key:string,location_id:string}|null
     */
    private static function refresh_publication_credentials_unlocked() {
        $settings = get_option('kiyoh_woocommerce_settings', array());
        $source = is_array($settings) && isset($settings['general']['credential_source']) ? $settings['general']['credential_source'] : '';
        if ($source !== 'connect') {
            return null;
        }
        $account = get_option(Kiyoh_Connect::OPTION_ACCOUNT, array());
        if (!is_array($account) || empty($account['issuer']) || !Kiyoh_Connect::is_allowed_issuer($account['issuer'])) {
            return null;
        }
        if (!self::secrets_configured()) {
            return null;
        }
        $material = self::crypto_key_material();
        $access = '';
        if (!empty($account['access_token']) && (int) $account['access_expires_at'] > time() + 30) {
            $access = Kiyoh_Connect::decrypt_secret($account['access_token'], $material);
        }
        if ($access === '') {
            $refresh = Kiyoh_Connect::decrypt_secret(isset($account['refresh_token']) ? $account['refresh_token'] : '', $material);
            if ($refresh === '') {
                self::mark_broken();
                return null;
            }
            $form = Kiyoh_Connect::refresh_token_form($refresh, self::client_secret());
            $token_response = self::remote_json('POST', $account['issuer'] . Kiyoh_Connect::TOKEN_PATH, array('Content-Type' => 'application/x-www-form-urlencoded'), $form, true);
            $token = Kiyoh_Connect::parse_token($token_response['data']);
            if (!$token['ok']) {
                self::mark_broken();
                return null;
            }
            $access = $token['access_token'];
            $account['access_token'] = Kiyoh_Connect::encrypt_secret($access, $material);
            $account['access_expires_at'] = time() + (int) $token['expires_in'];
            if ($token['refresh_token'] !== '') {
                $replacement = Kiyoh_Connect::encrypt_secret($token['refresh_token'], $material);
                if ($replacement !== '') {
                    $account['refresh_token'] = $replacement;
                }
            }
            update_option(Kiyoh_Connect::OPTION_ACCOUNT, $account, false);
        }

        $credentials_response = self::remote_json('GET', $account['issuer'] . Kiyoh_Connect::CREDENTIALS_PATH, array(
            'Authorization' => 'Bearer ' . $access,
            'Accept' => 'application/json',
        ), null, true);
        $credentials = Kiyoh_Connect::parse_credentials($credentials_response['data']);
        if (!$credentials['ok']) {
            self::mark_broken();
            return null;
        }
        self::apply_credentials_to_settings($credentials['location_id'], $credentials['api_key'], $credentials['platform']);
        $account = get_option(Kiyoh_Connect::OPTION_ACCOUNT, array());
        if (is_array($account)) {
            $account['status'] = 'connected';
            $account['location_name'] = $credentials['location_name'];
            $account['tenant_id'] = $credentials['tenant_id'];
            update_option(Kiyoh_Connect::OPTION_ACCOUNT, $account, false);
        }
        return array(
            'api_key' => $credentials['api_key'],
            'location_id' => $credentials['location_id'],
        );
    }

    private static function mark_broken() {
        $account = get_option(Kiyoh_Connect::OPTION_ACCOUNT, array());
        if (!is_array($account)) {
            return;
        }
        $account['status'] = 'broken';
        update_option(Kiyoh_Connect::OPTION_ACCOUNT, $account, false);
    }

    private static function revoke_current_account() {
        $account = get_option(Kiyoh_Connect::OPTION_ACCOUNT, array());
        if (!is_array($account) || empty($account['issuer']) || !Kiyoh_Connect::is_allowed_issuer($account['issuer'])) {
            return;
        }
        $secret = self::client_secret();
        $material = self::crypto_key_material();
        $refresh = Kiyoh_Connect::decrypt_secret(isset($account['refresh_token']) ? $account['refresh_token'] : '', $material);
        if ($refresh !== '' && $secret !== '') {
            self::remote_json(
                'POST',
                $account['issuer'] . Kiyoh_Connect::REVOKE_PATH,
                array('Content-Type' => 'application/x-www-form-urlencoded'),
                Kiyoh_Connect::revoke_token_form($refresh, $secret),
                false
            );
        }
        $origin = isset($account['shop_origin']) ? $account['shop_origin'] : '';
        $redirect = isset($account['redirect_uri']) ? $account['redirect_uri'] : '';
        $hmac = self::hmac_key();
        if ($origin !== '' && $redirect !== '' && $hmac !== '') {
            $body = Kiyoh_Connect::signed_shop_body('revoke', $origin, $redirect, time(), $hmac);
            if ($body !== array()) {
                self::remote_json('POST', $account['issuer'] . Kiyoh_Connect::SHOP_REVOKE_PATH, array('Content-Type' => 'application/json'), wp_json_encode($body), false);
            }
        }
    }

    /**
     * @param string $issuer
     * @param string $access_token
     */
    private static function revoke_access_token($issuer, $access_token) {
        $secret = self::client_secret();
        if ($secret === '' || $access_token === '') {
            return;
        }
        $form = Kiyoh_Connect::revoke_token_form($access_token, $secret);
        $form['token_type_hint'] = 'access_token';
        self::remote_json('POST', $issuer . Kiyoh_Connect::REVOKE_PATH, array('Content-Type' => 'application/x-www-form-urlencoded'), $form, false);
    }

    private static function clear_connect_credentials() {
        $settings = get_option('kiyoh_woocommerce_settings', array());
        if (!is_array($settings) || !isset($settings['general']) || !is_array($settings['general'])) {
            return;
        }
        if (($settings['general']['credential_source'] ?? '') !== 'connect') {
            return;
        }
        self::$apply_mode = 'disconnect';
        try {
            $settings['general']['location_id'] = '';
            $settings['general']['api_key'] = '';
            $settings['general']['credential_source'] = 'manual';
            update_option('kiyoh_woocommerce_settings', $settings);
        } finally {
            self::$apply_mode = '';
        }
    }

    /**
     * @param string $message
     * @param bool $ok
     */
    private static function flash($message, $ok = false) {
        set_transient(self::FLASH_TRANSIENT, array('message' => $message, 'ok' => (bool) $ok), 120);
    }

    /**
     * @param string $method
     * @param string $url
     * @param array<string,string> $headers
     * @param mixed $body
     * @param bool $interactive
     * @return array{status:int,data:array<string,mixed>}
     */
    private static function remote_json($method, $url, $headers, $body, $interactive) {
        $failures = 0;
        $last_status = 0;
        $last_data = array();
        $max = $interactive ? Kiyoh_Connect::INTERACTIVE_MAX_ATTEMPTS : 1;
        while ($failures < $max) {
            $args = array(
                'method' => $method,
                'timeout' => $interactive ? 15 : 5,
                'redirection' => 0,
                'headers' => $headers,
            );
            if ($body !== null) {
                $args['body'] = $body;
            }
            $response = wp_remote_request($url, $args);
            if (is_wp_error($response)) {
                $last_status = 0;
                $last_data = array();
                $retry_header = '';
            } else {
                $last_status = (int) wp_remote_retrieve_response_code($response);
                $raw = (string) wp_remote_retrieve_body($response);
                $decoded = json_decode($raw, true);
                $last_data = is_array($decoded) ? $decoded : array();
                $retry_header = (string) wp_remote_retrieve_header($response, 'retry-after');
            }
            $failures++;
            if (!Kiyoh_Connect::is_retryable_status($last_status)) {
                break;
            }
            $wait = self::retry_wait($failures, $last_status, isset($retry_header) ? $retry_header : '', $last_data);
            if (!$interactive || !Kiyoh_Connect::interactive_should_retry($failures, $wait)) {
                break;
            }
            if ($wait > 0) {
                sleep($wait);
            }
        }
        return array('status' => $last_status, 'data' => $last_data);
    }

    /**
     * @param int $failures
     * @param int $status
     * @param string $retry_header
     * @param array<string,mixed> $body
     * @return int
     */
    private static function retry_wait($failures, $status, $retry_header, $body) {
        $parsed = Kiyoh_Connect::parse_retry_after($retry_header, $body, time());
        if ($parsed !== null && ((int) $status === 429 || $retry_header !== '')) {
            return min(Kiyoh_Connect::RETRY_CAP_SECONDS, $parsed);
        }
        $bounds = Kiyoh_Connect::retry_delay_bounds($failures);
        if ($bounds[1] <= 0) {
            return 0;
        }
        return random_int(0, $bounds[1]);
    }
}
