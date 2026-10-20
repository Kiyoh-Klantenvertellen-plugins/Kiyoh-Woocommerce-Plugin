<?php

/**
 * Pure date math for the optional invitation send time.
 *
 * No WordPress calls, so the rules can be tested without a shop. The Kiyoh
 * invite API only accepts a whole-day `delay` (0 = send immediately). It has
 * no send-at datetime, so the plugin waits until the chosen shop-local moment
 * and then calls the API with delay 0.
 */
class Kiyoh_Invitation_Schedule {

    const HOOK = 'kiyoh_send_scheduled_invitation';
    const GROUP = 'kiyoh-woocommerce';

    /**
     * Preferred send time is on only when the merchant enabled it and entered
     * a real HH:MM. Anything else keeps the legacy "call the API now with delay_days" path.
     *
     * @param mixed $invitations invitations settings array.
     * @return bool
     */
    public static function is_schedule_mode_active($invitations) {
        if (!is_array($invitations) || empty($invitations['send_time_enabled'])) {
            return false;
        }

        $time = isset($invitations['send_time']) ? $invitations['send_time'] : '';
        return self::is_valid_time($time);
    }

    /**
     * @param mixed $send_time
     * @return bool
     */
    public static function is_valid_time($send_time) {
        return self::parse_time($send_time) !== null;
    }

    /**
     * Canonical HH:MM, or '' when the value is empty or not a time.
     *
     * @param mixed $send_time
     * @return string
     */
    public static function normalize_send_time($send_time) {
        $parts = self::parse_time($send_time);
        if ($parts === null) {
            return '';
        }

        return sprintf('%02d:%02d', $parts['hour'], $parts['minute']);
    }

    /**
     * Weekdays to store. Empty means "no restriction" (every day).
     * ISO-8601: 1 = Monday … 7 = Sunday.
     *
     * @param mixed $weekdays
     * @return int[]
     */
    public static function sanitize_weekdays($weekdays) {
        if (!is_array($weekdays)) {
            return array();
        }

        $normalized = array();
        foreach ($weekdays as $day) {
            $day = (int) $day;
            if ($day >= 1 && $day <= 7) {
                $normalized[$day] = $day;
            }
        }

        $normalized = array_values($normalized);
        sort($normalized);
        return $normalized;
    }

    /**
     * Weekdays the calculator may use. An empty selection means every day.
     *
     * @param mixed $weekdays
     * @return int[]
     */
    public static function normalize_weekdays($weekdays) {
        $sanitized = self::sanitize_weekdays($weekdays);
        if (empty($sanitized)) {
            return array(1, 2, 3, 4, 5, 6, 7);
        }

        return $sanitized;
    }

    /**
     * Next shop-local moment at $send_time on an allowed weekday, not before
     * $from plus $delay_days (same clock time, $delay_days later).
     *
     * @param DateTimeImmutable $from       Moment the order hit the trigger status.
     * @param int               $delay_days Whole days to wait first. Negative becomes 0.
     * @param string            $send_time  HH:MM (optional seconds ignored).
     * @param int[]             $weekdays   ISO 1–7. Empty = every day.
     * @param DateTimeZone      $timezone   Shop timezone (WordPress timezone).
     * @return DateTimeImmutable
     */
    public static function next_send_at(DateTimeImmutable $from, $delay_days, $send_time, array $weekdays, DateTimeZone $timezone) {
        $parts = self::parse_time($send_time);
        if ($parts === null) {
            throw new InvalidArgumentException('Send time must be HH:MM.');
        }

        $from = $from->setTimezone($timezone);
        $delay_days = max(0, (int) $delay_days);
        $allowed = self::normalize_weekdays($weekdays);
        $earliest = $from->modify('+' . $delay_days . ' days');

        $candidate = $earliest->setTime($parts['hour'], $parts['minute'], 0);
        if ($candidate < $earliest) {
            $candidate = $candidate->modify('+1 day')->setTime($parts['hour'], $parts['minute'], 0);
        }

        // At most seven steps: every weekday is allowed, or we wrap one week.
        for ($i = 0; $i < 7; $i++) {
            $dow = (int) $candidate->format('N');
            if (in_array($dow, $allowed, true)) {
                return $candidate;
            }
            $candidate = $candidate->modify('+1 day')->setTime($parts['hour'], $parts['minute'], 0);
        }

        return $candidate;
    }

    /**
     * @param mixed $send_time
     * @return array{hour:int,minute:int}|null
     */
    public static function parse_time($send_time) {
        if (!is_string($send_time)) {
            return null;
        }

        $send_time = trim($send_time);
        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?$/', $send_time, $matches)) {
            return null;
        }

        return array(
            'hour' => (int) $matches[1],
            'minute' => (int) $matches[2],
        );
    }
}
