<?php

namespace Database\Factories;

use App\Enums\AgendaType;
use App\Models\CarShow;
use App\Models\CarShowAgenda;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CarShowAgenda>
 */
class CarShowAgendaFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'show_id' => CarShow::factory(),
            'name' => fake()->words(2, true),
            'text' => fake()->sentence(),
            'type_id' => AgendaType::Agenda,
            'is_visible' => true,
            'is_pdf' => false,
            'sort_order' => 0,
        ];
    }

    public function hidden(): static
    {
        return $this->state(fn (): array => ['is_visible' => false]);
    }

    public function judging(): static
    {
        return $this->state(fn (): array => ['type_id' => AgendaType::Judging]);
    }
}
