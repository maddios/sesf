<?php

namespace App\Mail\Ipn;

use App\Models\ShowRegistration;
use App\Models\User;
use App\Services\PayPal\IpnPayload;
use App\Services\PayPal\StoredAmountOwedBalance;

/**
 * Plain values for the IPN emails. This is the only place that reads the IPN payload, so the
 * mailables and views do not depend on it. Blade escapes every value when it is printed.
 *
 * The registration arrives with the IPN already applied. The amounts are:
 *  - totalReceivedCents: amount_received on the registration (all gross payments so far).
 *  - stillOwedCents: the balance still owed, from RegistrationBalance (never below zero).
 *  - requiredCents: the registration fee, which is the total received plus the balance still owed.
 */
final readonly class IpnMailData
{
    /** IPN fields written into the raw report: the legacy paypal_vals, without the username and password fields. */
    private const REPORT_FIELDS = [
        'item_name', 'receiver_email', 'item_number', 'quantity', 'custom', 'payment_status',
        'pending_reason', 'payment_date', 'mc_gross', 'mc_fee', 'txn_id', 'txn_type', 'first_name',
        'last_name', 'address_street', 'address_city', 'address_state', 'address_zip',
        'address_country', 'address_status', 'payer_email', 'payer_status', 'payment_type',
        'subscr_date', 'period1', 'period2', 'period3', 'amount1', 'amount2', 'amount3', 'recurring',
        'reattempt', 'retry_at', 'recur_times', 'subscr_id', 'option_name1', 'option_selection1',
        'option_name2', 'option_selection2', 'num_cart_items',
    ];

    public function __construct(
        public ?int $registrationId,
        public ?int $userId,
        public ?int $showId,
        public ?string $customerEmail,
        public string $firstName,
        public string $lastName,
        public string $payUrl,
        public int $requiredCents,
        public int $grossCents,
        public int $feeCents,
        public int $totalReceivedCents,
        public int $stillOwedCents,
        public string $paymentStatus,
        public string $txnId,
        public string $itemNumber,
        public string $ipnReport,
    ) {}

    /**
     * The one adapter from the IPN payload to mail values.
     *
     * @param  int  $owedCents  Signed balance still owed after this IPN (RegistrationBalance::owedCents)
     */
    public static function fromRegistration(ShowRegistration $registration, IpnPayload $payload, int $owedCents): self
    {
        $user = $registration->user;
        $received = StoredAmountOwedBalance::cents($registration->amount_received);
        $stillOwed = max(0, $owedCents);

        return new self(
            registrationId: $registration->id,
            userId: $registration->user_id,
            showId: $registration->show_id,
            customerEmail: $user?->email,
            firstName: (string) $user?->first,
            lastName: (string) $user?->last,
            payUrl: self::billingUrl($user, $registration->id),
            requiredCents: max(0, $received + $owedCents),
            grossCents: $payload->grossCents() ?? 0,
            feeCents: $payload->feeCents() ?? 0,
            totalReceivedCents: max(0, $received),
            stillOwedCents: $stillOwed,
            paymentStatus: $payload->paymentStatus() ?? '',
            txnId: $payload->txnId() ?? '',
            itemNumber: (string) $payload->get('item_number'),
            ipnReport: self::report($payload),
        );
    }

    /**
     * For mails that have no registration: invalid IPN, fatal, postback failure, invoice mismatch,
     * receiver mismatch.
     */
    public static function fromPayload(IpnPayload $payload): self
    {
        return new self(
            registrationId: null,
            userId: null,
            showId: null,
            customerEmail: null,
            firstName: '',
            lastName: '',
            payUrl: rtrim((string) config('app.url'), '/').'/',
            requiredCents: 0,
            grossCents: $payload->grossCents() ?? 0,
            feeCents: $payload->feeCents() ?? 0,
            totalReceivedCents: 0,
            stillOwedCents: 0,
            paymentStatus: $payload->paymentStatus() ?? '',
            txnId: $payload->txnId() ?? '',
            itemNumber: (string) $payload->get('item_number'),
            ipnReport: self::report($payload),
        );
    }

    /**
     * Hash route to the member's billing page, e.g. https://host/#/JaneD/u7/billing/12.
     * Same shape as the legacy billingPayUrlForUser(). The member segment comes from the
     * profileURL accessor and is URL-encoded.
     */
    public static function billingUrl(?User $user, ?int $registrationId): string
    {
        $base = rtrim((string) config('app.url'), '/').'/#/';

        if ($user === null) {
            return $base;
        }

        $route = preg_replace('#^\#/#', '', (string) $user->profileURL) ?? '';
        if (preg_match('#^(.*)/(u\d+)$#', $route, $m) !== 1) {
            return $base.rawurlencode($route).'/billing';
        }

        $url = $base.rawurlencode($m[1]).'/'.$m[2].'/billing';

        return $registrationId ? $url.'/'.(int) $registrationId : $url;
    }

    /**
     * The known IPN fields as "key = value" lines, in the legacy order. Missing fields are left out.
     */
    public static function report(IpnPayload $payload): string
    {
        $lines = ['Posted IPN variables:'];
        foreach (self::REPORT_FIELDS as $key) {
            $value = $payload->get($key);
            if ($value !== null) {
                $lines[] = $key.' = '.$value;
            }
        }

        return implode("\n", $lines)."\n";
    }
}
