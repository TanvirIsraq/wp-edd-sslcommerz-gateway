<?php
if (!defined('ABSPATH')) exit;

/**
 * Build SSLCommerz success URL
 */
function edd_sslcommerz_success_url($payment_id) {
    return add_query_arg([
        'edd-listener' => 'sslcommerz_return',
        'edd-sslcz-pid' => $payment_id,
        'status' => 'success'
    ], home_url('/'));
}

/**
 * Build SSLCommerz fail URL
 */
function edd_sslcommerz_fail_url($payment_id) {
    return add_query_arg([
        'edd-listener' => 'sslcommerz_return',
        'edd-sslcz-pid' => $payment_id,
        'status' => 'fail'
    ], home_url('/'));
}

/**
 * Build SSLCommerz cancel URL
 */
function edd_sslcommerz_cancel_url($payment_id) {
    return add_query_arg([
        'edd-listener' => 'sslcommerz_return',
        'edd-sslcz-pid' => $payment_id,
        'status' => 'cancel'
    ], home_url('/'));
}

/**
 * Build SSLCommerz IPN URL
 */
function edd_sslcommerz_ipn_url() {
    return add_query_arg([
        'edd-listener' => 'sslcommerz_ipn'
    ], home_url('/'));
}

/**
 * Get comma-separated product names from purchase data
 */
function edd_sslcommerz_get_product_names($purchase_data) {
    $names = [];
    foreach ($purchase_data['cart_details'] as $item) {
        $names[] = $item['name'];
    }
    return implode(', ', $names);
}

/**
 * Debug logger for SSLCommerz gateway
 */
function edd_sslcommerz_log($message, $context = array()) {
    if (!function_exists('edd_sslcommerz_get_option') || !edd_sslcommerz_get_option('debug_log', false)) {
        return;
    }

    $prefix = '[EDD SSLCommerz] ';
    $line = $prefix . $message;

    if (!empty($context)) {
        $line .= ' ' . wp_json_encode($context);
    }

    error_log($line); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
}
