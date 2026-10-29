<?php

namespace Tests\Database\Admin;

use App\Enums\UserType;
use App\Models\User;
use App\Models\UserPreferences;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * admin-api/users create (POST users) and set password (POST users/{id}/password): legacy frozennode user.php let
 * admins create users and set password + confirm_password.
 */
class UserAccountAdminTest extends AdminTestCase
{
    private const BASE = '/admin-api/users';

    private const PASSWORD = 'Sharks-4-ever';

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
    private function newMember(array $overrides = []): array
    {
        return array_merge([
            'first' => 'Ada',
            'last' => 'Lovelace',
            'email' => 'ada.new@example.test',
            'phone' => '555-0100',
            'phone2' => null,
            'heard_from' => 'a friend',
            'address1' => '1 Main St',
            'address2' => null,
            'city' => 'Orlando',
            'state' => 'FL',
            'zip' => '32803',
            'type_id' => UserType::Regular->value,
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ], $overrides);
    }

    #[Test]
    #[DataProvider('actors')]
    public function create_and_set_password_are_admin_only(string $who, int $status): void
    {
        $member = User::factory()->create();
        $hash = $member->password;
        $user = $this->actor($who);
        $as = fn () => $user === null ? $this : $this->actingAs($user);
        $expect = fn (int $ok) => $status === 200 ? $ok : $status;

        $as()->postJson(self::BASE, $this->newMember())->assertStatus($expect(201));
        $as()->postJson(self::BASE.'/'.$member->id.'/password', ['password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])->assertStatus($expect(200));

        if ($status !== 200) {
            $this->assertFalse(User::query()->where('email', 'ada.new@example.test')->exists());
            $this->assertSame($hash, $member->refresh()->password);
        }
    }

    #[Test]
    public function set_password_is_not_reachable_by_get(): void
    {
        $member = User::factory()->create();

        $this->actingAs($this->admin())->getJson(self::BASE.'/'.$member->id.'/password?password=x&password_confirmation=x')->assertStatus(405);
    }

    #[Test]
    public function an_admin_creates_a_member_who_can_sign_in(): void
    {
        Mail::fake();

        $response = $this->actingAs($this->admin())->postJson(self::BASE, $this->newMember(['first' => ' Ada ', 'type_id' => UserType::Judge->value]))
            ->assertCreated()
            ->assertJsonPath('data.first', 'Ada')
            ->assertJsonPath('data.email', 'ada.new@example.test')
            ->assertJsonPath('data.type_id', UserType::Judge->value)
            ->assertJsonMissingPath('data.password');

        $created = User::query()->findOrFail($response->json('data.id'));
        $this->assertTrue(Hash::check(self::PASSWORD, $created->password));
        $this->assertSame(UserType::Judge, $created->type_id);
        $this->assertTrue(UserPreferences::query()->where('user_id', $created->id)->exists());
        Mail::assertNothingSent();
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidCreate(): array
    {
        return [
            'password missing' => [['password' => null, 'password_confirmation' => null], 'password'],
            'password too short' => [['password' => '12345', 'password_confirmation' => '12345'], 'password'],
            'password not confirmed' => [['password_confirmation' => 'something-else'], 'password'],
            'password blank spaces' => [['password' => '      ', 'password_confirmation' => '      '], 'password'],
            'type missing' => [['type_id' => null], 'type_id'],
            'type unknown' => [['type_id' => 9], 'type_id'],
            'email missing' => [['email' => ''], 'email'],
            'email invalid' => [['email' => 'not-an-email'], 'email'],
            'email too long' => [['email' => str_repeat('a', 49).'@example.test'], 'email'],
            'first too long' => [['first' => str_repeat('a', 21)], 'first'],
            'street missing' => [['address1' => ''], 'address1'],
            'zip too long' => [['zip' => str_repeat('1', 11)], 'zip'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[Test]
    #[DataProvider('invalidCreate')]
    public function invalid_creates_are_422(array $overrides, string $field): void
    {
        $before = User::query()->withTrashed()->count();
        $this->actingAs($this->admin())->postJson(self::BASE, $this->newMember($overrides))
            ->assertStatus(422)
            ->assertJsonValidationErrors($field);
        $this->assertSame($before + 1, User::query()->withTrashed()->count(), 'Only the acting admin exists.');
    }

    #[Test]
    public function a_duplicate_email_is_a_422_even_for_a_disabled_account(): void
    {
        $taken = User::factory()->create(['email' => 'taken@example.test']);
        $taken->delete();

        $this->actingAs($this->admin())->postJson(self::BASE, $this->newMember(['email' => 'taken@example.test']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    #[Test]
    public function an_admin_sets_a_members_password_and_old_remember_cookies_stop_working(): void
    {
        $member = User::factory()->create();
        $token = $member->remember_token;

        $this->actingAs($this->admin())->postJson(self::BASE.'/'.$member->id.'/password', ['password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])
            ->assertOk()
            ->assertJsonPath('message', 'The password has been changed.');

        $member->refresh();
        $this->assertTrue(Hash::check(self::PASSWORD, $member->password));
        $this->assertNotSame($token, $member->remember_token);
    }

    #[Test]
    public function a_disabled_members_password_can_be_set_like_legacy(): void
    {
        $member = User::factory()->create();
        $member->delete();

        $this->actingAs($this->admin())->postJson(self::BASE.'/'.$member->id.'/password', ['password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])->assertOk();
        $this->assertTrue(Hash::check(self::PASSWORD, User::withTrashed()->findOrFail($member->id)->password));
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidPasswords(): array
    {
        return [
            'missing' => [[]],
            'too short' => [['password' => '12345', 'password_confirmation' => '12345']],
            'not confirmed' => [['password' => self::PASSWORD, 'password_confirmation' => 'other-password']],
            'blank spaces' => [['password' => '      ', 'password_confirmation' => '      ']],
            'not a string' => [['password' => ['a'], 'password_confirmation' => ['a']]],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    #[Test]
    #[DataProvider('invalidPasswords')]
    public function invalid_passwords_are_422_and_change_nothing(array $input): void
    {
        $member = User::factory()->create();
        $hash = $member->password;

        $this->actingAs($this->admin())->postJson(self::BASE.'/'.$member->id.'/password', $input)
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
        $this->assertSame($hash, $member->refresh()->password);
    }

    #[Test]
    public function another_administrators_password_cannot_be_set(): void
    {
        $other = User::factory()->admin()->create();
        $hash = $other->password;

        $this->actingAs($this->admin())->postJson(self::BASE.'/'.$other->id.'/password', ['password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])
            ->assertForbidden()
            ->assertJsonPath('message', "You cannot set another administrator's password.");
        $this->assertSame($hash, $other->refresh()->password);
    }

    #[Test]
    public function an_admin_can_set_their_own_password_and_stays_signed_in(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->postJson(self::BASE.'/'.$admin->id.'/password', ['password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])->assertOk();
        $this->assertTrue(Hash::check(self::PASSWORD, $admin->refresh()->password));
        $this->getJson(self::BASE)->assertOk();
    }

    #[Test]
    public function an_unknown_member_is_404(): void
    {
        $this->actingAs($this->admin())->postJson(self::BASE.'/999999/password', ['password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])->assertNotFound();
    }

    #[Test]
    public function the_reset_email_option_is_still_there(): void
    {
        Mail::fake();
        $member = User::factory()->create();

        $this->actingAs($this->admin())->postJson(self::BASE.'/'.$member->id.'/password-reset')->assertOk();
    }

    #[Test]
    public function create_and_set_password_are_audited_without_secrets(): void
    {
        $admin = $this->admin();
        $member = User::factory()->create();

        $id = $this->actingAs($admin)->postJson(self::BASE, $this->newMember())->assertCreated()->json('data.id');
        $this->actingAs($admin)->postJson(self::BASE.'/'.$member->id.'/password', ['password' => 'Second-Secret-1', 'password_confirmation' => 'Second-Secret-1'])->assertOk();

        $entries = $this->auditEntries();
        $this->assertSame(['user.create', 'user.set_password'], array_column($entries, 'action'));
        $this->assertSame([$id], $entries[0]['ids']);
        $this->assertSame('[redacted]', $entries[0]['changes']['password']);
        $this->assertSame([$member->id], $entries[1]['ids']);
        $this->assertSame(['password' => '[redacted]'], $entries[1]['changes']);

        $text = $this->auditText();
        $this->assertStringNotContainsString(self::PASSWORD, $text);
        $this->assertStringNotContainsString('Second-Secret-1', $text);
        $this->assertStringNotContainsString('$2y$', $text);
        $this->assertStringNotContainsString('ada.new@example.test', $text);
    }

    #[Test]
    public function the_list_sends_the_profile_link(): void
    {
        $member = User::factory()->create(['first' => 'shawn', 'last' => 'baker']);

        $this->actingAs($this->admin())->getJson(self::BASE.'/'.$member->id)
            ->assertOk()
            ->assertJsonPath('data.profile_url', '/#/ShawnB/u'.$member->id);
    }
}
