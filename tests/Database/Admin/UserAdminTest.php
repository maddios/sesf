<?php

namespace Tests\Database\Admin;

use App\Enums\UserType;
use App\Mail\ResetPasswordMail;
use App\Models\Car;
use App\Models\CarShow;
use App\Models\ShowRegistration;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * admin-api/users: authorization, list (status filter, sort, injection probes), edit, disable/restore,
 * password reset mail, register-for-event, audit entries and secret values.
 */
class UserAdminTest extends AdminTestCase
{
    private const BASE = '/admin-api/users';

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

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function editable(array $overrides = []): array
    {
        return array_merge([
            'first' => 'Ada',
            'last' => 'Lovelace',
            'email' => 'ada.lovelace@example.test',
            'phone' => '555-0100',
            'phone2' => null,
            'heard_from' => 'a friend',
            'address1' => '1 Main St',
            'address2' => null,
            'city' => 'Orlando',
            'state' => 'FL',
            'zip' => '32803',
        ], $overrides);
    }

    #[Test]
    #[DataProvider('actors')]
    public function every_user_route_is_admin_only(string $who, int $status): void
    {
        $member = User::factory()->create();
        $user = $this->actor($who);
        $as = fn () => $user === null ? $this : $this->actingAs($user);
        $expect = fn (int $ok) => $status === 200 ? $ok : $status;

        $as()->getJson(self::BASE)->assertStatus($expect(200));
        $as()->getJson(self::BASE.'/lookup?search=a')->assertStatus($expect(200));
        $as()->getJson(self::BASE.'/'.$member->id)->assertStatus($expect(200));
        $as()->putJson(self::BASE.'/'.$member->id, $this->editable())->assertStatus($expect(200));
        $as()->postJson(self::BASE.'/'.$member->id.'/password-reset')->assertStatus($expect(200));
        $as()->deleteJson(self::BASE.'/'.$member->id)->assertStatus($expect(204));
        $as()->postJson(self::BASE.'/'.$member->id.'/restore')->assertStatus($status);

        if ($status !== 200) {
            $this->assertFalse($member->refresh()->trashed());
        }
    }

    #[Test]
    public function the_list_shows_active_members_by_default_and_the_status_filter_is_explicit(): void
    {
        $active = User::factory()->create(['last' => 'Active']);
        $disabled = User::factory()->deleted()->create(['last' => 'Gone']);
        $admin = $this->actingAs($this->admin());

        $default = array_column($admin->getJson(self::BASE)->assertOk()->json('data'), 'id');
        $this->assertContains($active->id, $default);
        $this->assertNotContains($disabled->id, $default);

        $disabledIds = array_column($admin->getJson(self::BASE.'?filter[status]=disabled')->json('data'), 'id');
        $this->assertSame([$disabled->id], $disabledIds);

        $all = array_column($admin->getJson(self::BASE.'?filter[status]=all')->json('data'), 'id');
        $this->assertContains($active->id, $all);
        $this->assertContains($disabled->id, $all);
    }

