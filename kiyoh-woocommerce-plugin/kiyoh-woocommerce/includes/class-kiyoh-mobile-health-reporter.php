<?php

/**
 * Posts shop health to each Kiyoh Mobile URL.
 *
 * Events update local state immediately and share one HTTP call at shutdown,
 * so a checkout that syncs several products does not wait once per product.
 * The call uses a short timeout. Failures are logged and never thrown back
 * to invites, product sync, or checkout.
 */
class Kiyoh_Mobile_Health_Reporter {

    /**
     * @var self|null
     */
    private static $instance = null;

    /**
     * @var bool
     */
    private $publish_queued = false;

    /**
     * @return self
     */
    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Daily schedule for shops that update the plugin without reactivating it.
     */
    public function ensure_cron() {
        try {
            if (!function_exists('wp_next_scheduled') || !function_exists('wp_schedule_event')) {
                return;
            }
            if (!wp_next_scheduled(Kiyoh_Mobile_Health::CRON_HOOK)) {
                wp_schedule_event(time() + 3600, 'daily', Kiyoh_Mobile_Health::CRON_HOOK);
            }
        } catch (Throwable $e) {
            $this->log($e->getMessage());
        }
    }

    /**
     * @param mixed $old
     * @param mixed $new
     * @param mixed $option
     */
    public function on_settings_updated($old, $new, $option = '') {
        $this->guard(function () use ($old, $new) {
            $now = time();
            $state = $this->load_state();
            if (Kiyoh_Mobile_Health::credentials_changed($old, $new)) {
                $state = Kiyoh_Mobile_Health::on_credentials_changed($state, $old, $new, $now);
            }
            $state = Kiyoh_Mobile_Health::sync_widget_flag(
                $state,
                Kiyoh_Mobile_Health::widget_injected_from_settings($new),
                $now,
                true
            );
            $this->save_state($state);
            $this->request_publish();
        });
    }

    /**
     * @param mixed $option
     * @param mixed $value
     */
    public function on_settings_added($option, $value) {
        $this->on_settings_updated(array(), is_array($value) ? $value : array(), $option);
    }

    /**
     * Connection test from the settings screen.
     *
     * @param bool  $ok
     * @param mixed $error
     * @param mixed $http_status
     */
    public function note_connection_test($ok, $error, $http_status) {
        $this->guard(function () use ($ok, $error, $http_status) {
            $now = time();
            $state = $this->load_state();
            $state = Kiyoh_Mobile_Health::apply_connection_result($state, (bool) $ok, $error, $http_status, $now);
            $state = $this->with_current_widget($state, false);
            $this->save_state($state);
            $this->request_publish();
        });
    }

    /**
     * @param bool  $ok
     * @param mixed $error
     * @param mixed $http_status
     */
    public function note_invite($ok, $error, $http_status) {
        $this->guard(function () use ($ok, $error, $http_status) {
            $now = time();
            $state = $this->load_state();
            $state = Kiyoh_Mobile_Health::apply_invite($state, (bool) $ok, $error, $http_status, $now);
            $state = $this->with_current_widget($state, false);
            $this->save_state($state);
            $this->request_publish();
        });
    }

    /**
     * @param bool  $ok
     * @param mixed $error
     * @param mixed $codes
     * @param mixed $http_status
     */
    public function note_product_sync($ok, $error, $codes, $http_status) {
        $this->guard(function () use ($ok, $error, $codes, $http_status) {
            $now = time();
            $state = $this->load_state();
            $state = Kiyoh_Mobile_Health::apply_product_sync(
                $state,
                (bool) $ok,
                $error,
                is_array($codes) ? $codes : array(),
                $http_status,
                $now
            );
            $state = $this->with_current_widget($state, false);
            $this->save_state($state);
            $this->request_publish();
        });
    }

    /**
     * Daily heartbeat. Resends when the payload changed or the last success is older than 24h.
     */
    public function on_daily_cron() {
        $this->guard(function () {
            $state = $this->load_state();
            $state = $this->with_current_widget($state, true);
            $this->save_state($state);
            $this->publish_now();
        });
    }

    /**
     * Shutdown hook. Public so WordPress can call it. Safe to run more than once.
     */
    public function publish_now() {
        $this->guard(function () {
            $this->publish_queued = false;
            $this->publish();
        });
    }

    /**
     * @param callable $callback
     */
    private function guard($callback) {
        try {
            $callback();
        } catch (Throwable $e) {
            $this->log($e->getMessage());
        }
    }

    private function request_publish() {
        if ($this->publish_queued) {
            return;
        }
        $this->publish_queued = true;
        if (function_exists('did_action') && did_action('shutdown')) {
            $this->publish_now();
            return;
        }
        if (function_exists('add_action')) {
            add_action('shutdown', array($this, 'publish_now'), 1);
            return;
        }
        $this->publish_now();
    }

    /**
     * @param array $state
     * @param bool  $touch_checked_at
     * @return array
     */
    private function with_current_widget($state, $touch_checked_at) {
        return Kiyoh_Mobile_Health::sync_widget_flag(
            $state,
            Kiyoh_Mobile_Health::widget_injected_from_settings($this->settings()),
            time(),
            $touch_checked_at
        );
    }

