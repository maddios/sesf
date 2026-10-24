<?php

namespace Database\Factories;

use App\Enums\UserType;
use App\Models\User;
use App\Models\UserPreferences;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $city = fake()->randomElement([
            ['Daytona Beach', 'FL', '32114'],
            ['Orlando', 'FL', '32803'],
            ['Atlanta', 'GA', '30309'],
            ['Charlotte', 'NC', '28202'],
        ]);

        return [
            'first' => fake()->firstName(),
            'last' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->numerify('###-###-####'),
            'phone2' => null,
            'date_registered' => now()->subDays(fake()->numberBetween(1, 900)),
            'location_id' => 0,
            'is_confirmed' => 1,
            'remember_token' => Str::random(10),
            'heard_from' => null,
            'password' => Hash::make('password'),
            'is_admin' => false,
            'type_id' => UserType::Regular->value,
            'has_image' => false,
            'address1' => fake()->numerify('####').' '.fake()->streetName(),
            'address2' => null,
            'city' => $city[0],
            'state' => $city[1],
            'zip' => $city[2],
            'lattitude' => null,
            'longitude' => null,
            'deleted_at' => null,
        ];
    }

    public function admin(): static
    {
        return $this->state(fn (): array => [
            'type_id' => UserType::Administrator->value,
            'is_admin' => true,
        ]);
    }

    public function judge(): static
    {
        return $this->state(fn (): array => [
            'type_id' => UserType::Judge->value,
        ]);
    }

    public function withImage(): static
    {
        return $this->state(fn (): array => [
            'has_image' => true,
        ]);
    }

    public function deleted(): static
    {
        return $this->state(fn (): array => [
            'deleted_at' => now(),
        ]);
    }

    /**
     * Removes the preferences row that the user observer creates, for tests that need a user
     * without one.
     */
    public function withoutPreferences(): static
    {
        return $this->afterCreating(function (User $user): void {
            UserPreferences::query()->where('user_id', $user->id)->delete();
        });
    }
}
