<?php

namespace Tests\Database\Auth;

use App\Mail\ResetPasswordMail;
use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\Support\LoadsLegacySchema;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use LoadsLegacySchema;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function makeUser(array $overrides = []): User
    {
        return User::factory()->create($overrides);
    }

    /**
     * Asks for a reset for the email and returns the plain token from the sent mail.
     */
    protected function requestReset(string $email): string
    {
        $this->postJson('/login/forgot-password', ['email' => $email])->assertOk();

        /** @var ResetPasswordMail|null $mail */
        $mail = Mail::sent(ResetPasswordMail::class)->last();
        if (! $mail instanceof ResetPasswordMail) {
            throw new RuntimeException('No reset mail was sent.');
        }

        return $mail->token;
    }

    /**
     * @return array<string, mixed>
     */
    protected function resetBody(string $token, string $email, string $password = 'new-secret-1', ?string $confirm = null): array
    {
        return [
            'token' => $token,
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $confirm ?? $password,
        ];
    }

    public function test_the_token_is_stored_hashed_and_the_mail_carries_the_hash_route_link(): void
    {
        $user = $this->makeUser();
        $token = $this->requestReset($user->email);

        $row = DB::table('password_reminder')->where('email', $user->email)->first();

        $this->assertNotNull($row);
        $this->assertNotSame($token, $row->token);
        $this->assertTrue(Hash::check($token, $row->token));

        $link = ResetPasswordMail::resetUrl($token, $user->email);
        $this->assertSame(url('/#/reset-password?token='.$token.'&email='.urlencode($user->email)), $link);
        $this->assertStringContainsString('/#/reset-password?token=', $link);

        /** @var ResetPasswordMail $mail */
        $mail = Mail::sent(ResetPasswordMail::class)->last();
        $this->assertTrue($mail->hasTo($user->email));
        $this->assertStringContainsString(htmlspecialchars($link, ENT_QUOTES), $mail->render());
    }

    public function test_unknown_and_soft_deleted_emails_get_the_same_200_body_and_no_mail(): void
    {
        $known = $this->makeUser();
        $deleted = $this->makeUser();
        $deleted->delete();

        $knownBody = $this->postJson('/login/forgot-password', ['email' => $known->email])->assertOk()->getContent();
        $unknownBody = $this->postJson('/login/forgot-password', ['email' => 'nobody@example.com'])->assertOk()->getContent();
        $deletedBody = $this->postJson('/login/forgot-password', ['email' => $deleted->email])->assertOk()->getContent();

        $this->assertSame('{"ok":true}', $knownBody);
        $this->assertSame($knownBody, $unknownBody);
        $this->assertSame($knownBody, $deletedBody);

        Mail::assertSent(ResetPasswordMail::class, 1);
        $this->assertFalse(DB::table('password_reminder')->where('email', 'nobody@example.com')->exists());
    }

    public function test_forgot_password_validates_the_email_with_a_standard_422(): void
    {
        $this->postJson('/login/forgot-password', ['email' => 'nope'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_forgot_password_is_throttled_per_email_with_429(): void
    {
        $user = $this->makeUser();

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/login/forgot-password', ['email' => $user->email])->assertOk();
        }

        $this->postJson('/login/forgot-password', ['email' => $user->email])->assertStatus(429);
    }

    public function test_a_valid_reset_sets_the_password_logs_in_and_returns_the_payload(): void
    {
        $user = $this->makeUser();
        $oldRemember = $user->remember_token;
        $token = $this->requestReset($user->email);

        $this->postJson('/login/reset-password', $this->resetBody($token, $user->email))
            ->assertOk()
            ->assertJsonPath('user_id', $user->id)
            ->assertJsonPath('email', $user->email)
            ->assertJsonStructure(['user_id', 'user_level', 'type_id', 'email', 'first_name', 'profileURL', 'slideOutName', 'fullAddress']);

        $this->assertAuthenticatedAs($user);

        $user->refresh();
        $this->assertTrue(Hash::check('new-secret-1', $user->password));
        $this->assertNotSame($oldRemember, $user->remember_token);
        $this->assertFalse(DB::table('password_reminder')->where('email', $user->email)->exists());
    }

    public function test_a_reset_token_works_once_only(): void
    {
        $user = $this->makeUser();
        $token = $this->requestReset($user->email);

        $this->postJson('/login/reset-password', $this->resetBody($token, $user->email))->assertOk();

        $this->postJson('/login/reset-password', $this->resetBody($token, $user->email, 'other-secret-2'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['token']);
    }

    public function test_a_token_is_rejected_after_60_minutes(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-25 12:00:00'));
        $user = $this->makeUser();
        $token = $this->requestReset($user->email);

        Carbon::setTestNow(Carbon::parse('2026-10-25 13:01:00'));

        $this->postJson('/login/reset-password', $this->resetBody($token, $user->email))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['token']);
    }

    public function test_a_token_is_accepted_at_59_minutes(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-25 12:00:00'));
        $user = $this->makeUser();
        $token = $this->requestReset($user->email);

        Carbon::setTestNow(Carbon::parse('2026-10-25 12:59:00'));

        $this->postJson('/login/reset-password', $this->resetBody($token, $user->email))->assertOk();
    }

    public function test_a_bad_token_and_an_unknown_email_get_the_same_422(): void
    {
        $user = $this->makeUser();
        $this->requestReset($user->email);

        $badToken = $this->postJson('/login/reset-password', $this->resetBody('not-the-token', $user->email));
        $unknown = $this->postJson('/login/reset-password', $this->resetBody('not-the-token', 'nobody@example.com'));

        $badToken->assertUnprocessable()
            ->assertJsonStructure(['message', 'errors' => ['token']])
            ->assertJsonMissingValidationErrors(['email']);

        $this->assertSame($badToken->getContent(), $unknown->getContent());
        $this->assertSame(422, $unknown->status());
    }

    public function test_a_legacy_plaintext_reminder_token_is_rejected_without_crashing(): void
    {
        $user = $this->makeUser();
        DB::table('password_reminder')->insert([
            'email' => $user->email,
            'token' => 'legacy-plain-token',
            'created_at' => now(),
        ]);

        $this->postJson('/login/reset-password', $this->resetBody('legacy-plain-token', $user->email))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['token']);

        $this->assertGuest();
    }

    public function test_the_password_must_be_at_least_6_characters_and_match_the_confirmation(): void
    {
        $user = $this->makeUser();
        $token = $this->requestReset($user->email);

        $this->postJson('/login/reset-password', $this->resetBody($token, $user->email, 'abc', 'abc'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);

        $this->postJson('/login/reset-password', $this->resetBody($token, $user->email, 'new-secret-1', 'different-1'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);
    }

    public function test_reset_attempts_are_throttled_with_429(): void
    {
        $user = $this->makeUser();

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/login/reset-password', $this->resetBody('bad-token', $user->email))
                ->assertUnprocessable();
        }

        $this->postJson('/login/reset-password', $this->resetBody('bad-token', $user->email))
            ->assertStatus(429);
    }

    public function test_a_reset_without_a_csrf_token_gets_419(): void
    {
        // Laravel skips CSRF checks while the app env is "testing". Switch it for this test only.
        app()->instance('env', 'local');

        $this->withHeader('Accept', 'application/json')
            ->post('/login/reset-password', $this->resetBody('x', 'someone@example.com'))
            ->assertStatus(419);
    }

    public function test_the_reset_link_in_the_mail_is_built_from_app_url_not_the_host_header(): void
    {
        $user = $this->makeUser();

        $this->withServerVariables(['HTTP_HOST' => 'evil.example'])
            ->postJson('/login/forgot-password', ['email' => $user->email])
            ->assertOk();

        /** @var ResetPasswordMail $mail */
        $mail = Mail::sent(ResetPasswordMail::class)->last();
        $html = $mail->render();

        $this->assertStringNotContainsString('evil.example', $html);
        $this->assertStringContainsString(rtrim((string) config('app.url'), '/').'/#/reset-password?token=', $html);
    }

    public function test_the_reset_row_and_the_mail_are_written_after_the_response_is_sent(): void
    {
        $user = $this->makeUser();
        $request = Request::create('/login/forgot-password', 'POST', ['email' => $user->email], [], [], [
            'HTTP_ACCEPT' => 'application/json',
        ]);

        $kernel = $this->app->make(Kernel::class);
        $response = $kernel->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        Mail::assertNotSent(ResetPasswordMail::class);
        $this->assertFalse(DB::table('password_reminder')->where('email', $user->email)->exists());

        $kernel->terminate($request, $response);

        Mail::assertSent(ResetPasswordMail::class, 1);
        $this->assertTrue(DB::table('password_reminder')->where('email', $user->email)->exists());
    }
}
