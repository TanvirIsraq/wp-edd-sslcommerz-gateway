<?php
/**
 * Plugin Name: EDD SSLCommerz Gateway
 * Plugin URI: https://github.com/TanvirIsraq/wp-edd-sslcommerz-gateway
 * Description: SSLCommerz payment gateway integration for Easy Digital Downloads.
 * Version: 1.0.0
 * Author: Tanvir Israq
 * Author URI: https://github.com/TanvirIsraq
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: edd-sslcommerz-gateway
 * Domain Path: /languages
 */

// Exit if accessed directly
defined('ABSPATH') || exit;

// Define plugin constants
define('EDD_SSLCOMMERZ_VERSION', '1.0.0');
define('EDD_SSLCOMMERZ_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('EDD_SSLCOMMERZ_PLUGIN_URL', plugin_dir_url(__FILE__));
define('EDD_SSLCOMMERZ_PLUGIN_FILE', __FILE__);

// Check EDD activation
register_activation_hook(__FILE__, function () {
    if (!class_exists('Easy_Digital_Downloads')) {
        // Deactivate plugin if EDD is not active
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            esc_html__('EDD SSLCommerz Gateway requires Easy Digital Downloads to be installed and active.', 'edd-sslcommerz-gateway'),
            esc_html__('Plugin Activation Error', 'edd-sslcommerz-gateway'),
            ['response' => 500, 'back_link' => true]
        );
    }

});

// Include plugin files
$edd_sslcommerz_includes_files = [
    'class-edd-sslcommerz-gateway.php',
    'class-sslcommerz-api.php',
    'class-sslcommerz-ipn-handler.php',
    'functions.php'
];

foreach ($edd_sslcommerz_includes_files as $edd_sslcommerz_include_file) {
    if (file_exists(EDD_SSLCOMMERZ_PLUGIN_DIR . 'includes/' . $edd_sslcommerz_include_file)) {
        require_once EDD_SSLCOMMERZ_PLUGIN_DIR . 'includes/' . $edd_sslcommerz_include_file;
    }
}

/**
 * Process SSLCommerz refund
 */
function edd_sslcommerz_process_refund($payment_id, $new_status, $old_status)
{
    // Only process if new status is "refunded" and gateway is SSLCommerz
    if ($new_status !== 'refunded' || edd_get_payment_gateway($payment_id) !== 'sslcommerz') {
        return;
    }

    // Get bank transaction ID from payment meta
    $bank_tran_id = edd_get_payment_meta($payment_id, '_sslcommerz_bank_tran_id', true);
    if (empty($bank_tran_id)) {
        edd_insert_payment_note(
            $payment_id,
            __('SSLCommerz refund failed: Bank transaction ID not found', 'edd-sslcommerz-gateway')
        );
        return;
    }

    // Get refund amount
    $refund_amount = edd_get_payment_amount($payment_id);

    // Generate unique refund transaction ID
    $refund_trans_id = 'REF-' . $payment_id . '-' . time();

    // Prepare API request data
    $api_data = array(
        'bank_tran_id' => $bank_tran_id,
        'refund_trans_id' => $refund_trans_id,
        'store_id' => edd_sslcommerz_get_option('store_id'),
        'store_passwd' => edd_sslcommerz_get_option('store_passwd'),
        'refund_amount' => $refund_amount,
        /* translators: %d: EDD payment ID. */
        'refund_remarks' => sprintf(__('Refund for EDD payment #%d', 'edd-sslcommerz-gateway'), $payment_id),
        'format' => 'json',
        'v' => '1'
    );

    // Initialize API
    $store_id = edd_sslcommerz_get_option('store_id');
    $store_passwd = edd_sslcommerz_get_option('store_passwd');
    $is_sandbox = edd_sslcommerz_get_option('sandbox', false);
    $api = new EDD_SSLCommerz_API($store_id, $store_passwd, $is_sandbox);

    // Send refund request
    $response = $api->initiate_refund($api_data);

    // Handle response
    $refund_success = !empty($response['success']) &&
        !empty($response['data']) &&
        !empty($response['data']['status']) &&
        strtolower((string) $response['data']['status']) === 'success' &&
        !empty($response['data']['refund_ref_id']);

    if ($refund_success) {
        // Store refund reference ID
        edd_update_payment_meta($payment_id, '_sslcommerz_refund_ref_id', $response['data']['refund_ref_id']);

        // Add payment note
        edd_insert_payment_note(
            $payment_id,
            /* translators: %s: SSLCommerz refund reference ID. */
            sprintf(
                __('SSLCommerz refund initiated. Refund Reference ID: %s', 'edd-sslcommerz-gateway'),
                $response['data']['refund_ref_id']
            )
        );
    } else {
        $error = $response['error'] ?? $response['data']['errorMessage'] ?? __('Unknown error', 'edd-sslcommerz-gateway');
        edd_insert_payment_note(
            $payment_id,
            /* translators: %s: Refund error message. */
            sprintf(__('SSLCommerz refund failed: %s', 'edd-sslcommerz-gateway'), $error)
        );
    }
}

