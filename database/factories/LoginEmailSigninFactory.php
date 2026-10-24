<?php

namespace Database\Factories;

use App\Models\LoginEmailSignin;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LoginEmailSignin>
 */
class LoginEmailSigninFactory extends Factory
{
    protected $model = LoginEmailSignin::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'token_hash' => hash('sha256', Str::random(40)),
            'code_hash' => hash('sha256', Str::random(40)),
            'redirect_path' => null,
            'expires_at' => now()->addMinutes(15),
            'used_at' => null,
            'created_at' => now(),
            'request_ip' => fake()->ipv4(),
        ];
    }

    public function used(): static
    {
        return $this->state(fn (): array => [
            'used_at' => now(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'expires_at' => now()->subMinute(),
        ]);
    }

    /**
     * Sent yesterday (before today's quota window).
     */
    public function sentYesterday(): static
    {
        return $this->state(fn (): array => [
            'created_at' => now()->subDay(),
            'expires_at' => now()->subDay()->addMinutes(15),
        ]);
    }
}
