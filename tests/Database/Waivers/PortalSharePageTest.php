<?php

namespace Tests\Database\Waivers;

use App\Models\User;
use App\Models\Waiver;
use App\Services\Waivers\WaiverSignService;
use PHPUnit\Framework\Attributes\Test;

/** waiver/{showId}/share (legacy share.blade.php), the portal status banner and optional email. */
class PortalSharePageTest extends WaiverTestCase
{
    private function asMember(User $member): static
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        return $this->actingAs($member);
    }

    #[Test]
    public function share_page_is_for_the_signed_in_member_only(): void
    {
        $this->withoutVite();
        $registration = $this->registration();
        $member = User::query()->findOrFail($registration->user_id);

        $this->get('/waiver/'.$registration->show_id.'/share')->assertRedirect('/waiver/'.$registration->show_id);

        $html = (string) $this->asMember($member)->get('/waiver/'.$registration->show_id.'/share')->assertOk()->getContent();

        $this->assertStringContainsString('Copy link', $html);
        $this->assertStringContainsString('/waiver_sign/t/', $html);
        $this->assertStringContainsString('id="sesf-share-root"', $html);
    }

    #[Test]
    public function share_page_redirects_for_an_unregistered_user_a_signed_waiver_or_an_unknown_show(): void
    {
        $this->withoutVite();
        $registration = $this->registration();
        $member = User::query()->findOrFail($registration->user_id);
        $stranger = User::factory()->create();

        $this->asMember($stranger)->get('/waiver/'.$registration->show_id.'/share')->assertRedirect('/waiver/'.$registration->show_id);
        $this->asMember($member)->get('/waiver/999999/share')->assertRedirect('/waiver/999999');

        $signed = Waiver::factory()->signed()->create(['show_registration_id' => $registration->id]);
        $registration->update(['waiver_id' => $signed->id]);
        $this->asMember($member)->get('/waiver/'.$registration->show_id.'/share')->assertRedirect('/waiver/'.$registration->show_id);
    }

    #[Test]
    public function share_page_for_a_guest_must_belong_to_the_registration(): void
    {
        $this->withoutVite();
        $registration = $this->registration();
        $member = User::query()->findOrFail($registration->user_id);
        $guest = $this->guest($registration);
        $otherGuest = $this->guest($this->registration());

        $this->asMember($member)->get('/waiver/'.$registration->show_id.'/share?guest_id='.$guest->id)->assertOk();
        $this->asMember($member)->get('/waiver/'.$registration->show_id.'/share?guest_id='.$otherGuest->id)
            ->assertRedirect('/waiver/'.$registration->show_id);
    }

    #[Test]
    public function status_reports_the_signed_in_email_for_the_banner(): void
    {
        $registration = $this->registration();
        $member = User::query()->findOrFail($registration->user_id);

        $this->asMember($member)->getJson('/waiver/'.$registration->show_id.'/status')
            ->assertOk()
            ->assertJsonPath('signedInAs', $member->email);
    }

    #[Test]
    public function signing_works_without_an_email(): void
    {
        $waiver = app(WaiverSignService::class)->createPending($this->registration());

        $this->postJson('/waiver_sign/t/'.$this->tracking($waiver), $this->payload(['email' => '']))->assertOk()->assertJsonPath('ok', true);
    }
}