// Hook into EDD's refund processing when status changes to 'refunded'
add_action('edd_post_refund_payment', 'edd_sslcommerz_process_refund', 10, 1);

// Instantiate gateway class to register hooks
new EDD_SSLCommerz_Gateway();

// Setup additional hooks
add_action('edd_gateway_sslcommerz', 'edd_sslcommerz_process_payment');
add_action('init', 'edd_sslcommerz_ipn_listener');
add_action('init', 'edd_sslcommerz_return_handler');
add_action('edd_gateway_sslcommerz_cc_form', '__return_false');
add_action('edd_sslcommerz_cc_form', '__return_false');
add_action('wp_ajax_edd_sslcommerz_init_popup', 'edd_sslcommerz_ajax_init_popup');
add_action('wp_ajax_nopriv_edd_sslcommerz_init_popup', 'edd_sslcommerz_ajax_init_popup');

/**
 * IPN listener router
 */
function edd_sslcommerz_ipn_listener()
{
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- External gateway callback endpoint.
    $listener = isset($_GET['edd-listener']) ? sanitize_text_field(wp_unslash($_GET['edd-listener'])) : '';

    if ($listener !== 'sslcommerz_ipn') {
        return;
    }

    $request_method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper(sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD']))) : 'GET';
    if ($request_method !== 'POST') {
        status_header(405);
        exit;
    }

    $handler = new EDD_SSLCommerz_IPN_Handler();
    $handler->handle();
    exit;
}

/**
 * AJAX handler for popup payment initialization
 */
function edd_sslcommerz_ajax_init_popup()
{
    // Verify nonce
    if (!check_ajax_referer('edd_sslcommerz_popup', 'nonce', false)) {
        wp_send_json_error(['message' => __('Nonce verification failed', 'edd-sslcommerz-gateway')]);
        wp_die();
    }

    // Parse form data
    $raw_form_data = isset($_POST['form_data']) ? sanitize_text_field(wp_unslash($_POST['form_data'])) : '';
    if (empty($raw_form_data)) {
        wp_send_json_error(['message' => __('Missing form data', 'edd-sslcommerz-gateway')]);
        wp_die();
    }

    parse_str($raw_form_data, $form_data);

    // Simulate EDD's process of handling the purchase form
    $_POST = $form_data;
    $purchase_data = edd_build_purchase_data($_POST);

    if ($purchase_data === false) {
        wp_send_json_error(['message' => __('Invalid purchase data', 'edd-sslcommerz-gateway')]);
        wp_die();
    }

    // Record the pending payment
    $payment_data = array(
        'price' => $purchase_data['price'],
        'date' => $purchase_data['date'],
        'user_email' => $purchase_data['user_email'],
        'purchase_key' => $purchase_data['purchase_key'],
        'currency' => edd_get_currency(),
        'downloads' => $purchase_data['downloads'],
        'cart_details' => $purchase_data['cart_details'],
        'user_info' => $purchase_data['user_info'],
        'status' => 'pending',
        'gateway' => 'sslcommerz',
    );

    $payment_id = edd_insert_payment($payment_data);

    if (!$payment_id) {
        wp_send_json_error(['message' => __('Payment creation failed', 'edd-sslcommerz-gateway')]);
        wp_die();
    }

    // Generate unique transaction ID
    $tran_id = 'EDD-' . $payment_id . '-' . time();

    // Update payment meta with transaction ID
    edd_update_payment_meta($payment_id, '_sslcommerz_tran_id', $tran_id);
    edd_sslcommerz_store_tran_payment_map($tran_id, $payment_id);

    // Prepare API request data
    $store_id = edd_sslcommerz_get_option('store_id');
    $store_passwd = edd_sslcommerz_get_option('store_passwd');
    $is_sandbox = edd_sslcommerz_get_option('sandbox', false);

    $api_data = array(
        'store_id' => $store_id,
        'store_passwd' => $store_passwd,
        'total_amount' => $purchase_data['price'],
        'currency' => edd_get_currency(),
        'tran_id' => $tran_id,
        'product_category' => 'Digital Download',
        'product_profile' => 'non-physical-goods',
        'success_url' => edd_sslcommerz_success_url($payment_id),
        'fail_url' => edd_sslcommerz_fail_url($payment_id),
        'cancel_url' => edd_sslcommerz_cancel_url($payment_id),
        'ipn_url' => edd_sslcommerz_ipn_url(),
        'cus_name' => $purchase_data['user_info']['first_name'] . ' ' . $purchase_data['user_info']['last_name'],
        'cus_email' => $purchase_data['user_email'],
        'cus_phone' => isset($purchase_data['user_info']['phone']) ? $purchase_data['user_info']['phone'] : 'N/A',
        'cus_add1' => isset($purchase_data['user_info']['address']['line1']) ? $purchase_data['user_info']['address']['line1'] : 'N/A',
        'cus_city' => isset($purchase_data['user_info']['address']['city']) ? $purchase_data['user_info']['address']['city'] : 'N/A',
        'cus_country' => isset($purchase_data['user_info']['address']['country']) ? $purchase_data['user_info']['address']['country'] : 'BD',
        'shipping_method' => 'NO',
        'product_name' => edd_sslcommerz_get_product_names($purchase_data),
        'num_of_item' => count($purchase_data['cart_details']),
        'value_a' => $payment_id  // store EDD payment ID in custom field
    );

    // Initialize API and send request
    $api = new EDD_SSLCommerz_API($store_id, $store_passwd, $is_sandbox);
    $response = $api->init_payment($api_data);

    // Handle API response
    $is_success = !empty($response['success']) &&
        !empty($response['data']) &&
        !empty($response['data']['status']) &&
        $response['data']['status'] === 'SUCCESS' &&
        !empty($response['data']['GatewayPageURL']);

    if ($is_success) {
        wp_send_json_success([
            'url' => $response['data']['GatewayPageURL']
        ]);
    } else {
        $error_message = $response['data']['failedreason'] ?? __('Payment initialization failed', 'edd-sslcommerz-gateway');

        // Log error
        edd_record_gateway_error(
            __('SSLCommerz Popup Error', 'edd-sslcommerz-gateway'),
            /* translators: 1: EDD payment ID, 2: Error message. */
            sprintf(
                __('Popup payment initiation failed for payment #%1$d. Error: %2$s', 'edd-sslcommerz-gateway'),
                $payment_id,
                $error_message
            ),
            $payment_id
        );

        wp_send_json_error(['message' => $error_message]);
    }
}

/**
 * Enqueue popup scripts when in popup mode
 */
function edd_sslcommerz_enqueue_popup_scripts()
{
    if (!edd_is_checkout() || edd_sslcommerz_get_option('checkout_mode') !== 'popup') {
        return;
    }

    $is_sandbox = edd_sslcommerz_get_option('sandbox', false);
    $embed_url = $is_sandbox
        ? 'https://sandbox.sslcommerz.com/embed.min.js'
        : 'https://seamless-epay.sslcommerz.com/embed.min.js';

    // Enqueue SSLCommerz embed script
    wp_enqueue_script(
        'sslcommerz-embed',
        $embed_url,
        [],
        EDD_SSLCOMMERZ_VERSION,
        true
    );

    // Enqueue custom popup handler
    wp_enqueue_script(
        'edd-sslcommerz-popup',
        EDD_SSLCOMMERZ_PLUGIN_URL . 'assets/js/edd-sslcommerz-popup.js',
        ['jquery', 'sslcommerz-embed'],
        EDD_SSLCOMMERZ_VERSION,
        true
    );

    // Localize script data
    wp_localize_script('edd-sslcommerz-popup', 'eddSSLCommerz', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('edd_sslcommerz_popup'),
        'endpoint' => admin_url('admin-ajax.php?action=edd_sslcommerz_init_popup')
    ]);
}
add_action('wp_enqueue_scripts', 'edd_sslcommerz_enqueue_popup_scripts');

