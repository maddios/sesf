<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserPreferences;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Preferences for a user. A user normally gets its row from UserObserver, so create the user
 * with User::factory()->withoutPreferences() before using this factory for the same user.
 *
 * @extends Factory<UserPreferences>
 */
class UserPreferencesFactory extends Factory
{
    protected $model = UserPreferences::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // UserObserver creates a preferences row for every new user, so the parent user must be
            // created without one, or this row would violate the unique user_id key.
            'user_id' => User::factory()->withoutPreferences(),
            'show_car_on_attendees_page' => true,
            'allow_future_show_notifications' => true,
            'allow_system_email' => true,
            'show_login_welcome' => true,
            'allow_private_message' => true,
        ];
    }

    public function optedOut(): static
    {
        return $this->state(fn (): array => [
            'show_car_on_attendees_page' => false,
            'allow_future_show_notifications' => false,
            'allow_system_email' => false,
            'show_login_welcome' => false,
            'allow_private_message' => false,
        ]);
    }
}
