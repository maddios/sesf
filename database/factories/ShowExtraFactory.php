<?php

namespace Database\Factories;

use App\Enums\ExtraType;
use App\Models\CarShow;
use App\Models\ShowExtra;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShowExtra>
 */
class ShowExtraFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'is_enabled' => true,
            'show_id' => CarShow::factory(),
            'name' => fake()->words(2, true),
            'description' => fake()->sentence(),
            'auth_key' => null,
            'price' => 5.00,
            'type_id' => ExtraType::Cars,
            'min_value' => 1,
            'max_value' => 100,
        ];
    }

    public function ofType(ExtraType $type): static
    {
        return $this->state(fn (): array => ['type_id' => $type]);
    }

    public function disabled(): static
    {
        return $this->state(fn (): array => ['is_enabled' => false]);
    }
}