/**
 * Hide card fields when SSLCommerz gateway is selected.
 */
function edd_sslcommerz_hide_cc_fields_script()
{
    if (!function_exists('edd_is_checkout') || !edd_is_checkout()) {
        return;
    }
    ?>
    <script type="text/javascript">
        (function ($) {
            function toggleSSLCommerzCardFields() {
                var selectedGateway = $('input[name="edd-gateway"]:checked').val() || $('input[name="payment-mode"]:checked').val();
                var ccFields = $('#edd_cc_fields');
                if (!ccFields.length) {
                    return;
                }
                if (selectedGateway === 'sslcommerz') {
                    ccFields.hide();
                } else {
                    ccFields.show();
                }
            }
            $(document).on('change', 'input[name="edd-gateway"], input[name="payment-mode"]', toggleSSLCommerzCardFields);
            $(document).ready(toggleSSLCommerzCardFields);
        })(jQuery);
    </script>
    <?php
}
add_action('wp_footer', 'edd_sslcommerz_hide_cc_fields_script', 100);

// Helper function to get settings
function edd_sslcommerz_get_option($key, $default = '')
{
    return edd_get_option('sslcommerz_' . $key, $default);
}

/**
 * Persist tran_id -> payment_id mapping as payment meta.
 *
 * @param string $tran_id    SSLCommerz transaction ID.
 * @param int    $payment_id EDD payment ID.
 * @return void
 */