    #[Test]
    public function an_unknown_status_is_a_422(): void
    {
        $this->actingAs($this->admin())->getJson(self::BASE.'?filter[status]=bogus')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['filter.status']);
    }

    #[Test]
    public function list_sorts_filters_and_pages_within_the_whitelist(): void
    {
        User::factory()->create(['last' => 'Zeta', 'type_id' => UserType::Judge->value]);
        User::factory()->create(['last' => 'Alpha', 'type_id' => UserType::Regular->value]);
        $admin = $this->actingAs($this->admin());

        $sorted = $admin->getJson(self::BASE.'?sort=last&direction=asc&filter[type_id]=2&per_page=1')->assertOk();
        $sorted->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.last', 'Zeta');

        $admin->getJson(self::BASE.'?sort=password')->assertStatus(422);
        $admin->getJson(self::BASE.'?sort=last;drop table user')->assertStatus(422);
        $admin->getJson(self::BASE.'?filter[password][like]=x')->assertStatus(422);
        $admin->getJson(self::BASE.'?filter[email][regex]=.*')->assertStatus(422);
    }

    #[Test]
    public function the_list_and_show_never_expose_password_or_remember_token(): void
    {
        $member = User::factory()->create();
        $admin = $this->actingAs($this->admin());

        $this->assertStringNotContainsString($member->password, $admin->getJson(self::BASE)->getContent());
        $body = $admin->getJson(self::BASE.'/'.$member->id)->assertOk()->json('data');
        $this->assertArrayNotHasKey('password', $body);
        $this->assertArrayNotHasKey('remember_token', $body);
        $this->assertSame('Regular', $body['type_name']);
    }

    #[Test]
    public function an_admin_edits_contact_and_address_fields(): void
    {
        $member = User::factory()->create();

        $this->actingAs($this->admin())->putJson(self::BASE.'/'.$member->id, $this->editable(['first' => '  Grace ']))
            ->assertOk()
            ->assertJsonPath('data.first', 'Grace')
            ->assertJsonPath('data.email', 'ada.lovelace@example.test');

        $member->refresh();
        $this->assertSame('Grace', $member->first);
        $this->assertSame('Orlando', $member->city);
    }

    #[Test]
    public function an_admin_changes_the_account_type_through_the_guarded_column(): void
    {
        $member = User::factory()->create();

        $this->actingAs($this->admin())->putJson(self::BASE.'/'.$member->id, $this->editable(['email' => $member->email, 'type_id' => UserType::Judge->value]))
            ->assertOk()
            ->assertJsonPath('data.type_id', UserType::Judge->value);

        $this->assertSame(UserType::Judge, $member->refresh()->type_id);
    }

    #[Test]
    public function a_member_cannot_be_made_an_administrator_by_fill(): void
    {
        $member = User::factory()->create();
        $member->fill(['type_id' => UserType::Administrator->value, 'is_admin' => true]);

        $this->assertSame(UserType::Regular, $member->type_id);
        $this->assertFalse((bool) $member->is_admin);
    }

    #[Test]
    public function an_admin_cannot_change_their_own_account_type(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->putJson(self::BASE.'/'.$admin->id, $this->editable(['type_id' => UserType::Regular->value]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['type_id']);
        $this->assertSame(UserType::Administrator, $admin->refresh()->type_id);
    }

    #[Test]
    public function the_edit_form_validates_widths_and_unique_email(): void
    {
        $other = User::factory()->create(['email' => 'taken@example.test']);
        $member = User::factory()->create();
        $admin = $this->actingAs($this->admin());

        $admin->putJson(self::BASE.'/'.$member->id, $this->editable(['email' => 'taken@example.test']))->assertJsonValidationErrors(['email']);
        $admin->putJson(self::BASE.'/'.$member->id, $this->editable(['first' => str_repeat('a', 20)]))->assertOk();
        $admin->putJson(self::BASE.'/'.$member->id, $this->editable(['first' => str_repeat('a', 21)]))->assertJsonValidationErrors(['first']);
        $admin->putJson(self::BASE.'/'.$member->id, $this->editable(['zip' => str_repeat('1', 11)]))->assertJsonValidationErrors(['zip']);
        $admin->putJson(self::BASE.'/'.$member->id, $this->editable(['heard_from' => str_repeat('a', 501)]))->assertJsonValidationErrors(['heard_from']);
        $admin->putJson(self::BASE.'/'.$member->id, $this->editable(['type_id' => 99]))->assertJsonValidationErrors(['type_id']);
        $admin->putJson(self::BASE.'/'.$member->id, $this->editable(['address1' => '']))->assertJsonValidationErrors(['address1']);
        $this->assertNotSame('taken@example.test', $member->refresh()->email);
        $this->assertSame('taken@example.test', $other->refresh()->email);
    }

    #[Test]
    public function an_admin_disables_and_restores_a_member(): void
    {
        $member = User::factory()->create();
        $admin = $this->actingAs($this->admin());

        $admin->deleteJson(self::BASE.'/'.$member->id)->assertNoContent();
        $this->assertTrue($member->refresh()->trashed());

        $admin->deleteJson(self::BASE.'/'.$member->id)->assertStatus(409);

        $admin->postJson(self::BASE.'/'.$member->id.'/restore')
            ->assertOk()
            ->assertJsonPath('data.is_disabled', false);
        $this->assertFalse($member->refresh()->trashed());
    }

    #[Test]
    public function a_disabled_member_is_shown_as_disabled_with_a_date(): void
    {
        $member = User::factory()->deleted()->create();

        $this->actingAs($this->admin())->getJson(self::BASE.'/'.$member->id)
            ->assertOk()
            ->assertJsonPath('data.is_disabled', true)
            ->assertJsonPath('data.disabled_at', fn ($value) => is_string($value));
    }

    #[Test]
    public function an_admin_cannot_disable_their_own_account(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->deleteJson(self::BASE.'/'.$admin->id)->assertStatus(422);
        $this->assertFalse($admin->refresh()->trashed());
    }

    #[Test]
    public function restore_is_refused_for_an_active_member(): void
    {
        $member = User::factory()->create();

        $this->actingAs($this->admin())->postJson(self::BASE.'/'.$member->id.'/restore')->assertStatus(409);
    }

    #[Test]
    public function a_password_reset_sends_the_normal_reset_mail_and_changes_no_password(): void
    {
        Mail::fake();
        $member = User::factory()->create();
        $hash = $member->password;

        $this->actingAs($this->admin())->postJson(self::BASE.'/'.$member->id.'/password-reset')->assertOk();

        Mail::assertSent(ResetPasswordMail::class, fn (ResetPasswordMail $mail) => $mail->hasTo($member->email));
        $this->assertSame($hash, $member->refresh()->password);
    }

    #[Test]
    public function another_administrators_email_cannot_be_changed_or_reset(): void
    {
        Mail::fake();
        $other = User::factory()->admin()->create(['email' => 'other-admin@example.test']);
        $admin = $this->actingAs($this->admin());

        $admin->putJson(self::BASE.'/'.$other->id, $this->editable(['email' => 'takeover@example.test']))->assertForbidden();
        $admin->postJson(self::BASE.'/'.$other->id.'/password-reset')->assertForbidden();

        $this->assertSame('other-admin@example.test', $other->refresh()->email);
        Mail::assertNothingSent();
        $admin->putJson(self::BASE.'/'.$other->id, $this->editable(['email' => 'other-admin@example.test', 'first' => 'Renamed']))->assertOk();
    }

    #[Test]
    public function the_email_cannot_change_in_the_same_save_as_the_account_type(): void
    {
        $member = User::factory()->create(['email' => 'member@example.test']);
        $other = User::factory()->admin()->create(['email' => 'other-admin@example.test']);
        $admin = $this->actingAs($this->admin());

        $admin->putJson(self::BASE.'/'.$member->id, $this->editable(['email' => 'new@example.test', 'type_id' => UserType::Judge->value]))->assertForbidden();
        $admin->putJson(self::BASE.'/'.$other->id, $this->editable(['email' => 'takeover@example.test', 'type_id' => UserType::Regular->value]))->assertForbidden();

        $this->assertSame('member@example.test', $member->refresh()->email);
        $this->assertSame(UserType::Regular, $member->type_id);
        $this->assertSame('other-admin@example.test', $other->refresh()->email);
        $this->assertTrue($other->isAdmin());

        $admin->putJson(self::BASE.'/'.$other->id, $this->editable(['email' => 'other-admin@example.test', 'type_id' => UserType::Regular->value]))->assertOk();
    }

    #[Test]
    public function an_administrator_can_change_their_own_email_and_reset_it(): void
    {
        Mail::fake();
        $self = $this->admin();
        $admin = $this->actingAs($self);

        $admin->putJson(self::BASE.'/'.$self->id, $this->editable(['email' => 'me-new@example.test']))->assertOk();
        $admin->postJson(self::BASE.'/'.$self->id.'/password-reset')->assertOk();

        $this->assertSame('me-new@example.test', $self->refresh()->email);
    }

    #[Test]
    public function a_reset_is_refused_for_a_disabled_member(): void
    {
        Mail::fake();
        $member = User::factory()->deleted()->create();

        $this->actingAs($this->admin())->postJson(self::BASE.'/'.$member->id.'/password-reset')->assertStatus(409);
        Mail::assertNothingSent();
    }

    #[Test]
    public function an_admin_registers_a_member_for_an_event_with_their_car(): void
    {
        $member = User::factory()->create();
        $car = Car::factory()->create(['user_id' => $member->id]);
        $show = CarShow::factory()->registrationOpen()->create();

        $this->actingAs($this->admin())
            ->postJson(self::BASE.'/'.$member->id.'/registrations', ['show_id' => $show->id, 'car_ids' => [$car->id]])
            ->assertCreated()
            ->assertJsonPath('data.show_id', $show->id);

        $registration = ShowRegistration::query()->where('show_id', $show->id)->where('user_id', $member->id)->firstOrFail();
        $this->assertTrue($registration->cars()->whereKey($car->id)->exists());
        $this->assertStringContainsString('"action":"user.register_for_show"', $this->auditText());
    }

    #[Test]
    public function registration_refuses_cars_the_member_does_not_own_and_a_second_registration(): void
    {
        $member = User::factory()->create();
        $other = Car::factory()->create();
        $own = Car::factory()->create(['user_id' => $member->id]);
        $show = CarShow::factory()->registrationOpen()->create();
        $admin = $this->actingAs($this->admin());

        $admin->postJson(self::BASE.'/'.$member->id.'/registrations', ['show_id' => $show->id, 'car_ids' => [$other->id]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['car_ids']);

        $admin->postJson(self::BASE.'/'.$member->id.'/registrations', ['show_id' => $show->id, 'car_ids' => [$own->id]])->assertCreated();
        $admin->postJson(self::BASE.'/'.$member->id.'/registrations', ['show_id' => $show->id, 'car_ids' => [$own->id]])->assertStatus(409);
    }

    #[Test]
    public function registration_needs_an_event_and_at_least_one_car(): void
    {
        $member = User::factory()->create();

        $this->actingAs($this->admin())->postJson(self::BASE.'/'.$member->id.'/registrations', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['show_id', 'car_ids']);
    }

    #[Test]
    public function the_lookup_returns_active_members_by_search_and_by_ids(): void
    {
        $match = User::factory()->create(['first' => 'Findme', 'last' => 'Lookup']);
        User::factory()->create(['first' => 'Findme', 'last' => 'Gone'])->delete();
        $admin = $this->actingAs($this->admin());

        $bySearch = $admin->getJson(self::BASE.'/lookup?search=Findme')->assertOk()->json('data');
        $this->assertSame([$match->id], array_column($bySearch, 'id'));
        $this->assertStringNotContainsString($match->password, json_encode($bySearch) ?: '');

        $byIds = $admin->getJson(self::BASE.'/lookup?ids='.$match->id)->assertOk()->json('data');
        $this->assertSame([$match->id], array_column($byIds, 'id'));
    }

    #[Test]
    public function every_write_is_audited_without_secret_values(): void
    {
        $member = User::factory()->create(['phone' => '555-9999', 'email' => 'secret.person@example.test']);
        $oldEmail = $member->email;
        $admin = $this->actingAs($this->admin());

        $admin->putJson(self::BASE.'/'.$member->id, $this->editable(['heard_from' => 'private remark']))->assertOk();
        $admin->deleteJson(self::BASE.'/'.$member->id)->assertNoContent();
        $admin->postJson(self::BASE.'/'.$member->id.'/restore')->assertOk();

        $actions = array_column($this->auditEntries(), 'action');
        $this->assertContains('user.update', $actions);
        $this->assertContains('user.disable', $actions);
        $this->assertContains('user.restore', $actions);

        $text = $this->auditText();
        $this->assertStringNotContainsString($oldEmail, $text);
        $this->assertStringNotContainsString('555-9999', $text);
        $this->assertStringNotContainsString('private remark', $text);
        $this->assertStringNotContainsString($member->password, $text);
    }
}
