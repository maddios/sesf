# PayPal IPN receiver

`POST /paypal-ipn` (CSRF-exempt, POST only; legacy accepted any verb, GET now gets 405). Throttle `paypal-ipn`:
120/min per IP (PayPal posts from a few IPs and retries a 429; it only caps forged floods).
Code: `PayPalIpnController` → `App\Services\PayPal\IpnProcessor` (+ `IpnVerifier`, `IpnPayload`, `IpnOutcome`).
Empty body always. 200 once handled (applied, recorded, duplicate, rejected, INVALID). 503 for a failed
postback or a processing crash, so PayPal (which re-sends until it sees a 200) retries; the duplicate check makes retries safe.

## Flow

1. Bodies over 64 KB, and empty bodies, are ignored (logged, no postback).
2. **Cheap checks before the postback**: txn_id, a known payment_status, receiver_email/business =
   `sesf.paypal.email` (case-insensitive), USD, `test_ipn` = `sesf.paypal.sandbox`, strict amounts (max 9999.99),
   item_number = an existing registration. Failure: 200, `adminEmailMismatch` / `adminInvalidInvoice` /
   `adminInvalid`, and (when txn_id is well formed) one unverified `paypal_payment` row: `paypal_verified` empty
   (not checked with PayPal), `registration_id` NULL (nothing in it is trusted), `entirepost.sesf.outcome=rejected`
   with the reason. A resend of the same (txn_id, payment_status, reason) adds no second row. These rows never count
   as processed, so a resend after a config fix still applies. Every notice kind is mailed at most once per 10
   minutes (cache lock); the rows are the durable record of what the mail dedupe suppressed. A malformed or missing
   txn_id is only logged. Each forged post with a valid-looking txn_id costs one insert (bounded by the throttle).
3. **Verify**: POST `cmd=_notify-validate&` + the raw body byte for byte to `sesf.paypal.verify_url` (TLS verified,
   10 s timeout). Only exactly `VERIFIED` (trimmed) passes. `INVALID` → `adminInvalid`, 200. HTTP error, timeout or any
   other reply → logged at error with txn_id, `adminPostbackFailed`, 503. Neither stores anything.
4. **Apply** in one transaction: `SELECT … FOR UPDATE` on the registration, the duplicate check, the money
   rules, one `paypal_payment` row. A crash rolls back: logged (critical, txn_id), `adminFatal`, 503.
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
| In-Progress, Partially_Refunded, or a wrong amount sign | none | adminWeird |

After a money change: `owed = RegistrationBalance::owedCents()`, `amount_owed = owed` (clamped to ±9999.99,
negative = credit), `is_paid = owed <= 0`. Money applied to a cancelled registration also sends `adminWeird`.
Bound: `RegistrationPricing` (total − received − discount, signed: refunds can make received negative and legacy
discounts can be negative), else `StoredAmountOwedBalance` (stored owed − change in received; can be stale).

## Storage and idempotency

One `paypal_payment` row per verified IPN: typed columns (SET columns only take their members, so the
`payment_status` column holds Completed/Pending/Denied/Failed), `paypal_verified=VERIFIED`, `registration_id`, and
`entirepost` = JSON `{"post": fields without password-like keys, "sesf": {status, outcome, reason, cents before/after}}`.
A `(txn_id, payment_status)` already stored as `applied`/`recorded` for the same registration (registration_id or
item_number) is skipped. It runs under the registration lock, so concurrent copies serialize. Legacy rows
(`[payment_status]=X|`) count only for the same item_number. Rejected rows never count as processed, so a resend
after a fix applies. INVALID replies and failed postbacks store nothing.

## Changed vs legacy
Verified before anything is stored (was: stored twice, before and after). Exact `VERIFIED` (was substring).
Receiver checked case-insensitively, plus currency, test_ipn and txn_id idempotency (none before). Correct
cents arithmetic: partial payments no longer set is_paid, no double subtraction of gross from owed,
refunds lower received. 10 s Http client (was fsockopen, 90 s). Mails go through `IpnMailer`, after commit.

## Ops

- Live: `PAYPAL_IPN_VERIFY_URL=https://ipnpb.paypal.com/cgi-bin/webscr`, `PAYPAL_SANDBOX=false` (defaults).
- Sandbox: `PAYPAL_IPN_VERIFY_URL=https://ipnpb.sandbox.paypal.com/cgi-bin/webscr` and `PAYPAL_SANDBOX=true`.
- Receiver: `SESF_PAYPAL_EMAIL`. Replay a rejected IPN from PayPal's IPN History after fixing config. Logs carry
  txn_id/status/item_number/size, never the body. Tests: `tests/Database/PayPal`.
