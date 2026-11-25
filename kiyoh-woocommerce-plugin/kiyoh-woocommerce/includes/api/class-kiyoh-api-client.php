<?php

class Kiyoh_Api_Client implements Kiyoh_Api_Interface {

    /** @var int Items per publication API page (Kiyoh uses start + limit query params). */
    const PUBLICATION_PAGE_LIMIT = 100;

    /** @var int Safety cap so a misbehaving API cannot loop forever. */
    const PUBLICATION_MAX_PAGES = 100;

    private $base_url;
    private $api_token;
    private $location_id;
    private $platform;
    private $connect_refreshed = false;
    
    public function __construct($platform, $api_token, $location_id) {
        $this->platform = $platform;
        $this->api_token = $api_token;
        $this->location_id = $location_id;
        
        $this->base_url = $this->get_base_url($platform);
    }
    
    private function get_base_url($platform) {
        switch ($platform) {
            case 'kiyoh':
                $url = 'https://www.kiyoh.com';
                break;
            case 'klantenvertellen':
                $url = 'https://www.klantenvertellen.nl';
                break;
            default:
                throw new InvalidArgumentException('Unsupported platform: ' . $platform);
        }

        /**
         * Override the API host. Default is the live Kiyoh or Klantenvertellen host.
         * Used by local test installs to point at a mock; production callers leave it alone.
         *
         * @param string $url      Base URL without a trailing path.
         * @param string $platform kiyoh|klantenvertellen
         */
        return apply_filters('kiyoh_api_base_url', $url, $platform);
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

        // The bulk endpoint validates location_id on BOTH the envelope AND every
        // product object. Missing per-product location_id triggers a
        // VALIDATION_ERROR (REQUIRED_FIELD) that rejects the entire batch, so we
        // must ensure each product carries it. (Verified against the live API.)
        $products = array();
        foreach ($products_data as $product) {
            if (!isset($product['location_id']) || $product['location_id'] === '') {
                $product['location_id'] = (string) $this->location_id;
            }
            $products[] = $product;
        }

        $payload = array(
            'location_id' => (string) $this->location_id,
            'products' => $products
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
        $pages = array();
        $start = 0;

        for ($page = 0; $page < self::PUBLICATION_MAX_PAGES; $page++) {
            $params = $this->build_review_query_params($query_params, $start, self::PUBLICATION_PAGE_LIMIT);
            $url = $endpoint . '?' . http_build_query($params);
            $page_result = $this->read_publication_page($this->make_request('GET', $url));
            if (isset($page_result['error'])) {
                return $page_result['error'];
            }

            $data = $page_result['data'];
            $count = $page_result['count'];
            if ($count === 0) {
                break;
            }

            $pages[] = $data;
            if ($count < self::PUBLICATION_PAGE_LIMIT) {
                break;
            }

            $start += self::PUBLICATION_PAGE_LIMIT;
        }

        return new Kiyoh_Api_Response(array(
            'success' => true,
            'data' => $this->merge_publication_pages($pages),
            'response_code' => 200,
        ));
    }

    /**
     * Products that received new reviews since a UTC timestamp.
     * Returns cluster-level averageRating / numberReviews without review bodies.
     *
     * @param string $updated_since UTC, e.g. 2020-01-15T01:00:00.000Z
     */
    public function get_updated_products($updated_since) {
        $paths = array(
            '/v1/publication/product',
            '/v1/publication/product/external',
        );
        $path_index = 0;
        $pages = array();
        $start = 0;

        for ($page = 0; $page < self::PUBLICATION_MAX_PAGES; $page++) {
            $params = array(
                'locationId' => $this->location_id,
                'updatedSince' => $updated_since,
                'start' => $start,
                'limit' => self::PUBLICATION_PAGE_LIMIT,
            );
            $query = http_build_query($params);
            $response = $this->make_request('GET', $paths[$path_index] . '?' . $query);

            if (!$response['success'] && $page === 0 && $path_index === 0
                && isset($response['response_code']) && (int) $response['response_code'] === 404) {
                $path_index = 1;
                $response = $this->make_request('GET', $paths[$path_index] . '?' . $query);
            }

            $page_result = $this->read_publication_page($response);
            if (isset($page_result['error'])) {
                return $page_result['error'];
            }

            $data = $page_result['data'];
            $count = $page_result['count'];
            if ($count === 0) {
                break;
            }

            $pages[] = $data;
            if ($count < self::PUBLICATION_PAGE_LIMIT) {
                break;
            }

            $start += self::PUBLICATION_PAGE_LIMIT;
        }

        return new Kiyoh_Api_Response(array(
            'success' => true,
            'data' => $this->merge_publication_pages($pages),
            'response_code' => 200,
        ));
    }
    
    public function get_company_stats() {
        $endpoint = '/v1/publication/review/external/location/statistics';
        $params = array('locationId' => $this->location_id);
        $url = $endpoint . '?' . http_build_query($params);

        // The connection test must judge the saved key. A 401 refresh would
        // swap in the Connect key and hide a rejected manual key.
        $response = $this->make_request('GET', $url, null, false);
        return new Kiyoh_Api_Response($response);
    }

    /**
     * @param int $http_code
     * @param mixed $data
     * @return array{ok:bool,message:string}
     */
    public static function assess_statistics_payload($http_code, $data) {
        $http_code = (int) $http_code;
        $error_code = (is_array($data) && isset($data['errorCode'])) ? (string) $data['errorCode'] : '';
        if ($http_code === 401 || $http_code === 403 || $error_code === 'ERROR_401' || $error_code === 'UNAUTHORIZED') {
            return array(
                'ok' => false,
                'message' => 'The API key was rejected.',
            );
        }
        if ($http_code >= 200 && $http_code < 300 && is_array($data) && (array_key_exists('numberReviews', $data) || array_key_exists('averageRating', $data))) {
            return array(
                'ok' => true,
                'message' => 'API connection successful',
            );
        }
        return array(
            'ok' => false,
            'message' => 'API connection failed.',
        );
    }

    /**
     * Shop (location) reviews and aggregates from the Review Publishing API.
     *
     * @see https://ekomi01.atlassian.net/wiki/spaces/KB/pages/3130327053/KV+Kiyoh+Publishing+API
     *
     * @param array $query_params limit, orderBy, sortOrder, updatedSince, locale, etc.
     */
    public function get_shop_reviews($query_params = array()) {
        $endpoint = '/v1/publication/review/external';

        $params = array(
            'locationId' => $this->location_id,
            'tenantId' => $this->get_tenant_id(),
        );

        if (!empty($query_params['limit'])) {
            $params['limit'] = (int) $query_params['limit'];
        }

        if (!empty($query_params['order_by'])) {
            $params['orderBy'] = $query_params['order_by'];
        }

        if (!empty($query_params['sort_order'])) {
            $params['sortOrder'] = $query_params['sort_order'];
        }

        if (!empty($query_params['updated_since'])) {
            $params['updatedSince'] = $query_params['updated_since'];
        }

        if (!empty($query_params['locale'])) {
            $params['locale'] = $query_params['locale'];
        }

        $url = $endpoint . '?' . http_build_query($params);
        $response = $this->make_request('GET', $url);

        return new Kiyoh_Api_Response($response);
    }

    private function get_tenant_id() {
        $account = get_option('kiyoh_connect_account', array());
        if (is_array($account) && !empty($account['tenant_id'])) {
            $tenant = (int) $account['tenant_id'];
            if ($tenant === 98 || $tenant === 99) {
                return $tenant;
            }
        }

        return $this->platform === 'klantenvertellen' ? 99 : 98;
    }
    
    private function make_request($method, $endpoint, $data = null, $refresh_on_unauthorized = true) {
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

        if ((int) $response_code === 401 && $refresh_on_unauthorized && !$this->connect_refreshed) {
            $this->connect_refreshed = true;
            if ($this->try_refresh_connect_credentials()) {
                return $this->make_request($method, $endpoint, $data);
            }
        }
        
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

            // Capture the full detailedError array (not just the first entry) so
            // the real cause behind a generic 500 (e.g. INVALID_HASH) is not lost.
            $detailed_errors = array();
            if (isset($error_data['detailedError']) && is_array($error_data['detailedError'])) {
                $detailed_errors = $error_data['detailedError'];
                foreach ($error_data['detailedError'] as $detailed_error) {
                    if (isset($detailed_error['errorCode'])) {
                        $error_code = $detailed_error['errorCode'];
                        if (isset($detailed_error['message'])) {
                            $error_message = $detailed_error['message'];
                        }
                        break; // Use the first detailed error code as the primary code
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
                'response_code' => $response_code,
                'detailed_errors' => $detailed_errors,
                'raw_body' => $response_body
            );
        }
    }
    
    /**
     * A 2xx body is a page only when it is JSON for a publication document.
     * Auth errors, HTML, and error documents must not end the loop as "no rows".
     *
     * @param array $response make_request result.
     * @return array{error?:Kiyoh_Api_Response,data?:mixed,count?:int}
     */
    private function read_publication_page($response) {
        if (empty($response['success'])) {
            return array('error' => new Kiyoh_Api_Response($response));
        }

        $data = isset($response['data']) ? $response['data'] : null;
        $rejection = $this->publication_page_rejection($data);
        if ($rejection !== null) {
            return array('error' => new Kiyoh_Api_Response(array(
                'success' => false,
                'error' => $rejection['error'],
                'error_code' => $rejection['error_code'],
                'response_code' => isset($response['response_code']) ? $response['response_code'] : null,
            )));
        }

        return array(
            'data' => $data,
            'count' => $this->count_publication_page_items($data),
        );
    }

    /**
     * @param mixed $data Decoded 2xx body.
     * @return array{error:string,error_code:string}|null
     */
    private function publication_page_rejection($data) {
        if (!is_array($data)) {
            return array(
                'error' => 'Kiyoh returned a response that was not a review page.',
                'error_code' => 'invalid_publication_page',
            );
        }

        if ($data === array()
            || array_key_exists('reviews', $data)
            || array_key_exists('clusters', $data)
            || isset($data['locationProduct'])
            || isset($data['averageRating'])
            || isset($data['numberReviews'])
            || isset($data['productCode'])
            || isset($data['clusterId'])
            || self::is_list_array($data)) {
            return null;
        }

        $error_code = '';
        if (isset($data['errorCode']) && is_string($data['errorCode']) && $data['errorCode'] !== '') {
            $error_code = $data['errorCode'];
        } elseif (!empty($data['detailedError'][0]['errorCode']) && is_string($data['detailedError'][0]['errorCode'])) {
            $error_code = $data['detailedError'][0]['errorCode'];
        } elseif (isset($data['error']) && is_string($data['error']) && $data['error'] !== '') {
            $error_code = $data['error'];
        }

        $message = '';
        if (isset($data['message']) && is_string($data['message'])) {
            $message = $data['message'];
        }

        $status = 0;
        if (isset($data['status']) && is_numeric($data['status'])) {
            $status = (int) $data['status'];
        }

        $has_error_key = $error_code !== ''
            || !empty($data['detailedError'])
            || !empty($data['errors'])
            || (isset($data['error']) && $data['error'] !== '' && $data['error'] !== array());

        if (!$has_error_key && $message === '' && $status < 400 && (!array_key_exists('success', $data) || $data['success'] !== false)) {
            return null;
        }

        if ($message === '') {
            $message = 'Kiyoh returned an error instead of a review page.';
        }

        return array(
            'error' => $message,
            'error_code' => $error_code !== '' ? $error_code : 'api_error',
        );
    }

    /**
     * PHP 7.4-compatible list check (array_is_list() is PHP 8.1+).
     *
     * @param array $data
     * @return bool
     */
    private static function is_list_array($data) {
        if (!is_array($data)) {
            return false;
        }
        if (function_exists('array_is_list')) {
            return array_is_list($data);
        }
        $i = 0;
        foreach ($data as $key => $_) {
            if ($key !== $i) {
                return false;
            }
            $i++;
        }
        return true;
    }

    /**
     * @param array $query_params
     * @param int   $start
     * @param int   $limit
     * @return array
     */
    private function build_review_query_params($query_params, $start, $limit) {
        $params = array(
            'locationId' => $this->location_id,
            'start' => max(0, (int) $start),
            'limit' => max(1, (int) $limit),
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

        return $params;
    }

    /**
     * @param mixed $data
     * @return int
     */
    private function count_publication_page_items($data) {
        if (!is_array($data)) {
            return 0;
        }

        if (!empty($data['clusters']) && is_array($data['clusters'])) {
            return count($data['clusters']);
        }

        if (!empty($data['reviews']) && is_array($data['reviews'])) {
            return count($data['reviews']);
        }

        if (isset($data['locationProduct']) || isset($data['averageRating']) || isset($data['productCode'])) {
            return 1;
        }

        return 0;
    }

    /**
     * @param array<int, array> $pages
     * @return array
     */
    private function merge_publication_pages(array $pages) {
        if (empty($pages)) {
            return array();
        }

        if (count($pages) === 1) {
            return $pages[0];
        }

        $merged_clusters = array();
        $merged_reviews = array();

        foreach ($pages as $data) {
            if (!is_array($data)) {
                continue;
            }

            if (!empty($data['clusters']) && is_array($data['clusters'])) {
                $merged_clusters = array_merge($merged_clusters, $data['clusters']);
                continue;
            }

            if (!empty($data['reviews']) && is_array($data['reviews']) && empty($data['productCode']) && empty($data['clusterId'])) {
                $merged_reviews = array_merge($merged_reviews, $data['reviews']);
                continue;
            }

            $merged_clusters[] = $data;
        }

        if (!empty($merged_clusters) && !empty($merged_reviews)) {
            return array(
                'clusters' => $merged_clusters,
                'reviews' => $merged_reviews,
            );
        }

        if (!empty($merged_clusters)) {
            return array('clusters' => $merged_clusters);
        }

        if (!empty($merged_reviews)) {
            return array('reviews' => $merged_reviews);
        }

        return array();
    }

    private function get_timeout($endpoint) {
        if (strpos($endpoint, '/bulk') !== false) {
            return 30;
        }

        if (strpos($endpoint, '/publication/product') !== false || strpos($endpoint, '/publication/review/external') !== false) {
            return 30;
        }
        
        if (strpos($endpoint, '/invite/') !== false) {
            return 10;
        }
        
        return 5;
    }
    
    /**
     * One Connect refresh after a publication 401. Does nothing for manual keys.
     *
     * @return bool
     */
    private function try_refresh_connect_credentials() {
        if (!class_exists('Kiyoh_Connect_Session')) {
            return false;
        }
        try {
            $fresh = Kiyoh_Connect_Session::refresh_publication_credentials();
        } catch (Throwable $e) {
            return false;
        }
        if (!is_array($fresh) || empty($fresh['api_key']) || empty($fresh['location_id'])) {
            return false;
        }
        $this->api_token = (string) $fresh['api_key'];
        $this->location_id = (string) $fresh['location_id'];
        return true;
    }

    private function log_error($message, $context = array()) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Kiyoh API Error: ' . $message . (empty($context) ? '' : ' - ' . json_encode($context)));
        }
        
        do_action('kiyoh_api_error', $message, $context);
    }
}