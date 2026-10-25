<?php

namespace App\Services\PayPal;

use Illuminate\Http\Request;

/**
 * One IPN message as PayPal posted it. Everything in it is untrusted until IpnVerifier has had
 * PayPal confirm it, and every field is a string: the typed accessors parse defensively and
 * return null for a missing or malformed value instead of guessing.
 *
 * rawBody is the form-encoded body exactly as received. It is what the verifier posts back,
 * because PayPal only answers VERIFIED for the unmodified message (same order, same encoding).
 * fields are the decoded pairs in that order, converted to UTF-8 from the IPN's charset.
 */
final readonly class IpnPayload
{
    /** Largest body accepted (bytes). Real IPNs are a few KB. */
    public const MAX_BYTES = 65536;

    /** Keys never stored or logged (legacy subscription posts could carry a password). */
    private const SECRET_KEY_PATTERN = '/pass|secret|token/i';

    /**
     * @param  array<string, string>  $fields
     */
    private function __construct(
        public string $rawBody,
        public array $fields,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $raw = (string) $request->getContent();

        // Best effort for callers that did not keep the raw body (e.g. a test posting an array).
        // A rebuilt body may not verify, which is the safe failure.
        if ($raw === '' && $request->request->count() > 0) {
            $raw = http_build_query($request->request->all(), '', '&', PHP_QUERY_RFC1738);
        }

        return self::fromRawBody($raw);
    }

    public static function fromRawBody(string $raw): self
    {
        $fields = [];

        foreach ($raw === '' ? [] : explode('&', $raw) as $pair) {
            if ($pair === '') {
                continue;
            }
            $parts = explode('=', $pair, 2);
            $key = urldecode($parts[0]);
            if ($key === '') {
                continue;
            }
            $fields[$key] = urldecode($parts[1] ?? '');
        }

        return new self($raw, self::toUtf8($fields));
    }

    /**
     * @param  array<string, string>  $fields
     * @return array<string, string>
     */
    private static function toUtf8(array $fields): array
    {
        $charset = strtoupper(trim($fields['charset'] ?? 'UTF-8'));
        $known = array_map('strtoupper', mb_list_encodings());
        $convert = $charset !== 'UTF-8' && $charset !== 'UTF8' && in_array($charset, $known, true);

        $out = [];
        foreach ($fields as $key => $value) {
            if ($convert) {
                $key = (string) mb_convert_encoding($key, 'UTF-8', $charset);
                $value = (string) mb_convert_encoding($value, 'UTF-8', $charset);
            }
            $out[mb_scrub($key, 'UTF-8')] = mb_scrub($value, 'UTF-8');
        }

        return $out;
    }

    public function isEmpty(): bool
    {
        return $this->fields === [];
    }

    /** Trimmed field value, or null when absent or blank. */
    public function get(string $key): ?string
    {
        $value = trim($this->fields[$key] ?? '');

        return $value === '' ? null : $value;
    }

    public function txnId(): ?string
    {
        $id = $this->get('txn_id');

        // PayPal transaction ids are 17 alphanumerics; the column holds 20.
        return $id !== null && preg_match('/^[A-Za-z0-9-]{1,20}$/', $id) === 1 ? $id : null;
    }

    public function paymentStatus(): ?string
    {
        $status = $this->get('payment_status');

        return $status !== null && preg_match('/^[A-Za-z_-]{1,40}$/', $status) === 1 ? $status : null;
    }

    /** Registration id from item_number: a positive int of at most 10 digits, else null. */
    public function itemNumber(): ?int
    {
        $value = $this->get('item_number');
        if ($value === null || preg_match('/^[0-9]{1,10}$/', $value) !== 1) {
            return null;
        }
        $id = (int) $value;

        return $id > 0 && $id <= 4294967295 ? $id : null;
    }

    public function receiverEmail(): ?string
    {
        return $this->get('receiver_email');
    }

    public function payerEmail(): ?string
    {
        return $this->get('payer_email');
    }

    public function business(): ?string
    {
        return $this->get('business');
    }

    public function currency(): ?string
    {
        return $this->get('mc_currency');
    }

    /** True when PayPal flagged the message as a sandbox IPN (test_ipn=1). */
    public function isTest(): bool
    {
        return $this->get('test_ipn') === '1';
    }

    public function hasField(string $key): bool
    {
        return $this->get($key) !== null;
    }

    /** Signed mc_gross in cents; null when absent or malformed. */
    public function grossCents(): ?int
    {
        return self::parseCents($this->get('mc_gross'));
    }

    /** Signed mc_fee in cents; 0 when absent (pending payments have no fee), null when malformed. */
    public function feeCents(): ?int
    {
        $value = $this->get('mc_fee');

        return $value === null ? 0 : self::parseCents($value);
    }

    /**
     * Strict decimal parse: optional minus, at most 7 integer digits and 2 decimals. No
     * exponents, no thousands separators, no floats.
     */
    public static function parseCents(?string $value): ?int
    {
        if ($value === null || preg_match('/^(-)?([0-9]{1,7})(?:\.([0-9]{1,2}))?$/', $value, $m) !== 1) {
            return null;
        }
        $cents = (int) $m[2] * 100 + (int) str_pad($m[3] ?? '', 2, '0');

        return $m[1] === '-' ? -$cents : $cents;
    }

    /**
     * The fields for storage, without password-like keys.
     *
     * @return array<string, string>
     */
    public function auditFields(): array
    {
        return array_filter(
            $this->fields,
            fn (string $key): bool => preg_match(self::SECRET_KEY_PATTERN, $key) !== 1,
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * A short, non-personal summary for log context (never the full body).
     *
     * @return array<string, mixed>
     */
    public function logContext(): array
    {
        return [
            'txn_id' => $this->get('txn_id') === null ? null : substr((string) $this->get('txn_id'), 0, 32),
            'payment_status' => $this->get('payment_status') === null ? null : substr((string) $this->get('payment_status'), 0, 40),
            'item_number' => $this->get('item_number') === null ? null : substr((string) $this->get('item_number'), 0, 32),
            'txn_type' => $this->get('txn_type') === null ? null : substr((string) $this->get('txn_type'), 0, 40),
            'bytes' => strlen($this->rawBody),
        ];
    }
}
