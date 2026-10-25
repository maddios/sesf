<?php

namespace Tests\Database\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\LoadsLegacySchema;
use Tests\TestCase;

class UpdatePasswordTest extends TestCase
{
    use LoadsLegacySchema;

    #[Test]
    public function an_anonymous_caller_gets_401_with_the_legacy_message(): void
    {
        $this->post('/login/update-password', [
            'old_password' => 'old-pass1',
            'new_password' => 'new-pass1',
            'verify_password' => 'new-pass1',
        ])->assertStatus(401)->assertExactJson(['Not authenticated']);
    }

    #[Test]
    public function a_signed_in_member_changes_their_password_and_the_new_one_verifies(): void
    {
        $user = User::factory()->create(['password' => Hash::make('old-pass1')]);
        $this->actingAs($user, 'web');

        $this->post('/login/update-password', [
            'old_password' => 'old-pass1',
            'new_password' => 'new-pass1',
            'verify_password' => 'new-pass1',
        ])->assertOk();

        $user->refresh();
        $this->assertTrue(Hash::check('new-pass1', $user->password));
        $this->assertFalse(Hash::check('old-pass1', $user->password));
    }

    #[Test]
    public function a_legacy_2y_10_hash_can_be_used_as_the_old_password(): void
    {
        $legacy = password_hash('legacy-pass', PASSWORD_BCRYPT, ['cost' => 10]);
        $user = User::factory()->create(['password' => $legacy]);
        $this->actingAs($user, 'web');

        $this->post('/login/update-password', [
            'old_password' => 'legacy-pass',
            'new_password' => 'fresh-pass',
            'verify_password' => 'fresh-pass',
        ])->assertOk();

        $this->assertTrue(Hash::check('fresh-pass', $user->refresh()->password));
    }

    #[Test]
    public function a_wrong_old_password_returns_400_and_changes_nothing(): void
    {
        $user = User::factory()->create(['password' => Hash::make('old-pass1')]);
        $this->actingAs($user, 'web');
        $before = $user->password;

        $this->post('/login/update-password', [
            'old_password' => 'not-it-pass',
            'new_password' => 'new-pass1',
            'verify_password' => 'new-pass1',
        ])->assertStatus(400)->assertExactJson(['Old Password invalid']);

        $this->assertSame($before, $user->refresh()->password);
    }

    #[Test]
    public function a_new_password_under_six_characters_returns_400_and_changes_nothing(): void
    {
        $user = User::factory()->create(['password' => Hash::make('old-pass1')]);
        $this->actingAs($user, 'web');
        $before = $user->password;

        $response = $this->post('/login/update-password', [
            'old_password' => 'old-pass1',
            'new_password' => '12345',
            'verify_password' => '12345',
        ]);

        $response->assertStatus(400)->assertJsonIsArray();
        $this->assertCount(1, $response->json());
        $this->assertSame($before, $user->refresh()->password);
    }

    #[Test]
    public function a_confirmation_that_does_not_match_returns_400_and_changes_nothing(): void
    {
        $user = User::factory()->create(['password' => Hash::make('old-pass1')]);
        $this->actingAs($user, 'web');
        $before = $user->password;

        $this->post('/login/update-password', [
            'old_password' => 'old-pass1',
            'new_password' => 'new-pass1',
            'verify_password' => 'new-pass2',
        ])->assertStatus(400)->assertJsonIsArray();

        $this->assertSame($before, $user->refresh()->password);
    }

    #[Test]
    public function a_user_id_in_the_request_is_ignored_and_only_the_signed_in_member_changes(): void
    {
        $me = User::factory()->create(['password' => Hash::make('old-pass1')]);
        $other = User::factory()->create(['password' => Hash::make('other-pass1')]);
        $this->actingAs($me, 'web');
        $otherBefore = $other->password;

        $this->post('/login/update-password', [
            'user_id' => $other->id,
            'old_password' => 'old-pass1',
            'new_password' => 'new-pass1',
            'verify_password' => 'new-pass1',
        ])->assertOk();

        $this->assertTrue(Hash::check('new-pass1', $me->refresh()->password));
        $this->assertSame($otherBefore, $other->refresh()->password);
    }

    #[Test]
    public function a_successful_change_rotates_the_remember_token(): void
    {
        $user = User::factory()->create(['password' => Hash::make('old-pass1'), 'remember_token' => 'before-token']);
        $this->actingAs($user, 'web');

        $this->post('/login/update-password', [
            'old_password' => 'old-pass1',
            'new_password' => 'new-pass1',
            'verify_password' => 'new-pass1',
        ])->assertOk();

        $this->assertNotSame('before-token', $user->refresh()->getRememberToken());
    }

    #[Test]
    public function a_password_change_signs_out_the_other_device_but_not_the_one_that_changed_it(): void
    {
        $user = User::factory()->create(['password' => Hash::make('old-pass1')]);

        $this->asBrowser(null)->post('/login/login', ['email' => $user->email, 'password' => 'old-pass1'])->assertOk();
        $deviceA = $this->app['session.store']->getId();

        $this->asBrowser(null)->post('/login/login', ['email' => $user->email, 'password' => 'old-pass1'])->assertOk();
        $deviceB = $this->app['session.store']->getId();

        $this->assertNotSame($deviceA, $deviceB);

        $this->asBrowser($deviceA)->postJson('/login/authenticate')->assertOk();

        $this->asBrowser($deviceA)->postJson('/login/update-password', [
            'old_password' => 'old-pass1',
            'new_password' => 'new-pass1',
            'verify_password' => 'new-pass1',
        ])->assertOk();
        // The session id is regenerated on a password change, so device A continues under the new id.
        $deviceA = $this->app['session.store']->getId();

        $this->asBrowser($deviceB)->postJson('/login/authenticate')->assertStatus(401);
        $this->asBrowser($deviceA)->postJson('/login/authenticate')->assertOk();
    }

    /**
     * Sends the next request as a browser holding $sessionId (null for a new browser). The app is
     * shared across requests in a test, so guards and in-memory session attributes are cleared to
     * stop one browser's state leaking into the next.
     */
    private function asBrowser(?string $sessionId): static
    {
        Auth::forgetGuards();
        $this->app['session.store']->flush();

        // postJson() sends no cookies unless credentials are enabled, so the session cookie is set
        // on the request explicitly.
        $this->withCredentials();

        return $sessionId === null ? $this : $this->withCookie('sesf_session', $sessionId);
    }

    #[Test]
    public function password_changes_are_limited_to_five_a_minute_per_member(): void
    {
        $user = User::factory()->create(['password' => Hash::make('old-pass1')]);
        $this->actingAs($user, 'web');

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login/update-password', [
                'old_password' => 'not-it-pass',
                'new_password' => 'new-pass1',
                'verify_password' => 'new-pass1',
            ])->assertStatus(400);
        }

        $this->post('/login/update-password', [
            'old_password' => 'not-it-pass',
            'new_password' => 'new-pass1',
            'verify_password' => 'new-pass1',
        ])->assertStatus(429);
    }
}
