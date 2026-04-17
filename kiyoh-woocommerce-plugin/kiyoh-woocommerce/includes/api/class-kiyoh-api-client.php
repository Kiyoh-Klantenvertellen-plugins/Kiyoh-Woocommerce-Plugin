<?php

class Kiyoh_Api_Client implements Kiyoh_Api_Interface {
    
    private $base_url;
    private $api_token;
    private $location_id;
    private $platform;
    
    public function __construct($platform, $api_token, $location_id) {
        $this->platform = $platform;
        $this->api_token = $api_token;
        $this->location_id = $location_id;
        
        $this->base_url = $this->get_base_url($platform);
    }
    
    private function get_base_url($platform) {
        switch ($platform) {
            case 'kiyoh':
                return 'https://www.kiyoh.com';
            case 'klantenvertellen':
                return 'https://www.klantenvertellen.nl';
            default:
                throw new InvalidArgumentException('Unsupported platform: ' . $platform);
        }
    }
    
    public function sync_product($product_data) {
        $endpoint = '/v1/location/product/external';
        
        $payload = array(
            'location_id' => (string) $this->location_id,
            'product_code' => (string) $product_data['product_code'],
            'product_name' => (string) $product_data['product_name'],
            'source_url' => $product_data['source_url'],
            'image_url' => $product_data['image_url'],
            'active' => isset($product_data['active']) ? (bool) $product_data['active'] : true
        );
        
        // Only add optional fields if they exist and are not empty
        if (!empty($product_data['brand_name'])) {
            $payload['brand_name'] = (string) $product_data['brand_name'];
        }
        
        if (!empty($product_data['skus'])) {
            $payload['skus'] = (string) $product_data['skus'];
        }
        
        if (!empty($product_data['gtins'])) {
            $payload['gtins'] = (string) $product_data['gtins'];
        }
        
        if (!empty($product_data['mpns'])) {
            $payload['mpns'] = (string) $product_data['mpns'];
        }
        
        $response = $this->make_request('PUT', $endpoint, $payload);
        return new Kiyoh_Api_Response($response);
    }
    
    public function sync_products_bulk($products_data) {
        $endpoint = '/v1/location/product/external/bulk';
        
        $payload = array(
            'location_id' => (string) $this->location_id,
            'products' => $products_data
        );
        
        $response = $this->make_request('PUT', $endpoint, $payload);
        return new Kiyoh_Api_Response($response);
    }
    
    public function send_invitation($invitation_data) {
        $endpoint = '/v1/invite/external';
        
        // Validate required fields
        if (empty($invitation_data['invite_email'])) {
            $this->log_error('Invitation failed: Missing or empty email address', $invitation_data);
            return new Kiyoh_Api_Response(array(
                'success' => false,
                'error' => 'Missing or empty email address',
                'error_code' => 'validation_error'
            ));
        }
        
        $payload = array(
            'location_id' => (string) $this->location_id,
            'invite_email' => $invitation_data['invite_email'],
            'delay' => (int) $invitation_data['delay'],
            'language' => $invitation_data['language']
        );
        
        if (!empty($invitation_data['first_name'])) {
            $payload['first_name'] = $invitation_data['first_name'];
        }
        
        if (!empty($invitation_data['last_name'])) {
            $payload['last_name'] = $invitation_data['last_name'];
        }
        
        if (!empty($invitation_data['city'])) {
            $payload['city'] = $invitation_data['city'];
        }
        
        if (!empty($invitation_data['reference_code'])) {
            $payload['reference_code'] = $invitation_data['reference_code'];
        }
        
        if (!empty($invitation_data['product_code'])) {
            if (is_array($invitation_data['product_code'])) {
                $payload['product_code'] = array_values($invitation_data['product_code']);
            } else {
                $payload['product_code'] = array($invitation_data['product_code']);
            }
        }
        
        if (isset($invitation_data['product_invite'])) {
            $payload['product_invite'] = (bool) $invitation_data['product_invite'];
        }
        
        $response = $this->make_request('POST', $endpoint, $payload);
        return new Kiyoh_Api_Response($response);
    }
    
