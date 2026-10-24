<?php

namespace Database\Factories;

use App\Models\CarShow;
use App\Models\CarShowJudge;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A judge assigned to a show. The pair (user_id, show_id) is unique, so use the for* states
 * when you need a specific pair. Each call to the default definition makes new parents.
 *
 * @extends Factory<CarShowJudge>
 */
class CarShowJudgeFactory extends Factory
{
    protected $model = CarShowJudge::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'show_id' => CarShow::factory(),
        ];
    }
}
