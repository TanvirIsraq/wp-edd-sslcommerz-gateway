# Implementation Guide

## Scope
This guide documents the implementation currently present in this repository.
Reference behavior should be cross-checked against:
- https://wordpress.org/plugins/wc-sslcommerz-easycheckout/

## File Map
- `edd-sslcommerz-gateway.php`: Plugin bootstrap, hooks, payment/refund flow functions.
- `includes/class-edd-sslcommerz-gateway.php`: Gateway registration, settings, admin notices, payment meta UI.
- `includes/class-sslcommerz-api.php`: SSLCommerz HTTP API wrapper using WordPress HTTP API (`wp_remote_get/wp_remote_post`).
- `includes/class-sslcommerz-ipn-handler.php`: IPN processing and validation flow.
- `includes/functions.php`: URL builders and helper utilities.

## Runtime Flow
1. User selects `sslcommerz` at EDD checkout.
2. `edd_sslcommerz_process_payment` creates a pending EDD payment.
3. Plugin generates `tran_id` and stores `_sslcommerz_tran_id`.
4. Plugin calls SSLCommerz session API and redirects user to `GatewayPageURL`.
5. SSLCommerz eventually calls IPN URL and redirects user back to return URL.
6. IPN listener (`edd_sslcommerz_ipn_listener`) routes callback traffic to `EDD_SSLCommerz_IPN_Handler`.
7. Return listener (`edd_sslcommerz_return_handler`) validates `val_id` and verifies amount/currency.
8. IPN/return handlers update EDD payment status with idempotent checks.
9. Popup checkout mode uses AJAX init and then redirects to `GatewayPageURL`.
10. Refunds are triggered from EDD status transition to `refunded`.

## Settings Keys
Prefixed as `sslcommerz_*` in EDD options:
- `enable`, `title`, `description`
- `store_id`, `store_passwd`
- `sandbox`, `checkout_mode`
- `emi_enabled`, `debug_log`

Currency source of truth:
- Payment request currency always uses EDD store currency via `edd_get_currency()`.

## Current Implementation Notes
- Gateway processing hook uses `edd_gateway_sslcommerz`.
- Credit card form is explicitly disabled via `edd_gateway_sslcommerz_cc_form`.
- Credit card form suppression is also wired to `edd_sslcommerz_cc_form` for compatibility with EDD hook variants.
- A checkout footer script hides `#edd_cc_fields` when `sslcommerz` is selected as a defensive UI fallback.
- Logging helper `edd_sslcommerz_log()` is available and gated by `sslcommerz_debug_log`.
- API wrapper now handles invalid JSON and HTTP 4xx/5xx responses as failures.
- Input handling uses sanitization/unslashing on callback and AJAX paths.
- Output in admin notices is escaped before rendering.
- IPN payment lookup uses WordPress query APIs (`get_posts` + meta query), not direct SQL.
- IPN payment lookup uses a stored `tran_id -> payment_id` option mapping to avoid slow meta queries.
- External SSLCommerz redirect now uses `wp_safe_redirect` with explicit allowed hosts.
- Plugin/readme metadata updated for current WordPress.org checks (`languages` folder, tested up to 6.9).

## Recommended Fix Order
1. Add automated tests or deterministic manual test script.
2. Add stronger callback replay protection.
3. Decide whether popup mode should stay redirect-based or move to full EasyCheckout embed flow.
