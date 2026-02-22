# Roles

## SSLCommerzGatewayAgent
Primary owner of the EDD SSLCommerz payment integration.

Responsibilities:
- Register and configure the SSLCommerz gateway in EDD.
- Handle hosted and popup payment initialization.
- Validate IPN/return callbacks and update payment status.
- Trigger and record refund operations.
- Maintain transaction metadata for auditability.

Boundaries:
- Does not manage EDD core internals.
- Does not store card PAN/CVV data.
- Relies on SSLCommerz APIs for payment validation.
