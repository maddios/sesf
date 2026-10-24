<?php

namespace Database\Factories\Judging;

use App\Enums\JudgingType;
use App\Models\Judging\Form;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Extra judging forms. The fixture already seeds the three forms, one per judging type. This
 * factory only adds rows beyond those. The type comes from JudgingType, not a hard-coded id.
 *
 * @extends Factory<Form>
 */
class FormFactory extends Factory
{
    protected $model = Form::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'type_id' => JudgingType::Clean->value,
        ];
    }

    public function forType(JudgingType $type): static
    {
        return $this->state(fn (): array => ['type_id' => $type->value]);
    }
}
