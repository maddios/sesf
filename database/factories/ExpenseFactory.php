<?php

namespace Database\Factories;

use App\Models\CarShow;
use App\Models\Expense;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Expense>
 */
class ExpenseFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'description' => fake()->sentence(3),
            'amount' => 42.50,
            'show_id' => CarShow::factory(),
        ];
    }

    public function withoutShow(): static
    {
        return $this->state(fn (): array => ['show_id' => null]);
    }
}
