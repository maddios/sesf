<?php

namespace App\Services\PayPal;

use App\Models\ShowRegistration;

/**
 * How much a registration still owes, in signed cents (negative = credit).
 *
 * IpnProcessor calls owedCents() AFTER it has applied the IPN's amount to the in-memory model
 * (amount_received changed, not yet saved), so an implementation reads the current attributes.
 * A pricing-based implementation returns total - (amount_received + discount) (docs/pricing.md).
 *
 * Bound in AppServiceProvider: App\Services\Registration\RegistrationPricing when that class
 * exists and implements this interface, else StoredAmountOwedBalance (stored amount_owed minus
 * the change in amount_received). IpnMailerImpl also uses it, on the saved registration.
 */
interface RegistrationBalance
{
    public function owedCents(ShowRegistration $registration): int;
}
