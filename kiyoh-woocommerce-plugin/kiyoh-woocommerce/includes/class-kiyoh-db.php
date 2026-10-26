<?php
/**
 * Table identifiers and the list of data this plugin owns.
 *
 * WordPress 5.8 has no %i placeholder, so table names are whitelisted and
 * concatenated outside the SQL string. Values still go through $wpdb->prepare.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Kiyoh_Db {

    const TABLE_SUFFIXES = array(
        'kiyoh_review_cache',
        'kiyoh_invitations',
        'kiyoh_product_sync',
    );

    /**
     * @param mixed $name
     * @return string Empty when the name is not a bare SQL identifier.
     */
    public static function identifier($name) {
        if (!is_string($name) || !preg_match('/^[A-Za-z0-9_]+$/', $name)) {
            return '';
        }
        return $name;
    }

    /**
     * @param mixed $prefix WordPress table prefix, such as wp_.
     * @param mixed $suffix One of TABLE_SUFFIXES.
     * @return string
     */
    public static function table($prefix, $suffix) {
        if (!is_string($suffix) || !in_array($suffix, self::TABLE_SUFFIXES, true)) {
            return '';
        }
        $prefix = self::identifier($prefix);
        $suffix = self::identifier($suffix);
        if ($prefix === '' || $suffix === '') {
            return '';
        }
        return $prefix . $suffix;
    }

    /**
     * @param mixed $name $wpdb->options, or the equivalent on this site.
     * @return string
     */
    public static function options_table($name) {
        return self::identifier($name);
    }

    /**
     * Options removed on uninstall. Deactivation does not delete these.
     *
     * @return string[]
     */
    public static function option_names() {
        return array(
            'kiyoh_woocommerce_settings',
            'kiyoh_woocommerce_version',
            'kiyoh_ratings_sync_state',
            'kiyoh_ratings_sync_job',
            'kiyoh_mobile_health',
            'kiyoh_connect_account',
        );
    }

    /**
     * Cron hooks removed on uninstall and cleared on deactivation.
     *
     * @return string[]
     */
    public static function cron_hooks() {
        return array(
            'kiyoh_process_scheduled_invitations',
            'kiyoh_cleanup_cache',
            'kiyoh_sync_product_ratings',
            'kiyoh_process_rating_sync',
            'kiyoh_report_mobile_health',
            'kiyoh_send_scheduled_invitation',
        );
    }

    /**
     * LIKE prefixes for this plugin's transients in the options table.
     *
     * @return string[]
     */
    public static function transient_like_prefixes() {
        return array(
            '_transient_kiyoh_',
            '_transient_timeout_kiyoh_',
            '_site_transient_kiyoh_',
            '_site_transient_timeout_kiyoh_',
        );
    }
}
