<?php

/**
 * Payload and send rules for Kiyoh Mobile shop health.
 *
 * No WordPress calls, so the rules can be tested without a shop. The reporter
 * posts the body this class builds to each configured base URL.
 */
class Kiyoh_Mobile_Health {

    const PLATFORM = 'woocommerce';
    const HEALTH_PATH = '/api/integrations/health';
    /** Production and staging. A blank setting uses this list, same as the other plugins. */
    const DEFAULT_URLS = 'https://kiyoh-mobile.com';
    const HEADER_PUBLICATION = 'X-Publication-Api-Token';
    const HEADER_LOCATION = 'X-Location-Id';
    const CRON_HOOK = 'kiyoh_report_mobile_health';
    const OPTION_STATE = 'kiyoh_mobile_health';

    /** Resend an unchanged payload once it is older than this. The server marks a shop stale after 48h. */
    const MAX_AGE_SECONDS = 86400;
    const FAILURE_WINDOW_SECONDS = 86400;
    const REQUEST_TIMEOUT_SECONDS = 2;
    const MAX_PRODUCT_CODES = 500;
    const MAX_PRODUCT_CODE_LENGTH = 200;
    const MAX_ERROR_LENGTH = 500;
    const MAX_FAILURES = 1000000;
    const MAX_STORED_FAILURES = 5000;
    const DEFAULT_RETRY_AFTER_SECONDS = 60;

    /**
     * @return array
     */
    public static function empty_state() {
        return array(
            'connection' => array(
                'status' => 'unknown',
                'last_ok_at' => null,
                'last_error' => null,
            ),
            'invites' => array(
                'last_ok_at' => null,
                'failures' => array(),
            ),
            'product_sync' => array(
                'last_ok_at' => null,
                'last_error' => null,
                'product_codes' => array(),
            ),
            'widget' => array(
                'injected' => false,
                'last_checked_at' => null,
            ),
            'reports' => array(),
        );
    }

    /**
     * @param mixed $state
     * @param int   $now
     * @return array
     */
    public static function normalize_state($state, $now) {
        $base = self::empty_state();
        if (!is_array($state)) {
            return $base;
        }

        foreach (array('connection', 'invites', 'product_sync', 'widget') as $group) {
            if (isset($state[$group]) && is_array($state[$group])) {
                $base[$group] = array_merge($base[$group], $state[$group]);
            }
        }

        if (isset($state['reports']) && is_array($state['reports'])) {
            $base['reports'] = $state['reports'];
        }

        $failures = isset($base['invites']['failures']) && is_array($base['invites']['failures'])
            ? $base['invites']['failures']
            : array();
        $base['invites']['failures'] = self::prune_failures($failures, $now);

        $codes = isset($base['product_sync']['product_codes']) && is_array($base['product_sync']['product_codes'])
            ? $base['product_sync']['product_codes']
            : array();
        $base['product_sync']['product_codes'] = self::merge_product_codes(array(), $codes);

        $status = isset($base['connection']['status']) ? $base['connection']['status'] : 'unknown';
        if (!in_array($status, array('ok', 'error', 'unknown'), true)) {
            $status = 'unknown';
        }
        $base['connection']['status'] = $status;
        $base['connection']['last_error'] = self::limit_error(isset($base['connection']['last_error']) ? $base['connection']['last_error'] : null);
        $base['product_sync']['last_error'] = self::limit_error(isset($base['product_sync']['last_error']) ? $base['product_sync']['last_error'] : null);
        $base['widget']['injected'] = !empty($base['widget']['injected']);

        return $base;
    }

    /**
     * Setting wins when it is non-blank. Otherwise the constant, otherwise production and staging.
     *
     * @param mixed $setting_value
     * @param mixed $constant_value
     * @return string[]
     */
    public static function resolve_base_urls($setting_value, $constant_value) {
        $raw = self::DEFAULT_URLS;
        if (is_string($constant_value) && trim($constant_value) !== '') {
            $raw = $constant_value;
        }
        if (is_string($setting_value) && trim($setting_value) !== '') {
            $raw = $setting_value;
        }

        return self::parse_urls($raw);
    }

    /**
     * @param string[]    $urls
     * @param mixed       $filtered Value from the kiyoh_mobile_urls filter.
     * @return string[]
     */
    public static function apply_url_override($urls, $filtered) {
        if (is_array($filtered)) {
            return self::parse_urls(implode(',', $filtered));
        }
        if (is_string($filtered)) {
            return self::parse_urls($filtered);
        }

        return $urls;
    }

