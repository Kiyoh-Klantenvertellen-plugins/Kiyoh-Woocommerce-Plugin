<?php

/**
 * Invite API error codes, merchant copy, and retry rules.
 *
 * No WordPress calls so mapping and retry timing can be unit-tested.
 */
class Kiyoh_Invitation_Errors {

    const MAX_RETRY_ATTEMPTS = 3;

    const PERMANENT_ERROR_CODES = array(
        'MAIL_LIMIT_EXCEED',
        'INVALID_EMAIL',
        'DUPLICATE_INVITATION',
        'EMAIL_BLACKLISTED',
        'INVALID_LOCATION_ID',
        'LOCATION_NOT_FOUND',
    );

  /** Backoff after attempt 1, 2, and 3 (seconds). */
    const RETRY_DELAYS = array(900, 3600, 14400);

    /**
     * @param mixed $code
     * @return bool
     */
    public static function is_permanent($code) {
        $code = strtoupper(trim((string) $code));
        if ($code === '') {
            return false;
        }

        return in_array($code, self::PERMANENT_ERROR_CODES, true);
    }

    /**
     * @param mixed $code
     * @param mixed $http_status
     * @return bool
     */
    public static function should_retry($code, $http_status) {
        if (self::is_permanent($code)) {
            return false;
        }

        $status = is_numeric($http_status) ? (int) $http_status : 0;
        if ($status >= 500 || $status === 429) {
            return true;
        }

        $code = strtoupper(trim((string) $code));
        if ($code === 'HTTP_ERROR' || $code === 'EXCEPTION' || $code === 'API_CLIENT_ERROR') {
            return true;
        }

        return $code !== '' && !self::is_permanent($code) && $status === 0;
    }

    /**
     * @param int $attempts_after_failure Attempts already recorded on the row (0 = first failure).
     * @return int Seconds until the next cron retry.
     */
    public static function retry_delay_seconds($attempts_after_failure) {
        $index = max(0, (int) $attempts_after_failure);
        if ($index >= count(self::RETRY_DELAYS)) {
            return self::RETRY_DELAYS[count(self::RETRY_DELAYS) - 1];
        }

        return self::RETRY_DELAYS[$index];
    }

    /**
     * @param mixed $error_code
     * @return string English source string (translated at display time).
     */
    public static function merchant_message($error_code) {
        $code = strtoupper(trim((string) $error_code));
        switch ($code) {
            case 'MAIL_LIMIT_EXCEED':
                return __('Your Kiyoh account has reached its email invitation limit. New invitations cannot be sent until the limit resets. Contact Kiyoh or Klantenvertellen support for help.', 'kiyoh-woocommerce');
            default:
                return '';
        }
    }

    /**
     * @param mixed $error_code
     * @return bool
     */
    public static function is_mail_limit_exceeded($error_code) {
        return strtoupper(trim((string) $error_code)) === 'MAIL_LIMIT_EXCEED';
    }

    /**
     * @param mixed $error_code
     * @param mixed $http_status
     * @param int   $failure_count
     * @param int   $now
     * @return array{status: string, retry_at: int|null, failure_count: int}
     */
    public static function resolve_failure_status($error_code, $http_status, $failure_count, $now) {
        $failure_count = max(1, (int) $failure_count);
        if (
            self::is_permanent($error_code)
            || !self::should_retry($error_code, $http_status)
            || $failure_count >= self::MAX_RETRY_ATTEMPTS
        ) {
            return array(
                'status' => 'failed_final',
                'retry_at' => null,
                'failure_count' => $failure_count,
            );
        }

        return array(
            'status' => 'error',
            'retry_at' => (int) $now + self::retry_delay_seconds(max(0, $failure_count - 1)),
            'failure_count' => $failure_count,
        );
    }
}