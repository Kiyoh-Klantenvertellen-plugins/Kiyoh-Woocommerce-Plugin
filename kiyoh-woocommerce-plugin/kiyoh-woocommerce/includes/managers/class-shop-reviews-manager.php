<?php

/**
 * Fetch, cache, and render location (shop) reviews from the Publication API.
 */
class Kiyoh_Shop_Reviews_Manager {

    const TRANSIENT_PREFIX = 'kiyoh_shop_reviews_';
    const CACHE_VERSION = 1;

    private $settings;
    private static $stylesheet_enqueued = false;

    public function __construct() {
        $this->settings = get_option('kiyoh_woocommerce_settings', array());
        $this->init_hooks();
    }

    private function init_hooks() {
        add_shortcode('kiyoh_shop_reviews', array($this, 'shortcode'));
        add_action('widgets_init', array($this, 'register_widget'));
        add_action('init', array($this, 'register_block'));
    }

    public function is_enabled() {
        if (empty($this->settings['general']['enabled'])) {
            return false;
        }

        $frontend = isset($this->settings['frontend']) && is_array($this->settings['frontend'])
            ? $this->settings['frontend']
            : array();

        return !empty($frontend['shop_reviews_enabled']);
    }

    public function register_widget() {
        if (!class_exists('WP_Widget')) {
            return;
        }

        require_once dirname(__DIR__) . '/public/class-kiyoh-shop-reviews-widget.php';
        register_widget('Kiyoh_Shop_Reviews_Widget');
    }

    public function register_block() {
        if (!function_exists('register_block_type')) {
            return;
        }

        wp_register_style(
            'kiyoh-shop-reviews',
            KIYOH_WOOCOMMERCE_PLUGIN_URL . 'assets/css/kiyoh-shop-reviews.css',
            array(),
            KIYOH_WOOCOMMERCE_VERSION
        );

        register_block_type('kiyoh/shop-reviews', array(
            'api_version' => 2,
            'editor_script' => 'kiyoh-shop-reviews-block',
            'render_callback' => array($this, 'render_block'),
            'attributes' => array(
                'limit' => array(
                    'type' => 'number',
                    'default' => 5,
                ),
            ),
        ));

        wp_register_script(
            'kiyoh-shop-reviews-block',
            KIYOH_WOOCOMMERCE_PLUGIN_URL . 'assets/js/kiyoh-shop-reviews-block.js',
            array('wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n'),
            KIYOH_WOOCOMMERCE_VERSION,
            true
        );
    }

    /**
     * @param array $atts
     * @return string
     */
    public function shortcode($atts) {
        $atts = shortcode_atts(array(
            'limit' => $this->default_limit(),
        ), $atts, 'kiyoh_shop_reviews');

        return $this->render_html((int) $atts['limit']);
    }

    /**
     * @param array $attributes
     * @return string
     */
    public function render_block($attributes) {
        $limit = isset($attributes['limit']) ? (int) $attributes['limit'] : $this->default_limit();
        return $this->render_html($limit);
    }

