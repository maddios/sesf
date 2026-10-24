<?php

namespace Database\Factories;

use App\Models\Shirt;
use App\Models\ShowRegistration;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * show_id is the registration's show, so the default follows the registration created above it
 * (the expansion order matters: registration_id is declared first).
 *
 * @extends Factory<Shirt>
 */
class ShirtFactory extends Factory
{
    protected $model = Shirt::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'registration_id' => ShowRegistration::factory(),
            'show_id' => fn (array $attributes): int => (int) ShowRegistration::query()
                ->findOrFail($attributes['registration_id'])
                ->show_id,
            'type_id' => ShirtTypeFactory::MENS_M_TSHIRT,
            'price' => 35.00,
            'is_handed_out' => false,
        ];
    }

    public function handedOut(): static
    {
        return $this->state(fn (): array => ['is_handed_out' => true]);
    }

    public function unpriced(): static
    {
        return $this->state(fn (): array => ['price' => null]);
    }
}
