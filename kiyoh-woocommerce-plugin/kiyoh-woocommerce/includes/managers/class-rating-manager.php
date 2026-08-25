<?php

class Kiyoh_Rating_Manager {

    const META_AVERAGE = '_kiyoh_average_rating';
    const META_COUNT = '_kiyoh_review_count';
    const META_SYNCED_AT = '_kiyoh_rating_synced_at';
    const META_NATIVE_AVERAGE = '_kiyoh_wc_native_average';
    const META_NATIVE_COUNT = '_kiyoh_wc_native_count';
    const META_NATIVE_COUNTS = '_kiyoh_wc_native_rating_counts';
    const COMMENT_META_REVIEW_ID = '_kiyoh_product_review_id';
    const COMMENT_META_HELD = '_kiyoh_held_for_deactivate';
    const OPTION_SYNC_STATE = 'kiyoh_ratings_sync_state';
    const OPTION_SYNC_JOB = 'kiyoh_ratings_sync_job';
    const CRON_PROCESS = 'kiyoh_process_rating_sync';
    const LOCK_TRANSIENT = 'kiyoh_rating_sync_lock';
    const FULL_SYNC_SINCE = '2000-01-01T00:00:00.000Z';
    const INCREMENTAL_OVERLAP_SECONDS = 300;
    const PRODUCT_REQUEST_INTERVAL = 3;
    const JOB_CHUNK_SIZE = 1;
    const JOB_STALE_SECONDS = 600;

    private $settings;
    private $rating_cache = array();
    private $native_cache = array();
    private $applied_skus = array();
    private $imported_product_ids = array();
    private static $hooks_registered = false;
    private static $suppress_comment_filters = false;

    public function __construct() {
        $this->settings = get_option('kiyoh_woocommerce_settings', array());
        $this->init_hooks();
        $this->ensure_cron_scheduled();
    }

    private function init_hooks() {
        if (self::$hooks_registered) {
            return;
        }
        self::$hooks_registered = true;

        add_action('kiyoh_sync_product_ratings', array($this, 'sync_incremental'));
        add_action(self::CRON_PROCESS, array($this, 'process_job_chunk'));
        add_action('update_option_kiyoh_woocommerce_settings', array($this, 'handle_settings_update'), 10, 2);

        if (empty($this->settings['general']['enabled'])) {
            return;
        }

        add_filter('woocommerce_product_get_average_rating', array($this, 'filter_average_rating'), 10, 2);
        add_filter('woocommerce_product_variation_get_average_rating', array($this, 'filter_average_rating'), 10, 2);
        add_filter('woocommerce_product_get_review_count', array($this, 'filter_review_count'), 10, 2);
        add_filter('woocommerce_product_variation_get_review_count', array($this, 'filter_review_count'), 10, 2);
        add_filter('woocommerce_product_get_rating_counts', array($this, 'filter_rating_counts'), 10, 2);
        add_filter('woocommerce_product_variation_get_rating_counts', array($this, 'filter_rating_counts'), 10, 2);
        add_action('pre_get_comments', array($this, 'filter_review_comments_query'), 10, 1);
        add_filter('the_comments', array($this, 'filter_the_review_comments'), 10, 2);
        add_filter('rest_comment_query', array($this, 'filter_rest_review_comments_query'), 10, 1);
        add_action('woocommerce_after_product_object_save', array($this, 'restore_kiyoh_rating_meta'), 20, 1);
        add_action('comment_post', array($this, 'handle_review_comment_changed'), 20, 1);
        add_action('wp_set_comment_status', array($this, 'handle_review_comment_changed'), 20, 1);
        add_action('deleted_comment', array($this, 'handle_review_comment_changed'), 20, 1);
    }

    public static function schedule_cron() {
        if (!wp_next_scheduled('kiyoh_sync_product_ratings')) {
            wp_schedule_event(time(), 'hourly', 'kiyoh_sync_product_ratings');
        }
    }

