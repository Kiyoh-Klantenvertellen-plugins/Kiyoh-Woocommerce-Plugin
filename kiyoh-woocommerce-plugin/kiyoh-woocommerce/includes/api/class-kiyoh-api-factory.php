<?php

class Kiyoh_Api_Factory {
    
    public static function create_client() {
        $settings = get_option('kiyoh_woocommerce_settings', array());
        
        $platform = isset($settings['general']['platform']) ? $settings['general']['platform'] : 'kiyoh';
        $api_token = isset($settings['general']['api_key']) ? $settings['general']['api_key'] : '';
        $location_id = isset($settings['general']['location_id']) ? $settings['general']['location_id'] : '';
        
        if (empty($api_token) || empty($location_id)) {
            throw new Exception('API credentials not configured');
        }
        
        return new Kiyoh_Api_Client($platform, $api_token, $location_id);
    }
    
    public static function is_configured() {
        $settings = get_option('kiyoh_woocommerce_settings', array());
        
        $api_token = isset($settings['general']['api_key']) ? $settings['general']['api_key'] : '';
        $location_id = isset($settings['general']['location_id']) ? $settings['general']['location_id'] : '';
        
        return !empty($api_token) && !empty($location_id);
    }
}