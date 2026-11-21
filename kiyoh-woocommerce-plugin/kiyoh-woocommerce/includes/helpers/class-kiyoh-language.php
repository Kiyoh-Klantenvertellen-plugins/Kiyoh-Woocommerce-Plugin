<?php

/**
 * WordPress / WPML / Polylang locale → Kiyoh invite language codes.
 */
class Kiyoh_Language {

    /**
     * @return array<string, string>
     */
    public static function locale_map() {
        return array(
            'nl_NL' => 'nl',
            'fr_FR' => 'fr', 'fr_CA' => 'fr',
            'de_DE' => 'de', 'de_AT' => 'de', 'de_CH' => 'de',
            'en_US' => 'en', 'en_GB' => 'en', 'en_AU' => 'en', 'en_CA' => 'en', 'en_NZ' => 'en',
            'da_DK' => 'da',
            'hu_HU' => 'hu',
            'bg_BG' => 'bg',
            'ro_RO' => 'ro',
            'hr_HR' => 'hr',
            'ja_JP' => 'ja',
            'es_ES' => 'es', 'es_AR' => 'es', 'es_CL' => 'es', 'es_CO' => 'es', 'es_MX' => 'es', 'es_PE' => 'es', 'es_VE' => 'es',
            'it_IT' => 'it', 'it_CH' => 'it',
            'pt_PT' => 'pt',
            'tr_TR' => 'tr',
            'nb_NO' => 'no', 'nn_NO' => 'no',
            'sv_SE' => 'sv',
            'fi_FI' => 'fi',
            'pt_BR' => 'pt',
            'pl_PL' => 'pl',
            'sl_SI' => 'sl',
            'zh_Hans_CN' => 'zh', 'zh_Hant_HK' => 'zh', 'zh_Hant_TW' => 'zh',
            'zh_CN' => 'zh', 'zh_TW' => 'zh',
            'ru_RU' => 'ru',
            'el_GR' => 'gr',
            'cs_CZ' => 'cs',
            'et_EE' => 'et',
            'lt_LT' => 'lt',
            'lv_LV' => 'lv',
            'sk_SK' => 'sk',
        );
    }

  /**
     * @return string[]
     */
    public static function supported_kiyoh_codes() {
        return array_values(array_unique(array_values(self::locale_map())));
    }

    /**
     * @param string $wp_locale
     * @param string $fallback
     * @return string
     */
    public static function map_wordpress_locale_to_kiyoh_language($wp_locale, $fallback = 'en') {
        $wp_locale = (string) $wp_locale;
        $map = self::locale_map();

        if (isset($map[$wp_locale])) {
            return $map[$wp_locale];
        }

        $language_code = substr($wp_locale, 0, 2);
        if (in_array($language_code, self::supported_kiyoh_codes(), true)) {
            return $language_code;
        }

        return $fallback;
    }

    /**
     * @param string $language_code WPML/Polylang slug or locale.
     * @param string $fallback
     * @return string
     */
    public static function map_order_language_to_kiyoh($language_code, $fallback = 'en') {
        $language_code = trim((string) $language_code);
        if ($language_code === '') {
            return $fallback;
        }

        if (strpos($language_code, '_') !== false || strpos($language_code, '-') !== false) {
            $normalized = str_replace('-', '_', $language_code);
            return self::map_wordpress_locale_to_kiyoh_language($normalized, $fallback);
        }

        $code = strtolower($language_code);
        if (in_array($code, self::supported_kiyoh_codes(), true)) {
            return $code;
        }

        return self::map_wordpress_locale_to_kiyoh_language($code . '_' . strtoupper($code), $fallback);
    }

    /**
     * Language stored on the order by WPML or Polylang.
     *
     * @param WC_Order|object $order
     * @return string
     */
    public static function order_multilingual_language($order) {
        if (!is_object($order)) {
            return '';
        }

        if (method_exists($order, 'get_meta')) {
            $wpml = $order->get_meta('wpml_language');
            if (is_string($wpml) && $wpml !== '') {
                return $wpml;
            }
        }

        if (function_exists('pllwc_get_order_language') && $order instanceof WC_Order) {
            $pll = pllwc_get_order_language($order);
            if (is_string($pll) && $pll !== '') {
                return $pll;
            }
        }

        if (method_exists($order, 'get_id') && function_exists('pll_get_post_language')) {
            $pll = pll_get_post_language((int) $order->get_id(), 'slug');
            if (is_string($pll) && $pll !== '') {
                return $pll;
            }
        }

        return '';
    }

    /**
     * Resolve invite language when auto-detect is enabled.
     *
     * @param WC_Order|object|null $order
     * @param string               $fallback
     * @return string
     */
    public static function resolve_invitation_language($order, $fallback = 'en') {
        $fallback = self::sanitize_kiyoh_language($fallback, 'en');

        if (is_object($order)) {
            $order_lang = self::order_multilingual_language($order);
            if ($order_lang !== '') {
                return self::map_order_language_to_kiyoh($order_lang, $fallback);
            }
        }

        if (is_object($order) && method_exists($order, 'get_customer_id')) {
            $customer_id = (int) $order->get_customer_id();
            if ($customer_id > 0 && function_exists('get_user_locale')) {
                $user_locale = get_user_locale($customer_id);
                if ($user_locale) {
                    return self::map_wordpress_locale_to_kiyoh_language($user_locale, $fallback);
                }
            }
        }

        if (function_exists('determine_locale')) {
            $request_locale = determine_locale();
            if ($request_locale) {
                return self::map_wordpress_locale_to_kiyoh_language($request_locale, $fallback);
            }
        }

        if (function_exists('get_locale')) {
            $site_locale = get_locale();
            if ($site_locale) {
                return self::map_wordpress_locale_to_kiyoh_language($site_locale, $fallback);
            }
        }

        return $fallback;
    }

    /**
     * @param string $code
     * @param string $default
     * @return string
     */
    public static function sanitize_kiyoh_language($code, $default = 'en') {
        $code = strtolower((string) $code);
        if (function_exists('sanitize_key')) {
            $code = sanitize_key($code);
        } else {
            $code = preg_replace('/[^a-z0-9_\-]/', '', $code);
        }
        if ($code === '' || !in_array($code, self::supported_kiyoh_codes(), true)) {
            return $default;
        }

        return $code;
    }
}