# Communication Protocol

## Mode
- Protocol: REST-like request/response exchanges with JSON payloads.
- Transport expectation: HTTPS for external gateway communication.

## Internal Handoff Contract
For inter-agent updates, use this compact structure:
- `context`: Current state and relevant IDs.
- `action`: What is being executed.
- `result`: Outcome with success/failure.
- `next`: Immediate follow-up.

Example:
```json
{
  "context": {"payment_id": 123, "tran_id": "EDD-123-1700000000"},
  "action": "validate_ipn",
  "result": {"status": "complete", "valid": true},
  "next": "notify_checkout_return_handler"
}
```
