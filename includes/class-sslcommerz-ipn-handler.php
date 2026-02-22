<?php
if (!defined('ABSPATH'))
    exit;

class EDD_SSLCommerz_IPN_Handler
{

    public function handle()
    {
        // Get POST data
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- External gateway callback endpoint.
        $ipn_data = map_deep(wp_unslash($_POST), 'sanitize_text_field');

        // Validate required fields
        if (empty($ipn_data['tran_id']) || empty($ipn_data['val_id'])) {
            edd_sslcommerz_log('IPN Error: Missing tran_id or val_id');
            return;
        }

        // Find payment by transaction ID
        $payment_id = $this->get_payment_id_by_tran_id($ipn_data['tran_id']);
        if (!$payment_id) {
            edd_sslcommerz_log('IPN Error: Payment not found for tran_id: ' . $ipn_data['tran_id']);
            return;
        }

        // Validate signature (Security fix)
        if (!$this->is_signature_valid($ipn_data)) {
            edd_sslcommerz_log('IPN Error: Signature verification failed for payment #' . $payment_id);
            edd_record_gateway_error(
                __('SSLCommerz IPN Security Error', 'edd-sslcommerz-gateway'),
                sprintf(__('IPN signature verification failed for payment #%d. Potential data tampering.', 'edd-sslcommerz-gateway'), $payment_id),
                $payment_id
            );
            return;
        }

        // Skip if payment already completed
        if (edd_get_payment_status($payment_id) === 'complete') {
            edd_sslcommerz_log('IPN Notice: Payment #' . $payment_id . ' already completed. Skipping IPN.');
            return;
        }

        // Initialize API
        $store_id = edd_sslcommerz_get_option('store_id');
        $store_passwd = edd_sslcommerz_get_option('store_passwd');
        $is_sandbox = edd_sslcommerz_get_option('sandbox', false);
        $api = new EDD_SSLCommerz_API($store_id, $store_passwd, $is_sandbox);

        // Validate payment
        $validation = $api->validate_payment($ipn_data['val_id']);

        if (!$validation['success']) {
            edd_record_gateway_error(
                __('SSLCommerz Validation Error', 'edd-sslcommerz-gateway'),
                /* translators: 1: EDD payment ID, 2: Validation error message. */
                sprintf(
                    __('Validation failed for payment #%1$d. Error: %2$s', 'edd-sslcommerz-gateway'),
                    $payment_id,
                    $validation['error']
                ),
                $payment_id
            );
            return;
        }

        // Process validation result
        $validation_data = $validation['data'];

        $is_valid_status = !empty($validation_data['status']) &&
            ($validation_data['status'] === 'VALID' || $validation_data['status'] === 'VALIDATED');

        if ($is_valid_status && $this->is_amount_currency_valid($payment_id, $ipn_data, $validation_data)) {
            $this->handle_valid_payment($payment_id, $ipn_data, $validation_data);
        } else {
            $this->handle_invalid_payment($payment_id, $ipn_data, $validation_data);
        }
    }

    private function get_payment_id_by_tran_id($tran_id)
    {
        $payments = edd_get_payments([
            'post_status' => ['pending', 'publish'],
            'number' => 1,
            'meta_key' => '_sslcommerz_tran_id',
            'meta_value' => $tran_id,
        ]);

        if ($payments) {
            return $payments[0]->ID;
        }

        return 0;
    }

    /**
     * Verify SSLCommerz signature (verify_sign)
     */
    private function is_signature_valid($data)
    {
        if (empty($data['verify_sign']) || empty($data['verify_key'])) {
            return false;
        }

        $verify_keys = explode(',', $data['verify_key']);
        $post_data = [];
        foreach ($verify_keys as $key) {
            $post_data[$key] = $data[$key] ?? '';
        }

        ksort($post_data);

        $hash_string = "";
        foreach ($post_data as $key => $value) {
            $hash_string .= $key . '=' . $value . '&';
        }

        $store_passwd = edd_sslcommerz_get_option('store_passwd');
        $hash_string .= "store_passwd=" . md5($store_passwd);

        $calculated_sign = md5($hash_string);

        return hash_equals(strtolower($calculated_sign), strtolower($data['verify_sign']));
    }

