# End-to-End Walkthrough

## 1. Configure Gateway
1. Activate plugin.
2. Open EDD gateway settings and enable SSLCommerz.
3. Set `store_id`, `store_passwd`, and mode.
4. Set currency in EDD store settings (this plugin uses EDD currency as source of truth).

## 2. Hosted Checkout Path
1. Place an order with `sslcommerz` gateway selected.
2. Confirm pending payment is created in EDD.
3. Confirm `_sslcommerz_tran_id` is stored on the payment.
4. Confirm browser redirects to SSLCommerz `GatewayPageURL`.
5. Confirm EDD card fields (`#edd_cc_fields`) are hidden while `sslcommerz` is selected.

## 3. Callback Path
1. Simulate/receive SSLCommerz callback with `edd-listener=sslcommerz_ipn` and `val_id`.
2. Validate that plugin calls validation API.
3. Confirm amount/currency match checks pass before marking complete.
4. On valid result, payment transitions to `complete`.
5. Simulate return callback with `edd-listener=sslcommerz_return`.
6. On invalid/cancel result, payment transitions to `failed` or `abandoned`.

## 4. Popup Checkout Path
1. Set `checkout_mode=popup`.
2. Ensure `embed.min.js` and popup handler script are enqueued on checkout.
3. Trigger popup init AJAX endpoint.
4. Validate AJAX returns `GatewayPageURL`.
5. Validate browser redirects to returned SSLCommerz URL.

## 5. Refund Path
1. Mark SSLCommerz payment as `refunded` in EDD.
2. Confirm refund API call contains `bank_tran_id`.
3. Confirm `_sslcommerz_refund_ref_id` is saved on success.
4. Confirm payment note contains success/failure reason.

## 6. Regression Checklist
- No duplicate completion when IPN and return both arrive.
- Amount/currency mismatch is rejected in IPN and return validation paths.
- Missing credentials produce clear admin notice.
- Sandbox/live endpoint selection matches settings.
- Error paths write meaningful gateway logs/notes.
