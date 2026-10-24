<?php

namespace Database\Factories\Judging;

use App\Models\Judging\Form;
use App\Models\Judging\FormQuestion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Creates rating questions on a form. The parent form is created through FormFactory, so the
 * foreign key holds.
 *
 * @extends Factory<FormQuestion>
 */
class FormQuestionFactory extends Factory
{
    protected $model = FormQuestion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'form_id' => Form::factory(),
            'type' => FormQuestion::TYPE_RATING,
            'title' => fake()->words(3, true),
            'description' => null,
            'page' => 1,
            'page_name' => 'Page 1',
            'sort_order' => fake()->numberBetween(1, 20),
            'valueMin' => 1,
            'valueMax' => 10,
            'valueStep' => 1,
            'has_quarter' => false,
        ];
    }

    public function range(int $min, int $max, int $step = 1): static
    {
        return $this->state(fn (): array => ['valueMin' => $min, 'valueMax' => $max, 'valueStep' => $step]);
    }
}
