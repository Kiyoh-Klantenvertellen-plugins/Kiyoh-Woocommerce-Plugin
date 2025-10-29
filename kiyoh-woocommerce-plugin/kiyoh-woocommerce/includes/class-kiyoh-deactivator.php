<?php

/**
 * Fired during plugin deactivation
 *
 * This class defines all code necessary to run during the plugin's deactivation.
 */
class Kiyoh_Deactivator {

    /**
     * Short Description. (use period)
     *
     * Long Description.
     */
    public static function deactivate() {
        // Clear scheduled cron events
        self::clear_cron_events();
        
        // Clear any cached data
        self::clear_cache();
    }

    /**
     * Clear scheduled cron events
     */
    private static function clear_cron_events() {
        // Clear invitation processing cron
        $timestamp = wp_next_scheduled('kiyoh_process_scheduled_invitations');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'kiyoh_process_scheduled_invitations');
        }

        // Clear cache cleanup cron
        $timestamp = wp_next_scheduled('kiyoh_cleanup_cache');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'kiyoh_cleanup_cache');
        }
    }

    /**
     * Clear cached data
     */
    private static function clear_cache() {
        global $wpdb;
        
        // Clear review cache table
        $table_name = $wpdb->prefix . 'kiyoh_review_cache';
        $wpdb->query("TRUNCATE TABLE $table_name");
        
        // Clear any WordPress transients
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_kiyoh_%' OR option_name LIKE '_transient_timeout_kiyoh_%'");
    }
}