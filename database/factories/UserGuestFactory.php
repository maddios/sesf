<?php

namespace Database\Factories;

use App\Enums\UserGuestType;
use App\Models\CarShow;
use App\Models\ShowRegistration;
use App\Models\User;
use App\Models\UserGuest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * The default creates a registration for the guest's show, and the guest's user_id is that
 * registration's owner. When the caller passes an existing registration_id, user_id follows the
 * registration's owner too, so the guest and its registration never disagree. Use forRegistration()
 * to attach to an existing registration.
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
            'user_id' => fn (array $attributes): int => is_int($attributes['registration_id'] ?? null)
                ? (int) ShowRegistration::query()->findOrFail($attributes['registration_id'])->user_id
                : (int) User::factory()->create()->getKey(),
            'first' => fake()->firstName(),
            'last' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'show_id' => CarShow::factory(),
            'waiver_id' => null,
            'type_id' => UserGuestType::Adult,
            'has_signed' => false,
            'signature_image' => null,
            'registration_id' => fn (array $attributes): int => ShowRegistration::factory()
                ->create([
                    'show_id' => $attributes['show_id'],
                    'user_id' => $attributes['user_id'],
                ])
                ->getKey(),
        ];
    }

    public function forRegistration(ShowRegistration $registration): static
    {
        return $this->state(fn (): array => [
            'registration_id' => $registration->id,
            'show_id' => $registration->show_id,
            'user_id' => $registration->user_id,
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
