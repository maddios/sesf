<?php

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Models\CarShow;
use App\Models\ShowRegistration;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * invoice_id is left to the creating hook, which allocates the next number for the show.
 *
 * @extends Factory<ShowRegistration>
 */
class ShowRegistrationFactory extends Factory
{
    protected $model = ShowRegistration::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'show_id' => CarShow::factory(),
            'user_id' => User::factory(),
            'waiver_id' => null,
            'amount_owed' => 0,
            'amount_received' => 0,
            'amount_received_actual' => null,
            'donation' => 0,
            'is_paid' => false,
            'payment_method_id' => PaymentMethod::PayPal,
            'is_cancelled' => false,
            'is_checked_in' => false,
            'date_checked_in' => null,
            'discount' => 0,
            'discount_note' => null,
            'is_judge' => false,
            'has_signed' => false,
            'arrival_date' => null,
            'arrival_time' => null,
        ];
    }

    public function paid(): static
    {
        return $this->state(fn (): array => [
            'is_paid' => true,
            'amount_received_actual' => 25.00,
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (): array => ['is_cancelled' => true]);
    }

    public function checkedIn(): static
    {
        return $this->state(fn (): array => [
            'is_checked_in' => true,
            'date_checked_in' => now(),
        ]);
    }

    public function judge(): static
    {
        return $this->state(fn (): array => ['is_judge' => true]);
    }

    public function withArrival(): static
    {
        return $this->state(fn (): array => [
            'arrival_date' => now()->addDay()->toDateString(),
            'arrival_time' => '9:00 AM',
        ]);
    }
}
