<?php

namespace Database\Factories;

use App\Models\ShowExtra;
use App\Models\ShowExtraRegistration;
use App\Models\ShowRegistration;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * The registration's show is the extra's show, so the default creates a registration for it.
 *
 * @extends Factory<ShowExtraRegistration>
 */
class ShowExtraRegistrationFactory extends Factory
{
    protected $model = ShowExtraRegistration::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'show_extra_id' => ShowExtra::factory(),
            'registration_id' => fn (array $attributes): int => ShowRegistration::factory()
                ->create(['show_id' => ShowExtra::query()->findOrFail($attributes['show_extra_id'])->show_id])
                ->getKey(),
            'num_extras' => 1,
        ];
    }

    public function quantity(int $count): static
    {
        return $this->state(fn (): array => ['num_extras' => $count]);
    }
}