function edd_sslcommerz_store_tran_payment_map($tran_id, $payment_id)
{
    if (empty($tran_id) || empty($payment_id)) {
        return;
    }

    edd_update_payment_meta($payment_id, '_sslcommerz_tran_id', $tran_id);
}

/**
 * SSLCommerz return handler for customer redirects
 */
function edd_sslcommerz_return_handler()
{
    // Only handle SSLCommerz return requests
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- External gateway return endpoint.
    $listener = isset($_GET['edd-listener']) ? sanitize_text_field(wp_unslash($_GET['edd-listener'])) : '';
    if ($listener !== 'sslcommerz_return') {
        return;
    }

    // Get payment ID and status from request
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- External gateway return endpoint.
    $payment_id = absint($_GET['edd-sslcz-pid'] ?? 0);
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- External gateway return endpoint.
    $status = isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : '';
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- External gateway return endpoint.
    $val_id = isset($_POST['val_id']) ? sanitize_text_field(wp_unslash($_POST['val_id'])) : '';

    if (!$payment_id) {
        return;
    }

    // Get payment status
    $payment_status = edd_get_payment_status($payment_id);

    // If payment already completed, redirect to success page
    if ($payment_status === 'complete') {
        edd_send_to_success_page();
        exit;
    }

    // Initialize API
    $store_id = edd_sslcommerz_get_option('store_id');
    $store_passwd = edd_sslcommerz_get_option('store_passwd');
    $is_sandbox = edd_sslcommerz_get_option('sandbox', false);
    $api = new EDD_SSLCommerz_API($store_id, $store_passwd, $is_sandbox);

    // Validate payment using val_id if available
    $is_valid = false;
    if (!empty($val_id)) {
        $validation = $api->validate_payment($val_id);
        if (!empty($validation['success']) && !empty($validation['data']['status'])) {
            $status_valid = in_array($validation['data']['status'], ['VALID', 'VALIDATED'], true);
            $is_valid = $status_valid && edd_sslcommerz_validation_matches_order($payment_id, $validation['data']);
        }
    }

    // Handle based on status and validation
    if ($is_valid) {
        // Update payment status to complete
        edd_update_payment_status($payment_id, 'complete');
        edd_insert_payment_note(
            $payment_id,
            __('Payment validated successfully via return handler', 'edd-sslcommerz-gateway')
        );
        edd_send_to_success_page();
    } elseif ($status === 'cancel') {
        // Handle canceled payment
        edd_update_payment_status($payment_id, 'abandoned');
        wp_safe_redirect(edd_get_failed_transaction_uri());
        exit;
    } else {
        // Handle failed payment
        edd_update_payment_status($payment_id, 'failed');
        edd_set_error('sslcommerz_payment_failed', __('Payment verification failed', 'edd-sslcommerz-gateway'));
        edd_send_back_to_checkout(array('payment-mode' => 'sslcommerz'));
    }
}

