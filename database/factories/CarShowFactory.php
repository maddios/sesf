<?php

namespace Database\Factories;

use App\Models\CarShow;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CarShow>
 */
class CarShowFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'short_name' => 'show-'.fake()->unique()->numberBetween(1, 99999),
            'name' => fake()->words(3, true),
            'description' => fake()->paragraph(),
            'schedule' => null,
            'is_schedule_pdf' => false,
            'short_description' => null,
            'price_car' => 25.00,
            'price_adult' => 20.00,
            'price_child' => 10.00,
            'date_start' => now()->addMonths(2)->toDateString(),
            'date_end' => now()->addMonths(2)->addDay()->toDateString(),
            'date_active' => now()->subMonth(),
            'date_registration_deactivation' => now()->addMonth(),
            'is_active' => true,
            'is_judging_released' => false,
            'waiver_id' => null,
            'has_image' => false,
            'primary_sponsor_id' => null,
            'shirt_stock_json' => null,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (): array => ['is_active' => true]);
    }

    /** Both the show dates and the registration window are in the past. */
    public function past(): static
    {
        return $this->state(fn (): array => [
            'date_start' => now()->subMonths(3)->toDateString(),
            'date_end' => now()->subMonths(3)->addDay()->toDateString(),
            'date_active' => now()->subMonths(4),
            'date_registration_deactivation' => now()->subMonths(2),
        ]);
    }

    public function registrationOpen(): static
    {
        return $this->state(fn (): array => [
            'date_active' => now()->subDay(),
            'date_registration_deactivation' => now()->addDay(),
        ]);
    }

    public function registrationClosed(): static
    {
        return $this->state(fn (): array => [
            'date_active' => now()->subMonth(),
            'date_registration_deactivation' => now()->subDay(),
        ]);
    }

    public function withSchedulePdf(): static
    {
        return $this->state(fn (): array => ['is_schedule_pdf' => true]);
    }

    public function judgingReleased(): static
    {
        return $this->state(fn (): array => ['is_judging_released' => true]);
    }
}