    private function publish() {
        if (!function_exists('wp_remote_post')) {
            return;
        }

        $settings = $this->settings();
        if (!Kiyoh_Mobile_Health::has_credentials($settings)) {
            return;
        }

        $shop_id = '';
        if (function_exists('home_url')) {
            $shop_id = Kiyoh_Mobile_Health::shop_id_from_url(home_url());
        }
        $version = Kiyoh_Mobile_Health::normalize_plugin_version(
            defined('KIYOH_WOOCOMMERCE_VERSION') ? KIYOH_WOOCOMMERCE_VERSION : ''
        );
        if ($shop_id === '' || $version === '') {
            $this->log('skipped health post because shop id or plugin version is invalid');
            return;
        }

        $urls = $this->base_urls();
        if (empty($urls)) {
            return;
        }

        $credentials = Kiyoh_Mobile_Health::credentials_from_settings($settings);
        $now = time();
        $state = $this->with_current_widget($this->load_state(), false);
        $body = Kiyoh_Mobile_Health::build_body($state, $shop_id, $version, $now);
        $digest = Kiyoh_Mobile_Health::digest($body);
        if ($digest === '') {
            return;
        }

        $changed = false;
        foreach ($urls as $url) {
            $report = isset($state['reports'][$url]) ? $state['reports'][$url] : null;
            if (!Kiyoh_Mobile_Health::should_send($report, $digest, $now)) {
                continue;
            }

            $result = $this->post_one($url, $body, $credentials['api_token'], $credentials['location_id']);
            $state = Kiyoh_Mobile_Health::apply_post_result(
                $state,
                $url,
                $digest,
                $now,
                $result['status'],
                $result['retry_after'],
                $result['body']
            );
            $changed = true;
        }

        if ($changed) {
            $this->save_state($state);
        }
    }

    /**
     * @param string $base_url
     * @param array  $body
     * @param string $api_token
     * @param string $location_id
     * @return array{status: int, retry_after: string, body: string}
     */
    private function post_one($base_url, $body, $api_token, $location_id) {
        $endpoint = Kiyoh_Mobile_Health::endpoint($base_url);
        $encoded = function_exists('wp_json_encode') ? wp_json_encode($body) : json_encode($body);
        if (!is_string($encoded)) {
            return array('status' => 0, 'retry_after' => '', 'body' => '');
        }

        // Blocking with a short timeout so a 429 Retry-After can be stored.
        // blocking=false would not return the status.
        $response = wp_remote_post($endpoint, array(
            'timeout' => Kiyoh_Mobile_Health::REQUEST_TIMEOUT_SECONDS,
            'blocking' => true,
            'redirection' => 0,
            'headers' => array(
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                Kiyoh_Mobile_Health::HEADER_PUBLICATION => $api_token,
                Kiyoh_Mobile_Health::HEADER_LOCATION => $location_id,
            ),
            'body' => $encoded,
        ));

        if (function_exists('is_wp_error') && is_wp_error($response)) {
            $this->log('post failed for ' . $base_url . ': ' . $response->get_error_message());
            return array('status' => 0, 'retry_after' => '', 'body' => '');
        }

        $status = function_exists('wp_remote_retrieve_response_code') ? (int) wp_remote_retrieve_response_code($response) : 0;
        $retry_after = function_exists('wp_remote_retrieve_header') ? (string) wp_remote_retrieve_header($response, 'retry-after') : '';
        $raw = function_exists('wp_remote_retrieve_body') ? wp_remote_retrieve_body($response) : '';
        if (!is_string($raw)) {
            $raw = '';
        }

        if ($status === 429) {
            $this->log('rate limited by ' . $base_url);
        } elseif ($status < 200 || $status >= 300) {
            $this->log($base_url . ' returned HTTP ' . $status);
        }

        return array(
            'status' => $status,
            'retry_after' => $retry_after,
            'body' => $raw,
        );
    }

    /**
     * @return string[]
     */
    private function base_urls() {
        $settings = $this->settings();
        $setting = '';
        if (isset($settings['general']['mobile_urls'])) {
            $setting = $settings['general']['mobile_urls'];
        }
        $constant = defined('KIYOH_MOBILE_URLS') ? KIYOH_MOBILE_URLS : '';
        $urls = Kiyoh_Mobile_Health::resolve_base_urls($setting, $constant);
        if (function_exists('apply_filters')) {
            $filtered = apply_filters('kiyoh_mobile_urls', $urls);
            $urls = Kiyoh_Mobile_Health::apply_url_override($urls, $filtered);
        }

        return $urls;
    }

    /**
     * @return array
     */
    private function settings() {
        if (!function_exists('get_option')) {
            return array();
        }
        $settings = get_option('kiyoh_woocommerce_settings', array());
        return is_array($settings) ? $settings : array();
    }

    /**
     * @return array
     */
    private function load_state() {
        $stored = array();
        if (function_exists('get_option')) {
            $stored = get_option(Kiyoh_Mobile_Health::OPTION_STATE, array());
        }

        return Kiyoh_Mobile_Health::normalize_state($stored, time());
    }

    /**
     * @param array $state
     */
    private function save_state($state) {
        if (!function_exists('update_option')) {
            return;
        }
        update_option(Kiyoh_Mobile_Health::OPTION_STATE, $state, false);
    }

    /**
     * @param string $message
     */
    private function log($message) {
        if (function_exists('error_log')) {
            error_log('Kiyoh Mobile Health: ' . $message);
        }
    }
}
