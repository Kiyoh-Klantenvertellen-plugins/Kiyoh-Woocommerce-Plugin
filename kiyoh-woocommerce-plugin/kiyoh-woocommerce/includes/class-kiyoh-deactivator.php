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

        // Hide imported Kiyoh reviews and restore native WooCommerce ratings.
        self::restore_native_reviews();
        
        // Clear any cached data
        self::clear_cache();
    }

    /**
     * Hide imported Kiyoh comments and restore native ministars. Kiyoh
     * rating meta is kept for the next activation.
     */
    private static function restore_native_reviews() {
        if (!class_exists('Kiyoh_Rating_Manager')) {
            $rating_manager = KIYOH_WOOCOMMERCE_PLUGIN_DIR . 'includes/managers/class-rating-manager.php';
            if (file_exists($rating_manager)) {
                require_once $rating_manager;
            }
        }

        if (class_exists('Kiyoh_Rating_Manager')) {
            Kiyoh_Rating_Manager::hide_kiyoh_reviews_for_deactivate();
        }
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

        $timestamp = wp_next_scheduled('kiyoh_sync_product_ratings');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'kiyoh_sync_product_ratings');
        }
        wp_clear_scheduled_hook('kiyoh_sync_product_ratings');
        wp_clear_scheduled_hook('kiyoh_process_rating_sync');
        wp_clear_scheduled_hook('kiyoh_report_mobile_health');

        // Stop send-time jobs while the plugin is inactive. Rows stay scheduled
        // and are queued again on the next activation.
        $schedule_class = KIYOH_WOOCOMMERCE_PLUGIN_DIR . 'includes/managers/class-invitation-schedule.php';
        if (!class_exists('Kiyoh_Invitation_Schedule') && file_exists($schedule_class)) {
            require_once $schedule_class;
        }

        $hook = class_exists('Kiyoh_Invitation_Schedule') ? Kiyoh_Invitation_Schedule::HOOK : 'kiyoh_send_scheduled_invitation';
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions($hook);
        }
        wp_clear_scheduled_hook($hook);
    }

    /**
     * Clear cached data
     */
    private static function clear_cache() {
        global $wpdb;

        if (!class_exists('Kiyoh_Db')) {
            require_once KIYOH_WOOCOMMERCE_PLUGIN_DIR . 'includes/class-kiyoh-db.php';
        }

        $table_name = Kiyoh_Db::table($wpdb->prefix, 'kiyoh_review_cache');
        if ($table_name !== '') {
            $wpdb->query('TRUNCATE TABLE ' . $table_name);
        }

        $options_table = Kiyoh_Db::options_table($wpdb->options);
        if ($options_table !== '') {
            $sql = $wpdb->prepare(
                'DELETE FROM ' . $options_table . ' WHERE option_name LIKE %s OR option_name LIKE %s',
                $wpdb->esc_like('_transient_kiyoh_') . '%',
                $wpdb->esc_like('_transient_timeout_kiyoh_') . '%'
            );
            if (is_string($sql)) {
                $wpdb->query($sql);
            }
        }

        delete_option('kiyoh_ratings_sync_job');
    }
}