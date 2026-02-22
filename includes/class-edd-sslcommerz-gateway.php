<?php
if (!defined('ABSPATH'))
    exit;

class EDD_SSLCommerz_Gateway
{

    public function __construct()
    {
        add_filter('edd_payment_gateways', [$this, 'register_gateway']);
        add_filter('edd_settings_sections_gateways', [$this, 'add_settings_section']);
        add_filter('edd_settings_gateways', [$this, 'add_settings']);

        // Add admin hooks
        add_action('edd_view_order_details_main_after', [$this, 'add_payment_meta_box'], 10, 1);
        add_action('admin_notices', [$this, 'show_admin_notices']);
    }

    /**
     * Add payment details meta box
     */
    public function add_payment_meta_box($payment_id)
    {
        if (edd_get_payment_gateway($payment_id) !== 'sslcommerz') {
            return;
        }

        add_meta_box(
            'edd_sslcommerz_details',
            __('SSLCommerz Payment Details', 'edd-sslcommerz-gateway'),
            [$this, 'render_payment_meta_box'],
            'download_page_edd-payment-history',
            'normal',
            'high',
            ['payment_id' => $payment_id]
        );
    }

    /**
     * Render payment meta box content
     */
    public function render_payment_meta_box($payment)
    {
        $payment_id = $payment['args']['payment_id'];
        ?>
        <div class="edd-order-data-column">
            <h3><?php esc_html_e('SSLCommerz Transaction Details', 'edd-sslcommerz-gateway'); ?></h3>
            <table class="wp-list-table widefat striped">
                <tbody>
                    <?php $this->render_meta_row($payment_id, '_sslcommerz_tran_id', 'Transaction ID'); ?>
                    <?php $this->render_meta_row($payment_id, '_sslcommerz_val_id', 'Validation ID'); ?>
                    <?php $this->render_meta_row($payment_id, '_sslcommerz_bank_tran_id', 'Bank Transaction ID'); ?>
                    <?php $this->render_meta_row($payment_id, '_sslcommerz_card_type', 'Card Type'); ?>
                    <?php $this->render_meta_row($payment_id, '_sslcommerz_refund_ref_id', 'Refund Reference ID'); ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Render single meta row
     */
    private function render_meta_row($payment_id, $meta_key, $label)
    {
        $value = edd_get_payment_meta($payment_id, $meta_key, true);
        if ($value): ?>
            <tr>
                <td class="column-primary"><strong><?php echo esc_html($label); ?></strong></td>
                <td><?php echo esc_html($value); ?></td>
            </tr>
        <?php endif;
    }

    /**
     * Show admin notices
     */
    public function show_admin_notices()
    {
        // Check if we're in EDD settings
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin page context check.
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if ($page !== 'edd-settings') {
            return;
        }

        // Check if SSLCommerz is enabled
        if (!edd_sslcommerz_get_option('enable', false)) {
            return;
        }

        // Show notices for missing settings
        $notices = [];

        if (empty(edd_sslcommerz_get_option('store_id'))) {
            $notices[] = __('SSLCommerz Store ID is missing', 'edd-sslcommerz-gateway');
        }

        if (empty(edd_sslcommerz_get_option('store_passwd'))) {
            $notices[] = __('SSLCommerz Store Password is missing', 'edd-sslcommerz-gateway');
        }

        if (edd_sslcommerz_get_option('sandbox')) {
            $notices[] = __('SSLCommerz Sandbox mode is enabled', 'edd-sslcommerz-gateway');
        }

        if (!empty($notices)) {
            echo '<div class="notice notice-warning">';
            echo '<p><strong>' . esc_html__('SSLCommerz Gateway Notice:', 'edd-sslcommerz-gateway') . '</strong></p>';
            echo '<ul>';
            foreach ($notices as $notice) {
                echo '<li>' . esc_html($notice) . '</li>';
            }
            echo '</ul></div>';
        }
    }

    /**
     * Register SSLCommerz as a payment gateway
     */
    public function register_gateway($gateways)
    {
        $gateways['sslcommerz'] = [
            'admin_label' => 'SSLCommerz',
            'checkout_label' => __('Pay via SSLCommerz (Card/Mobile Banking/Net Banking)', 'edd-sslcommerz-gateway'),
            'supports' => ['refunds'],
        ];
        return $gateways;
    }

    /**
     * Add settings section for SSLCommerz
     */
    public function add_settings_section($sections)
    {
        $sections['sslcommerz'] = __('SSLCommerz', 'edd-sslcommerz-gateway');
        return $sections;
    }

    /**
     * Add settings fields for SSLCommerz
     */
    public function add_settings($settings)
    {
        $sslcommerz_settings = [
            'sslcommerz' => [
                [
                    'id' => 'sslcommerz_settings_header',
                    'name' => '<strong>' . __('SSLCommerz Settings', 'edd-sslcommerz-gateway') . '</strong>',
                    'type' => 'header',
                ],
                [
                    'id' => 'sslcommerz_enable',
                    'name' => __('Enable SSLCommerz', 'edd-sslcommerz-gateway'),
                    'desc' => __('Check to enable SSLCommerz gateway', 'edd-sslcommerz-gateway'),
                    'type' => 'checkbox',
                ],
                [
                    'id' => 'sslcommerz_title',
                    'name' => __('Title', 'edd-sslcommerz-gateway'),
                    'desc' => __('The title shown at checkout', 'edd-sslcommerz-gateway'),
                    'type' => 'text',
                    'size' => 'regular',
                    'std' => __('Pay via SSLCommerz', 'edd-sslcommerz-gateway'),
                ],
                [
                    'id' => 'sslcommerz_description',
                    'name' => __('Description', 'edd-sslcommerz-gateway'),
                    'desc' => __('The description shown at checkout', 'edd-sslcommerz-gateway'),
                    'type' => 'textarea',
                    'std' => __('Pay securely with your card or mobile banking.', 'edd-sslcommerz-gateway'),
                ],
                [
                    'id' => 'sslcommerz_store_id',
                    'name' => __('Store ID', 'edd-sslcommerz-gateway'),
                    'desc' => __('Your SSLCommerz Store ID', 'edd-sslcommerz-gateway'),
                    'type' => 'text',
                    'size' => 'regular',
                ],
                [
                    'id' => 'sslcommerz_store_passwd',
                    'name' => __('Store Password', 'edd-sslcommerz-gateway'),
                    'desc' => __('Your SSLCommerz Store Password', 'edd-sslcommerz-gateway'),
                    'type' => 'password',
                    'size' => 'regular',
                ],
                [
                    'id' => 'sslcommerz_sandbox',
                    'name' => __('Enable Sandbox Mode', 'edd-sslcommerz-gateway'),
                    'desc' => __('Check to enable sandbox mode for testing', 'edd-sslcommerz-gateway'),
                    'type' => 'checkbox',
                ],
                [
                    'id' => 'sslcommerz_checkout_mode',
                    'name' => __('Checkout Mode', 'edd-sslcommerz-gateway'),
                    'desc' => __('Select the checkout mode', 'edd-sslcommerz-gateway'),
                    'type' => 'select',
                    'options' => [
                        'hosted' => __('Hosted (Redirect)', 'edd-sslcommerz-gateway'),
                        'popup' => __('Popup (EasyCheckout)', 'edd-sslcommerz-gateway'),
                    ],
                    'std' => 'hosted',
                ],
                [
                    'id' => 'sslcommerz_emi_enabled',
                    'name' => __('Enable EMI Option', 'edd-sslcommerz-gateway'),
                    'desc' => __('Check to enable EMI payment option', 'edd-sslcommerz-gateway'),
                    'type' => 'checkbox',
                ],
                [
                    'id' => 'sslcommerz_debug_log',
                    'name' => __('Enable Debug Logging', 'edd-sslcommerz-gateway'),
                    'desc' => __('Log plugin events for debugging', 'edd-sslcommerz-gateway'),
                    'type' => 'checkbox',
                ],
            ],
        ];

        return array_merge($settings, $sslcommerz_settings);
    }
}
