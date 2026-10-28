<?php

namespace App\Services\PayPal;

use App\Enums\PaymentMethod;
use App\Models\PayPalPayment;
use App\Models\ShowRegistration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Processes one PayPal IPN (docs/paypal-ipn.md).
 *
 *  1. Ignore an empty or oversized body.
 *  2. Cheap checks that need no PayPal reply, BEFORE the postback (so forged posts cost no
 *     outbound request): txn_id, a known payment_status, receiver_email/business, mc_currency,
 *     test_ipn against the sandbox setting, the amounts, and item_number naming an existing
 *     registration. A failure with a well-formed txn_id stores one unverified REJECTED row per
 *     (txn_id, payment_status, reason) (paypal_verified empty, no registration link), so a genuine
 *     payment that fails here is never only in the logs. The admin notice (adminEmailMismatch,
 *     adminInvalidInvoice or adminInvalid) is sent at most once per kind per 10 minutes.
 *  3. Verify with PayPal (IpnVerifier). INVALID or a failed postback stores nothing; it is
 *     logged (summary only, with txn_id) and the admin notice is rate limited the same way.
 *  4. In one transaction: lock the registration row (item_number) FOR UPDATE, skip a
 *     (txn_id, payment_status) pair that was already applied or recorded, apply the money rules
 *     in integer cents, and store exactly one paypal_payment row.
 *  5. Mail notices are only queued in the outcome; deliver() sends them after the commit, each
 *     isolated so a mail failure never undoes the payment.
 */
class IpnProcessor
{
    /** Largest absolute amount the float(6,2) money columns hold, in cents. */
    public const MAX_CENTS = 999999;

    /** Minimum seconds between two admin notices of the same unverified kind. */
    public const NOTICE_INTERVAL_SECONDS = 600;

    private const MONEY_STATUSES = ['Completed', 'Refunded', 'Reversed', 'Canceled_Reversal'];

    private const PENDING_STATUSES = ['Pending', 'Created', 'Processed', 'Voided'];

    private const DENIED_STATUSES = ['Denied', 'Failed', 'Expired', 'Canceled'];

    /** Documented statuses without a money rule here: recorded and reported as weird. */
    private const OTHER_KNOWN_STATUSES = ['In-Progress', 'Partially_Refunded'];

    public const RECEIVER_MISMATCH = 'receiver_email/business does not match the configured PayPal account';

    public const UNKNOWN_INVOICE = 'unknown item_number';

    public function __construct(
        private readonly IpnVerifier $verifier,
        private readonly IpnMailer $mailer,
        private readonly RegistrationBalance $balance,
    ) {}