    public function get_reviews($query_params = array()) {
        $endpoint = '/v1/publication/product/review/external';
        
        $params = array(
            'locationId' => $this->location_id
        );
        
        if (!empty($query_params['product_code'])) {
            $params['productCode'] = $query_params['product_code'];
        }
        
        if (!empty($query_params['cluster_id'])) {
            $params['clusterId'] = $query_params['cluster_id'];
        }
        
        if (!empty($query_params['updated_since'])) {
            $params['updatedSince'] = $query_params['updated_since'];
        }
        
        $url = $endpoint . '?' . http_build_query($params);
        
        $response = $this->make_request('GET', $url);
        return new Kiyoh_Api_Response($response);
    }
    
    public function get_company_stats() {
        $endpoint = '/v1/publication/review/external/location/statistics';
        $params = array('locationId' => $this->location_id);
        $url = $endpoint . '?' . http_build_query($params);
        
        $response = $this->make_request('GET', $url);
        return new Kiyoh_Api_Response($response);
    }
    
    private function make_request($method, $endpoint, $data = null) {
        $url = $this->base_url . $endpoint;
        
        $args = array(
            'method' => $method,
            'headers' => array(
                'X-Publication-Api-Token' => $this->api_token,
                'Content-Type' => 'application/json'
            ),
            'timeout' => $this->get_timeout($endpoint)
        );
        
        if ($data && in_array($method, array('POST', 'PUT'))) {
            $args['body'] = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            
            // Debug log the actual JSON being sent
            if (defined('WP_DEBUG') && WP_DEBUG && strpos($endpoint, '/invite/') !== false) {
                error_log('Kiyoh API: JSON payload being sent: ' . $args['body']);
            }
        }
        
        $response = wp_remote_request($url, $args);
        
        if (is_wp_error($response)) {
            $this->log_error('HTTP Error: ' . $response->get_error_message(), array(
                'url' => $url,
                'method' => $method,
                'data' => $data
            ));
            
            return array(
                'success' => false,
                'error' => $response->get_error_message(),
                'error_code' => 'http_error'
            );
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        if ($response_code >= 200 && $response_code < 300) {
            $decoded_body = json_decode($response_body, true);
            
            return array(
                'success' => true,
                'data' => $decoded_body,
                'response_code' => $response_code
            );
        } else {
            $error_data = json_decode($response_body, true);
            $error_message = isset($error_data['message']) ? $error_data['message'] : 'API request failed';
            $error_code = 'api_error';
            
            // Parse detailed error structure to extract specific error codes
            if (isset($error_data['detailedError']) && is_array($error_data['detailedError'])) {
                foreach ($error_data['detailedError'] as $detailed_error) {
                    if (isset($detailed_error['errorCode'])) {
                        $error_code = $detailed_error['errorCode'];
                        if (isset($detailed_error['message'])) {
                            $error_message = $detailed_error['message'];
                        }
                        break; // Use the first detailed error code
                    }
                }
            }
            
            $this->log_error('API Error: ' . $error_message, array(
                'url' => $url,
                'method' => $method,
                'response_code' => $response_code,
                'response_body' => $response_body,
                'request_data' => $data
            ));
            
            return array(
                'success' => false,
                'error' => $error_message,
                'error_code' => $error_code,
                'response_code' => $response_code
            );
        }
    }
    
    private function get_timeout($endpoint) {
        if (strpos($endpoint, '/bulk') !== false) {
            return 30;
        }
        
        if (strpos($endpoint, '/invite/') !== false) {
            return 10;
        }
        
        return 5;
    }
    
    private function log_error($message, $context = array()) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Kiyoh API Error: ' . $message . (empty($context) ? '' : ' - ' . json_encode($context)));
        }
        
        do_action('kiyoh_api_error', $message, $context);
    }
}