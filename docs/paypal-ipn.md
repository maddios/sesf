# PayPal IPN receiver

`POST /paypal-ipn` (CSRF-exempt, no throttle, POST only; legacy accepted any verb, GET now gets 405).
Code: `PayPalIpnController` → `App\Services\PayPal\IpnProcessor` (+ `IpnVerifier`, `IpnPayload`, `IpnOutcome`).
The response is always an empty 200 once handled, including handled failures. PayPal re-sends an IPN
until it sees a 200, so failures are logged and mailed to the admin instead of returned as errors.

## Flow

1. Bodies over 64 KB, and empty bodies, are ignored (logged, no postback).
2. **Verify first**: POST `cmd=_notify-validate&` + the raw body byte for byte (same order and encoding)
   to `sesf.paypal.verify_url`. TLS verified, 15 s timeout, own User-Agent. Only a reply of exactly
   `VERIFIED` (trimmed) passes. `INVALID` → `adminInvalid`. HTTP error, timeout or any other reply →
   `adminPostbackFailed`. Neither stores anything; each notice kind is sent at most once per 10 minutes (cache lock).
3. **Validate**: txn_id, payment_status, receiver_email or business = `sesf.paypal.email` (case-insensitive),
   `mc_currency` = USD, `test_ipn` matches `sesf.paypal.sandbox`, strict amounts (max 9999.99). On failure: stored as
   `rejected`; `adminEmailMismatch` for a wrong receiver, else `adminInvalid`.
4. **Apply** in one transaction: `SELECT … FOR UPDATE` on the registration (`item_number`). An unknown id
   → `adminInvalidInvoice`. Then the duplicate check, the money rules and one `paypal_payment` row.
5. Mails are queued in the outcome and sent after the response (`AfterResponse`), one by one. A failing mail is
   logged and never rolls back the payment or changes the status.

## Statuses (amounts in integer cents; gross/fee as sent by PayPal, with their sign)

| payment_status | Money | Mails |
|---|---|---|
| Completed (gross > 0) | received += gross; actual += gross − fee; method = PayPal | owed ≤ 0: customer/adminPaid; else customer/adminInsufficient |
| Refunded, Reversed (gross < 0) | received += gross; actual += gross − fee (floored at 0, unsigned column) | owed > 0: customer/adminUnderfund; else customer/adminDenied |
| Canceled_Reversal (gross > 0) | as Completed | adminPaid or adminInsufficient |
| Pending, Created, Processed, Voided | none | customer/adminPending |
| Denied, Failed, Expired, Canceled | none | customer/adminDenied |
| anything else, or a wrong amount sign | none | adminWeird |

After a money change: `owed = RegistrationBalance::owedCents()`, `amount_owed = owed` (clamped to ±9999.99,
negative = credit), `is_paid = owed <= 0`. Money applied to a cancelled registration also sends `adminWeird`.
AppServiceProvider binds `Registration\RegistrationPricing` if it implements the interface, else
`StoredAmountOwedBalance` (stored amount_owed − change in received; can be stale, see docs/pricing.md).

## Storage and idempotency

One `paypal_payment` row per verified IPN: typed columns (SET columns only take their members, so the
`payment_status` column holds Completed/Pending/Denied/Failed), `paypal_verified=VERIFIED`, `registration_id`, and
`entirepost` = JSON `{"post": fields without password-like keys, "sesf": {status, outcome, reason, cents before/after}}`.
A `(txn_id, payment_status)` already stored as `applied`/`recorded` is skipped (no money, no mail). The check
runs under the registration lock, so concurrent copies serialize. Legacy rows count too
(`[payment_status]=X|` in entirepost). Rejected rows do not block a later resend from PayPal's IPN history.

## Changed vs legacy

Verified before anything is stored (was: stored twice, before and after). Exact `VERIFIED` (was substring).
Receiver checked case-insensitively, plus currency, test_ipn and txn_id idempotency (none before). Correct
cents arithmetic: partial payments no longer set is_paid, no double subtraction of gross from owed,
refunds lower received. Http client with timeouts (was fsockopen, 90 s). Mails go through `IpnMailer`, after commit.

## Ops

- Live: `PAYPAL_IPN_VERIFY_URL=https://ipnpb.paypal.com/cgi-bin/webscr`, `PAYPAL_SANDBOX=false` (defaults).
- Sandbox: `PAYPAL_IPN_VERIFY_URL=https://ipnpb.sandbox.paypal.com/cgi-bin/webscr` and `PAYPAL_SANDBOX=true`.
- Receiver account: `SESF_PAYPAL_EMAIL`. To replay a rejected IPN, fix the config and resend it from PayPal's IPN History.
- Logs carry only txn_id/status/item_number/size, never the body. Tests: `tests/Database/PayPal`.
