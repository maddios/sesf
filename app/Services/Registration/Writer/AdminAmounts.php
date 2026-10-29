<?php

namespace App\Services\Registration\Writer;

/**
 * Invoice number and money fields only an administrator sets (legacy CarShowRegistration admin).
 * Money is signed integer cents, except received_actual (an unsigned column).
 *
 * $isPaid and $owedCents are overrides: null keeps the writer's rule (recomputed on open shows,
 * adjusted by the change in received and discount on ended shows).
 *
 * $expectedReceivedCents and $expectedDiscountCents are the stored values the admin's form was loaded with; the
 * writer refuses the edit (RegistrationChanged) when the locked row no longer holds them.
 */
final readonly class AdminAmounts
{
    public function __construct(
        public int $invoiceId,
        public int $receivedCents,
        public ?int $receivedActualCents,
        public int $discountCents,
        public ?string $discountNote,
        public ?bool $isPaid,
        public ?int $owedCents,
        public int $expectedReceivedCents,
        public int $expectedDiscountCents,
    ) {}
}