    public function process(IpnPayload $payload): IpnOutcome
    {
        if (strlen($payload->rawBody) > IpnPayload::MAX_BYTES) {
            Log::warning('PayPal IPN ignored: body too large', $payload->logContext());

            return new IpnOutcome(IpnOutcome::IGNORED, 'body too large');
        }

        if ($payload->isEmpty()) {
            Log::info('PayPal IPN ignored: empty body');

            return new IpnOutcome(IpnOutcome::IGNORED, 'empty body');
        }

        $reason = $this->precheck($payload);
        if ($reason !== null) {
            Log::warning('PayPal IPN rejected before postback: '.$reason, $payload->logContext());
            [$kind, $mail] = match ($reason) {
                self::RECEIVER_MISMATCH => ['email-mismatch', ['adminEmailMismatch', [$payload]]],
                self::UNKNOWN_INVOICE => ['invalid-invoice', ['adminInvalidInvoice', [$payload]]],
                default => ['precheck', ['adminInvalid', [$payload, $reason]]],
            };

            $payment = $this->storeUnverifiedRejection($payload, $reason);

            return new IpnOutcome(IpnOutcome::REJECTED, $reason, paymentId: $payment?->id, mails: $this->rateLimited($kind, $mail));
        }

        try {
            $verified = $this->verifier->verify($payload);
        } catch (Throwable $e) {
            Log::error('PayPal IPN postback failed', $payload->logContext() + [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return new IpnOutcome(IpnOutcome::POSTBACK_FAILED, $e->getMessage(), mails: $this->rateLimited(
                'postback', ['adminPostbackFailed', [$payload, $e->getMessage()]],
            ));
        }

        if (! $verified) {
            Log::warning('PayPal IPN not verified (INVALID)', $payload->logContext());

            return new IpnOutcome(IpnOutcome::UNVERIFIED, 'PayPal answered INVALID', mails: $this->rateLimited(
                'invalid', ['adminInvalid', [$payload, 'PayPal answered INVALID']],
            ));
        }

        try {
            return DB::transaction(fn (): IpnOutcome => $this->apply($payload), 3);
        } catch (Throwable $e) {
            Log::critical('PayPal IPN processing failed; nothing applied', $payload->logContext() + [
                'exception' => $e,
            ]);

            return new IpnOutcome(IpnOutcome::FATAL, $e::class, mails: [
                ['adminFatal', [$payload, $e::class.': '.$e->getMessage()]],
            ]);
        }
    }

    /**
     * Sends the queued notices. Each call is isolated: a failure is logged and the rest still go.
     */
    public function deliver(IpnOutcome $outcome): void
    {
        foreach ($outcome->mails as [$method, $args]) {
            try {
                $this->mailer->{$method}(...$args);
            } catch (Throwable $e) {
                Log::error('PayPal IPN notice failed', [
                    'mail' => $method,
                    'result' => $outcome->result,
                    'registration_id' => $outcome->registrationId,
                    'exception' => $e,
                ]);
            }
        }
    }

    /** Null when the message passes the checks that need no PayPal reply, else the reason. */
    private function precheck(IpnPayload $payload): ?string
    {
        if ($payload->txnId() === null) {
            return 'txn_id is missing or malformed';
        }

        $known = [...self::MONEY_STATUSES, ...self::PENDING_STATUSES, ...self::DENIED_STATUSES, ...self::OTHER_KNOWN_STATUSES];
        if (! in_array($payload->paymentStatus(), $known, true)) {
            return 'payment_status is missing or unknown';
        }

        $expected = strtolower(trim((string) config('sesf.paypal.email', config('sesf.paypal_email'))));
        $receivers = array_map(
            fn (?string $email): string => strtolower((string) $email),
            [$payload->receiverEmail(), $payload->business()],
        );
        if ($expected === '' || ! in_array($expected, $receivers, true)) {
            return self::RECEIVER_MISMATCH;
        }

        $currency = (string) config('sesf.paypal.currency', 'USD');
        if ($payload->currency() !== $currency) {
            return 'mc_currency is not '.$currency;
        }

        $sandbox = (bool) config('sesf.paypal.sandbox', false);
        if ($payload->isTest() !== $sandbox) {
            return $payload->isTest() ? 'sandbox IPN (test_ipn=1) sent to a live receiver' : 'live IPN sent to a sandbox receiver';
        }

        $gross = $payload->grossCents();
        $fee = $payload->feeCents();
        if ($gross === null) {
            return 'mc_gross is missing or malformed';
        }
        if ($fee === null) {
            return 'mc_fee is malformed';
        }
        if (abs($gross) > self::MAX_CENTS || abs($fee) > self::MAX_CENTS) {
            return 'amount is out of range';
        }

        $itemNumber = $payload->itemNumber();
        if ($itemNumber === null || ! ShowRegistration::query()->whereKey($itemNumber)->exists()) {
            return self::UNKNOWN_INVOICE;
        }

        return null;
    }

    private function apply(IpnPayload $payload): IpnOutcome
    {
        $txnId = (string) $payload->txnId();
        $status = (string) $payload->paymentStatus();
        $itemNumber = $payload->itemNumber();

        $registration = $itemNumber === null ? null : ShowRegistration::query()
            ->whereKey($itemNumber)
            ->lockForUpdate()
            ->first();

        if ($registration === null) {
            // Deleted between the pre-check and the lock.
            Log::error('PayPal IPN for an unknown item_number', $payload->logContext());
            $payment = $this->store($payload, null, IpnOutcome::REJECTED, self::UNKNOWN_INVOICE);

            return new IpnOutcome(IpnOutcome::REJECTED, self::UNKNOWN_INVOICE, paymentId: $payment->id, mails: [
                ['adminInvalidInvoice', [$payload]],
            ]);
        }

        // Checked while holding the registration lock, so a concurrent copy of this IPN waits
        // here until the first one has committed its row.
        if ($this->alreadyProcessed($txnId, $status, $registration->id)) {
            Log::info('PayPal IPN duplicate ignored', $payload->logContext());

            return new IpnOutcome(IpnOutcome::DUPLICATE, 'already processed', registrationId: $registration->id);
        }

        $gross = (int) $payload->grossCents();
        $fee = (int) $payload->feeCents();
        $mails = [];
        $before = self::amounts($registration);
        $money = in_array($status, self::MONEY_STATUSES, true);
        $signOk = $status === 'Refunded' || $status === 'Reversed' ? $gross < 0 : $gross > 0;

        if ($money && $signOk) {
            $receivedAfter = $before['received'] + $gross;
            $actualAfter = $before['received_actual'] + ($gross - $fee);

            if (abs($receivedAfter) > self::MAX_CENTS || $actualAfter > self::MAX_CENTS) {
                return $this->rejectForRegistration($payload, $registration, 'amount_received would exceed the column range');
            }

            if ($actualAfter < 0) {
                // amount_received_actual is UNSIGNED; keep the true figure in the audit row.
                Log::warning('PayPal IPN: amount_received_actual would go negative; stored as 0', $payload->logContext());
            }

            $registration->amount_received = self::decimal($receivedAfter);
            $registration->amount_received_actual = self::decimal(max(0, $actualAfter));
            $registration->payment_method_id = PaymentMethod::PayPal;

            $owed = max(-self::MAX_CENTS, min(self::MAX_CENTS, $this->balance->owedCents($registration)));
            $registration->amount_owed = self::decimal($owed);
            $registration->is_paid = $owed <= 0;
            $registration->save();

            $mails = match (true) {
                $status === 'Completed' && $owed <= 0 => [['customerPaid', [$registration, $payload]], ['adminPaid', [$registration, $payload]]],
                $status === 'Completed' => [['customerInsufficient', [$registration, $payload]], ['adminInsufficient', [$registration, $payload]]],
                $status === 'Canceled_Reversal' && $owed <= 0 => [['adminPaid', [$registration, $payload]]],
                $status === 'Canceled_Reversal' => [['adminInsufficient', [$registration, $payload]]],
                $owed > 0 => [['customerUnderfund', [$registration, $payload]], ['adminUnderfund', [$registration, $payload]]],
                default => [['customerDenied', [$registration, $payload]], ['adminDenied', [$registration, $payload]]],
            };

            if ($registration->is_cancelled) {
                Log::warning('PayPal IPN money applied to a cancelled registration', $payload->logContext());
                $mails[] = ['adminWeird', [$registration, $payload]];
            }

            $outcome = IpnOutcome::APPLIED;
        } else {
            $mails = match (true) {
                in_array($status, self::PENDING_STATUSES, true) => [['customerPending', [$registration, $payload]], ['adminPending', [$registration, $payload]]],
                in_array($status, self::DENIED_STATUSES, true) => [['customerDenied', [$registration, $payload]], ['adminDenied', [$registration, $payload]]],
                // Unknown status, or a money status with an unexpected sign.
                default => [['adminWeird', [$registration, $payload]]],
            };

            if ($mails[0][0] === 'adminWeird') {
                Log::warning('PayPal IPN with an unexpected status or amount sign', $payload->logContext());
            }

            $outcome = IpnOutcome::RECORDED;
        }

        $after = self::amounts($registration);
        $payment = $this->store($payload, $registration, $outcome, null, [
            'gross_cents' => $gross,
            'fee_cents' => $fee,
            'before' => $before,
            'after' => $after,
        ]);

        Log::info('PayPal IPN '.$outcome, $payload->logContext() + ['registration_id' => $registration->id, 'before' => $before, 'after' => $after]);

        return new IpnOutcome($outcome, null, $registration->id, $payment->id, $mails);
    }

    /**
     * Records a message that failed the pre-check, without a postback. The row is not linked to a registration
     * (nothing in it is trusted) and never counts as processed, so a resend after a fix still applies. A resend of the
     * same rejected message adds no second row.
     */
    private function storeUnverifiedRejection(IpnPayload $payload, string $reason): ?PayPalPayment
    {
        $txnId = $payload->txnId();
        if ($txnId === null) {
            return null;
        }

        $status = (string) $payload->get('payment_status');
        $seen = PayPalPayment::query()->forTxn($txnId)->where('paypal_verified', '')->pluck('entirepost')
            ->contains(function (?string $post) use ($status, $reason): bool {
                $meta = json_decode((string) $post, true)['sesf'] ?? null;

                return is_array($meta) && ($meta['status'] ?? null) === $status && ($meta['reason'] ?? null) === $reason;
            });
        if ($seen) {
            return null;
        }

        return $this->store($payload, null, IpnOutcome::REJECTED, $reason, verified: false);
    }

    private function rejectForRegistration(IpnPayload $payload, ShowRegistration $registration, string $reason): IpnOutcome
    {
        Log::warning('PayPal IPN rejected: '.$reason, $payload->logContext());
        $payment = $this->store($payload, $registration, IpnOutcome::REJECTED, $reason);

        return new IpnOutcome(IpnOutcome::REJECTED, $reason, $registration->id, $payment->id, [
            ['adminInvalid', [$payload, $reason]],
        ]);
    }

    /**
     * Whether this (txn_id, payment_status) was already applied or recorded FOR THIS
     * REGISTRATION (registration_id or item_number must match, so a row with a guessed txn_id
     * for another registration cannot suppress a real IPN). New rows carry the exact status and
     * outcome in entirepost JSON (the payment_status column is a legacy SET that cannot hold
     * Refunded and friends). Legacy rows ("[key]=value|" text) count when their payment_status
     * matches, so a message the old system already processed is not applied twice.
     */
    private function alreadyProcessed(string $txnId, string $status, int $registrationId): bool
    {
        $rows = PayPalPayment::query()->forTxn($txnId)->get(['id', 'entirepost', 'item_number', 'registration_id']);

        foreach ($rows as $row) {
            if ($row->registration_id !== $registrationId && trim((string) $row->item_number) !== (string) $registrationId) {
                continue;
            }

            $post = (string) $row->entirepost;
            $decoded = json_decode($post, true);

            if (is_array($decoded) && is_array($decoded['sesf'] ?? null)) {
                if (($decoded['sesf']['status'] ?? null) === $status
                    && in_array($decoded['sesf']['outcome'] ?? null, [IpnOutcome::APPLIED, IpnOutcome::RECORDED], true)) {
                    return true;
                }

                continue;
            }

            if (str_contains($post, '[payment_status]='.$status.'|')) {
                return true;
            }
        }

        return false;
    }

    /**
     * One audit row per processed IPN. Typed columns get the known fields (SET columns only
     * accept their members); entirepost holds the full post (minus password-like keys) as JSON
     * plus the processing result under "sesf".
     *
     * @param  array<string, mixed>  $money
     */
    private function store(IpnPayload $payload, ?ShowRegistration $registration, string $outcome, ?string $reason, array $money = [], bool $verified = true): PayPalPayment
    {
        $status = (string) $payload->get('payment_status');
        $text = fn (string $key, int $max): ?string => $payload->get($key) === null ? null : mb_substr((string) $payload->get($key), 0, $max);
        $set = fn (string $key, array $members, string $default): string => in_array($payload->get($key), $members, true) ? (string) $payload->get($key) : $default;
        $int = fn (string $key, int $max): ?int => preg_match('/^[0-9]{1,9}$/', (string) $payload->get($key)) === 1 ? min($max, (int) $payload->get($key)) : null;
        $gross = $payload->grossCents();
        $fee = $payload->feeCents();

        $columns = [
            'item_name' => $text('item_name', 130) ?? '',
            'receiver_email' => $text('receiver_email', 125),
            'item_number' => $text('item_number', 130) ?? '0',
            'quantity' => $int('quantity', 32767) ?? 0,
            'invoice' => $text('invoice', 25) ?? '0',
            'custom' => $text('custom', 60),
            'payment_status' => match (true) {
                $status === 'Completed' => 'Completed',
                in_array($status, self::PENDING_STATUSES, true) => 'Pending',
                $status === 'Denied' => 'Denied',
                default => 'Failed',
            },
            'pending_reason' => $set('pending_reason', ['echeck', 'intl', 'verify', 'address', 'upgrade', 'unilateral', 'other'], 'other'),
            'mc_gross' => $gross !== null && abs($gross) <= self::MAX_CENTS ? self::decimal($gross) : 0,
            'mc_fee' => $fee !== null && abs($fee) <= self::MAX_CENTS ? self::decimal($fee) : 0,
            'payment_type' => $set('payment_type', ['echeck', 'instant'], 'instant'),
            'payment_date' => $text('payment_date', 50) ?? '0',
            'txn_id' => $text('txn_id', 20) ?? '0',
            'payer_email' => $text('payer_email', 125),
            'payer_status' => $set('payer_status', ['verified', 'unverified', 'intl_verified'], 'unverified'),
            'txn_type' => $text('txn_type', 128) ?? '',
            'first_name' => $text('first_name', 35),
            'last_name' => $text('last_name', 60),
            'address_city' => $text('address_city', 60),
            'address_street' => $text('address_street', 60),
            'address_state' => $text('address_state', 60),
            'address_zip' => $text('address_zip', 15),
            'address_country' => $text('address_country', 60),
            'address_status' => $set('address_status', ['confirmed', 'unconfirmed'], 'unconfirmed'),
            'subscr_id' => $text('subscr_id', 20),
            'verify_sign' => $text('verify_sign', 125),
            'num_cart_items' => $int('num_cart_items', 2147483647),
            'paypal_verified' => $verified ? 'VERIFIED' : '',
            'registration_id' => $registration?->id,
            'entirepost' => self::entirepost($payload, [
                'status' => $status,
                'outcome' => $outcome,
                'reason' => $reason,
            ] + $money),
        ];

        $payment = new PayPalPayment;
        $payment->forceFill($columns)->save();

        return $payment;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private static function entirepost(IpnPayload $payload, array $meta): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;
        $json = (string) json_encode(['post' => $payload->auditFields(), 'sesf' => $meta], $flags);

        // TEXT holds 65,535 bytes. Keep the processing result; trim the post values.
        if (strlen($json) > 60000) {
            $trimmed = array_map(fn (string $v): string => mb_substr($v, 0, 200), array_slice($payload->auditFields(), 0, 100, true));
            $json = (string) json_encode(['post' => $trimmed, 'post_truncated' => true, 'sesf' => $meta], $flags);
        }

        return $json;
    }

    /**
     * @return array{owed: int, received: int, received_actual: int, is_paid: bool}
     */
    private static function amounts(ShowRegistration $registration): array
    {
        return [
            'owed' => StoredAmountOwedBalance::cents($registration->amount_owed),
            'received' => StoredAmountOwedBalance::cents($registration->amount_received),
            'received_actual' => StoredAmountOwedBalance::cents($registration->amount_received_actual),
            'is_paid' => (bool) $registration->is_paid,
        ];
    }

    /** Signed cents as a decimal string, e.g. -1230 => "-12.30". */
    private static function decimal(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return $sign.intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Returns the notice only when no notice of this kind went out in the last 10 minutes. The
     * cache lock is never released; it simply expires, which is the rate limit.
     *
     * @param  array{0: string, 1: list<mixed>}  $mail
     * @return list<array{0: string, 1: list<mixed>}>
     */
    private function rateLimited(string $kind, array $mail): array
    {
        try {
            $acquired = Cache::lock('paypal-ipn:notice:'.$kind, self::NOTICE_INTERVAL_SECONDS)->get();
        } catch (Throwable $e) {
            Log::warning('PayPal IPN notice rate limit unavailable', ['exception' => $e::class]);
            $acquired = true;
        }

        return $acquired ? [$mail] : [];
    }
}