/**
 * Compare SSLCommerz validation response with local EDD order amount/currency
 */
function edd_sslcommerz_validation_matches_order($payment_id, $validation_data)
{
    $expected_amount = (float) edd_get_payment_amount($payment_id);
    $expected_currency = function_exists('edd_get_payment_currency_code')
        ? strtoupper((string) edd_get_payment_currency_code($payment_id))
        : strtoupper((string) edd_get_currency());

    $actual_amount = isset($validation_data['amount']) ? (float) $validation_data['amount'] : null;
    $actual_currency = isset($validation_data['currency_type']) ? strtoupper((string) $validation_data['currency_type']) : null;

    $amount_matches = $actual_amount !== null && abs($actual_amount - $expected_amount) < 0.01;
    $currency_matches = $actual_currency !== null && $actual_currency === $expected_currency;

    if (!$amount_matches || !$currency_matches) {
        edd_sslcommerz_log('Return validation mismatch', array(
            'payment_id' => $payment_id,
            'expected_amount' => $expected_amount,
            'actual_amount' => $actual_amount,
            'expected_currency' => $expected_currency,
            'actual_currency' => $actual_currency,
        ));
    }

    return $amount_matches && $currency_matches;
}

/**
 * Process SSLCommerz payment
 */
function edd_sslcommerz_process_payment($purchase_data)
{
    // Check if SSLCommerz is enabled
    if (!edd_sslcommerz_get_option('enable', false)) {
        edd_send_back_to_checkout(array(
            'payment-mode' => 'sslcommerz',
            'edd_sslcommerz_error' => true
        ));
    }

    // Collect payment data
    $payment_data = array(
        'price' => $purchase_data['price'],
        'date' => $purchase_data['date'],
        'user_email' => $purchase_data['user_email'],
        'purchase_key' => $purchase_data['purchase_key'],
        'currency' => edd_get_currency(),
        'downloads' => $purchase_data['downloads'],
        'cart_details' => $purchase_data['cart_details'],
        'user_info' => $purchase_data['user_info'],
        'status' => 'pending',
        'gateway' => 'sslcommerz',
    );

    // Record the pending payment
    $payment_id = edd_insert_payment($payment_data);

    if (!$payment_id) {
        edd_record_gateway_error(
            __('Payment Error', 'edd-sslcommerz-gateway'),
            /* translators: %s: Encoded purchase data. */
            sprintf(__('Payment creation failed for purchase data: %s', 'edd-sslcommerz-gateway'), json_encode($purchase_data)),
            $payment_id
        );
        edd_send_back_to_checkout(array(
            'payment-mode' => 'sslcommerz',
            'edd_sslcommerz_error' => true
        ));
    }

    // Generate unique transaction ID
    $tran_id = 'EDD-' . $payment_id . '-' . time();

    // Update payment meta with transaction ID
    edd_update_payment_meta($payment_id, '_sslcommerz_tran_id', $tran_id);
    edd_sslcommerz_store_tran_payment_map($tran_id, $payment_id);

    // Prepare API request data
    $store_id = edd_sslcommerz_get_option('store_id');
    $store_passwd = edd_sslcommerz_get_option('store_passwd');
    $is_sandbox = edd_sslcommerz_get_option('sandbox', false);

    $api_data = array(
        'store_id' => $store_id,
        'store_passwd' => $store_passwd,
        'total_amount' => $purchase_data['price'],
        'currency' => edd_get_currency(),
        'tran_id' => $tran_id,
        'product_category' => 'Digital Download',
        'product_profile' => 'non-physical-goods',
        'success_url' => edd_sslcommerz_success_url($payment_id),
        'fail_url' => edd_sslcommerz_fail_url($payment_id),
        'cancel_url' => edd_sslcommerz_cancel_url($payment_id),
        'ipn_url' => edd_sslcommerz_ipn_url(),
        'cus_name' => $purchase_data['user_info']['first_name'] . ' ' . $purchase_data['user_info']['last_name'],
        'cus_email' => $purchase_data['user_email'],
        'cus_phone' => isset($purchase_data['user_info']['phone']) ? $purchase_data['user_info']['phone'] : 'N/A',
        'cus_add1' => isset($purchase_data['user_info']['address']['line1']) ? $purchase_data['user_info']['address']['line1'] : 'N/A',
        'cus_city' => isset($purchase_data['user_info']['address']['city']) ? $purchase_data['user_info']['address']['city'] : 'N/A',
        'cus_country' => isset($purchase_data['user_info']['address']['country']) ? $purchase_data['user_info']['address']['country'] : 'BD',
        'shipping_method' => 'NO',
        'product_name' => edd_sslcommerz_get_product_names($purchase_data),
        'num_of_item' => count($purchase_data['cart_details']),
        'value_a' => $payment_id  // store EDD payment ID in custom field
    );

    // Initialize API and send request
    $api = new EDD_SSLCommerz_API($store_id, $store_passwd, $is_sandbox);
    $response = $api->init_payment($api_data);

    // Handle API response
    $is_success = !empty($response['success']) &&
        !empty($response['data']) &&
        !empty($response['data']['status']) &&
        $response['data']['status'] === 'SUCCESS' &&
        !empty($response['data']['GatewayPageURL']);

    if ($is_success) {
        $gateway_url = $response['data']['GatewayPageURL'];
        add_filter('allowed_redirect_hosts', 'edd_sslcommerz_allowed_redirect_hosts');
        wp_safe_redirect(esc_url_raw($gateway_url));
        remove_filter('allowed_redirect_hosts', 'edd_sslcommerz_allowed_redirect_hosts');
        exit;
    } else {
        $error_message = $response['data']['failedreason'] ?? __('Payment initialization failed', 'edd-sslcommerz-gateway');

        // Log error
        edd_record_gateway_error(
            __('SSLCommerz Error', 'edd-sslcommerz-gateway'),
            /* translators: 1: EDD payment ID, 2: Error message. */
            sprintf(
                __('Payment initiation failed for payment #%1$d. Error: %2$s', 'edd-sslcommerz-gateway'),
                $payment_id,
                $error_message
            ),
            $payment_id
        );

        edd_set_error('sslcommerz_error', __('Payment initialization failed. Please try again.', 'edd-sslcommerz-gateway'));
        edd_send_back_to_checkout(array(
            'payment-mode' => 'sslcommerz',
            'edd_sslcommerz_error' => true
        ));
    }
}

/**
 * Allow SSLCommerz hosts for safe redirects.
 *
 * @param array $hosts Allowed redirect hosts.
 * @return array
 */
function edd_sslcommerz_allowed_redirect_hosts($hosts)
{
    $hosts[] = 'sandbox.sslcommerz.com';
    $hosts[] = 'securepay.sslcommerz.com';
    $hosts[] = 'seamless-epay.sslcommerz.com';

    return array_unique($hosts);
}

