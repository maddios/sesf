<?php

namespace Database\Factories;

use App\Enums\JudgingType;
use App\Models\Car;
use App\Models\ShowRegistration;
use App\Models\ShowRegistrationCar;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShowRegistrationCar>
 */
class ShowRegistrationCarFactory extends Factory
{
    protected $model = ShowRegistrationCar::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'car_id' => Car::factory(),
            'registration_id' => ShowRegistration::factory(),
            'judging_type_id' => null,
        ];
    }

    public function judged(JudgingType $type): static
    {
        return $this->state(fn (): array => ['judging_type_id' => $type]);
    }
}
