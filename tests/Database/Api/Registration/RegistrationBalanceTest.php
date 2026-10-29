<?php

namespace Tests\Database\Api\Registration;

use App\Models\ShowRegistration;
use App\Services\PayPal\RegistrationBalance;
use App\Services\Registration\RegistrationPricing;
use PHPUnit\Framework\Attributes\Test;

/**
 * RegistrationBalance (PayPal IPN contract) as implemented by RegistrationPricing.
 */
class RegistrationBalanceTest extends RegistrationTestCase
{
    #[Test]
    public function the_container_resolves_the_balance_contract_to_the_pricing_adapter(): void
    {
        $this->assertInstanceOf(RegistrationPricing::class, app(RegistrationBalance::class));
    }

    #[Test]
    public function an_open_show_owes_the_computed_total_minus_the_in_memory_payment_and_discount(): void
    {
        // Car 25 (one car, no free guest needed), donation 0: total 25. Stored received 0, discount 0.
        $show = $this->show();
        $user = $this->member();
        $registration = $this->registration($show, $user);
        $this->enterCar($registration, $this->car($user));
        $registration = ShowRegistration::query()->findOrFail($registration->id);

        // The IPN applies a payment in memory and asks before saving.
        $registration->amount_received = '10.00';
        $registration->discount = '5.00';

        $this->assertSame(1000, app(RegistrationBalance::class)->owedCents($registration));
        $this->assertSame('0.00', (string) ShowRegistration::query()->findOrFail($registration->id)->amount_received);
    }

    #[Test]
    public function an_overpayment_is_a_signed_credit(): void
    {
        $show = $this->show();
        $user = $this->member();
        $registration = $this->registration($show, $user);
        $this->enterCar($registration, $this->car($user));
        $registration = ShowRegistration::query()->findOrFail($registration->id);
        $registration->amount_received = '40.00';

        $this->assertSame(-1500, app(RegistrationBalance::class)->owedCents($registration));
    }

    #[Test]
    public function an_ended_show_adjusts_the_stored_owed_by_the_payment_made_in_memory(): void
    {
        $show = $this->show(['date_end' => now()->subDay()->toDateString(), 'date_start' => now()->subDays(3)->toDateString()]);
        $user = $this->member();
        $registration = $this->registration($show, $user, ['amount_owed' => 7, 'amount_received' => 0]);
        $registration = ShowRegistration::query()->findOrFail($registration->id);

        $registration->amount_received = '10.00';

        // Stored owed 7.00 minus the payment of 10.00 applied in memory = -3.00.
        $this->assertSame(-300, app(RegistrationBalance::class)->owedCents($registration));
    }
}
