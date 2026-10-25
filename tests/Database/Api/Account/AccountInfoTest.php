<?php

namespace Tests\Database\Api\Account;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class AccountInfoTest extends AccountTestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function info(User $user, array $overrides = []): array
    {
        return array_merge([
            'first' => 'Janet',
            'last' => 'Doe',
            'email' => $user->email,
            'phone' => '407-555-0199',
        ], $overrides);
    }

    #[Test]
    public function anonymous_requests_get_401(): void
    {
        $this->postJson('/member/account-info', ['first' => 'A', 'last' => 'B', 'email' => 'a@example.test', 'phone' => '407-555-0199'])
            ->assertUnauthorized();
    }

    #[Test]
    public function the_member_updates_their_name_and_phone_without_a_password(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->postJson('/member/account-info', $this->info($user))
            ->assertOk()
            ->assertContent('""');

        $user->refresh();
        $this->assertSame('Janet', $user->first);
        $this->assertSame('407-555-0199', $user->phone);
    }

    #[Test]
    public function changing_the_email_requires_the_current_password(): void
    {
        $user = $this->makeUser(['email' => 'before@example.test']);

        $this->actingAs($user)->postJson('/member/account-info', $this->info($user, ['email' => 'after@example.test']))
            ->assertStatus(400)
            ->assertJsonIsArray();

        $this->assertSame('before@example.test', $user->fresh()->email);
    }

    #[Test]
    public function a_wrong_current_password_does_not_change_the_email(): void
    {
        $user = $this->makeUser(['email' => 'before@example.test']);

        $this->actingAs($user)->postJson('/member/account-info', $this->info($user, [
            'email' => 'after@example.test',
            'current_password' => 'not-the-password',
        ]))->assertStatus(400)->assertJsonFragment(['Current password is incorrect.']);

        $this->assertSame('before@example.test', $user->fresh()->email);
    }

    #[Test]
    public function the_correct_current_password_allows_the_email_change(): void
    {
        $user = $this->makeUser(['email' => 'before@example.test']);

        $this->actingAs($user)->postJson('/member/account-info', $this->info($user, [
            'email' => 'after@example.test',
            'current_password' => self::PASSWORD,
        ]))->assertOk();

        $this->assertSame('after@example.test', $user->fresh()->email);
    }

    #[Test]
    public function an_email_already_used_by_another_member_is_refused(): void
    {
        $user = $this->makeUser();
        $other = $this->makeUser(['email' => 'taken@example.test']);

        $this->actingAs($user)->postJson('/member/account-info', $this->info($user, [
            'email' => $other->email,
            'current_password' => self::PASSWORD,
        ]))->assertStatus(400);

        $this->assertNotSame('taken@example.test', $user->fresh()->email);
    }

    #[Test]
    public function keeping_the_same_email_needs_no_password(): void
    {
        $user = $this->makeUser(['email' => 'same@example.test']);

        $this->actingAs($user)->postJson('/member/account-info', $this->info($user, ['email' => ' SAME@example.test ']))
            ->assertOk();
    }

    /**
     * @return array<string, array{0: string, 1: mixed}>
     */
    public static function invalidInfo(): array
    {
        return [
            'first with markup' => ['first', '<script>x</script>'],
            'last missing' => ['last', null],
            'first too long' => ['first', str_repeat('a', 63)],
            'phone wrong' => ['phone', '555'],
        ];
    }

    #[Test]
    #[DataProvider('invalidInfo')]
    public function invalid_details_are_rejected_and_nothing_changes(string $field, mixed $value): void
    {
        $user = $this->makeUser(['first' => 'Stable']);
        $body = $this->info($user, [$field => $value]);
        if ($value === null) {
            unset($body[$field]);
        }

        $this->actingAs($user)->postJson('/member/account-info', $body)->assertStatus(400);

        $this->assertSame('Stable', $user->fresh()->first);
    }

    #[Test]
    public function a_user_id_in_the_body_cannot_change_another_member(): void
    {
        $me = $this->makeUser();
        $other = $this->makeUser(['first' => 'Keep']);

        $this->actingAs($me)->postJson('/member/account-info', $this->info($me, ['user_id' => $other->id]))->assertOk();

        $this->assertSame('Keep', $other->fresh()->first);
    }

    #[Test]
    public function deleting_the_account_with_the_right_password_soft_deletes_and_logs_out(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->postJson('/member/delete-account', ['password' => self::PASSWORD])
            ->assertOk();

        $this->assertSoftDeleted('user', ['id' => $user->id]);
        $this->assertNotNull(User::withTrashed()->find($user->id));
        $this->assertGuest('web');
    }

    #[Test]
    public function a_wrong_password_does_not_delete_the_account(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->postJson('/member/delete-account', ['password' => 'nope-nope'])
            ->assertStatus(400)
            ->assertExactJson(['Password is incorrect']);

        $this->assertNull($user->fresh()->deleted_at);
    }

    #[Test]
    public function a_missing_password_is_refused(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->postJson('/member/delete-account', [])->assertStatus(400);
        $this->assertNull($user->fresh()->deleted_at);
    }

    #[Test]
    public function anonymous_delete_requests_get_401(): void
    {
        $user = $this->makeUser();

        $this->postJson('/member/delete-account', ['password' => self::PASSWORD])->assertUnauthorized();
        $this->assertNull($user->fresh()->deleted_at);
    }

    #[Test]
    public function a_deleted_member_cannot_sign_in_again(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user)->postJson('/member/delete-account', ['password' => self::PASSWORD])->assertOk();
        Auth::guard('web')->logout();

        $this->postJson('/login/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertStatus(401);
        $this->assertGuest('web');
    }

    #[Test]
    public function the_password_hash_is_still_checked_after_a_delete_request(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->postJson('/member/delete-account', ['password' => self::PASSWORD])->assertOk();

        $this->assertTrue(Hash::check(self::PASSWORD, User::withTrashed()->find($user->id)->password));
    }
}
