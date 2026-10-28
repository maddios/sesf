<?php

namespace Tests\Database\Waivers;

use App\Models\User;
use App\Models\Waiver;
use PHPUnit\Framework\Attributes\Test;

/** waiver/{showId}/login, sign-url, status and logout. */
class PortalEndpointTest extends WaiverTestCase
{
    private function asMember(User $member): static
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        return $this->actingAs($member);
    }

    #[Test]
    public function login_uses_the_password_login_messages_without_enumeration(): void
    {
        $registration = $this->registration();
        $member = User::query()->findOrFail($registration->user_id);
        $showId = $registration->show_id;

        $wrong = $this->postJson('/waiver/'.$showId.'/login', ['email' => $member->email, 'password' => 'wrong-pass'])->assertStatus(401);
        $unknown = $this->postJson('/waiver/'.$showId.'/login', ['email' => 'nobody@example.test', 'password' => 'wrong-pass'])->assertStatus(401);
        $this->assertSame(['Either your email or password are incorrect'], $wrong->json());
        $this->assertSame($wrong->json(), $unknown->json());
        $this->assertGuest();
    }

    #[Test]
    public function login_signs_in_and_regenerates_the_session(): void
    {
        $registration = $this->registration();
        $member = User::query()->findOrFail($registration->user_id);
        $this->startSession();
        $before = session()->getId();

        $this->postJson('/waiver/'.$registration->show_id.'/login', ['email' => $member->email, 'password' => self::PASSWORD])
            ->assertOk()
            ->assertExactJson(['ok' => true, 'redirect' => '/waiver/'.$registration->show_id]);

        $this->assertAuthenticatedAs($member);
        $this->assertNotSame($before, session()->getId());
    }

    #[Test]
    public function login_for_an_unknown_show_is_404(): void
    {
        $this->postJson('/waiver/999999/login', ['email' => 'a@example.test', 'password' => 'x'])->assertNotFound();
    }

    #[Test]
    public function portal_login_is_throttled_at_25_per_minute_per_ip(): void
    {
        $show = $this->show();
        for ($i = 0; $i < 25; $i++) {
            $this->postJson('/waiver/'.$show->id.'/login', ['email' => 'nobody'.$i.'@example.test', 'password' => 'x'])->assertStatus(401);
        }

        $this->postJson('/waiver/'.$show->id.'/login', ['email' => 'nobody-last@example.test', 'password' => 'x'])->assertStatus(429);
    }

    #[Test]
    public function portal_login_shares_the_per_email_login_limit_with_the_main_login(): void
    {
        $show = $this->show();
        $email = 'target@example.test';
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/login/login', ['email' => $email, 'password' => 'guess'.$i])->assertStatus(401);
            $this->postJson('/waiver/'.$show->id.'/login', ['email' => $email, 'password' => 'guess'.$i])->assertStatus(401);
        }

        $this->postJson('/waiver/'.$show->id.'/login', ['email' => $email, 'password' => 'guess'])->assertStatus(429);
        $this->postJson('/waiver/'.$show->id.'/login', ['email' => 'someone-else@example.test', 'password' => 'guess'])->assertStatus(401);
    }

    #[Test]
    public function status_lists_the_member_and_their_guests_only(): void
    {
        $registration = $this->registration(null, ['first' => 'Mia', 'last' => 'Member']);
        $member = User::query()->findOrFail($registration->user_id);
        $signedGuest = $this->guest($registration, 'Ann', 'Alpha');
        $pendingGuest = $this->guest($registration, 'Zed', 'Zulu');
        $signed = Waiver::factory()->signed()->forGuest($signedGuest)->create();
        $signedGuest->forceFill(['waiver_id' => $signed->id])->save();
        $pending = Waiver::factory()->forGuest($pendingGuest)->create();
        $pendingGuest->forceFill(['waiver_id' => $pending->id])->save();

        // Another member's guest at the same show must never appear.
        $this->guest($this->registration($registration->carShow), 'Other', 'Person');

        $response = $this->asMember($member)->getJson('/waiver/'.$registration->show_id.'/status')->assertOk();

        $this->assertSame([
            ['name' => 'Mia Member', 'guest_id' => null, 'status' => 'not_started', 'has_signed' => null],
            ['name' => 'Ann Alpha', 'guest_id' => $signedGuest->id, 'status' => 'signed', 'has_signed' => true],
            ['name' => 'Zed Zulu', 'guest_id' => $pendingGuest->id, 'status' => 'not_signed', 'has_signed' => false],
        ], $response->json('people'));
        $this->assertStringNotContainsString('tracking', (string) $response->getContent());
    }

    #[Test]
    public function status_needs_a_login_and_a_registration_for_the_show(): void
    {
        $registration = $this->registration();

        $this->getJson('/waiver/'.$registration->show_id.'/status')->assertStatus(401);
        $this->asMember(User::factory()->create())->getJson('/waiver/'.$registration->show_id.'/status')
            ->assertStatus(403)->assertJson(['error' => 'not_registered']);
        $this->asMember(User::factory()->create())->getJson('/waiver/999999/status')->assertNotFound();
    }

    #[Test]
    public function sign_url_creates_the_pending_waiver_for_the_member_or_their_guest(): void
    {
        $registration = $this->registration(null, ['first' => 'Mia', 'last' => 'Member']);
        $member = User::query()->findOrFail($registration->user_id);
        $guest = $this->guest($registration, 'Gus', 'Guest');

        $own = $this->asMember($member)->getJson('/waiver/'.$registration->show_id.'/sign-url')->assertOk();
        $waiver = Waiver::query()->findOrFail($registration->fresh()?->waiver_id);
        $own->assertJson(['url' => 'https://sesf.example.test/waiver_sign/t/'.$this->tracking($waiver), 'person_name' => 'Mia Member']);

        $this->asMember($member)->getJson('/waiver/'.$registration->show_id.'/sign-url?guest_id='.$guest->id)
            ->assertOk()->assertJson(['person_name' => 'Gus Guest']);
        $this->assertNotNull($guest->fresh()?->waiver_id);

        // Repeat calls reuse the waiver.
        $this->asMember($member)->getJson('/waiver/'.$registration->show_id.'/sign-url')->assertOk();
        $this->assertSame(1, Waiver::query()->where('show_registration_id', $registration->id)->count());
    }

    #[Test]
    public function sign_url_refuses_other_peoples_guests_and_signed_waivers(): void
    {
        $registration = $this->registration();
        $member = User::query()->findOrFail($registration->user_id);
        $foreignGuest = $this->guest($this->registration($registration->carShow));

        $this->getJson('/waiver/'.$registration->show_id.'/sign-url')->assertStatus(401);
        $this->asMember($member)->getJson('/waiver/'.$registration->show_id.'/sign-url?guest_id='.$foreignGuest->id)
            ->assertStatus(400)->assertJson(['error' => 'prepare', 'message' => 'Invalid guest.']);
        $this->assertNull($foreignGuest->fresh()?->waiver_id);
        $this->asMember($member)->getJson('/waiver/'.$registration->show_id.'/sign-url?guest_id=1;drop')->assertStatus(400);

        $signed = Waiver::factory()->signed()->create(['show_registration_id' => $registration->id]);
        $registration->forceFill(['waiver_id' => $signed->id])->save();
        $this->asMember($member)->getJson('/waiver/'.$registration->show_id.'/sign-url')
            ->assertStatus(400)->assertJson(['error' => 'already_signed']);

        $this->asMember(User::factory()->create())->getJson('/waiver/'.$registration->show_id.'/sign-url')->assertStatus(403);
    }

    #[Test]
    public function logout_accepts_get_and_post_and_only_redirects_locally(): void
    {
        $member = User::factory()->create();

        $this->asMember($member)->get('/waiver/5/logout')->assertRedirect('/waiver/5');
        $this->assertGuest();

        $this->asMember($member)->post('/waiver/5/logout', ['redirect' => '/#/events'])->assertRedirect('/#/events');
        $this->asMember($member)->get('/waiver/5/logout?redirect=//evil.example')->assertRedirect('/waiver/5');
        $this->asMember($member)->get('/waiver/5/logout?redirect=https://evil.example')->assertRedirect('/waiver/5');
        $this->asMember($member)->get('/waiver/5/logout?redirect=/\\evil.example')->assertRedirect('/waiver/5');
        $this->asMember($member)->getJson('/waiver/5/logout')->assertOk()->assertJson(['message' => 'Logged out']);
    }

    #[Test]
    public function a_post_logout_without_a_csrf_token_gets_419(): void
    {
        $this->app->instance('env', 'local');
        $member = User::factory()->create();

        $this->asMember($member)->post('/waiver/5/logout')->assertStatus(419);
        $this->assertAuthenticatedAs($member);
    }
}
