<?php
/**
 * Remove plugin data on uninstall.
 *
 * Deactivation keeps settings, Connect tokens, and the custom tables.
 * WordPress runs this file only from the Plugins screen when the plugin is deleted.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once __DIR__ . '/includes/class-kiyoh-db.php';
require_once __DIR__ . '/includes/class-kiyoh-connect.php';
require_once __DIR__ . '/includes/class-kiyoh-connect-session.php';

if (is_multisite()) {
    $site_ids = get_sites(array('fields' => 'ids', 'number' => 0));
    if (is_array($site_ids)) {
        foreach ($site_ids as $site_id) {
            switch_to_blog((int) $site_id);
            kiyoh_woocommerce_uninstall_site();
            restore_current_blog();
        }
    }
} else {
    kiyoh_woocommerce_uninstall_site();
}

/**
 * Revoke this shop at Kiyoh-Connect, then delete local options, tables, cron, and transients.
 */
function kiyoh_woocommerce_uninstall_site() {
    global $wpdb;

    if (class_exists('Kiyoh_Connect_Session')) {
        Kiyoh_Connect_Session::revoke_for_uninstall();
    }

    foreach (Kiyoh_Db::option_names() as $option_name) {
        delete_option($option_name);
    }

    foreach (Kiyoh_Db::cron_hooks() as $hook) {
        wp_clear_scheduled_hook($hook);
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions($hook);
        }
    }

    foreach (Kiyoh_Db::TABLE_SUFFIXES as $suffix) {
        $table = Kiyoh_Db::table($wpdb->prefix, $suffix);
        if ($table === '') {
            continue;
        }
        $wpdb->query('DROP TABLE IF EXISTS ' . $table);
    }

    $options_table = Kiyoh_Db::options_table($wpdb->options);
    if ($options_table === '') {
        return;
    }
    $likes = array();
    foreach (Kiyoh_Db::transient_like_prefixes() as $prefix) {
        $likes[] = $wpdb->esc_like($prefix) . '%';
    }
    $sql = $wpdb->prepare(
        'DELETE FROM ' . $options_table . ' WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s',
        $likes[0],
        $likes[1],
        $likes[2],
        $likes[3]
    );
    if (is_string($sql)) {
        $wpdb->query($sql);
    }
}
