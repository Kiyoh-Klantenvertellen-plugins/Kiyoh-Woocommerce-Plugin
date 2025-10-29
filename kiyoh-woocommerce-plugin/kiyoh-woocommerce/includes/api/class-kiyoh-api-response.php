<?php

class Kiyoh_Api_Response {
    
    private $success;
    private $data;
    private $error;
    private $error_code;
    private $response_code;
    
    public function __construct($response_data) {
        $this->success = isset($response_data['success']) ? $response_data['success'] : false;
        $this->data = isset($response_data['data']) ? $response_data['data'] : null;
        $this->error = isset($response_data['error']) ? $response_data['error'] : null;
        $this->error_code = isset($response_data['error_code']) ? $response_data['error_code'] : null;
        $this->response_code = isset($response_data['response_code']) ? $response_data['response_code'] : null;
    }
    
    public function is_success() {
        return $this->success;
    }
    
    public function get_data() {
        return $this->data;
    }
    
    public function get_error() {
        return $this->error;
    }
    
    public function get_error_code() {
        return $this->error_code;
    }
    
    public function get_response_code() {
        return $this->response_code;
    }
    
    public function is_retryable() {
        if (!$this->success && $this->error_code === 'http_error') {
            return true;
        }
        
        if (!$this->success && $this->response_code >= 500) {
            return true;
        }
        
        if (!$this->success && $this->response_code === 429) {
            return true;
        }
        
        return false;
    }
}