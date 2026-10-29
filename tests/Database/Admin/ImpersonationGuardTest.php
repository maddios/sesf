<?php

namespace Tests\Database\Admin;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * While an administrator is logged in as a member: account-security changes are refused (block.impersonated), every
 * successful member write is audited with the administrator's id, and the admin password-reset mail failure is a
 * clean 502.
 */
class ImpersonationGuardTest extends AdminTestCase
{
    private const START = '/admin-api/users/%d/impersonate';

    /**
     * Start an impersonation as an administrator. The session now belongs to the returned member.
     */
    private function impersonating(): User
    {
        $admin = $this->admin();
        $member = User::factory()->create(['first' => 'Mona']);
        $this->actingAs($admin)->postJson(sprintf(self::START, $member->id))->assertOk();

        return $member;
    }

    /**
     * @return array<string, array{string, string, array<string, mixed>}>
     */
    public static function blockedChanges(): array
    {
        return [
            'password change' => ['login/update-password', 'POST', ['old_password' => 'password', 'new_password' => 'brand-new-secret', 'verify_password' => 'brand-new-secret']],
            'account info (email)' => ['member/account-info', 'POST', ['email' => 'changed@example.test']],
            'delete account' => ['member/delete-account', 'POST', ['password' => 'password']],
            'reset password by token' => ['login/reset-password', 'POST', ['token' => 'x', 'email' => 'x@example.test', 'password' => 'brand-new-secret', 'password_confirmation' => 'brand-new-secret']],
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[Test]
    #[DataProvider('blockedChanges')]
    public function account_security_changes_are_refused_while_impersonating(string $path, string $method, array $body): void
    {
        $member = $this->impersonating();
        $hashBefore = $member->refresh()->password;

        $this->json($method, '/'.$path, $body, self::JSON)
            ->assertStatus(403)
            ->assertJsonPath('message', 'This change is not available while you are logged in as another user.');

        $this->assertSame($hashBefore, $member->refresh()->password);
        $blocked = array_values(array_filter($this->auditEntries(), fn (array $e) => ($e['action'] ?? null) === 'impersonation.blocked'));
        $this->assertCount(1, $blocked);
        $this->assertSame('/'.$path, $blocked[0]['path']);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[Test]
    #[DataProvider('blockedChanges')]
    public function the_same_routes_are_not_blocked_for_an_ordinary_session(string $path, string $method, array $body): void
    {
        $member = User::factory()->create();
        $this->actingAs($member);

        $response = $this->json($method, '/'.$path, $body, self::JSON);

        $this->assertNotSame(403, $response->getStatusCode());

        $this->assertNotContains('impersonation.blocked', array_column($this->auditEntries(), 'action'));
    }

    /**
     * Acts the member cannot undo or that leave the site in their name.
     *
     * @return array<string, array{string, string, array<string, mixed>}>
     */
    public static function irrevocableActions(): array
    {
        return [
            'vanilla forum sso' => ['login/vanilla', 'GET', []],
            'waiver sign link' => ['waiver/1/sign-url', 'GET', []],
            'waiver sign by tracking id' => ['waiver_sign/t/abcdef0123456789abcdef0123456789', 'POST', ['signature' => 'x']],
            'walk-in waiver sign' => ['waiver_sign/s/1', 'POST', ['signature' => 'x']],
            'private message' => ['member/message', 'POST', ['to' => 1, 'message' => 'hello']],
            'sell car' => ['member/sell-car', 'POST', ['car_id' => 1]],
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[Test]
    #[DataProvider('irrevocableActions')]
    public function acting_irrevocably_as_the_member_is_refused_while_impersonating(string $path, string $method, array $body): void
    {
        $this->impersonating();

        $this->json($method, '/'.$path, $body, self::JSON)
            ->assertStatus(403)
            ->assertJsonPath('message', 'This change is not available while you are logged in as another user.');

        $blocked = array_values(array_filter($this->auditEntries(), fn (array $e) => ($e['action'] ?? null) === 'impersonation.blocked'));
        $this->assertCount(1, $blocked);
    }

    #[Test]
    public function a_member_write_during_impersonation_is_audited_with_the_administrator_id(): void
    {
        $admin = $this->admin();
        $member = User::factory()->create();
        $this->actingAs($admin)->postJson(sprintf(self::START, $member->id))->assertOk();

        $this->postJson('/tutorial/tutorial', ['show_login_welcome' => false], self::JSON)->assertOk();

        $writes = array_values(array_filter($this->auditEntries(), fn (array $e) => ($e['action'] ?? null) === 'impersonation.write'));
        $this->assertCount(1, $writes);
        $this->assertSame($admin->id, $writes[0]['extra']['impersonator_id']);
        $this->assertSame($member->id, $writes[0]['user_id']);
    }

    #[Test]
    public function an_ordinary_member_write_is_not_audited_as_impersonation(): void
    {
        $this->actingAs(User::factory()->create());

        $this->postJson('/tutorial/tutorial', ['show_login_welcome' => false], self::JSON)->assertOk();

        $this->assertNotContains('impersonation.write', array_column($this->auditEntries(), 'action'));
    }

    #[Test]
    public function a_failed_admin_reset_mail_is_a_clean_502_and_is_logged(): void
    {
        $logger = Log::spy();
        $member = User::factory()->create();
        Mail::shouldReceive('to')->andThrow(new RuntimeException('smtp password=hunter2 host=mail.internal'));

        $response = $this->actingAs($this->admin())->postJson('/admin-api/users/'.$member->id.'/password-reset');

        $response->assertStatus(502)->assertJsonPath('message', 'The password reset email could not be sent. Try again later.');
        $this->assertStringNotContainsString('hunter2', $response->getContent() ?: '');
        $this->assertStringNotContainsString('trace', $response->getContent() ?: '');
        $logger->shouldHaveReceived('error')->once();
        $this->assertNotContains('user.password_reset', array_column($this->auditEntries(), 'action'));
    }
}
