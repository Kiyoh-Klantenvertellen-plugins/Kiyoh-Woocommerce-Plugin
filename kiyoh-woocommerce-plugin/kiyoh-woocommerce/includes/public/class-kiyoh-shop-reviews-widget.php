<?php

class Kiyoh_Shop_Reviews_Widget extends WP_Widget {

    public function __construct() {
        parent::__construct(
            'kiyoh_shop_reviews',
            __('Kiyoh shop reviews', 'kiyoh-woocommerce'),
            array(
                'classname' => 'kiyoh-shop-reviews-widget',
                'description' => __('Shows your Kiyoh location reviews, average score, and review count.', 'kiyoh-woocommerce'),
            )
        );
    }

  /**
     * @param array $args
     * @param array $instance
     */
    public function widget($args, $instance) {
        $manager = new Kiyoh_Shop_Reviews_Manager();
        if (!$manager->is_enabled()) {
            return;
        }

        $limit = isset($instance['limit']) ? (int) $instance['limit'] : 5;
        $html = $manager->render_html($limit);
        if ($html === '') {
            return;
        }

        echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        if (!empty($instance['title'])) {
            echo $args['before_title'] . esc_html($instance['title']) . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * @param array $instance
     * @return array
     */
    public function update($new_instance, $old_instance) {
        $instance = array();
        $instance['title'] = sanitize_text_field($new_instance['title'] ?? '');
        $instance['limit'] = max(1, min(50, absint($new_instance['limit'] ?? 5)));
        return $instance;
    }

    /**
     * @param array $instance
     */
    public function form($instance) {
        $title = isset($instance['title']) ? $instance['title'] : __('Customer reviews', 'kiyoh-woocommerce');
        $limit = isset($instance['limit']) ? (int) $instance['limit'] : 5;
        ?>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('title')); ?>"><?php esc_html_e('Title:', 'kiyoh-woocommerce'); ?></label>
            <input class="widefat" id="<?php echo esc_attr($this->get_field_id('title')); ?>" name="<?php echo esc_attr($this->get_field_name('title')); ?>" type="text" value="<?php echo esc_attr($title); ?>" />
        </p>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('limit')); ?>"><?php esc_html_e('Number of reviews:', 'kiyoh-woocommerce'); ?></label>
            <input class="tiny-text" id="<?php echo esc_attr($this->get_field_id('limit')); ?>" name="<?php echo esc_attr($this->get_field_name('limit')); ?>" type="number" min="1" max="50" value="<?php echo esc_attr((string) $limit); ?>" />
        </p>
        <?php
    }
}