    public static function unschedule_cron() {
        $timestamp = wp_next_scheduled('kiyoh_sync_product_ratings');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'kiyoh_sync_product_ratings');
        }
        wp_clear_scheduled_hook('kiyoh_sync_product_ratings');
        wp_clear_scheduled_hook(self::CRON_PROCESS);
    }

    private function ensure_cron_scheduled() {
        if ($this->is_sync_enabled() && $this->needs_review_sync()) {
            self::schedule_cron();
        }
    }

    public function get_rating_source() {
        if (empty($this->settings['general']['enabled'])) {
            return 'woocommerce';
        }

        $ratings = isset($this->settings['ratings']) && is_array($this->settings['ratings'])
            ? $this->settings['ratings']
            : array();

        if (!empty($ratings['source']) && in_array($ratings['source'], array('kiyoh', 'woocommerce', 'both'), true)) {
            return $ratings['source'];
        }

        if (!isset($this->settings['ratings'])) {
            return 'kiyoh';
        }

        return !empty($ratings['enabled']) ? 'kiyoh' : 'woocommerce';
    }

    public function is_display_enabled() {
        return $this->get_rating_source() !== 'woocommerce';
    }

    public function is_sync_enabled() {
        return !empty($this->settings['general']['enabled'])
            && !empty($this->settings['general']['api_key'])
            && !empty($this->settings['general']['location_id']);
    }

    public function is_import_enabled() {
        return in_array($this->get_rating_source(), array('kiyoh', 'both'), true);
    }

    private function needs_review_sync() {
        return $this->is_display_enabled() || $this->is_import_enabled();
    }

    /**
     * Convert a Kiyoh 1-10 rating to WooCommerce's 0-5 scale.
     */
    public static function to_woocommerce_rating($kiyoh_rating) {
        $kiyoh_rating = (float) $kiyoh_rating;
        if ($kiyoh_rating <= 0) {
            return 0.0;
        }

        $wc_rating = $kiyoh_rating / 2;
        if ($wc_rating > 5) {
            $wc_rating = 5;
        }

        return round($wc_rating, 2);
    }

    public function filter_average_rating($value, $product) {
        $effective = $this->get_effective_rating($product);
        if (!$effective) {
            return $value;
        }

        return (string) $effective['average'];
    }

    public function filter_review_count($value, $product) {
        $effective = $this->get_effective_rating($product);
        if (!$effective) {
            return $value;
        }

        return $effective['count'];
    }

    public function filter_rating_counts($value, $product) {
        $effective = $this->get_effective_rating($product);
        if (!$effective) {
            return $value;
        }

        return $effective['counts'];
    }

    /**
     * WooCommerce recalculates native ratings on comment save. Re-apply the
     * selected source so catalog sort stays correct.
     */
    public function restore_kiyoh_rating_meta($product) {
        if (self::$suppress_comment_filters || !$product instanceof WC_Product) {
            return;
        }

        $this->write_effective_woocommerce_meta($product->get_id());
    }

    public function filter_review_comments_query($query) {
        if (self::$suppress_comment_filters || !$this->should_filter_review_comments_query($query)) {
            return;
        }

        $meta_query = $this->review_comments_meta_query($query->query_vars);
        if ($meta_query === null) {
            return;
        }

        $query->query_vars['meta_query'] = $meta_query;
    }

    public function filter_rest_review_comments_query($args) {
        if (self::$suppress_comment_filters) {
            return $args;
        }

        if (is_admin() && !wp_doing_ajax()) {
            return $args;
        }

        $source = $this->get_rating_source();
        if ($source === 'both') {
            return $args;
        }

        $post_id = isset($args['post_id']) ? (int) $args['post_id'] : 0;
        $is_product = $post_id && function_exists('wc_get_product') && wc_get_product($post_id);
        $type = isset($args['type']) ? $args['type'] : '';
        $is_review = ($type === 'review') || (isset($args['type__in']) && in_array('review', (array) $args['type__in'], true));

        if (!$is_product && !$is_review) {
            return $args;
        }

        $args['meta_query'] = $this->review_comments_meta_query($args);
        return $args;
    }

    public function filter_the_review_comments($comments, $query) {
        if (self::$suppress_comment_filters || !is_array($comments) || empty($comments)) {
            return $comments;
        }

        if (!$this->should_filter_review_comments_query($query) && !$this->comments_include_product_reviews($comments)) {
            return $comments;
        }

        $source = $this->get_rating_source();
        if ($source === 'both') {
            return $comments;
        }

        return array_values(array_filter($comments, array($this, $source === 'kiyoh' ? 'is_kiyoh_review_comment' : 'is_native_review_comment')));
    }

    private function should_filter_review_comments_query($query) {
        if (!($query instanceof WP_Comment_Query)) {
            return false;
        }

        if (is_admin() && !wp_doing_ajax()) {
            return false;
        }

        $post_ids = $this->comment_query_post_ids($query);
        if (!empty($post_ids) && function_exists('wc_get_product')) {
            foreach ($post_ids as $post_id) {
                if (wc_get_product($post_id)) {
                    return true;
                }
            }
        }

        return $this->comment_query_is_review_type($query);
    }

    private function comment_query_post_ids($query) {
        $ids = array();
        $vars = $query->query_vars;

        if (!empty($vars['post_id'])) {
            $ids[] = (int) $vars['post_id'];
        }
        if (!empty($vars['post__in']) && is_array($vars['post__in'])) {
            foreach ($vars['post__in'] as $post_id) {
                $ids[] = (int) $post_id;
            }
        }

        return array_values(array_filter(array_unique($ids)));
    }

    private function comment_query_is_review_type($query) {
        $type = isset($query->query_vars['type']) ? $query->query_vars['type'] : '';
        if (is_array($type)) {
            return in_array('review', $type, true);
        }

        return $type === 'review';
    }

    private function review_comments_meta_query($query_vars) {
        $source = $this->get_rating_source();
        if ($source === 'both') {
            return isset($query_vars['meta_query']) && is_array($query_vars['meta_query'])
                ? $query_vars['meta_query']
                : null;
        }

        $meta_query = isset($query_vars['meta_query']) && is_array($query_vars['meta_query'])
            ? $query_vars['meta_query']
            : array();

        if ($source === 'kiyoh') {
            $meta_query[] = array(
                'key' => self::COMMENT_META_REVIEW_ID,
                'compare' => 'EXISTS'
            );
        } else {
            $meta_query[] = array(
                'key' => self::COMMENT_META_REVIEW_ID,
                'compare' => 'NOT EXISTS'
            );
        }

        return $meta_query;
    }

    private function comments_include_product_reviews($comments) {
        foreach ($comments as $comment) {
            if (!isset($comment->comment_post_ID) || !function_exists('wc_get_product')) {
                continue;
            }
            if (wc_get_product($comment->comment_post_ID)) {
                return true;
            }
        }

        return false;
    }

    public function is_kiyoh_review_comment($comment) {
        return (bool) get_comment_meta($comment->comment_ID, self::COMMENT_META_REVIEW_ID, true);
    }

    public function is_native_review_comment($comment) {
        return !$this->is_kiyoh_review_comment($comment);
    }

    public function handle_review_comment_changed($comment_id) {
        if (self::$suppress_comment_filters) {
            return;
        }

        $comment = get_comment($comment_id);
        if (!$comment || $comment->comment_type !== 'review') {
            return;
        }

        $product_id = (int) $comment->comment_post_ID;
        $this->refresh_native_rating_snapshot($product_id);
        $this->write_effective_woocommerce_meta($product_id);
    }

    public function handle_settings_update($old_value, $new_value) {
        $this->settings = is_array($new_value) ? $new_value : array();

        $was_override = $this->settings_uses_kiyoh_ratings($old_value);
        $now_override = $this->settings_uses_kiyoh_ratings($new_value);
        $now_import = $this->settings_import_enabled($new_value);
        $old_source = $this->settings_rating_source($old_value);
        $new_source = $this->settings_rating_source($new_value);

        if ($old_source !== $new_source) {
            if ($now_import) {
                self::unhide_imported_reviews();
            } else {
                self::hide_imported_reviews();
            }
            $this->refresh_effective_ratings_for_stored_products();
        }

        if ($this->is_sync_enabled() && ($now_override || $now_import)) {
            self::schedule_cron();
            if (!$was_override && $now_override && !wp_next_scheduled('kiyoh_sync_product_ratings')) {
                wp_schedule_single_event(time() + 15, 'kiyoh_sync_product_ratings');
            }
            return;
        }

        self::unschedule_cron();
    }

    public function sync_incremental() {
        if (!$this->job_is_active()) {
            $this->queue_sync($this->incremental_since() === self::FULL_SYNC_SINCE);
        }

        return $this->process_job_chunk();
    }

    /**
     * Queue a full rating sync and start the first background chunk.
     */
    public function queue_full_sync() {
        return $this->queue_sync(true);
    }

    /**
     * Fetch Kiyoh product aggregates and store them on matching WooCommerce products.
     *
     * @param bool   $full  When true, request all products with reviews.
     * @param string $since UTC timestamp for incremental updates.
     * @return array
     */
    public function sync_ratings($full = false, $since = '') {
        $results = array(
            'success' => false,
            'clusters' => 0,
            'updated' => 0,
            'unmatched' => 0,
            'skipped' => 0,
            'imported' => 0,
            'import_skipped' => 0,
            'error' => ''
        );

        if (!$this->is_sync_enabled()) {
            $results['error'] = __('API credentials are not configured or the plugin is disabled.', 'kiyoh-woocommerce');
            return $results;
        }

        if (!$this->needs_review_sync()) {
            $results['error'] = __('Enable ministar override and/or review import first.', 'kiyoh-woocommerce');
            return $results;
        }

        try {
            $client = Kiyoh_Api_Factory::create_client();
        } catch (Exception $e) {
            $results['error'] = $e->getMessage();
            return $results;
        }

        $this->applied_skus = array();
        $this->imported_product_ids = array();
        $updated_since = $full || $since === '' ? self::FULL_SYNC_SINCE : $since;
        $review_ok = false;
        $update_ok = false;

        // Reviews appear on /review/external immediately. Official averageRating /
        // numberReviews often stay 0 until Kiyoh's aggregates catch up, so we
        // compute from the reviews array when those fields are still empty.
        $review_query = $full ? array() : array('updated_since' => $updated_since);
        $review_response = $client->get_reviews($review_query);
        if ($review_response->is_success()) {
            $review_ok = true;
            $this->apply_publication_payload($review_response->get_data(), $results);
        }

        $update_response = $client->get_updated_products($updated_since);
        if ($update_response->is_success()) {
            $update_ok = true;
            $clusters = $this->extract_clusters($update_response->get_data());
            foreach ($clusters as $cluster) {
                $aggregates = $this->extract_aggregates($cluster);
                if ($aggregates['count'] <= 0 || ($this->is_import_enabled() && empty($cluster['reviews']))) {
                    $cluster = $this->hydrate_cluster_reviews($client, $cluster);
                }
                $this->apply_cluster($cluster, $results);
            }
        }

        if ($full && $results['updated'] === 0) {
            $this->sync_ratings_by_catalog_skus($client, $results);
        }

        if (!$review_ok && !$update_ok && $results['updated'] === 0) {
            $failed = $review_response->is_success() ? $update_response : $review_response;
            $results['error'] = $failed->get_error() ? $failed->get_error() : __('Failed to fetch product ratings from Kiyoh.', 'kiyoh-woocommerce');
            if ($failed->get_error_code()) {
                $results['error'] .= ' [' . $failed->get_error_code() . ']';
            }
            if ($failed->get_response_code()) {
                $results['error'] .= ' (HTTP ' . $failed->get_response_code() . ')';
            }
            error_log('Kiyoh Rating Sync: ' . $results['error']);
            return $results;
        }

        $this->finalize_imported_reviews();

        $results['success'] = true;
        $this->store_sync_state($updated_since, $results);

        error_log(sprintf(
            'Kiyoh Rating Sync: Updated %d product(s) from %d cluster(s) (unmatched: %d, skipped: %d, imported: %d)',
            $results['updated'],
            $results['clusters'],
            $results['unmatched'],
            $results['skipped'],
            $results['imported']
        ));

        return $results;
    }

    public function get_sync_state() {
        $state = get_option(self::OPTION_SYNC_STATE, array());
        return is_array($state) ? $state : array();
    }

    public function get_public_sync_status() {
        $job = $this->get_job();
        $state = $this->get_sync_state();
        $status = isset($job['status']) ? $job['status'] : 'idle';
        if ($status === 'completed' && !empty($job['updated_at']) && (time() - (int) $job['updated_at']) > 300) {
            $status = 'idle';
        }

        $processed = isset($job['processed']) ? (int) $job['processed'] : 0;
        $total = isset($job['total']) ? (int) $job['total'] : 0;

        if ($total < 1) {
            $total = $this->job_is_active($job) ? 1 : 0;
        }

        $percent = ($total > 0) ? (int) min(100, round(($processed / $total) * 100)) : 0;
        $message = '';
        if ($status === 'completed' && !empty($job['message'])) {
            $message = $job['message'];
        } elseif ($status === 'failed' && !empty($job['error'])) {
            $message = $job['error'];
        } elseif ($this->job_is_active($job)) {
            $message = sprintf(
                /* translators: 1: processed count, 2: total count */
                __('Syncing ratings… %1$d of %2$d', 'kiyoh-woocommerce'),
                $processed,
                $total
            );
        }

        return array(
            'status' => $status,
            'active' => $this->job_is_active($job),
            'processed' => $processed,
            'total' => $total,
            'percent' => $percent,
            'message' => $message,
            'error' => isset($job['error']) ? $job['error'] : '',
            'last_sync' => isset($state['last_sync_local']) ? $state['last_sync_local'] : '',
            'last_result' => isset($state['last_result']) ? $state['last_result'] : array()
        );
    }

    public function process_job_chunk() {
        $job = $this->get_job();
        if (!$this->job_is_active($job)) {
            return $this->get_public_sync_status();
        }

        if (!$this->acquire_job_lock()) {
            return $this->get_public_sync_status();
        }

        try {
            if (function_exists('set_time_limit')) {
                set_time_limit(120);
            }

            $this->restore_job_runtime($job);
            $job['status'] = 'running';
            $job['updated_at'] = time();
            $this->save_job($job);

            if (empty($job['phase']) || $job['phase'] === 'bootstrap' || $job['phase'] === 'queued') {
                $this->bootstrap_job($job);
                $this->save_job($job);
            }

            if ($job['status'] === 'failed') {
                return $this->get_public_sync_status();
            }

            if ($job['phase'] === 'hydrate' || $job['phase'] === 'catalog') {
                $this->process_throttled_queue($job);
                $this->save_job($job);
            }

            if ($job['phase'] === 'finalizing' && $job['status'] === 'running') {
                $this->finalize_job($job);
                $this->save_job($job);
            }
        } catch (Exception $e) {
            $job['status'] = 'failed';
            $job['error'] = $e->getMessage();
            $job['updated_at'] = time();
            $this->save_job($job);
        } finally {
            $this->release_job_lock();
        }

        if ($this->job_is_active()) {
            $this->schedule_job_tick(self::PRODUCT_REQUEST_INTERVAL);
        }

        return $this->get_public_sync_status();
    }

    private function queue_sync($full) {
        if (!$this->is_sync_enabled()) {
            $failed = array(
                'status' => 'failed',
                'error' => __('API credentials are not configured or the plugin is disabled.', 'kiyoh-woocommerce')
            );
            $this->save_job($failed);
            return $failed;
        }

        if (!$this->needs_review_sync()) {
            $failed = array(
                'status' => 'failed',
                'error' => __('Enable ministar override and/or review import first.', 'kiyoh-woocommerce')
            );
            $this->save_job($failed);
            return $failed;
        }

        $existing = $this->get_job();
        if ($this->job_is_active($existing)) {
            $this->schedule_job_tick();
            return $existing;
        }

        $job = array(
            'status' => 'queued',
            'full' => (bool) $full,
            'since' => $full ? self::FULL_SYNC_SINCE : $this->incremental_since(),
            'phase' => 'bootstrap',
            'started_at' => current_time('mysql'),
            'updated_at' => time(),
            'processed' => 0,
            'total' => 1,
            'message' => '',
            'error' => '',
            'results' => $this->empty_sync_results(),
            'applied_skus' => array(),
            'imported_product_ids' => array(),
            'hydrate_queue' => array(),
            'catalog_queue' => array(),
            'last_product_request' => 0,
            'requested_since' => ''
        );

        $this->save_job($job);

        return $job;
    }

    private function incremental_since() {
        $state = $this->get_sync_state();
        $last_sync = isset($state['last_sync_utc']) ? $state['last_sync_utc'] : '';
        $last_ts = $last_sync ? strtotime($last_sync) : false;

        if (!$last_ts) {
            return self::FULL_SYNC_SINCE;
        }

        return gmdate('Y-m-d\TH:i:s.000\Z', $last_ts - self::INCREMENTAL_OVERLAP_SECONDS);
    }

    private function bootstrap_job(&$job) {
        $results = $this->empty_sync_results();
        $this->applied_skus = array();
        $this->imported_product_ids = array();

        try {
            $client = Kiyoh_Api_Factory::create_client();
        } catch (Exception $e) {
            $job['status'] = 'failed';
            $job['error'] = $e->getMessage();
            return;
        }

        $full = !empty($job['full']);
        $updated_since = $full || empty($job['since']) ? self::FULL_SYNC_SINCE : $job['since'];
        $job['requested_since'] = $updated_since;

        $review_ok = false;
        $update_ok = false;
        $hydrate_queue = array();

        $review_query = $full ? array() : array('updated_since' => $updated_since);
        $review_response = $client->get_reviews($review_query);
        if ($review_response->is_success()) {
            $review_ok = true;
            $this->apply_publication_payload($review_response->get_data(), $results);
        }

        $update_response = $client->get_updated_products($updated_since);
        if ($update_response->is_success()) {
            $update_ok = true;
            foreach ($this->extract_clusters($update_response->get_data()) as $cluster) {
                $aggregates = $this->extract_aggregates($cluster);
                if ($aggregates['count'] <= 0 || ($this->is_import_enabled() && empty($cluster['reviews']))) {
                    $hydrate_queue[] = $this->cluster_for_queue($cluster);
                    continue;
                }
                $this->apply_cluster($cluster, $results);
            }
        }

        $catalog_queue = array();
        if ($full && $results['updated'] === 0) {
            foreach ($this->get_fallback_skus() as $sku) {
                if (!isset($this->applied_skus[$sku])) {
                    $catalog_queue[] = $sku;
                }
            }
        }

        if (!$review_ok && !$update_ok && $results['updated'] === 0 && empty($hydrate_queue) && empty($catalog_queue)) {
            $failed = $review_response->is_success() ? $update_response : $review_response;
            $job['status'] = 'failed';
            $job['error'] = $failed->get_error() ? $failed->get_error() : __('Failed to fetch product ratings from Kiyoh.', 'kiyoh-woocommerce');
            if ($failed->get_error_code()) {
                $job['error'] .= ' [' . $failed->get_error_code() . ']';
            }
            if ($failed->get_response_code()) {
                $job['error'] .= ' (HTTP ' . $failed->get_response_code() . ')';
            }
            error_log('Kiyoh Rating Sync: ' . $job['error']);
            return;
        }

        $job['results'] = $results;
        $job['hydrate_queue'] = $hydrate_queue;
        $job['catalog_queue'] = $catalog_queue;
        $job['applied_skus'] = array_keys($this->applied_skus);
        $job['imported_product_ids'] = array_map('intval', array_keys($this->imported_product_ids));
        $job['total'] = max(1, count($hydrate_queue) + count($catalog_queue));
        $job['processed'] = 0;
        $job['updated_at'] = time();

        if (empty($hydrate_queue) && empty($catalog_queue)) {
            $job['phase'] = 'finalizing';
            $job['processed'] = $job['total'];
            return;
        }

        $job['phase'] = !empty($hydrate_queue) ? 'hydrate' : 'catalog';
    }

    private function process_throttled_queue(&$job) {
        try {
            $client = Kiyoh_Api_Factory::create_client();
        } catch (Exception $e) {
            $job['status'] = 'failed';
            $job['error'] = $e->getMessage();
            return;
        }

        $processed_this_chunk = 0;

        while ($processed_this_chunk < self::JOB_CHUNK_SIZE && $job['status'] === 'running') {
            if ($job['phase'] === 'hydrate') {
                if (empty($job['hydrate_queue'])) {
                    $job['phase'] = !empty($job['catalog_queue']) ? 'catalog' : 'finalizing';
                    break;
                }

                $this->wait_for_product_rate_limit($job);
                $cluster = array_shift($job['hydrate_queue']);
                $cluster = $this->hydrate_cluster_reviews($client, $cluster);
                $results = $job['results'];
                $this->apply_cluster($cluster, $results);
                $job['results'] = $results;
                $job['processed']++;
                $processed_this_chunk++;
                $this->persist_job_progress($job);
                continue;
            }

            if ($job['phase'] === 'catalog') {
                if (empty($job['catalog_queue'])) {
                    $job['phase'] = 'finalizing';
                    break;
                }

                $sku = array_shift($job['catalog_queue']);
                if (isset($this->applied_skus[$sku])) {
                    $this->persist_job_progress($job);
                    continue;
                }

                $this->wait_for_product_rate_limit($job);
                $response = $client->get_reviews(array('product_code' => $sku));
                if ($response->is_success()) {
                    $results = $job['results'];
                    $this->apply_publication_payload($response->get_data(), $results);
                    $job['results'] = $results;
                }

                $job['processed']++;
                $processed_this_chunk++;
                $this->persist_job_progress($job);
                continue;
            }

            break;
        }

        $this->persist_job_progress($job);

        if ($job['phase'] === 'hydrate' && empty($job['hydrate_queue'])) {
            $job['phase'] = !empty($job['catalog_queue']) ? 'catalog' : 'finalizing';
        }

        if ($job['phase'] === 'catalog' && empty($job['catalog_queue'])) {
            $job['phase'] = 'finalizing';
        }
    }

    private function persist_job_progress(&$job) {
        $job['applied_skus'] = array_keys($this->applied_skus);
        $job['imported_product_ids'] = array_map('intval', array_keys($this->imported_product_ids));
        $job['updated_at'] = time();
        $this->save_job($job);
    }

    private function finalize_job(&$job) {
        $this->imported_product_ids = array();
        if (!empty($job['imported_product_ids']) && is_array($job['imported_product_ids'])) {
            foreach ($job['imported_product_ids'] as $product_id) {
                $this->imported_product_ids[(int) $product_id] = true;
            }
        }

        $this->finalize_imported_reviews();

        $results = isset($job['results']) && is_array($job['results']) ? $job['results'] : $this->empty_sync_results();
        $results['success'] = true;
        $this->store_sync_state(isset($job['requested_since']) ? $job['requested_since'] : self::FULL_SYNC_SINCE, $results);

        $job['results'] = $results;
        $job['status'] = 'completed';
        $job['phase'] = 'done';
        $job['processed'] = max((int) $job['processed'], (int) $job['total']);
        $job['message'] = sprintf(
            __('Rating sync completed. Updated %1$d product(s) from %2$d Kiyoh cluster(s). Unmatched SKUs: %3$d. Imported reviews: %4$d (already present: %5$d).', 'kiyoh-woocommerce'),
            isset($results['updated']) ? (int) $results['updated'] : 0,
            isset($results['clusters']) ? (int) $results['clusters'] : 0,
            isset($results['unmatched']) ? (int) $results['unmatched'] : 0,
            isset($results['imported']) ? (int) $results['imported'] : 0,
            isset($results['import_skipped']) ? (int) $results['import_skipped'] : 0
        );
        $job['hydrate_queue'] = array();
        $job['catalog_queue'] = array();
        $job['updated_at'] = time();

        error_log(sprintf(
            'Kiyoh Rating Sync: Updated %d product(s) from %d cluster(s) (unmatched: %d, skipped: %d, imported: %d)',
            $results['updated'],
            $results['clusters'],
            $results['unmatched'],
            $results['skipped'],
            $results['imported']
        ));
    }

    private function wait_for_product_rate_limit(&$job) {
        $last = isset($job['last_product_request']) ? (float) $job['last_product_request'] : 0;
        if ($last > 0) {
            $elapsed = microtime(true) - $last;
            if ($elapsed < self::PRODUCT_REQUEST_INTERVAL) {
                usleep((int) round((self::PRODUCT_REQUEST_INTERVAL - $elapsed) * 1000000));
            }
        }

        $job['last_product_request'] = microtime(true);
    }

    private function cluster_for_queue($cluster) {
        return array(
            'clusterId' => isset($cluster['clusterId']) ? $cluster['clusterId'] : '',
            'locationProduct' => isset($cluster['locationProduct']) ? $cluster['locationProduct'] : array(),
            'averageRating' => isset($cluster['averageRating']) ? $cluster['averageRating'] : 0,
            'numberReviews' => isset($cluster['numberReviews']) ? $cluster['numberReviews'] : 0,
            'reviews' => isset($cluster['reviews']) ? $cluster['reviews'] : array()
        );
    }

    private function restore_job_runtime($job) {
        $this->applied_skus = array();
        if (!empty($job['applied_skus']) && is_array($job['applied_skus'])) {
            foreach ($job['applied_skus'] as $sku) {
                $this->applied_skus[(string) $sku] = true;
            }
        }

        $this->imported_product_ids = array();
        if (!empty($job['imported_product_ids']) && is_array($job['imported_product_ids'])) {
            foreach ($job['imported_product_ids'] as $product_id) {
                $this->imported_product_ids[(int) $product_id] = true;
            }
        }
    }

    private function empty_sync_results() {
        return array(
            'success' => false,
            'clusters' => 0,
            'updated' => 0,
            'unmatched' => 0,
            'skipped' => 0,
            'imported' => 0,
            'import_skipped' => 0,
            'error' => ''
        );
    }

    private function get_job() {
        $job = get_option(self::OPTION_SYNC_JOB, array());
        return is_array($job) ? $job : array();
    }

    private function save_job($job) {
        update_option(self::OPTION_SYNC_JOB, $job, false);
    }

    private function job_is_active($job = null) {
        if ($job === null) {
            $job = $this->get_job();
        }

        return isset($job['status']) && in_array($job['status'], array('queued', 'running'), true);
    }

    private function acquire_job_lock() {
        if (get_transient(self::LOCK_TRANSIENT)) {
            return false;
        }

        set_transient(self::LOCK_TRANSIENT, 1, 90);
        return true;
    }

    private function release_job_lock() {
        delete_transient(self::LOCK_TRANSIENT);
    }

    private function schedule_job_tick($delay = 1) {
        $timestamp = wp_next_scheduled(self::CRON_PROCESS);
        if (!$timestamp) {
            wp_schedule_single_event(time() + max(0, (int) $delay), self::CRON_PROCESS);
        }

        if (function_exists('as_enqueue_async_action') && function_exists('as_next_scheduled_action')) {
            if (!as_next_scheduled_action(self::CRON_PROCESS)) {
                as_enqueue_async_action(self::CRON_PROCESS, array(), 'kiyoh-ratings');
            }
        }

        if (function_exists('spawn_cron')) {
            spawn_cron();
        }
    }

    private function store_sync_state($requested_since, $results) {
        update_option(self::OPTION_SYNC_STATE, array(
            'last_sync_utc' => gmdate('Y-m-d\TH:i:s.000\Z'),
            'requested_since' => $requested_since,
            'last_result' => $results,
            'last_sync_local' => current_time('mysql')
        ), false);
    }

    private function extract_clusters($data) {
        if (!is_array($data)) {
            return array();
        }

        if (!empty($data['clusters']) && is_array($data['clusters'])) {
            return $data['clusters'];
        }

        // Single-product publication response (productCode / clusterId).
        if (isset($data['locationProduct']) || isset($data['averageRating']) || isset($data['reviews'])) {
            return array($data);
        }

        return array();
    }

    private function apply_publication_payload($data, &$results) {
        $clusters = $this->extract_clusters($data);

        if (empty($clusters) && is_array($data) && !empty($data['reviews']) && is_array($data['reviews'])) {
            $clusters = $this->clusters_from_flat_reviews($data['reviews']);
        }

        foreach ($clusters as $cluster) {
            $this->apply_cluster($cluster, $results);
        }
    }

    private function apply_cluster($cluster, &$results) {
        $results['clusters']++;

        $aggregates = $this->extract_aggregates($cluster);
        $product_codes = $this->extract_product_codes($cluster);
        if (empty($product_codes) && !empty($cluster['reviews']) && is_array($cluster['reviews'])) {
            $product_codes = $this->product_codes_from_reviews($cluster['reviews']);
        }

        $did_work = false;

        if ($aggregates['count'] > 0 && !empty($product_codes)) {
            foreach ($product_codes as $product_code) {
                if (isset($this->applied_skus[$product_code])) {
                    continue;
                }

                $applied = $this->apply_rating_to_sku($product_code, $aggregates['average'], $aggregates['count']);
                $this->applied_skus[$product_code] = true;
                if ($applied) {
                    $results['updated']++;
                    $did_work = true;
                } else {
                    $results['unmatched']++;
                }
            }
        }

        if ($this->is_import_enabled() && !empty($cluster['reviews']) && is_array($cluster['reviews'])) {
            $imported = $this->import_reviews_from_cluster($cluster);
            $results['imported'] += $imported['imported'];
            $results['import_skipped'] += $imported['skipped'];
            if ($imported['imported'] > 0) {
                $did_work = true;
            }
        }

        if (!$did_work && $aggregates['count'] <= 0) {
            $results['skipped']++;
        }
    }

    /**
     * Fetch the reviews for a cluster/SKU when the update API omitted them
     * or still reports averageRating/numberReviews as 0.
     */
    private function hydrate_cluster_reviews($client, $cluster) {
        $query = array();
        if (!empty($cluster['clusterId'])) {
            $query['cluster_id'] = $cluster['clusterId'];
        } else {
            $codes = $this->extract_product_codes($cluster);
            if (empty($codes)) {
                return $cluster;
            }
            $query['product_code'] = $codes[0];
        }

        $response = $client->get_reviews($query);
        if (!$response->is_success()) {
            return $cluster;
        }

        $data = $response->get_data();
        if (!is_array($data)) {
            return $cluster;
        }

        if (!empty($data['reviews'])) {
            $cluster['reviews'] = $data['reviews'];
        }
        if (empty($cluster['locationProduct']) && !empty($data['locationProduct'])) {
            $cluster['locationProduct'] = $data['locationProduct'];
        }

        return $cluster;
    }

    private function sync_ratings_by_catalog_skus($client, &$results) {
        $skus = $this->get_fallback_skus();
        foreach ($skus as $sku) {
            if (isset($this->applied_skus[$sku])) {
                continue;
            }

            $response = $client->get_reviews(array('product_code' => $sku));
            if (!$response->is_success()) {
                continue;
            }

            $this->apply_publication_payload($response->get_data(), $results);
        }
    }

    private function get_fallback_skus() {
        global $wpdb;

        $skus = array();
        $table = $wpdb->prefix . 'kiyoh_product_sync';
        $table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));

        if ($table_exists === $table) {
            $product_ids = $wpdb->get_col("SELECT product_id FROM {$table} WHERE sync_status = 'success'");
            foreach ($product_ids as $product_id) {
                $product = wc_get_product((int) $product_id);
                if (!$product) {
                    continue;
                }
                $sku = $product->get_sku();
                if ($sku !== '' && $sku !== null) {
                    $skus[] = $sku;
                }
            }
        }

        if (!empty($skus) || !function_exists('wc_get_products')) {
            return array_values(array_unique($skus));
        }

        $products = wc_get_products(array(
            'status' => 'publish',
            'limit' => 100,
            'return' => 'objects'
        ));

        foreach ($products as $product) {
            $sku = $product->get_sku();
            if ($sku !== '' && $sku !== null) {
                $skus[] = $sku;
            }
        }

        return array_values(array_unique($skus));
    }

    private function clusters_from_flat_reviews($reviews) {
        $by_code = array();

        foreach ($reviews as $review) {
            if (!is_array($review)) {
                continue;
            }
            $code = '';
            if (!empty($review['productCode'])) {
                $code = (string) $review['productCode'];
            } elseif (!empty($review['product_code'])) {
                $code = (string) $review['product_code'];
            }
            if ($code === '') {
                continue;
            }
            if (!isset($by_code[$code])) {
                $by_code[$code] = array(
                    'locationProduct' => array(array('productCode' => $code)),
                    'reviews' => array()
                );
            }
            $by_code[$code]['reviews'][] = $review;
        }

        return array_values($by_code);
    }

    private function product_codes_from_reviews($reviews) {
        $codes = array();
        foreach ($reviews as $review) {
            if (!is_array($review)) {
                continue;
            }
            if (!empty($review['productCode'])) {
                $codes[] = (string) $review['productCode'];
            } elseif (!empty($review['product_code'])) {
                $codes[] = (string) $review['product_code'];
            }
        }
        return array_values(array_unique($codes));
    }

    private function extract_aggregates($cluster) {
        $average = null;
        if (isset($cluster['averageRating'])) {
            $average = $cluster['averageRating'];
        } elseif (isset($cluster['average_rating'])) {
            $average = $cluster['average_rating'];
        }

        $count = null;
        if (isset($cluster['numberReviews'])) {
            $count = $cluster['numberReviews'];
        } elseif (isset($cluster['number_reviews'])) {
            $count = $cluster['number_reviews'];
        }

        $from_reviews = array('average' => 0.0, 'count' => 0);
        if (!empty($cluster['reviews']) && is_array($cluster['reviews'])) {
            $from_reviews = $this->aggregates_from_reviews($cluster['reviews']);
        }

        // Kiyoh often leaves averageRating/numberReviews at 0 until a later
        // aggregation pass, while the reviews array is already populated.
        if ($from_reviews['count'] > 0 && (int) $count <= 0) {
            return $from_reviews;
        }

        if ((int) $count > 0) {
            return array(
                'average' => (float) $average,
                'count' => (int) $count
            );
        }

        return $from_reviews;
    }

    private function aggregates_from_reviews($reviews) {
        $ratings = array();

        foreach ($reviews as $review) {
            if (!is_array($review) || !empty($review['notApplicable'])) {
                continue;
            }
            $rating = $this->review_numeric_rating($review);
            if ($rating === null) {
                continue;
            }
            $ratings[] = $rating;
        }

        $count = count($ratings);

        return array(
            'average' => $count > 0 ? array_sum($ratings) / $count : 0.0,
            'count' => $count
        );
    }

    private function review_numeric_rating($review) {
        if (isset($review['rating']) && $review['rating'] !== '' && is_numeric($review['rating'])) {
            return (float) $review['rating'];
        }

        if (empty($review['productReviewContent']) || !is_array($review['productReviewContent'])) {
            return null;
        }

        foreach ($review['productReviewContent'] as $content) {
            if (!is_array($content) || !isset($content['rating']) || !is_numeric($content['rating'])) {
                continue;
            }
            if (isset($content['questionGroup']) && $content['questionGroup'] === 'DEFAULT_OVERALL') {
                return (float) $content['rating'];
            }
        }

        return null;
    }

    private function extract_product_codes($cluster) {
        $codes = array();

        if (empty($cluster['locationProduct']) || !is_array($cluster['locationProduct'])) {
            return $codes;
        }

        foreach ($cluster['locationProduct'] as $location_product) {
            if (!is_array($location_product)) {
                continue;
            }
            $raw_code = '';
            if (!empty($location_product['productCode'])) {
                $raw_code = $location_product['productCode'];
            } elseif (!empty($location_product['product_code'])) {
                $raw_code = $location_product['product_code'];
            }
            $code = trim((string) $raw_code);
            if ($code !== '') {
                $codes[] = $code;
            }
        }

        return array_values(array_unique($codes));
    }

    private function apply_rating_to_sku($sku, $average, $count) {
        if (!function_exists('wc_get_product_id_by_sku')) {
            return false;
        }

        $product_id = wc_get_product_id_by_sku($sku);
        if (!$product_id) {
            return false;
        }

        $this->write_kiyoh_rating($product_id, $average, $count);

        $product = wc_get_product($product_id);
        if ($product && $product->is_type('variation')) {
            $parent_id = $product->get_parent_id();
            if ($parent_id) {
                $parent_count = (int) get_post_meta($parent_id, self::META_COUNT, true);
                // Catalog stars render on the parent; prefer the cluster with more reviews.
                if ($parent_count <= 0 || $count >= $parent_count) {
                    $this->write_kiyoh_rating($parent_id, $average, $count);
                }
            }
        }

        return true;
    }

    private function import_reviews_from_cluster($cluster) {
        $stats = array(
            'imported' => 0,
            'skipped' => 0
        );

        $fallback_sku = '';
        $codes = $this->extract_product_codes($cluster);
        if (!empty($codes)) {
            $fallback_sku = $codes[0];
        }

        foreach ($cluster['reviews'] as $review) {
            if (!is_array($review)) {
                continue;
            }

            $sku = '';
            if (!empty($review['productCode'])) {
                $sku = (string) $review['productCode'];
            } elseif (!empty($review['product_code'])) {
                $sku = (string) $review['product_code'];
            }
            if ($sku === '') {
                $sku = $fallback_sku;
            }

            $status = $this->import_single_review($review, $sku);
            if ($status === 'imported') {
                $stats['imported']++;
            } elseif ($status === 'exists') {
                $stats['skipped']++;
            }
        }

        return $stats;
    }

    private function import_single_review($review, $sku) {
        $review_id = $this->review_unique_id($review);
        if ($review_id === '' || $sku === '' || !function_exists('wc_get_product_id_by_sku')) {
            return 'failed';
        }

        if ($this->imported_review_exists($review_id)) {
            return 'exists';
        }

        $product_id = wc_get_product_id_by_sku($sku);
        if (!$product_id) {
            return 'failed';
        }

        $product_id = $this->storefront_product_id($product_id);
        $content = $this->review_comment_content($review);
        $author = !empty($review['reviewAuthor']) ? sanitize_text_field($review['reviewAuthor']) : __('Kiyoh reviewer', 'kiyoh-woocommerce');
        $kiyoh_rating = $this->review_numeric_rating($review);
        $star = $kiyoh_rating === null ? 0 : (int) round(self::to_woocommerce_rating($kiyoh_rating));
        if ($star < 0) {
            $star = 0;
        }
        if ($star > 5) {
            $star = 5;
        }

        $timestamps = $this->review_comment_dates($review);

        $comment_id = $this->without_comment_filters(function () use ($product_id, $author, $review_id, $content, $timestamps) {
            return wp_insert_comment(array(
                'comment_post_ID' => $product_id,
                'comment_author' => $author,
                'comment_author_email' => 'kiyoh-' . substr(md5($review_id), 0, 12) . '@noreply.invalid',
                'comment_author_url' => '',
                'comment_content' => $content,
                'comment_type' => 'review',
                'comment_parent' => 0,
                'user_id' => 0,
                'comment_approved' => 1,
                'comment_date' => $timestamps['local'],
                'comment_date_gmt' => $timestamps['gmt']
            ));
        });

        if (!$comment_id) {
            return 'failed';
        }

        if ($star > 0) {
            update_comment_meta($comment_id, 'rating', $star);
        }
        update_comment_meta($comment_id, self::COMMENT_META_REVIEW_ID, $review_id);
        update_comment_meta($comment_id, 'verified', 0);

        $this->imported_product_ids[$product_id] = true;

        return 'imported';
    }

    private function imported_review_exists($review_id) {
        return $this->without_comment_filters(function () use ($review_id) {
            $existing = get_comments(array(
                'type' => 'review',
                'status' => 'all',
                'meta_key' => self::COMMENT_META_REVIEW_ID,
                'meta_value' => $review_id,
                'number' => 1,
                'fields' => 'ids'
            ));

            return !empty($existing);
        });
    }

    private function review_unique_id($review) {
        if (!empty($review['productReviewId'])) {
            return (string) $review['productReviewId'];
        }
        if (!empty($review['reviewId'])) {
            return (string) $review['reviewId'];
        }
        return '';
    }

    private function review_comment_content($review) {
        $oneliner = '';
        $opinion = '';

        if (!empty($review['oneliner'])) {
            $oneliner = $review['oneliner'];
        }
        if (!empty($review['description'])) {
            $opinion = $review['description'];
        }

        if (!empty($review['productReviewContent']) && is_array($review['productReviewContent'])) {
            foreach ($review['productReviewContent'] as $content) {
                if (!is_array($content) || !isset($content['questionGroup']) || !isset($content['rating'])) {
                    continue;
                }
                if ($content['questionGroup'] === 'DEFAULT_ONELINER' && $content['rating'] !== '') {
                    $oneliner = $content['rating'];
                }
                if ($content['questionGroup'] === 'DEFAULT_OPINION' && $content['rating'] !== '') {
                    $opinion = $content['rating'];
                }
            }
        }

        $parts = array();
        if (trim((string) $oneliner) !== '') {
            $parts[] = trim((string) $oneliner);
        }
        if (trim((string) $opinion) !== '' && trim((string) $opinion) !== trim((string) $oneliner)) {
            $parts[] = trim((string) $opinion);
        }

        return implode("\n\n", $parts);
    }

    private function review_comment_dates($review) {
        $source = '';
        if (!empty($review['dateSince'])) {
            $source = $review['dateSince'];
        } elseif (!empty($review['updatedSince'])) {
            $source = $review['updatedSince'];
        }

        $timestamp = $source ? strtotime($source) : time();
        if (!$timestamp) {
            $timestamp = time();
        }

        $gmt = gmdate('Y-m-d H:i:s', $timestamp);

        return array(
            'gmt' => $gmt,
            'local' => function_exists('get_date_from_gmt') ? get_date_from_gmt($gmt) : $gmt
        );
    }

    private function storefront_product_id($product_id) {
        $product = wc_get_product($product_id);
        if ($product && $product->is_type('variation') && $product->get_parent_id()) {
            return $product->get_parent_id();
        }

        return $product_id;
    }

    private function finalize_imported_reviews() {
        foreach (array_keys($this->imported_product_ids) as $product_id) {
            wp_update_comment_count_now($product_id);
            $this->refresh_native_rating_snapshot($product_id);
            $this->write_effective_woocommerce_meta($product_id);
        }
    }

    private function write_kiyoh_rating($product_id, $average, $count) {
        update_post_meta($product_id, self::META_AVERAGE, $average);
        update_post_meta($product_id, self::META_COUNT, $count);
        update_post_meta($product_id, self::META_SYNCED_AT, current_time('mysql'));

        unset($this->rating_cache[$product_id], $this->native_cache[$product_id]);
        $this->refresh_native_rating_snapshot($product_id);
        $this->write_effective_woocommerce_meta($product_id);

        if (function_exists('wc_delete_product_transients')) {
            wc_delete_product_transients($product_id);
        }
        clean_post_cache($product_id);
    }

    private function write_effective_woocommerce_meta($product_id) {
        $product = wc_get_product($product_id);
        if (!$product) {
            return;
        }

        unset($this->rating_cache[$product_id], $this->native_cache[$product_id]);
        $effective = $this->get_effective_rating($product);

        if (!$effective) {
            $this->write_woocommerce_rating_store($product_id, '0', 0, array());
            return;
        }

        $this->write_woocommerce_rating_store(
            $product_id,
            (string) $effective['average'],
            $effective['count'],
            $effective['counts']
        );
    }

    private function write_native_woocommerce_meta($product_id) {
        unset($this->rating_cache[$product_id], $this->native_cache[$product_id]);

        $native = $this->compute_native_woocommerce_rating($product_id);
        if ($native['count'] <= 0) {
            $this->write_woocommerce_rating_store($product_id, '0', 0, array());
            return;
        }

        $this->write_woocommerce_rating_store(
            $product_id,
            (string) $native['average'],
            $native['count'],
            $native['counts']
        );
    }

    private function write_woocommerce_rating_store($product_id, $average, $count, $counts) {
        update_post_meta($product_id, '_wc_average_rating', (string) $average);
        update_post_meta($product_id, '_wc_review_count', (int) $count);
        update_post_meta($product_id, '_wc_rating_count', is_array($counts) ? $counts : array());
        $this->update_product_lookup_table($product_id, $average, $count);
        $this->clear_product_rating_cache($product_id);
    }

    private function clear_product_rating_cache($product_id) {
        wp_cache_delete($product_id, 'post_meta');
        clean_post_cache($product_id);

        if (function_exists('wc_delete_product_transients')) {
            wc_delete_product_transients($product_id);
        }
    }

    private function update_product_lookup_table($product_id, $wc_rating, $count) {
        global $wpdb;

        $table = $wpdb->prefix . 'wc_product_meta_lookup';
        $exists = $wpdb->get_var($wpdb->prepare(
            'SHOW TABLES LIKE %s',
            $wpdb->esc_like($table)
        ));
        if ($exists !== $table) {
            return;
        }

        $row = $wpdb->get_var($wpdb->prepare(
            "SELECT product_id FROM {$table} WHERE product_id = %d",
            $product_id
        ));
        if (!$row) {
            return;
        }

        $wpdb->update(
            $table,
            array(
                'average_rating' => (float) $wc_rating,
                'rating_count' => (int) $count
            ),
            array('product_id' => (int) $product_id),
            array('%f', '%d'),
            array('%d')
        );
    }

    private function build_rating_counts($kiyoh_average, $count) {
        $count = (int) $count;
        if ($count <= 0) {
            return array();
        }

        $star = (int) round(self::to_woocommerce_rating($kiyoh_average));
        if ($star < 1) {
            $star = 1;
        }
        if ($star > 5) {
            $star = 5;
        }

        return array($star => $count);
    }

    private function get_effective_rating($product) {
        if (!$product instanceof WC_Product) {
            return null;
        }

        $product_id = $product->get_id();
        if (array_key_exists($product_id, $this->rating_cache)) {
            return $this->rating_cache[$product_id];
        }

        $effective = $this->combine_ratings(
            $this->get_rating_source(),
            $this->get_kiyoh_rating($product_id),
            $this->get_native_rating($product_id)
        );

        $this->rating_cache[$product_id] = $effective;
        return $effective;
    }

    private function get_kiyoh_rating($product_id) {
        $count = (int) get_post_meta($product_id, self::META_COUNT, true);
        if ($count <= 0) {
            return null;
        }

        return array(
            'average' => (float) get_post_meta($product_id, self::META_AVERAGE, true),
            'count' => $count
        );
    }

    private function get_native_rating($product_id) {
        if (array_key_exists($product_id, $this->native_cache)) {
            return $this->native_cache[$product_id];
        }

        $native = $this->compute_native_woocommerce_rating($product_id);
        $result = $native['count'] > 0 ? $native : null;
        $this->native_cache[$product_id] = $result;

        return $result;
    }

    private function refresh_native_rating_snapshot($product_id) {
        unset($this->native_cache[$product_id], $this->rating_cache[$product_id]);

        $native = $this->compute_native_woocommerce_rating($product_id);
        update_post_meta($product_id, self::META_NATIVE_AVERAGE, $native['average']);
        update_post_meta($product_id, self::META_NATIVE_COUNT, $native['count']);
        update_post_meta($product_id, self::META_NATIVE_COUNTS, $native['counts']);

        $result = $native['count'] > 0 ? $native : null;
        $this->native_cache[$product_id] = $result;

        return $result;
    }

    /**
     * Native store reviews only. Imported Kiyoh comments are excluded so
     * "both" does not count the same review twice.
     */
    private function compute_native_woocommerce_rating($product_id) {
        return $this->without_comment_filters(function () use ($product_id) {
            $comments = get_comments(array(
                'post_id' => $product_id,
                'status' => 'approve',
                'number' => 0
            ));

            $sum = 0;
            $count = 0;
            $counts = array();

            foreach ($comments as $comment) {
                if (in_array($comment->comment_type, array('order_note', 'action_log'), true)) {
                    continue;
                }

                if (get_comment_meta($comment->comment_ID, self::COMMENT_META_REVIEW_ID, true)) {
                    continue;
                }

                $rating = get_comment_meta($comment->comment_ID, 'rating', true);
                if ($rating === '' || !is_numeric($rating)) {
                    continue;
                }

                $star = (int) $rating;
                if ($star < 1 || $star > 5) {
                    continue;
                }

                $sum += $star;
                $count++;
                $counts[$star] = isset($counts[$star]) ? $counts[$star] + 1 : 1;
            }

            return array(
                'average' => $count > 0 ? round($sum / $count, 2) : 0.0,
                'count' => $count,
                'counts' => $counts
            );
        });
    }

    private function without_comment_filters($callback) {
        $previous = self::$suppress_comment_filters;
        self::$suppress_comment_filters = true;

        try {
            return $callback();
        } finally {
            self::$suppress_comment_filters = $previous;
        }
    }

    private function combine_ratings($source, $kiyoh, $native) {
        $has_kiyoh = is_array($kiyoh) && $kiyoh['count'] > 0;
        $has_native = is_array($native) && $native['count'] > 0;

        if ($source === 'woocommerce') {
            return $has_native ? $native : null;
        }

        if ($source === 'kiyoh') {
            return $has_kiyoh ? $this->kiyoh_as_effective($kiyoh) : ($has_native ? $native : null);
        }

        if ($has_kiyoh && $has_native) {
            $kiyoh_five = self::to_woocommerce_rating($kiyoh['average']);
            $total = $kiyoh['count'] + $native['count'];
            $average = (($kiyoh_five * $kiyoh['count']) + ($native['average'] * $native['count'])) / $total;

            return array(
                'average' => round($average, 2),
                'count' => $total,
                'counts' => $this->merge_rating_counts(
                    $this->build_rating_counts($kiyoh['average'], $kiyoh['count']),
                    $native['counts']
                )
            );
        }

        if ($has_kiyoh) {
            return $this->kiyoh_as_effective($kiyoh);
        }

        if ($has_native) {
            return $native;
        }

        return null;
    }

    private function kiyoh_as_effective($kiyoh) {
        return array(
            'average' => self::to_woocommerce_rating($kiyoh['average']),
            'count' => $kiyoh['count'],
            'counts' => $this->build_rating_counts($kiyoh['average'], $kiyoh['count'])
        );
    }

    private function merge_rating_counts($left, $right) {
        $merged = is_array($left) ? $left : array();
        if (!is_array($right)) {
            return $merged;
        }

        foreach ($right as $star => $count) {
            $star = (int) $star;
            $merged[$star] = (isset($merged[$star]) ? (int) $merged[$star] : 0) + (int) $count;
        }

        return $merged;
    }

    private function refresh_effective_ratings_for_stored_products() {
        global $wpdb;

        $product_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key IN (%s, %s)",
            self::META_COUNT,
            self::META_NATIVE_COUNT
        ));

        foreach ($product_ids as $product_id) {
            $this->refresh_native_rating_snapshot((int) $product_id);
            $this->write_effective_woocommerce_meta((int) $product_id);
        }
    }

    private function settings_rating_source($settings) {
        $previous = $this->settings;
        $this->settings = is_array($settings) ? $settings : array();
        $source = $this->get_rating_source();
        $this->settings = $previous;
        return $source;
    }

    private function settings_uses_kiyoh_ratings($settings) {
        return $this->settings_rating_source($settings) !== 'woocommerce';
    }

    private function settings_import_enabled($settings) {
        return in_array($this->settings_rating_source($settings), array('kiyoh', 'both'), true);
    }

    /**
     * Permanently delete WooCommerce review comments that were imported from Kiyoh.
     */
    public function remove_imported_reviews() {
        $comments = $this->without_comment_filters(function () {
            $query = new WP_Comment_Query(array(
                'type' => 'review',
                'status' => 'all',
                'meta_key' => self::COMMENT_META_REVIEW_ID,
                'number' => 0
            ));

            return $query->comments;
        });

        $deleted = 0;
        $product_ids = array();

        if (!empty($comments)) {
            foreach ($comments as $comment) {
                $product_ids[(int) $comment->comment_post_ID] = true;
                if (wp_delete_comment($comment->comment_ID, true)) {
                    $deleted++;
                }
            }
        }

        foreach (array_keys($product_ids) as $product_id) {
            wp_update_comment_count_now($product_id);
            $this->refresh_native_rating_snapshot($product_id);
            $this->write_effective_woocommerce_meta($product_id);
        }

        return array(
            'deleted' => $deleted
        );
    }

    /**
     * Hide imported Kiyoh comments and restore native WooCommerce ratings.
     * Stored Kiyoh meta is kept so reactivation can put everything back.
     */
    public static function hide_kiyoh_reviews_for_deactivate() {
        self::with_suppressed_comment_filters(function () {
            $product_ids = self::hide_imported_reviews() + self::product_ids_with_kiyoh_rating_meta();
            $manager = new self();

            foreach (array_keys($product_ids) as $product_id) {
                wp_update_comment_count_now($product_id);
                $manager->write_native_woocommerce_meta((int) $product_id);
            }
        });
    }

    /**
     * Unhide stored Kiyoh comments and rewrite ministars from kept meta
     * when the saved source is Kiyoh or both.
     */
    public static function restore_kiyoh_reviews_on_activate() {
        $manager = new self();

        self::with_suppressed_comment_filters(function () use ($manager) {
            $product_ids = self::product_ids_with_kiyoh_rating_meta();

            if ($manager->is_import_enabled()) {
                $product_ids += self::unhide_imported_reviews();
            }

            foreach (array_keys($product_ids) as $product_id) {
                wp_update_comment_count_now($product_id);
                $manager->refresh_native_rating_snapshot((int) $product_id);
                $manager->write_effective_woocommerce_meta((int) $product_id);
            }
        });

        if ($manager->is_sync_enabled() && $manager->needs_review_sync()) {
            self::schedule_cron();
        }
    }

    private static function hide_imported_reviews() {
        $product_ids = array();

        self::with_suppressed_comment_filters(function () use (&$product_ids) {
            foreach (self::imported_comment_ids() as $comment_id) {
                $comment = get_comment($comment_id);
                if (!$comment) {
                    continue;
                }

                $product_ids[(int) $comment->comment_post_ID] = true;

                if (get_comment_meta($comment_id, self::COMMENT_META_HELD, true) !== '') {
                    if ($comment->comment_approved === '1') {
                        wp_set_comment_status($comment_id, 'hold');
                    }
                    continue;
                }

                update_comment_meta($comment_id, self::COMMENT_META_HELD, $comment->comment_approved);

                if ($comment->comment_approved === '1') {
                    wp_set_comment_status($comment_id, 'hold');
                }
            }
        });

        return $product_ids;
    }

    private static function unhide_imported_reviews() {
        $product_ids = array();

        self::with_suppressed_comment_filters(function () use (&$product_ids) {
            global $wpdb;

            $comment_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT comment_id FROM {$wpdb->commentmeta} WHERE meta_key = %s",
                self::COMMENT_META_HELD
            ));

            foreach ($comment_ids as $comment_id) {
                $comment_id = (int) $comment_id;
                $comment = get_comment($comment_id);
                if ($comment) {
                    $product_ids[(int) $comment->comment_post_ID] = true;
                }

                $previous_status = get_comment_meta($comment_id, self::COMMENT_META_HELD, true);
                delete_comment_meta($comment_id, self::COMMENT_META_HELD);
                wp_set_comment_status($comment_id, self::comment_status_for_restore($previous_status));
            }
        });

        return $product_ids;
    }

    private static function imported_comment_ids() {
        global $wpdb;

        $comment_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT comment_id FROM {$wpdb->commentmeta} WHERE meta_key = %s",
            self::COMMENT_META_REVIEW_ID
        ));

        return array_map('intval', $comment_ids);
    }

    private static function product_ids_with_kiyoh_rating_meta() {
        global $wpdb;

        $product_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key IN (%s, %s)",
            self::META_COUNT,
            self::META_NATIVE_COUNT
        ));

        $indexed = array();
        foreach ($product_ids as $product_id) {
            $indexed[(int) $product_id] = true;
        }

        return $indexed;
    }

    private static function comment_status_for_restore($previous_status) {
        if ($previous_status === '1' || $previous_status === 'approve' || $previous_status === '') {
            return 'approve';
        }

        if ($previous_status === 'spam') {
            return 'spam';
        }

        if ($previous_status === 'trash') {
            return 'trash';
        }

        return 'hold';
    }

    private static function with_suppressed_comment_filters($callback) {
        $previous = self::$suppress_comment_filters;
        self::$suppress_comment_filters = true;

        try {
            return $callback();
        } finally {
            self::$suppress_comment_filters = $previous;
        }
    }

}
