<?php

namespace Database\Factories;

use App\Models\CarShow;
use App\Models\CarShowLocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CarShowLocation>
 */
class CarShowLocationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'show_id' => CarShow::factory(),
            'location_id' => null,
            'name' => fake()->company(),
            'description' => null,
            'is_primary' => false,
            'sort_order' => 0,
            'address1' => fake()->streetAddress(),
            'address2' => null,
            'city' => fake()->city(),
            'state' => 'NC',
            'zip' => fake()->postcode(),
            'lattitude' => null,
            'longitude' => null,
        ];
    }

    public function primary(): static
    {
        return $this->state(fn (): array => ['is_primary' => true]);
    }
}
