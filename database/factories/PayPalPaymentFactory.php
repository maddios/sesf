<?php

namespace Database\Factories;

use App\Models\PayPalPayment;
use App\Models\ShowRegistration;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A completed IPN post for one payment. registration_id is null by default, as legacy rows are.
 *
 * @extends Factory<PayPalPayment>
 */
class PayPalPaymentFactory extends Factory
{
    protected $model = PayPalPayment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'item_name' => 'Car show registration',
            'receiver_email' => fake()->safeEmail(),
            'item_number' => '0',
            'quantity' => 1,
            'invoice' => (string) fake()->numberBetween(1000, 999999),
            'custom' => null,
            'payment_status' => 'Completed',
            'pending_reason' => 'other',
            'mc_gross' => 25.00,
            'mc_fee' => 1.00,
            'payment_type' => 'instant',
            'payment_date' => now()->format('H:i:s M d, Y T'),
            'txn_id' => Str::upper(Str::random(17)),
            'payer_email' => fake()->safeEmail(),
            'payer_status' => 'verified',
            'txn_type' => 'web_accept',
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'address_city' => fake()->city(),
            'address_street' => fake()->streetAddress(),
            'address_state' => fake()->randomElement(['CA', 'NY', 'TX', 'WA']),
            'address_zip' => fake()->postcode(),
            'address_country' => 'United States',
            'address_status' => 'confirmed',
            'subscr_date' => '0',
            'period1' => 'UNK',
            'period2' => 'UNK',
            'period3' => 'UNK',
            'amount1' => 0,
            'amount2' => 0,
            'amount3' => 0,
            'recurring' => 0,
            'reattempt' => 0,
            'retry_at' => null,
            'recur_times' => 0,
            'username' => null,
            'PASSWORD' => null,
            'subscr_id' => null,
            'entirepost' => 'txn_id=raw-ipn-body',
            'paypal_verified' => 'VERIFIED',
            'verify_sign' => null,
            'num_cart_items' => null,
            'registration_id' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (): array => ['payment_status' => 'Pending', 'pending_reason' => 'echeck']);
    }

    public function forRegistration(ShowRegistration $registration): static
    {
        return $this->state(fn (): array => ['registration_id' => $registration->getKey()]);
    }
}