    /**
     * Split a comma-separated list into unique http(s) origins. Paths are dropped;
     * the health path is appended by the reporter.
     *
     * @param mixed $raw
     * @return string[]
     */
    public static function parse_urls($raw) {
        if (is_array($raw)) {
            $raw = implode(',', $raw);
        }
        if (!is_string($raw)) {
            return array();
        }

        $urls = array();
        foreach (explode(',', $raw) as $segment) {
            $origin = self::normalize_origin($segment);
            if ($origin === '') {
                continue;
            }
            $urls[$origin] = $origin;
        }

        return array_values($urls);
    }

    /**
     * @param mixed $segment
     * @return string
     */
    public static function normalize_origin($segment) {
        $segment = trim((string) $segment);
        if ($segment === '') {
            return '';
        }

        $parts = parse_url($segment);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return '';
        }

        $scheme = strtolower((string) $parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return '';
        }

        $host = (string) $parts['host'];
        if ($host === '') {
            return '';
        }

        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        return $scheme . '://' . $host . $port;
    }

    /**
     * @param string $base_url
     * @return string
     */
    public static function endpoint($base_url) {
        return rtrim((string) $base_url, '/') . self::HEALTH_PATH;
    }

    /**
     * Site domain used as shopId. Case is preserved. Empty when the host is not a valid shop id.
     *
     * @param mixed $url
     * @return string
     */
    public static function shop_id_from_url($url) {
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        }

        $host = $url;
        if (strpos($url, '://') !== false) {
            $parts = parse_url($url);
            if (!is_array($parts) || empty($parts['host'])) {
                return '';
            }
            $host = (string) $parts['host'];
        }

        $host = rtrim(trim($host), '.');
        if (strlen($host) > 200) {
            $host = substr($host, 0, 200);
        }

        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:@\/-]{0,199}$/', $host)) {
            return '';
        }

        return $host;
    }

    /**
     * @param mixed $version
     * @return string
     */
    public static function normalize_plugin_version($version) {
        $version = trim((string) $version);
        if ($version === '') {
            return '';
        }
        if (strlen($version) > 64) {
            $version = substr($version, 0, 64);
        }
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._+\-]{0,63}$/', $version)) {
            return '';
        }

        return $version;
    }

    /**
     * @param mixed $settings
     * @return array{api_token: string, location_id: string}
     */
    public static function credentials_from_settings($settings) {
        $general = array();
        if (is_array($settings) && isset($settings['general']) && is_array($settings['general'])) {
            $general = $settings['general'];
        }

        $token = isset($general['api_key']) ? trim((string) $general['api_key']) : '';
        $location = isset($general['location_id']) ? trim((string) $general['location_id']) : '';

        return array(
            'api_token' => $token,
            'location_id' => $location,
        );
    }

    /**
     * @param mixed $settings
     * @return bool
     */
    public static function has_credentials($settings) {
        $credentials = self::credentials_from_settings($settings);
        return $credentials['api_token'] !== '' && $credentials['location_id'] !== '';
    }

    /**
     * @param mixed $old
     * @param mixed $new
     * @return bool
     */
    public static function credentials_changed($old, $new) {
        return self::credentials_from_settings($old) !== self::credentials_from_settings($new);
    }

    /**
     * Kiyoh ministars are the storefront widget. They are injected when the
     * plugin is on and the rating source is Kiyoh or both.
     *
     * @param mixed $settings
     * @return bool
     */
    public static function widget_injected_from_settings($settings) {
        if (!is_array($settings)) {
            return false;
        }

        $general = isset($settings['general']) && is_array($settings['general']) ? $settings['general'] : array();
        if (empty($general['enabled'])) {
            return false;
        }

        if (!isset($settings['ratings']) || !is_array($settings['ratings'])) {
            return true;
        }

        $ratings = $settings['ratings'];
        if (!empty($ratings['source'])) {
            return $ratings['source'] === 'kiyoh' || $ratings['source'] === 'both';
        }

        return !empty($ratings['enabled']);
    }

    /**
     * @param mixed $error
     * @return string|null
     */
    public static function limit_error($error) {
        if ($error === null || is_bool($error) || is_array($error)) {
            return null;
        }
        if (!is_string($error) && !is_numeric($error)) {
            return null;
        }

        $error = trim((string) $error);
        if ($error === '') {
            return null;
        }
        if (strlen($error) > self::MAX_ERROR_LENGTH) {
            $error = substr($error, 0, self::MAX_ERROR_LENGTH);
        }

        return $error;
    }

    /**
     * @param mixed $code
     * @return string|null
     */
    public static function clean_product_code($code) {
        if (is_int($code)) {
            $code = (string) $code;
        } elseif (is_float($code)) {
            return null;
        }
        if (!is_string($code)) {
            return null;
        }

        $code = trim($code);
        if ($code === '' || strlen($code) > self::MAX_PRODUCT_CODE_LENGTH) {
            return null;
        }

        return $code;
    }

    /**
     * Newest codes win and stay at the end. At most 500 valid codes are kept.
     *
     * @param mixed $existing
     * @param mixed $incoming
     * @return string[]
     */
    public static function merge_product_codes($existing, $incoming) {
        $ordered = array();
        $combined = array();
        if (is_array($existing)) {
            $combined = array_merge($combined, $existing);
        }
        if (is_array($incoming)) {
            $combined = array_merge($combined, $incoming);
        }

        foreach ($combined as $code) {
            $clean = self::clean_product_code($code);
            if ($clean === null) {
                continue;
            }
            unset($ordered[$clean]);
            $ordered[$clean] = $clean;
        }

        $values = array_values($ordered);
        if (count($values) > self::MAX_PRODUCT_CODES) {
            $values = array_slice($values, -self::MAX_PRODUCT_CODES);
        }

        return $values;
    }

    /**
     * A failure at exactly 24h ago still counts. Older ones do not.
     *
     * @param mixed $timestamps
     * @param int   $now
     * @return int[]
     */
    public static function prune_failures($timestamps, $now) {
        $cutoff = (int) $now - self::FAILURE_WINDOW_SECONDS;
        $kept = array();
        if (!is_array($timestamps)) {
            return $kept;
        }

        foreach ($timestamps as $timestamp) {
            if (!is_numeric($timestamp)) {
                continue;
            }
            $timestamp = (int) $timestamp;
            if ($timestamp >= $cutoff) {
                $kept[] = $timestamp;
            }
        }

        if (count($kept) > self::MAX_STORED_FAILURES) {
            $kept = array_slice($kept, -self::MAX_STORED_FAILURES);
        }

        return $kept;
    }

    /**
     * @param array $state
     * @param int   $now
     * @return int
     */
    public static function failures_24h($state, $now) {
        $failures = array();
        if (isset($state['invites']['failures']) && is_array($state['invites']['failures'])) {
            $failures = $state['invites']['failures'];
        }
        $count = count(self::prune_failures($failures, $now));
        if ($count > self::MAX_FAILURES) {
            return self::MAX_FAILURES;
        }

        return $count;
    }

    /**
     * @param mixed $unix
     * @return string|null
     */
    public static function iso_timestamp($unix) {
        if ($unix === null || $unix === '' || !is_numeric($unix)) {
            return null;
        }
        $unix = (int) $unix;
        if ($unix <= 0) {
            return null;
        }

        return gmdate('Y-m-d\TH:i:s\Z', $unix);
    }

    /**
     * @param array $state
     * @param bool  $ok
     * @param mixed $error
     * @param mixed $http_status
     * @param int   $now
     * @return array
     */
    public static function apply_connection_result($state, $ok, $error, $http_status, $now) {
        $state = self::normalize_state($state, $now);
        if ($ok) {
            $state['connection']['status'] = 'ok';
            $state['connection']['last_ok_at'] = (int) $now;
            $state['connection']['last_error'] = null;
            return $state;
        }

        $message = self::limit_error($error);
        if ($message === null) {
            $code = is_numeric($http_status) ? (int) $http_status : 0;
            if ($code === 401) {
                $message = 'unauthorized';
            } elseif ($code === 403) {
                $message = 'forbidden';
            } else {
                $message = 'connection failed';
            }
        }

        $state['connection']['status'] = 'error';
        $state['connection']['last_error'] = $message;
        return $state;
    }

    /**
     * 2xx confirms the token. 401 and 403 mark the connection as failed.
     * Other statuses leave the previous connection group alone.
     *
     * @param array $state
     * @param mixed $http_status
     * @param mixed $error
     * @param int   $now
     * @return array
     */
    public static function apply_auth_http($state, $http_status, $error, $now) {
        if (!is_numeric($http_status)) {
            return $state;
        }

        $http_status = (int) $http_status;
        if ($http_status >= 200 && $http_status < 300) {
            return self::apply_connection_result($state, true, null, $http_status, $now);
        }
        if ($http_status === 401 || $http_status === 403) {
            return self::apply_connection_result($state, false, $error, $http_status, $now);
        }

        return $state;
    }

    /**
     * @param array $state
     * @param bool  $ok
     * @param mixed $error
     * @param mixed $http_status
     * @param int   $now
     * @return array
     */
    public static function apply_invite($state, $ok, $error, $http_status, $now) {
        $state = self::normalize_state($state, $now);
        if ($ok) {
            $state['invites']['last_ok_at'] = (int) $now;
        } else {
            $state['invites']['failures'][] = (int) $now;
            $state['invites']['failures'] = self::prune_failures($state['invites']['failures'], $now);
        }

        return self::apply_auth_http($state, $http_status, $error, $now);
    }

    /**
     * A partial bulk sync passes $ok true, the successful codes, and the error
     * text from the failed batches. Failed calls do not add codes.
     *
     * @param array $state
     * @param bool  $ok
     * @param mixed $error
     * @param mixed $codes
     * @param mixed $http_status
     * @param int   $now
     * @return array
     */
    public static function apply_product_sync($state, $ok, $error, $codes, $http_status, $now) {
        $state = self::normalize_state($state, $now);
        if ($ok) {
            $state['product_sync']['last_ok_at'] = (int) $now;
            $state['product_sync']['last_error'] = null;
            $state['product_sync']['product_codes'] = self::merge_product_codes(
                $state['product_sync']['product_codes'],
                is_array($codes) ? $codes : array()
            );
            $partial = self::limit_error($error);
            if ($partial !== null) {
                $state['product_sync']['last_error'] = $partial;
            }
        } else {
            $message = self::limit_error($error);
            $state['product_sync']['last_error'] = $message === null ? 'product sync failed' : $message;
        }

        return self::apply_auth_http($state, $http_status, $error, $now);
    }

    /**
     * @param array $state
     * @param mixed $old
     * @param mixed $new
     * @param int   $now
     * @return array
     */
    public static function on_credentials_changed($state, $old, $new, $now) {
        $state = self::normalize_state($state, $now);
        $before = self::credentials_from_settings($old);
        $after = self::credentials_from_settings($new);

        $state['connection']['status'] = 'unknown';
        $state['connection']['last_ok_at'] = null;
        $state['connection']['last_error'] = null;

        if ($before['location_id'] !== $after['location_id']) {
            $state['product_sync']['last_ok_at'] = null;
            $state['product_sync']['last_error'] = null;
            $state['product_sync']['product_codes'] = array();
        }

        $blocked = array();
        foreach ($state['reports'] as $url => $report) {
            if (!is_array($report) || empty($report['blocked_until'])) {
                continue;
            }
            if ((int) $report['blocked_until'] > (int) $now) {
                $blocked[$url] = array(
                    'digest' => '',
                    'at' => 0,
                    'blocked_until' => (int) $report['blocked_until'],
                );
            }
        }
        $state['reports'] = $blocked;

        return $state;
    }

    /**
     * @param array $state
     * @param bool  $injected
     * @param int   $now
     * @param bool  $touch_checked_at
     * @return array
     */
    public static function sync_widget_flag($state, $injected, $now, $touch_checked_at) {
        $state = self::normalize_state($state, $now);
        $injected = (bool) $injected;
        $changed = (bool) $state['widget']['injected'] !== $injected;
        $state['widget']['injected'] = $injected;
        if ($touch_checked_at || $changed || empty($state['widget']['last_checked_at'])) {
            $state['widget']['last_checked_at'] = (int) $now;
        }

        return $state;
    }

    /**
     * Groups the plugins page renders. This plugin does not receive webhooks, so
     * lastReceivedAt stays null. The key is still sent: the card reads it on every row.
     *
     * @param array  $state
     * @param string $shop_id
     * @param string $plugin_version
     * @param int    $now
     * @return array
     */
    public static function build_body($state, $shop_id, $plugin_version, $now) {
        $state = self::normalize_state($state, $now);
        $product = array(
            'lastOkAt' => self::iso_timestamp($state['product_sync']['last_ok_at']),
            'lastError' => $state['product_sync']['last_error'],
        );
        if (!empty($state['product_sync']['product_codes'])) {
            $product['productCodes'] = array_values($state['product_sync']['product_codes']);
        }

        return array(
            'platform' => self::PLATFORM,
            'shopId' => $shop_id,
            'pluginVersion' => $plugin_version,
            'connection' => array(
                'status' => $state['connection']['status'],
                'lastOkAt' => self::iso_timestamp($state['connection']['last_ok_at']),
                'lastError' => $state['connection']['last_error'],
            ),
            'invites' => array(
                'lastOkAt' => self::iso_timestamp($state['invites']['last_ok_at']),
                'failures24h' => self::failures_24h($state, $now),
            ),
            'productSync' => $product,
            'webhooks' => array(
                'lastReceivedAt' => null,
            ),
            'widget' => array(
                'injected' => (bool) $state['widget']['injected'],
                'lastCheckedAt' => self::iso_timestamp($state['widget']['last_checked_at']),
            ),
        );
    }

    /**
     * @param array $body
     * @return string
     */
    public static function digest($body) {
        $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            return '';
        }

        return hash('sha256', $json);
    }

    /**
     * Send when this URL has no successful report, the payload changed, or the
     * last success is older than 24h. A 429 block holds even a changed payload.
     *
     * @param mixed  $report
     * @param string $digest
     * @param int    $now
     * @return bool
     */
    public static function should_send($report, $digest, $now) {
        if (!is_array($report)) {
            return true;
        }

        $blocked = isset($report['blocked_until']) ? (int) $report['blocked_until'] : 0;
        if ($blocked > (int) $now) {
            return false;
        }

        $at = isset($report['at']) ? (int) $report['at'] : 0;
        $stored = isset($report['digest']) ? (string) $report['digest'] : '';
        if ($at <= 0 || $stored === '' || strlen($stored) !== strlen($digest) || !hash_equals($stored, $digest)) {
            return true;
        }

        return ((int) $now - $at) > self::MAX_AGE_SECONDS;
    }

    /**
     * @param mixed $header
     * @param int   $now
     * @return int|null
     */
    public static function parse_retry_after_header($header, $now) {
        if ($header === null) {
            return null;
        }
        $header = trim((string) $header);
        if ($header === '') {
            return null;
        }
        if (preg_match('/^\d+$/', $header)) {
            return (int) $header;
        }

        $parsed = strtotime($header);
        if ($parsed === false) {
            return null;
        }

        return max(0, $parsed - (int) $now);
    }

    /**
     * @param mixed $header
     * @param mixed $body
     * @param int   $now
     * @return int
     */
    public static function retry_after_seconds($header, $body, $now) {
        $from_header = self::parse_retry_after_header($header, $now);
        if ($from_header !== null) {
            return $from_header;
        }

        $decoded = json_decode((string) $body, true);
        if (is_array($decoded) && isset($decoded['retryAfterSeconds']) && is_numeric($decoded['retryAfterSeconds'])) {
            return max(0, (int) $decoded['retryAfterSeconds']);
        }

        return self::DEFAULT_RETRY_AFTER_SECONDS;
    }

    /**
     * @param array  $state
     * @param string $url
     * @param string $digest
     * @param int    $now
     * @param int    $status
     * @param mixed  $retry_after_header
     * @param mixed  $body
     * @return array
     */
    public static function apply_post_result($state, $url, $digest, $now, $status, $retry_after_header, $body) {
        $state = self::normalize_state($state, $now);
        $current = array(
            'digest' => '',
            'at' => 0,
            'blocked_until' => 0,
        );
        if (isset($state['reports'][$url]) && is_array($state['reports'][$url])) {
            $current = array_merge($current, $state['reports'][$url]);
        }

        $status = (int) $status;
        if ($status >= 200 && $status < 300) {
            $current['digest'] = $digest;
            $current['at'] = (int) $now;
            $current['blocked_until'] = 0;
            $state['reports'][$url] = $current;
            return $state;
        }

        if ($status === 429) {
            $seconds = self::retry_after_seconds($retry_after_header, $body, $now);
            $current['blocked_until'] = (int) $now + $seconds;
            $state['reports'][$url] = $current;
            return $state;
        }

        $current['blocked_until'] = 0;
        $state['reports'][$url] = $current;
        return $state;
    }
}