    private function handle_valid_payment($payment_id, $ipn_data, $validation_data)
    {
        $val_id = isset($ipn_data['val_id']) ? $ipn_data['val_id'] : '';
        $bank_tran_id = isset($ipn_data['bank_tran_id']) ? $ipn_data['bank_tran_id'] : '';
        $card_type = isset($ipn_data['card_type']) ? $ipn_data['card_type'] : '';
        $amount = isset($ipn_data['amount']) ? $ipn_data['amount'] : '';
        $currency = isset($ipn_data['currency']) ? $ipn_data['currency'] : '';

        // Store validation data
        edd_update_payment_meta($payment_id, '_sslcommerz_val_id', $val_id);
        edd_update_payment_meta($payment_id, '_sslcommerz_bank_tran_id', $bank_tran_id);
        edd_update_payment_meta($payment_id, '_sslcommerz_card_type', $card_type);
        edd_update_payment_meta($payment_id, '_sslcommerz_amount', $amount);
        edd_update_payment_meta($payment_id, '_sslcommerz_currency', $currency);

        // Update payment status
        edd_update_payment_status($payment_id, 'complete');

        // Add payment note
        edd_insert_payment_note(
            $payment_id,
            /* translators: %s: SSLCommerz validation ID. */
            sprintf(
                __('SSLCommerz payment validated. Validation ID: %s', 'edd-sslcommerz-gateway'),
                $val_id
            )
        );

        edd_sslcommerz_log('Payment validated successfully for payment #' . $payment_id);
    }

    private function handle_invalid_payment($payment_id, $ipn_data, $validation_data)
    {
        // Update payment status to failed
        edd_update_payment_status($payment_id, 'failed');

        // Add payment note
        $reason = $validation_data['error'] ?? __('Validation failed', 'edd-sslcommerz-gateway');
        edd_insert_payment_note(
            $payment_id,
            /* translators: %s: Validation failure reason. */
            sprintf(__('SSLCommerz payment validation failed. Reason: %s', 'edd-sslcommerz-gateway'), $reason)
        );

        edd_sslcommerz_log('Payment validation failed for payment #' . $payment_id . '. Reason: ' . $reason);
    }

    private function is_amount_currency_valid($payment_id, $ipn_data, $validation_data)
    {
        $expected_amount = (float) edd_get_payment_amount($payment_id);
        $expected_currency = function_exists('edd_get_payment_currency_code')
            ? strtoupper((string) edd_get_payment_currency_code($payment_id))
            : strtoupper((string) edd_get_currency());

        $amount = null;
        if (isset($validation_data['amount'])) {
            $amount = (float) $validation_data['amount'];
        } elseif (isset($ipn_data['amount'])) {
            $amount = (float) $ipn_data['amount'];
        }

        $currency = null;
        if (!empty($validation_data['currency_type'])) {
            $currency = strtoupper((string) $validation_data['currency_type']);
        } elseif (!empty($ipn_data['currency'])) {
            $currency = strtoupper((string) $ipn_data['currency']);
        }

        $amount_is_valid = $amount !== null && abs($amount - $expected_amount) < 0.01;
        $currency_is_valid = $currency !== null && $currency === $expected_currency;

        if (!$amount_is_valid || !$currency_is_valid) {
            edd_sslcommerz_log(
                'IPN rejected due to amount/currency mismatch',
                array(
                    'payment_id' => $payment_id,
                    'expected_amount' => $expected_amount,
                    'received_amount' => $amount,
                    'expected_currency' => $expected_currency,
                    'received_currency' => $currency,
                )
            );
        }

        return $amount_is_valid && $currency_is_valid;
    }
}
