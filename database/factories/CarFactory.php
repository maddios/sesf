<?php

namespace Database\Factories;

use App\Models\Car;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Car>
 */
class CarFactory extends Factory
{
    public const TYPE_CAR = 1;

    public const TYPE_MOTORCYCLE = 2;

    protected $model = Car::class;

    /**
     * car_type and car_body are pre-seeded from tests/Fixtures/lookup-data.sql; the factory references them.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'has_image' => false,
            'is_confirmed' => true,
            'model' => fake()->word(),
            'year' => (string) fake()->numberBetween(1950, 2026),
            'body_id' => CarBodyFactory::E24,
            'type_id' => self::TYPE_CAR,
            'nickname' => null,
            'VIN' => null,
        ];
    }

    /** A car sold by its owner: user_id is null. */
    public function sold(): static
    {
        return $this->state(fn (): array => ['user_id' => null]);
    }

    public function withImage(): static
    {
        return $this->state(fn (): array => ['has_image' => true]);
    }

    public function unconfirmed(): static
    {
        return $this->state(fn (): array => ['is_confirmed' => false]);
    }

    public function motorcycle(): static
    {
        return $this->state(fn (): array => [
            'type_id' => self::TYPE_MOTORCYCLE,
        ]);
    }
}
