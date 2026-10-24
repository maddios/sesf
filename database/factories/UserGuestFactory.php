<?php

namespace Database\Factories;

use App\Enums\UserGuestType;
use App\Models\CarShow;
use App\Models\ShowRegistration;
use App\Models\User;
use App\Models\UserGuest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * The guest's show_id is the registration's show, so the default creates the registration for
 * the show the guest was given. Use forRegistration() to attach to an existing registration.
 *
 * @extends Factory<UserGuest>
 */
class UserGuestFactory extends Factory
{
    protected $model = UserGuest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'first' => fake()->firstName(),
            'last' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'show_id' => CarShow::factory(),
            'waiver_id' => null,
            'type_id' => UserGuestType::Adult,
            'has_signed' => false,
            'signature_image' => null,
            'registration_id' => fn (array $attributes): int => ShowRegistration::factory()
                ->create(['show_id' => $attributes['show_id']])
                ->getKey(),
        ];
    }

    public function forRegistration(ShowRegistration $registration): static
    {
        return $this->state(fn (): array => [
            'registration_id' => $registration->id,
            'show_id' => $registration->show_id,
        ]);
    }

    public function child(): static
    {
        return $this->state(fn (): array => ['type_id' => UserGuestType::Child]);
    }

    public function signed(): static
    {
        return $this->state(fn (): array => [
            'has_signed' => true,
            'signature_image' => 'signature-bytes',
        ]);
    }
}
