<?php

namespace Database\Factories;

use App\Models\ShirtType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * shirt_type is a catalogue pre-seeded by tests/Fixtures/lookup-data.sql (ids 1 to 22). Use the
 * constants below for those rows. This factory only adds extra rows for tests.
 *
 * @extends Factory<ShirtType>
 */
class ShirtTypeFactory extends Factory
{
    /** Fixture ids (tests/Fixtures/lookup-data.sql). */
    public const MENS_S_TSHIRT = 1;

    public const MENS_M_TSHIRT = 2;

    public const MENS_S_POLO = 12;

    protected $model = ShirtType::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(3, true).' T-Shirt',
            'price' => 35.00,
        ];
    }
}
