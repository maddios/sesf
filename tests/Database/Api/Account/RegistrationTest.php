<?php

namespace Tests\Database\Api\Account;

use App\Mail\AdminNewAccountMail;
use App\Mail\NewAccountMail;
use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class RegistrationTest extends AccountTestCase
{
    private const DUPLICATE = 'Looks like you have already registered. Please click LOGIN at the top.';

    #[Test]
    public function a_valid_registration_creates_the_member_logs_them_in_and_mails_both_parties(): void
    {
        $this->turnstile();
        $body = $this->registration(['email' => 'new.member@example.test', 'email_confirmation' => 'new.member@example.test']);

        $this->postJson('/member/register', $body)
            ->assertOk()
            ->assertJsonPath('email', 'new.member@example.test')
            ->assertJsonPath('first_name', 'Jane')
            ->assertJsonStructure(['user_id', 'user_level', 'type_id', 'email', 'first_name', 'profileURL', 'slideOutName', 'fullAddress']);

        $user = User::query()->where('email', 'new.member@example.test')->firstOrFail();
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
        $this->assertSame(1, DB::table('user_preferences')->where('user_id', $user->id)->count());

        Mail::assertSent(NewAccountMail::class, fn (NewAccountMail $mail) => $mail->hasTo('new.member@example.test'));
        Mail::assertSent(AdminNewAccountMail::class, fn (AdminNewAccountMail $mail) => $mail->hasTo(config('sesf.reg_email')));
    }

    #[Test]
    public function the_registration_payload_matches_the_password_login_payload(): void
    {
        $this->turnstile();
        $body = $this->registration();

        $registered = $this->postJson('/member/register', $body)->assertOk()->json();

        Auth::guard('web')->logout();
        $loggedIn = $this->postJson('/login/login', ['email' => $body['email'], 'password' => self::PASSWORD])
            ->assertOk()
            ->json();

        $this->assertSame(array_keys($loggedIn), array_keys($registered));
        $this->assertSame($loggedIn, $registered);
    }

    #[Test]
    public function the_welcome_and_admin_mails_are_sent_after_the_response_is_returned(): void
    {
        $this->turnstile();
        $body = $this->registration();

        $kernel = $this->app->make(Kernel::class);
        $request = Request::create('/member/register', 'POST', $body, [], [], ['HTTP_ACCEPT' => 'application/json']);
        $response = $kernel->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        Mail::assertNotSent(NewAccountMail::class);
        Mail::assertNotSent(AdminNewAccountMail::class);

        $kernel->terminate($request, $response);

        Mail::assertSent(NewAccountMail::class, 1);
        Mail::assertSent(AdminNewAccountMail::class, 1);
    }

    #[Test]
    public function a_duplicate_email_gets_the_generic_message(): void
    {
        $this->turnstile();
        $existing = $this->makeUser(['email' => 'taken@example.test']);

        $this->postJson('/member/register', $this->registration(['email' => $existing->email, 'email_confirmation' => $existing->email]))
            ->assertStatus(400)
            ->assertExactJson([self::DUPLICATE]);

        $this->assertGuest('web');
    }

    #[Test]
    public function a_soft_deleted_duplicate_gets_the_same_generic_message_with_no_hint_of_deletion(): void
    {
        $this->turnstile();
        $gone = $this->makeUser(['email' => 'gone@example.test']);
        $gone->delete();

        $response = $this->postJson('/member/register', $this->registration(['email' => 'gone@example.test', 'email_confirmation' => 'gone@example.test']))
            ->assertStatus(400)
            ->assertExactJson([self::DUPLICATE]);

        $this->assertStringNotContainsStringIgnoringCase('deleted', $response->getContent());
        $this->assertStringNotContainsString('*', $response->getContent());
    }

    #[Test]
    public function a_matching_name_does_not_reveal_an_existing_email(): void
    {
        $this->turnstile();
        $this->makeUser(['first' => 'Jane', 'last' => 'Doe', 'email' => 'jane.existing@example.test']);

        $response = $this->postJson('/member/register', $this->registration(['first_name' => 'Jane', 'last_name' => 'Doe']))
            ->assertOk();

        $this->assertStringNotContainsString('jane.existing', $response->getContent());
        $this->assertStringNotContainsString('*', $response->getContent());
    }

    #[Test]
    public function a_mail_failure_does_not_fail_the_registration(): void
    {
        $this->turnstile();
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp down'));

        $this->postJson('/member/register', $this->registration())->assertOk();
        $this->assertAuthenticated('web');
    }

    #[Test]
    public function a_missing_turnstile_token_is_rejected_without_calling_cloudflare(): void
    {
        $body = $this->registration();
        unset($body['cf_turnstile_token']);

        $this->postJson('/member/register', $body)
            ->assertStatus(400)
            ->assertJson(['Please complete the security check before submitting.']);
    }

    #[Test]
    public function a_rejected_turnstile_token_is_refused(): void
    {
        $this->turnstile(false);

        $this->postJson('/member/register', $this->registration())
            ->assertStatus(400)
            ->assertJson(['Please complete the security check before submitting.']);
        $this->assertGuest('web');
    }

    #[Test]
    public function the_privacy_policy_must_be_accepted(): void
    {
        $this->turnstile();

        $this->postJson('/member/register', $this->registration(['accept_privacy_policy' => false]))
            ->assertStatus(400)
            ->assertJson(['You must accept the Privacy Policy to complete your registration.']);
    }

    #[Test]
    public function the_email_must_match_its_confirmation(): void
    {
        $this->turnstile();

        $this->postJson('/member/register', $this->registration(['email_confirmation' => 'other@example.test']))
            ->assertStatus(400);
        $this->assertSame(0, User::query()->where('email', 'like', 'jane.%')->count());
    }

    #[Test]
    public function the_password_must_be_at_least_six_characters_and_confirmed(): void
    {
        $this->turnstile();

        $this->postJson('/member/register', $this->registration(['password' => 'short', 'password_confirmation' => 'short']))
            ->assertStatus(400);
        $this->postJson('/member/register', $this->registration(['password_confirmation' => 'different-1']))
            ->assertStatus(400);
    }

    /**
     * @return array<string, array{0: string, 1: mixed}>
     */
    public static function invalidFields(): array
    {
        return [
            'first name with markup' => ['first_name', '<script>alert(1)</script>'],
            'last name too long' => ['last_name', str_repeat('a', 63)],
            'street missing' => ['street', null],
            'street2 too long' => ['street2', str_repeat('a', 71)],
            'city too long' => ['city', str_repeat('a', 61)],
            'state too long' => ['state', str_repeat('a', 11)],
            'zip with spaces' => ['zip', '32 803'],
            'phone wrong shape' => ['phone', '12345'],
            'email invalid' => ['email', 'not-an-email'],
        ];
    }

    #[Test]
    #[DataProvider('invalidFields')]
    public function invalid_fields_are_rejected_with_the_legacy_message_array(string $field, mixed $value): void
    {
        $this->turnstile();

        $this->postJson('/member/register', $this->registration([$field => $value]))
            ->assertStatus(400)
            ->assertJsonIsArray();
        $this->assertGuest('web');
    }

    #[Test]
    public function registration_is_rate_limited_to_ten_attempts_a_minute(): void
    {
        $this->turnstile(false);

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/member/register', $this->registration(['cf_turnstile_token' => ''])); // fails validation
        }

        $this->postJson('/member/register', $this->registration())->assertStatus(429);
    }

    #[Test]
    public function a_registration_without_a_csrf_token_gets_419(): void
    {
        $this->app->instance('env', 'local');

        $this->withHeader('Accept', 'application/json')
            ->post('/member/register', $this->registration())
            ->assertStatus(419);
    }
}
