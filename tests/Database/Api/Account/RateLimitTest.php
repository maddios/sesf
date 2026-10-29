<?php

namespace Tests\Database\Api\Account;

use App\Models\User;
use PHPUnit\Framework\Attributes\Test;

class RateLimitTest extends AccountTestCase
{
    /** Re-authenticates as the given member, with no session carried over from the previous request. */
    private function asMember(User $member): static
    {
        $this->app['auth']->forgetGuards();
        // The session still holds the previous member's password hash; AuthenticateSession would log the new one out.
        $this->flushSession();

        return $this->actingAs($member);
    }

    #[Test]
    public function the_password_check_group_allows_six_attempts_then_returns_429(): void
    {
        $member = $this->makeUser();

        for ($i = 0; $i < 6; $i++) {
            $this->asMember($member)->postJson('/member/delete-account', ['password' => 'wrong-pass'])->assertStatus(400);
        }

        $this->asMember($member)->postJson('/member/delete-account', ['password' => 'wrong-pass'])
            ->assertStatus(429);
        // account-info shares the same per-member bucket.
        $this->asMember($member)->postJson('/member/account-info', [])->assertStatus(429);
        $this->assertNull($member->fresh()->deleted_at);
    }

    #[Test]
    public function the_password_check_limit_does_not_affect_another_member(): void
    {
        $member = $this->makeUser();
        $other = $this->makeUser();

        for ($i = 0; $i < 7; $i++) {
            $this->asMember($member)->postJson('/member/delete-account', ['password' => 'wrong-pass']);
        }

        $this->asMember($other)->postJson('/member/delete-account', ['password' => 'wrong-pass'])
            ->assertStatus(400);
    }

    #[Test]
    public function the_account_write_group_allows_thirty_writes_then_returns_429_across_routes(): void
    {
        $member = $this->makeUser();

        for ($i = 0; $i < 30; $i++) {
            $this->asMember($member)->postJson('/member/private-preferences', [])->assertStatus(400);
        }

        $this->asMember($member)->postJson('/member/private-preferences', [])->assertStatus(429);
        // Tutorial and location POSTs share the bucket with private preferences.
        $this->asMember($member)->postJson('/tutorial/tutorial', [])->assertStatus(429);
        $this->asMember($member)->postJson('/member/location', [])->assertStatus(429);
    }

    #[Test]
    public function the_account_write_limit_does_not_affect_another_member(): void
    {
        $member = $this->makeUser();
        $other = $this->makeUser();

        for ($i = 0; $i < 31; $i++) {
            $this->asMember($member)->postJson('/member/private-preferences', []);
        }

        $this->asMember($other)->postJson('/member/private-preferences', [])->assertStatus(400);
    }

    #[Test]
    public function the_car_write_group_allows_twenty_saves_then_returns_429(): void
    {
        $member = $this->makeUser();

        for ($i = 0; $i < 20; $i++) {
            $status = $this->asMember($member)->putJson('/car/profile', [])->getStatusCode();
            $this->assertNotSame(429, $status, 'request '.($i + 1).' should be within the limit');
        }

        $this->asMember($member)->putJson('/car/profile', [])->assertStatus(429);
    }

    #[Test]
    public function the_car_write_limit_does_not_affect_another_member(): void
    {
        $member = $this->makeUser();
        $other = $this->makeUser();

        for ($i = 0; $i < 21; $i++) {
            $this->asMember($member)->putJson('/car/profile', []);
        }

        $this->assertNotSame(429, $this->actingAs($other)->putJson('/car/profile', [])->getStatusCode());
    }
}
