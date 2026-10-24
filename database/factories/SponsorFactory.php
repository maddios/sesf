<?php

namespace Database\Factories;

use App\Models\Sponsor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Sponsor>
 */
class SponsorFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'URL' => fake()->url(),
            'description' => fake()->paragraph(),
            'short_description' => null,
            'is_enabled' => true,
            'internal_notes' => fake()->sentence(),
            'sort_order' => 0,
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn (): array => ['is_enabled' => false]);
    }
}