    /**
     * @param int $limit
     * @return string
     */
    public function render_html($limit = 5) {
        if (!$this->is_enabled()) {
            return '';
        }

        $limit = max(1, min(50, (int) $limit));
        $bundle = $this->get_reviews_bundle($limit);
        if (empty($bundle['reviews']) && (float) $bundle['average'] <= 0 && (int) $bundle['count'] <= 0) {
            return '';
        }

        $this->enqueue_styles();

        ob_start();
        ?>
        <div class="kiyoh-shop-reviews" data-limit="<?php echo esc_attr((string) $limit); ?>">
            <div class="kiyoh-shop-reviews__summary">
                <span class="kiyoh-shop-reviews__stars" aria-hidden="true"><?php echo esc_html($this->star_string($bundle['average'])); ?></span>
                <span class="kiyoh-shop-reviews__score">
                    <?php
                    printf(
                        /* translators: 1: average score out of 10, 2: review count */
                        esc_html__('%1$s / 10 (%2$s reviews)', 'kiyoh-woocommerce'),
                        esc_html(number_format_i18n($bundle['average'], 1)),
                        esc_html(number_format_i18n($bundle['count']))
                    );
                    ?>
                </span>
            </div>
            <ol class="kiyoh-shop-reviews__list">
                <?php foreach ($bundle['reviews'] as $review) : ?>
                    <li class="kiyoh-shop-reviews__item">
                        <div class="kiyoh-shop-reviews__item-header">
                            <strong class="kiyoh-shop-reviews__author"><?php echo esc_html($review['author']); ?></strong>
                            <span class="kiyoh-shop-reviews__item-stars" aria-hidden="true"><?php echo esc_html($this->star_string($review['rating'])); ?></span>
                        </div>
                        <?php if ($review['title'] !== '') : ?>
                            <p class="kiyoh-shop-reviews__title"><?php echo esc_html($review['title']); ?></p>
                        <?php endif; ?>
                        <?php if ($review['body'] !== '') : ?>
                            <p class="kiyoh-shop-reviews__body"><?php echo esc_html($review['body']); ?></p>
                        <?php endif; ?>
                        <?php if ($review['date'] !== '') : ?>
                            <time class="kiyoh-shop-reviews__date" datetime="<?php echo esc_attr($review['date_iso']); ?>"><?php echo esc_html($review['date']); ?></time>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ol>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * @param int $limit
     * @return array{average: float, count: int, reviews: array<int, array{author: string, rating: float, title: string, body: string, date: string, date_iso: string}>}
     */
    public function get_reviews_bundle($limit = 5) {
        $limit = max(1, min(50, (int) $limit));
        $cache_key = $this->cache_key($limit);
        $cached = get_transient($cache_key);
        if (is_array($cached) && isset($cached['v']) && (int) $cached['v'] === self::CACHE_VERSION) {
            return $cached['data'];
        }

        $fresh = $this->fetch_from_api($limit);
        set_transient($cache_key, array(
            'v' => self::CACHE_VERSION,
            'data' => $fresh,
        ), $this->cache_ttl());

        return $fresh;
    }

    /**
     * @param array $payload
     * @return array{average: float, count: int, reviews: array<int, array{author: string, rating: float, title: string, body: string, date: string, date_iso: string}>}
     */
    public function normalize_publication_payload($payload, $limit = 5) {
        $limit = max(1, min(50, (int) $limit));
        if (!is_array($payload)) {
            return $this->empty_bundle();
        }

        $average = 0.0;
        if (isset($payload['averageRating'])) {
            $average = (float) $payload['averageRating'];
        } elseif (isset($payload['statistics']['averageRating'])) {
            $average = (float) $payload['statistics']['averageRating'];
        }

        $count = 0;
        if (isset($payload['numberReviews'])) {
            $count = (int) $payload['numberReviews'];
        } elseif (isset($payload['statistics']['numberReviews'])) {
            $count = (int) $payload['statistics']['numberReviews'];
        }

        $reviews_raw = array();
        if (!empty($payload['reviews']) && is_array($payload['reviews'])) {
            $reviews_raw = $payload['reviews'];
        }

        $normalized_reviews = array();
        foreach (array_slice($reviews_raw, 0, $limit) as $review) {
            if (!is_array($review)) {
                continue;
            }
            $normalized = $this->normalize_review_row($review);
            if ($normalized !== null) {
                $normalized_reviews[] = $normalized;
            }
        }

        if ($count <= 0 && !empty($normalized_reviews)) {
            $count = count($normalized_reviews);
        }

        if ($average <= 0 && !empty($normalized_reviews)) {
            $sum = 0.0;
            foreach ($normalized_reviews as $row) {
                $sum += (float) $row['rating'];
            }
            $average = $sum / max(1, count($normalized_reviews));
        }

        return array(
            'average' => round($average, 1),
            'count' => $count,
            'reviews' => $normalized_reviews,
        );
    }

    /**
     * @return array{average: float, count: int, reviews: array<int, array{author: string, rating: float, title: string, body: string, date: string, date_iso: string}>}
     */
    private function fetch_from_api($limit) {
        if (!Kiyoh_Api_Factory::is_configured()) {
            return $this->empty_bundle();
        }

        try {
            $client = Kiyoh_Api_Factory::create_client();
            $response = $client->get_shop_reviews(array('limit' => $limit));
            if (!$response->is_success()) {
                return $this->empty_bundle();
            }

            return $this->normalize_publication_payload($response->get_data(), $limit);
        } catch (Exception $e) {
            return $this->empty_bundle();
        }
    }

    /**
     * @param array $review
     * @return array{author: string, rating: float, title: string, body: string, date: string, date_iso: string}|null
     */
    private function normalize_review_row($review) {
        $rating = null;
        if (isset($review['rating']) && is_numeric($review['rating'])) {
            $rating = (float) $review['rating'];
        }

        $title = '';
        if (!empty($review['oneliner'])) {
            $title = trim((string) $review['oneliner']);
        }

        $body = '';
        if (!empty($review['description'])) {
            $body = trim((string) $review['description']);
        }

        if ($title !== '' && $body === $title) {
            $body = '';
        }

        if ($rating === null && $title === '' && $body === '') {
            return null;
        }

        $author = !empty($review['reviewAuthor']) ? sanitize_text_field($review['reviewAuthor']) : __('Kiyoh reviewer', 'kiyoh-woocommerce');

        $date_iso = '';
        if (!empty($review['dateSince'])) {
            $date_iso = gmdate('Y-m-d', strtotime((string) $review['dateSince']));
        }

        $date_label = $date_iso;
        if ($date_iso !== '' && function_exists('date_i18n')) {
            $date_label = date_i18n(get_option('date_format'), strtotime($date_iso . ' 00:00:00 UTC'));
        }

        return array(
            'author' => $author,
            'rating' => $rating === null ? 0.0 : $rating,
            'title' => $title,
            'body' => $body,
            'date' => $date_label,
            'date_iso' => $date_iso,
        );
    }

    /**
     * @return array{average: float, count: int, reviews: array<int, mixed>}
     */
    private function empty_bundle() {
        return array(
            'average' => 0.0,
            'count' => 0,
            'reviews' => array(),
        );
    }

    private function cache_key($limit) {
        $location = isset($this->settings['general']['location_id']) ? (string) $this->settings['general']['location_id'] : '0';
        return self::TRANSIENT_PREFIX . md5($location . '|' . (int) $limit);
    }

    private function cache_ttl() {
        $frontend = isset($this->settings['frontend']) && is_array($this->settings['frontend'])
            ? $this->settings['frontend']
            : array();
        $ttl = isset($frontend['cache_ttl']) ? (int) $frontend['cache_ttl'] : 3600;
        return max(300, min(DAY_IN_SECONDS, $ttl));
    }

    private function default_limit() {
        $frontend = isset($this->settings['frontend']) && is_array($this->settings['frontend'])
            ? $this->settings['frontend']
            : array();
        $limit = isset($frontend['shop_reviews_limit']) ? (int) $frontend['shop_reviews_limit'] : 5;
        return max(1, min(50, $limit));
    }

    /**
     * @param float $kiyoh_rating 1-10 scale
     */
    private function star_string($kiyoh_rating) {
        $wc = class_exists('Kiyoh_Rating_Manager')
            ? Kiyoh_Rating_Manager::to_woocommerce_rating($kiyoh_rating)
            : min(5, max(0, $kiyoh_rating / 2));

        $full = (int) floor($wc);
        $half = ($wc - $full) >= 0.5 ? 1 : 0;
        $empty = 5 - $full - $half;

        return str_repeat('★', $full) . ($half ? '½' : '') . str_repeat('☆', max(0, $empty));
    }

    private function enqueue_styles() {
        if (self::$stylesheet_enqueued) {
            return;
        }
        self::$stylesheet_enqueued = true;

        wp_enqueue_style(
            'kiyoh-shop-reviews',
            KIYOH_WOOCOMMERCE_PLUGIN_URL . 'assets/css/kiyoh-shop-reviews.css',
            array(),
            KIYOH_WOOCOMMERCE_VERSION
        );
    }
}