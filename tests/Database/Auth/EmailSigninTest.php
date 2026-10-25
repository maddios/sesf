<?php

namespace Tests\Database\Auth;

use App\Http\Resources\Auth\LoginUserResource;
use App\Mail\EmailSigninMail;
use App\Models\LoginEmailSignin;
use App\Models\User;
use App\Services\Auth\EmailSigninService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\LoadsLegacySchema;
use Tests\TestCase;

class EmailSigninTest extends TestCase
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
     * Requests a sign-in for the email and returns the plain url token and code from the sent mail.
     *
     * @return array{token: string, code: string}
     */
    protected function requestSignin(string $email, ?string $redirectPath = null): array
    {
        $payload = ['email' => $email];
        if ($redirectPath !== null) {
            $payload['redirect_path'] = $redirectPath;
        }

        $this->postJson('/login/email-signin/request', $payload)->assertOk();

        /** @var EmailSigninMail|null $mail */
        $mail = Mail::sent(EmailSigninMail::class)->last();
        if (! $mail instanceof EmailSigninMail) {
            throw new RuntimeException('No sign-in mail was sent.');
        }

        preg_match('#/login/email-signin/consume/([A-Fa-f0-9]{64})$#', $mail->signInUrl, $matches);

        return ['token' => $matches[1], 'code' => $mail->code];
    }

    protected function latestRow(User $user): LoginEmailSignin
    {
        /** @var LoginEmailSignin $row */
        $row = LoginEmailSignin::query()->where('user_id', $user->id)->orderByDesc('id')->firstOrFail();

        return $row;
    }

    /**
     * Sends $count codes that are guaranteed wrong.
     */
    protected function sendWrongCodes(User $user, string $code, int $count): void
    {
        $wrong = $code === '000000' ? '000001' : '000000';
        for ($i = 0; $i < $count; $i++) {
            $this->postJson('/login/email-signin/verify-code', ['email' => $user->email, 'code' => $wrong])
                ->assertStatus(401);
        }
    }

    public function test_stores_hmac_hashes_of_the_url_token_and_code_never_the_plaintext(): void
    {
        $user = $this->makeUser();
        ['token' => $token, 'code' => $code] = $this->requestSignin($user->email);

        $row = $this->latestRow($user);

        $this->assertSame(hash_hmac('sha256', $token, (string) config('app.key')), $row->token_hash);
        $this->assertSame(hash_hmac('sha256', 'code|'.$code, (string) config('app.key')), $row->code_hash);
        $this->assertNotSame($token, $row->token_hash);
        $this->assertNotSame($code, $row->code_hash);
        $this->assertMatchesRegularExpression('/^[0-9]{6}$/', $code);
    }

    public function test_zero_pads_codes_to_six_digits(): void
    {
        $this->assertSame('000007', EmailSigninService::formatCode(7));
        $this->assertSame('000000', EmailSigninService::formatCode(0));
        $this->assertSame('123456', EmailSigninService::formatCode(123456));
    }

    public function test_known_unknown_and_soft_deleted_emails_get_the_same_200_body(): void
    {
        $known = $this->makeUser();
        $deleted = $this->makeUser();
        $deleted->delete();

        $knownResponse = $this->postJson('/login/email-signin/request', ['email' => $known->email]);
        $unknownResponse = $this->postJson('/login/email-signin/request', ['email' => 'nobody@example.com']);
        $deletedResponse = $this->postJson('/login/email-signin/request', ['email' => $deleted->email]);

        $knownResponse->assertOk()->assertExactJson(['ok' => true]);
        $this->assertSame($knownResponse->getContent(), $unknownResponse->getContent());
        $this->assertSame(200, $unknownResponse->status());
        $this->assertSame($knownResponse->getContent(), $deletedResponse->getContent());

        Mail::assertSent(EmailSigninMail::class, 1);
        $this->assertSame(0, LoginEmailSignin::query()->where('user_id', $deleted->id)->count());
    }

    public function test_answers_200_even_when_the_mail_send_fails(): void
    {
        $user = $this->makeUser();
        Mail::shouldReceive('to')->andThrow(new RuntimeException('smtp down'));

        $this->postJson('/login/email-signin/request', ['email' => $user->email])
            ->assertOk()
            ->assertExactJson(['ok' => true]);

        $this->assertSame(1, LoginEmailSignin::query()->where('user_id', $user->id)->count());
    }

    public function test_validates_the_email_field_with_a_standard_422(): void
    {
        $this->postJson('/login/email-signin/request', ['email' => 'not-an-email'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_a_new_request_closes_earlier_pending_rows(): void
    {
        $user = $this->makeUser();
        $this->requestSignin($user->email);
        $first = $this->latestRow($user);

        $this->requestSignin($user->email);
        $second = $this->latestRow($user);

        $this->assertNotNull($first->fresh()?->used_at);
        $this->assertNull($second->used_at);
        $this->assertNotSame($first->id, $second->id);
    }

    public function test_the_code_expires_after_30_minutes(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-25 12:00:00'));
        $user = $this->makeUser();
        ['code' => $code] = $this->requestSignin($user->email);

        Carbon::setTestNow(Carbon::parse('2026-10-25 12:31:00'));

        $this->postJson('/login/email-signin/verify-code', ['email' => $user->email, 'code' => $code])
            ->assertStatus(401);
    }

    public function test_a_code_is_accepted_once_only(): void
    {
        $user = $this->makeUser();
        ['code' => $code] = $this->requestSignin($user->email);

        $this->postJson('/login/email-signin/verify-code', ['email' => $user->email, 'code' => $code])
            ->assertOk();

        $this->postJson('/login/email-signin/verify-code', ['email' => $user->email, 'code' => $code])
            ->assertStatus(401)
            ->assertExactJson([EmailSigninService::INVALID_MESSAGE]);
    }

    public function test_five_wrong_codes_close_the_row_even_for_the_right_code_afterwards(): void
    {
        $user = $this->makeUser();
        ['code' => $code] = $this->requestSignin($user->email);
        $this->sendWrongCodes($user, $code, 5);

        $this->assertNotNull($this->latestRow($user)->used_at);

        $this->postJson('/login/email-signin/verify-code', ['email' => $user->email, 'code' => $code])
            ->assertStatus(401);
    }

    public function test_four_wrong_codes_still_leave_the_right_code_working(): void
    {
        $user = $this->makeUser();
        ['code' => $code] = $this->requestSignin($user->email);
        $this->sendWrongCodes($user, $code, 4);

        $this->postJson('/login/email-signin/verify-code', ['email' => $user->email, 'code' => $code])
            ->assertOk();
    }

    public function test_the_login_payload_and_redirect_path_are_returned_on_a_correct_code(): void
    {
        $user = $this->makeUser();
        ['code' => $code] = $this->requestSignin($user->email, '/judging/abc');

        $this->postJson('/login/email-signin/verify-code', ['email' => $user->email, 'code' => $code])
            ->assertOk()
            ->assertJsonStructure(['user_id', 'user_level', 'type_id', 'email', 'first_name', 'profileURL', 'slideOutName', 'fullAddress', 'redirect_path'])
            ->assertJsonPath('redirect_path', '/judging/abc')
            ->assertJsonPath('user_id', $user->id)
            ->assertJsonPath('email', $user->email);

        $this->assertAuthenticatedAs($user);
    }

    public function test_the_login_resource_keys_match_the_password_login_contract(): void
    {
        $user = $this->makeUser(['first' => 'Shark', 'last' => 'Fan']);

        $payload = (new LoginUserResource($user))->resolve(Request::create('/'));

        $this->assertSame(
            ['user_id', 'user_level', 'type_id', 'email', 'first_name', 'profileURL', 'slideOutName', 'fullAddress'],
            array_keys($payload),
        );
        $this->assertSame('Shark', $payload['first_name']);
        $this->assertArrayNotHasKey('password', $payload);
    }

    public function test_spaces_are_stripped_from_the_code_before_checking(): void
    {
        $user = $this->makeUser();
        ['code' => $code] = $this->requestSignin($user->email);
        $spaced = substr($code, 0, 3).' '.substr($code, 3);

        $this->postJson('/login/email-signin/verify-code', ['email' => $user->email, 'code' => $spaced])
            ->assertOk();
    }

    public function test_every_bad_code_gets_the_same_401_including_malformed_and_unknown_email(): void
    {
        $user = $this->makeUser();
        $this->requestSignin($user->email);

        $responses = [
            $this->postJson('/login/email-signin/verify-code', ['email' => $user->email, 'code' => '999999']),
            $this->postJson('/login/email-signin/verify-code', ['email' => $user->email, 'code' => '12345']),
            $this->postJson('/login/email-signin/verify-code', ['email' => $user->email, 'code' => 'abcdef']),
            $this->postJson('/login/email-signin/verify-code', ['email' => 'nobody@example.com', 'code' => '123456']),
        ];

        foreach ($responses as $response) {
            $response->assertStatus(401)->assertExactJson([EmailSigninService::INVALID_MESSAGE]);
        }
    }

    public function test_100_sign_ins_today_give_503_with_no_new_row_and_no_mail(): void
    {
        $user = $this->makeUser();
        LoginEmailSignin::factory()->count(LoginEmailSignin::DAILY_QUOTA)->create(['user_id' => $user->id]);

        $this->postJson('/login/email-signin/request', ['email' => $user->email])
            ->assertStatus(503)
            ->assertExactJson([
                'ok' => false,
                'message' => 'Email sign-in is temporarily unavailable. Please use your password.',
            ]);

        $this->postJson('/login/email-signin/request', ['email' => 'nobody@example.com'])
            ->assertStatus(503);

        $this->assertSame(LoginEmailSignin::DAILY_QUOTA, LoginEmailSignin::query()->count());
        Mail::assertNothingSent();
    }

    public function test_yesterday_sign_ins_do_not_count_against_today(): void
    {
        $user = $this->makeUser();
        LoginEmailSignin::factory()->sentYesterday()->count(LoginEmailSignin::DAILY_QUOTA)->create(['user_id' => $user->id]);

        $this->postJson('/login/email-signin/request', ['email' => $user->email])
            ->assertOk();
    }

    /**
     * @return array<string, array{0: string|null, 1: string|null}>
     */
    public static function consumeRedirects(): array
    {
        return [
            'waiver path' => ['/waiver/12', '/waiver/12'],
            'judging path' => ['/judging/abc/results', '/judging/abc/results'],
            'app path' => ['/members/profile', '/members/profile'],
            'no path' => [null, null],
        ];
    }

    #[DataProvider('consumeRedirects')]
    public function test_a_consumed_link_logs_the_user_in_and_redirects(?string $requested, ?string $expectedPath): void
    {
        $user = $this->makeUser();
        ['token' => $token] = $this->requestSignin($user->email, $requested);

        $this->get('/login/email-signin/consume/'.$token)
            ->assertRedirect(url(EmailSigninService::redirectTarget($expectedPath)));

        $this->assertAuthenticatedAs($user);
    }

    public function test_an_invalid_or_used_link_goes_to_the_login_page(): void
    {
        $user = $this->makeUser();
        ['token' => $token] = $this->requestSignin($user->email);
        $this->get('/login/email-signin/consume/'.$token);

        $this->get('/login/email-signin/consume/'.$token)
            ->assertRedirect(url('/#/login?email_signin=invalid'));

        $this->get('/login/email-signin/consume/'.str_repeat('a', 64))
            ->assertRedirect(url('/#/login?email_signin=invalid'));
    }

    public function test_an_expired_link_goes_to_the_login_page(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-25 12:00:00'));
        $user = $this->makeUser();
        ['token' => $token] = $this->requestSignin($user->email);
        Carbon::setTestNow(Carbon::parse('2026-10-25 12:45:00'));

        $this->get('/login/email-signin/consume/'.$token)
            ->assertRedirect(url('/#/login?email_signin=invalid'));
    }

    public function test_a_link_token_that_is_not_64_hex_characters_is_a_404(): void
    {
        $this->get('/login/email-signin/consume/not-a-token')->assertNotFound();
    }

    /**
     * @return array<string, array{0: mixed, 1: string|null}>
     */
    public static function redirectPaths(): array
    {
        return [
            'null' => [null, null],
            'empty' => ['', null],
            'waiver' => ['/waiver/12', '/waiver/12'],
            'judging' => ['/judging/x', '/judging/x'],
            'app route' => ['/members', '/members'],
            'nested app route' => ['/a/b_c-d', '/a/b_c-d'],
            'no leading slash' => ['members', null],
            'protocol relative' => ['//evil.example', null],
            'absolute url' => ['https://evil.example/x', null],
            'dot dot' => ['/a/../b', null],
            'query string' => ['/x?y=1', null],
            'space' => ['/bad path', null],
            'trailing newline is trimmed like legacy' => ["/ok\n", '/ok'],
            'root only' => ['/', null],
            'array' => [['/a'], null],
            'exactly 1024' => ['/'.str_repeat('a', 1023), '/'.str_repeat('a', 1023)],
            'over 1024' => ['/'.str_repeat('a', 1024), null],
        ];
    }

    #[DataProvider('redirectPaths')]
    public function test_redirect_paths_follow_the_legacy_rule(mixed $raw, ?string $expected): void
    {
        $this->assertSame($expected, EmailSigninService::normalizeRedirectPath($raw));
    }

    public function test_an_invalid_redirect_path_is_stored_as_null(): void
    {
        $user = $this->makeUser();
        $this->requestSignin($user->email, '//evil.example/x');

        $this->assertNull($this->latestRow($user)->redirect_path);
    }

    public function test_sign_in_requests_for_one_email_are_throttled_with_429(): void
    {
        $user = $this->makeUser();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/login/email-signin/request', ['email' => $user->email])->assertOk();
        }

        $this->postJson('/login/email-signin/request', ['email' => $user->email])->assertStatus(429);
    }

    public function test_code_checks_for_one_email_are_throttled_with_429(): void
    {
        $user = $this->makeUser();

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/login/email-signin/verify-code', ['email' => $user->email, 'code' => '000000'])
                ->assertStatus(401);
        }

        $this->postJson('/login/email-signin/verify-code', ['email' => $user->email, 'code' => '000000'])
            ->assertStatus(429);
    }

    public function test_state_changing_requests_without_a_csrf_token_get_419(): void
    {
        // Laravel skips CSRF checks while the app env is "testing". Switch it for this test only.
        app()->instance('env', 'local');

        $this->withHeader('Accept', 'application/json')
            ->post('/login/email-signin/request', ['email' => 'someone@example.com'])
            ->assertStatus(419);
    }
}
