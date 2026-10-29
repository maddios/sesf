<?php

namespace App\Services\PayPal;

/**
 * Result of IpnProcessor::process(). The mails are IpnMailer method calls queued while
 * processing and sent by IpnProcessor::deliver() once the transaction has committed.
 */
final readonly class IpnOutcome
{
    /** Money was applied to the registration (Completed, Refunded, Reversed, Canceled_Reversal). */
    public const APPLIED = 'applied';

    /** Recorded against the registration without a money change (Pending, Denied, unknown...). */
    public const RECORDED = 'recorded';

    /** The same (txn_id, payment_status) was already applied or recorded: nothing done. */
    public const DUPLICATE = 'duplicate';

    /** Verified by PayPal but failed validation; stored for audit, no money change. */
    public const REJECTED = 'rejected';

    /** PayPal answered INVALID: nothing stored. */
    public const UNVERIFIED = 'unverified';

    /** The postback could not be completed: nothing stored. */
    public const POSTBACK_FAILED = 'postback_failed';

    /** Empty or oversized body: ignored without a postback. */
    public const IGNORED = 'ignored';

    /** Unexpected exception; the transaction was rolled back. */
    public const FATAL = 'fatal';

    /**
     * @param  list<array{0: string, 1: list<mixed>}>  $mails  IpnMailer method name and arguments.
     */
    public function __construct(
        public string $result,
        public ?string $reason = null,
        public ?int $registrationId = null,
        public ?int $paymentId = null,
        public array $mails = [],
    ) {}

    /** True when this IPN changed or recorded something on a registration. */
    public function stored(): bool
    {
        return $this->paymentId !== null;
    }

    /** @return list<string> */
    public function mailMethods(): array
    {
        return array_map(fn (array $mail): string => $mail[0], $this->mails);
    }
}
