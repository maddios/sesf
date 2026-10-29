<?php

namespace Tests\Database\Admin;

use App\Enums\UserType;
use App\Http\Controllers\Admin\ImpersonationController;
use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * "Log in as": admin-only start, the audited switch, the way back (stop), and the limits that stop an
 * impersonation from giving more than the administrator already has.
 */
class ImpersonationTest extends AdminTestCase
{
    private const START = '/admin-api/users/%d/impersonate';

    private const STOP = '/admin-api/impersonation/stop';

    private const STATUS = '/admin-api/impersonation';

    private const LOGOUT = '/login/logout';

    /**
     * @return array<string, array{string, int}>
     */
    public static function actors(): array
    {
        return [
            'anonymous' => ['anonymous', 401],
            'member' => ['member', 403],
            'judge' => ['judge', 403],
            'admin' => ['admin', 200],
        ];
    }

    #[Test]
    #[DataProvider('actors')]
    public function only_an_administrator_can_start_an_impersonation(string $who, int $status): void
    {
        $member = User::factory()->create();
        $user = $this->actor($who);
        $request = $user === null ? $this : $this->actingAs($user);

        $request->postJson(sprintf(self::START, $member->id))->assertStatus($status);
    }

    #[Test]
    public function starting_switches_the_session_to_the_member_and_regenerates_it(): void
    {
        $admin = $this->admin();
        $member = User::factory()->create(['first' => 'Mona']);
        $this->actingAs($admin);
        $this->getJson(self::STATUS)->assertOk();
        $before = session()->getId();

        $this->postJson(sprintf(self::START, $member->id))
            ->assertOk()
            ->assertJsonPath('data.user_id', $member->id);

        $this->assertNotSame($before, session()->getId());
        $this->assertSame($admin->id, session()->get(ImpersonationController::SESSION_KEY));
    }

    #[Test]
    public function the_member_stays_signed_in_on_the_next_request_and_is_not_an_administrator(): void
    {
        $admin = $this->admin();
        $member = User::factory()->create(['first' => 'Mona']);
        $this->actingAs($admin);
        $this->postJson(sprintf(self::START, $member->id))->assertOk();

        $this->getJson('/admin-api/impersonation')
            ->assertOk()
            ->assertJsonPath('data.impersonating', true)
            ->assertJsonPath('data.impersonator_name', $admin->fullName);

        $this->getJson('/admin-api/users')->assertStatus(403);
        $this->getJson('/admin-api/sponsors')->assertStatus(403);
    }

    #[Test]
    public function the_impersonation_is_audited_before_the_switch(): void
    {
        $admin = $this->admin();
        $member = User::factory()->create();

        $this->actingAs($admin)->postJson(sprintf(self::START, $member->id))->assertOk();

        $entries = array_values(array_filter($this->auditEntries(), fn (array $e) => ($e['action'] ?? null) === 'impersonation.start'));
        $this->assertCount(1, $entries);
        $this->assertSame($admin->id, $entries[0]['user_id']);
        $this->assertSame([$member->id], $entries[0]['ids']);
        $this->assertSame($admin->id, $entries[0]['extra']['impersonator_id']);
    }

    #[Test]
    public function the_way_back_restores_the_administrator_and_is_audited(): void
    {
        $admin = $this->admin();
        $member = User::factory()->create();
        $this->actingAs($admin);
        $this->postJson(sprintf(self::START, $member->id))->assertOk();

        $this->postJson(self::STOP)
            ->assertOk()
            ->assertJsonPath('data.user_id', $admin->id);

        $this->getJson('/admin-api/users')->assertOk();
        $this->getJson(self::STATUS)->assertOk()->assertJsonPath('data.impersonating', false);
        $this->assertNull(session()->get(ImpersonationController::SESSION_KEY));
        $this->assertContains('impersonation.stop', array_column($this->auditEntries(), 'action'));
    }

    #[Test]
    public function stopping_without_an_impersonation_is_a_409(): void
    {
        $this->actingAs($this->admin())->postJson(self::STOP)->assertStatus(409);
    }

    #[Test]
    public function an_impersonated_member_cannot_start_another_impersonation(): void
    {
        $admin = $this->admin();
        $first = User::factory()->create();
        $second = User::factory()->create();
        $this->actingAs($admin);
        $this->postJson(sprintf(self::START, $first->id))->assertOk();

        $this->postJson(sprintf(self::START, $second->id))->assertStatus(403);
    }

    #[Test]
    public function an_administrator_account_cannot_be_impersonated(): void
    {
        $admin = $this->admin();
        $other = User::factory()->admin()->create();

        $this->actingAs($admin)->postJson(sprintf(self::START, $other->id))->assertStatus(403);
        $this->assertSame(UserType::Administrator, $other->refresh()->type_id);
    }

    #[Test]
    public function nobody_impersonates_themselves_and_a_disabled_account_must_be_restored_first(): void
    {
        $admin = $this->admin();
        $disabled = User::factory()->deleted()->create();
        $this->actingAs($admin);

        $this->postJson(sprintf(self::START, $admin->id))->assertStatus(422);
        $this->postJson(sprintf(self::START, $disabled->id))->assertStatus(409);
        $this->postJson(sprintf(self::START, 999999))->assertNotFound();
    }

    #[Test]
    public function logging_out_ends_the_impersonation(): void
    {
        $admin = $this->admin();
        $member = User::factory()->create();
        $this->actingAs($admin);
        $this->postJson(sprintf(self::START, $member->id))->assertOk();

        $this->postJson(self::LOGOUT)->assertOk();

        $this->assertNull(session()->get(ImpersonationController::SESSION_KEY));
    }

    #[Test]
    public function the_way_back_is_refused_when_the_administrator_has_lost_the_role(): void
    {
        $admin = $this->admin();
        $member = User::factory()->create();
        $this->actingAs($admin);
        $this->postJson(sprintf(self::START, $member->id))->assertOk();

        User::query()->whereKey($admin->id)->update(['type_id' => UserType::Regular->value]);

        $this->postJson(self::STOP)->assertStatus(401);
        $this->assertNull(session()->get(ImpersonationController::SESSION_KEY));
    }

    #[Test]
    public function the_status_says_not_impersonating_for_a_normal_session(): void
    {
        $this->actingAs(User::factory()->create())->getJson(self::STATUS)->assertOk()->assertJsonPath('data.impersonating', false);
    }
}
