<?php

interface Kiyoh_Api_Interface {
    
    public function sync_product($product_data);
    
    public function sync_products_bulk($products_data);
    
    public function send_invitation($invitation_data);
    
    public function get_reviews($query_params = array());
    
    public function get_company_stats();
}