<?php

namespace Database\Factories\Judging;

use App\Models\Judging\Answer;
use App\Models\Judging\FormQuestion;
use App\Models\ShowRegistrationCar;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * One judge's rating for one car on one question. Parents are created through their factories.
 *
 * @extends Factory<Answer>
 */
class AnswerFactory extends Factory
{
    protected $model = Answer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'question_id' => FormQuestion::factory(),
            'show_registration_car_id' => ShowRegistrationCar::factory(),
            'user_id' => User::factory(),
            'data' => (string) fake()->numberBetween(1, 10),
            'comment' => null,
            'deleted_at' => null,
        ];
    }

    public function rated(string $data): static
    {
        return $this->state(fn (): array => ['data' => $data]);
    }

    public function deleted(): static
    {
        return $this->state(fn (): array => ['deleted_at' => now()]);
    }
}
