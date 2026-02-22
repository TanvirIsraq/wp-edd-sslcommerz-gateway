<?php
if (!defined('ABSPATH')) exit;

class EDD_SSLCommerz_API {
    private $store_id;
    private $store_passwd;
    private $is_sandbox;
    private $base_url;

    public function __construct($store_id, $store_passwd, $is_sandbox = false) {
        $this->store_id = $store_id;
        $this->store_passwd = $store_passwd;
        $this->is_sandbox = $is_sandbox;
        $this->base_url = $is_sandbox ? 
            'https://sandbox.sslcommerz.com/' : 
            'https://securepay.sslcommerz.com/';
    }

    public function init_payment($data) {
        $url = $this->base_url . 'gwprocess/v4/api.php';
        return $this->remote_post($url, $data);
    }

    public function validate_payment($val_id) {
        $url = $this->base_url . 'validator/api/validationserverAPI.php';
        $params = [
            'val_id' => $val_id,
            'store_id' => $this->store_id,
            'store_passwd' => $this->store_passwd,
            'format' => 'json',
            'v' => '1'
        ];
        return $this->remote_get($url, $params);
    }

    public function initiate_refund($data) {
        $url = $this->base_url . 'validator/api/merchantTransIDvalidationAPI.php';
        $params = array_merge([
            'store_id' => $this->store_id,
            'store_passwd' => $this->store_passwd,
            'format' => 'json',
            'v' => '1'
        ], $data);
        return $this->remote_get($url, $params);
    }

    private function remote_post($url, $data) {
        $response = wp_remote_post($url, array(
            'timeout'   => 45,
            'sslverify' => true,
            'body'      => $data,
        ));

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'error' => $response->get_error_message()
            ];
        }

        $http_code = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return [
                'success' => false,
                'error' => 'Invalid JSON response from SSLCommerz'
            ];
        }

        if ($http_code >= 400) {
            return [
                'success' => false,
                'error' => 'HTTP ' . $http_code . ' returned by SSLCommerz',
                'data' => $decoded
            ];
        }

        return [
            'success' => true,
            'data' => $decoded
        ];
    }

    private function remote_get($url, $params) {
        $query = http_build_query($params);
        $full_url = $url . '?' . $query;

        $response = wp_remote_get($full_url, array(
            'timeout'   => 45,
            'sslverify' => true,
        ));

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'error' => $response->get_error_message()
            ];
        }

        $http_code = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return [
                'success' => false,
                'error' => 'Invalid JSON response from SSLCommerz'
            ];
        }

        if ($http_code >= 400) {
            return [
                'success' => false,
                'error' => 'HTTP ' . $http_code . ' returned by SSLCommerz',
                'data' => $decoded
            ];
        }

        return [
            'success' => true,
            'data' => $decoded
        ];
    }
}
