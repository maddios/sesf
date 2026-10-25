<?php

namespace Tests\Database\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\LoadsLegacySchema;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use LoadsLegacySchema;

    private const AUTH_LOGIN_GENERIC = 'Either your email or password are incorrect';

    private const AUTH_LOGIN_DELETED = 'Your account has been deleted. Please contact an administrator to have it re-enabled.';

    #[Test]
    public function valid_credentials_return_the_legacy_member_payload_and_start_a_session(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-horse')]);

        $response = $this->post('/login/login', ['email' => $user->email, 'password' => 'correct-horse']);

        $response->assertOk()
            ->assertJsonPath('user_id', $user->id)
            ->assertJsonPath('email', $user->email);
        $this->assertAuthenticatedAs($user, 'web');
    }

    #[Test]
    public function a_wrong_password_returns_401_with_the_legacy_message_array(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-horse')]);

        $response = $this->post('/login/login', ['email' => $user->email, 'password' => 'wrong-horse']);

        $response->assertStatus(401)->assertExactJson([self::AUTH_LOGIN_GENERIC]);
        $this->assertGuest('web');
    }

    #[Test]
    public function an_unknown_email_returns_the_same_401_message_as_a_wrong_password(): void
    {
        $response = $this->post('/login/login', ['email' => 'nobody-here@example.test', 'password' => 'whatever1']);

        $response->assertStatus(401)->assertExactJson([self::AUTH_LOGIN_GENERIC]);
    }

    #[Test]
    public function a_deleted_account_is_named_only_when_the_correct_password_is_given(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('correct-horse'),
            'deleted_at' => now(),
        ]);

        $this->post('/login/login', ['email' => $user->email, 'password' => 'correct-horse'])
            ->assertStatus(401)
            ->assertExactJson([self::AUTH_LOGIN_DELETED]);

        $this->post('/login/login', ['email' => $user->email, 'password' => 'wrong-horse'])
            ->assertStatus(401)
            ->assertExactJson([self::AUTH_LOGIN_GENERIC]);

        $this->assertGuest('web');
    }

    #[Test]
    public function a_user_with_an_empty_password_hash_cannot_log_in(): void
    {
        // UserObserver drops blank passwords on save, so the empty hash is written directly.
        $user = User::factory()->create();
        DB::table('user')->where('id', $user->id)->update(['password' => '']);

        $this->post('/login/login', ['email' => $user->email, 'password' => ''])
            ->assertStatus(401)
            ->assertJsonIsArray();

        $this->post('/login/login', ['email' => $user->email, 'password' => 'anything'])
            ->assertStatus(401)
            ->assertExactJson([self::AUTH_LOGIN_GENERIC]);
        $this->assertGuest('web');
    }

    #[Test]
    public function a_legacy_2y_10_bcrypt_hash_verifies_and_logs_in(): void
    {
        $legacy = password_hash('legacy-pass', PASSWORD_BCRYPT, ['cost' => 10]);
        $this->assertTrue(str_starts_with($legacy, '$2y$10$'));
        $user = User::factory()->create(['password' => $legacy]);

        $this->post('/login/login', ['email' => $user->email, 'password' => 'legacy-pass'])->assertOk();
        $this->assertAuthenticatedAs($user, 'web');
    }

    #[Test]
    public function remember_true_sets_a_30_day_remember_cookie(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-horse')]);

        $remembered = $this->post('/login/login', [
            'email' => $user->email,
            'password' => 'correct-horse',
            'remember' => true,
        ]);
        $cookie = collect($remembered->headers->getCookies())
            ->first(fn ($c) => str_starts_with($c->getName(), 'remember_web_'));

        $this->assertNotNull($cookie);
        $this->assertGreaterThan(time() + 30 * 86400 - 120, $cookie->getExpiresTime());
        $this->assertLessThan(time() + 30 * 86400 + 120, $cookie->getExpiresTime());
    }

    #[Test]
    public function a_login_without_remember_sets_no_remember_cookie(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-horse')]);

        $plain = $this->post('/login/login', ['email' => $user->email, 'password' => 'correct-horse']);

        $none = collect($plain->headers->getCookies())
            ->first(fn ($c) => str_starts_with($c->getName(), 'remember_web_'));
        $this->assertNull($none);
    }

    #[Test]
    public function the_session_id_changes_on_successful_login(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-horse')]);
        $this->startSession();
        $before = $this->app['session.store']->getId();

        $this->post('/login/login', ['email' => $user->email, 'password' => 'correct-horse'])->assertOk();

        $this->assertNotSame($before, $this->app['session.store']->getId());
    }

    #[Test]
    public function invalid_input_returns_401_with_validation_messages_as_an_array(): void
    {
        $this->post('/login/login', ['email' => 'not-an-email'])
            ->assertStatus(401)
            ->assertJsonIsArray()
            ->assertJsonCount(2);
    }

    #[Test]
    public function the_eleventh_login_attempt_in_a_minute_is_throttled_with_429(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-horse')]);

        for ($i = 0; $i < 10; $i++) {
            $this->post('/login/login', ['email' => $user->email, 'password' => 'wrong-horse'])->assertStatus(401);
        }

        $this->post('/login/login', ['email' => $user->email, 'password' => 'correct-horse'])->assertStatus(429);
        $this->assertGuest('web');
    }

    #[Test]
    public function session_cookie_is_named_sesf_session_http_only_and_lax(): void
    {
        $this->assertSame('sesf_session', config('session.cookie'));
        $this->assertTrue(config('session.http_only'));
        $this->assertSame('lax', config('session.same_site'));
    }

    #[Test]
    public function post_login_without_a_csrf_token_is_rejected_with_419_and_with_a_token_it_works(): void
    {
        // The test runner is treated as unit testing, which skips CSRF checks. Pretend to be
        // the app so the middleware runs.
        $this->app->instance('env', 'local');
        $user = User::factory()->create(['password' => Hash::make('correct-horse')]);
        $payload = ['email' => $user->email, 'password' => 'correct-horse'];

        $this->post('/login/login', $payload)->assertStatus(419);

        $this->withSession(['_token' => 'csrf-test-token'])
            ->post('/login/login', $payload + ['_token' => 'csrf-test-token'])
            ->assertOk();
    }

    #[Test]
    public function login_payload_never_includes_the_hash_or_remember_token(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-horse')]);

        $body = $this->post('/login/login', ['email' => $user->email, 'password' => 'correct-horse'])->getContent();

        $this->assertIsString($body);
        $this->assertStringNotContainsString('password', $body);
        $this->assertStringNotContainsString('remember_token', $body);
    }

    /**
     * Response time in milliseconds for one password login attempt.
     */
    private function timedLogin(string $email, string $password): float
    {
        $start = microtime(true);
        $this->post('/login/login', ['email' => $email, 'password' => $password]);

        return (microtime(true) - $start) * 1000;
    }

    #[Test]
    public function a_wrong_password_an_unknown_email_and_a_success_take_about_the_same_time(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-horse')]);

        $wrong = $this->timedLogin($user->email, 'wrong-horse');
        $unknown = $this->timedLogin('nobody-here@example.test', 'wrong-horse');
        $success = $this->timedLogin($user->email, 'correct-horse');

        $this->assertGreaterThanOrEqual(450, $wrong, 'wrong password answered too fast');
        $this->assertGreaterThanOrEqual(450, $unknown, 'unknown email answered too fast');
        $this->assertGreaterThanOrEqual(450, $success, 'success answered too fast');
        $this->assertLessThan(150, abs($wrong - $unknown), 'wrong password and unknown email differ in time');
        $this->assertLessThan(150, abs($success - $unknown), 'success and unknown email differ in time');
    }
}
