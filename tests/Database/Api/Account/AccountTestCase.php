<?php

namespace Tests\Database\Api\Account;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Support\LoadsLegacySchema;
use Tests\TestCase;

/**
 * Shared setup for the member account API tests. Each test gets its own client IP so the
 * per-IP rate limiters do not leak between tests (the cache is an array store in tests).
 */
abstract class AccountTestCase extends TestCase
{
    use LoadsLegacySchema;

    protected const PASSWORD = 'secret-1';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        config(['sesf.turnstile.secret' => 'test-secret']);
        $this->withServerVariables(['REMOTE_ADDR' => '10.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254)]);
    }

    /** @param array<string, mixed> $overrides */
    protected function makeUser(array $overrides = []): User
    {
        return User::factory()->create(array_merge(
            ['password' => bcrypt(self::PASSWORD)],
            $overrides,
        ));
    }

    /** Cloudflare answers for every Turnstile check in the test. */
    protected function turnstile(bool $accept = true): void
    {
        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => $accept])]);
    }

    /**
     * A valid registration body. Override any field with $overrides.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function registration(array $overrides = []): array
    {
        $email = 'jane.'.bin2hex(random_bytes(4)).'@example.test';

        return array_merge([
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => $email,
            'email_confirmation' => $email,
            'street' => '123 Main St',
            'street2' => null,
            'city' => 'Orlando',
            'state' => 'FL',
            'zip' => '32803',
            'phone' => '386-555-1234',
            'accept_privacy_policy' => true,
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'cf_turnstile_token' => 'token-123',
        ], $overrides);
    }
}
