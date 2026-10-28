<?php

namespace Tests\Database\Admin;

use App\Mail\EmailSigninMail;
use App\Models\CarShow;
use App\Models\User;
use App\Support\Admin\ImpersonationSession;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * The impersonation marker belongs to one admin -> member switch: any later login on the same browser clears it,
 * stop only works for the impersonated member, and logging out does not sign the real member out elsewhere.
 */
class ImpersonationSessionTest extends AdminTestCase
{
    private const START = '/admin-api/users/%d/impersonate';

    private const STOP = '/admin-api/impersonation/stop';

    private const STATUS = '/admin-api/impersonation';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    /** @return array{0: User, 1: User} admin and the impersonated member; the session now belongs to the member */
    private function impersonating(): array
    {
        $admin = $this->admin();
        $member = User::factory()->create();
        $this->actingAs($admin)->postJson(sprintf(self::START, $member->id))->assertOk();

        return [$admin, $member];
    }

    private function assertNoAdminAccess(User $expected): void
    {
        $this->assertNull(session()->get(ImpersonationSession::KEY));
        $this->getJson(self::STATUS)->assertOk()->assertJsonPath('data.impersonating', false);
        $this->postJson(self::STOP)->assertStatus(409);
        $this->assertSame($expected->id, auth()->id());
        $this->getJson('/admin-api/users')->assertStatus(403);
    }

    /** @return array{token: string, code: string} */
    private function emailSignin(User $user): array
    {
        $this->postJson('/login/email-signin/request', ['email' => $user->email])->assertOk();
        $mail = Mail::sent(EmailSigninMail::class)->last();
        if (! $mail instanceof EmailSigninMail) {
            throw new RuntimeException('No sign-in mail was sent.');
        }
        preg_match('#/login/email-signin/consume/([A-Fa-f0-9]{64})$#', $mail->signInUrl, $matches);

        return ['token' => $matches[1], 'code' => $mail->code];
    }

    #[Test]
    public function start_stores_the_administrator_and_the_member(): void
    {
        [$admin, $member] = $this->impersonating();

        $this->assertSame($admin->id, session()->get(ImpersonationSession::KEY));
        $this->assertSame($member->id, session()->get(ImpersonationSession::TARGET_KEY));
    }

    #[Test]
    public function a_password_login_by_someone_else_clears_the_marker(): void
    {
        $this->impersonating();
        $other = User::factory()->create();

        $this->postJson('/login/login', ['email' => $other->email, 'password' => 'password'])->assertOk();

        $this->assertNoAdminAccess($other);
    }

    #[Test]
    public function an_email_code_sign_in_clears_the_marker(): void
    {
        $this->impersonating();
        $other = User::factory()->create();
        $signin = $this->emailSignin($other);

        $this->postJson('/login/email-signin/verify-code', ['email' => $other->email, 'code' => $signin['code']])->assertOk();

        $this->assertNoAdminAccess($other);
    }

    #[Test]
    public function an_email_link_sign_in_clears_the_marker(): void
    {
        $this->impersonating();
        $other = User::factory()->create();
        $signin = $this->emailSignin($other);

        $this->get('/login/email-signin/consume/'.$signin['token'])->assertRedirect();

        $this->assertNoAdminAccess($other);
    }

    #[Test]
    public function a_waiver_portal_login_clears_the_marker(): void
    {
        $this->impersonating();
        $other = User::factory()->create();
        $show = CarShow::factory()->create();

        $this->postJson('/waiver/'.$show->id.'/login', ['email' => $other->email, 'password' => 'password'])->assertOk();

        $this->assertNoAdminAccess($other);
    }

    #[Test]
    public function a_marker_for_another_member_ends_the_session_instead_of_restoring_the_administrator(): void
    {
        $admin = $this->admin();
        $other = User::factory()->create();
        $this->actingAs($other)->withSession([ImpersonationSession::KEY => $admin->id, ImpersonationSession::TARGET_KEY => $other->id + 1]);

        $this->postJson(self::STOP)->assertStatus(401);

        $this->assertGuest();
        $this->getJson('/admin-api/users')->assertStatus(401);
    }

    #[Test]
    public function a_marker_without_the_member_id_never_restores_the_administrator(): void
    {
        $admin = $this->admin();
        $other = User::factory()->create();
        $this->actingAs($other)->withSession([ImpersonationSession::KEY => $admin->id]);

        $this->postJson(self::STOP)->assertStatus(401);

        $this->assertGuest();
    }

    #[Test]
    public function a_member_disabled_during_the_impersonation_does_not_hand_the_marker_to_the_next_login(): void
    {
        [, $member] = $this->impersonating();
        $member->delete();
        $this->app['auth']->forgetGuards();
        $this->getJson(self::STATUS)->assertStatus(401);
        $other = User::factory()->create();

        $this->postJson('/login/login', ['email' => $other->email, 'password' => 'password'])->assertOk();

        $this->assertNoAdminAccess($other);
    }

    #[Test]
    public function logging_out_while_impersonating_keeps_the_members_remember_token(): void
    {
        [, $member] = $this->impersonating();
        $member->forceFill(['remember_token' => 'member-device-token'])->save();

        $this->postJson('/login/logout')->assertOk();

        $this->assertGuest();
        $this->assertSame('member-device-token', $member->refresh()->remember_token);
    }

    #[Test]
    public function the_waiver_portal_logout_while_impersonating_keeps_the_members_remember_token(): void
    {
        [, $member] = $this->impersonating();
        $member->forceFill(['remember_token' => 'member-device-token'])->save();
        $show = CarShow::factory()->create();

        $this->postJson('/waiver/'.$show->id.'/logout')->assertOk();

        $this->assertGuest();
        $this->assertSame('member-device-token', $member->refresh()->remember_token);
    }

    #[Test]
    public function an_ordinary_logout_still_rotates_the_remember_token(): void
    {
        $member = User::factory()->create(['remember_token' => 'member-device-token']);
        $this->actingAs($member);

        $this->postJson('/login/logout')->assertOk();

        $this->assertNotSame('member-device-token', $member->refresh()->remember_token);
    }
}
