<?php

namespace Tests\Database\Waivers;

use App\Models\User;
use App\Models\Waiver;
use App\Services\Waivers\WaiverSignService;
use PHPUnit\Framework\Attributes\Test;

/** The waiver HTML pages: Blade shells with a JSON config, no secrets, 404 for unknown links. */
class WaiverPagesTest extends WaiverTestCase
{
    /** @return array<string, mixed> */
    private function embeddedConfig(string $html): array
    {
        $this->assertSame(1, preg_match('#<script id="sesf-waiver-config" type="application/json">(.*?)</script>#s', $html, $match));

        return json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);
    }

    private function pendingWaiver(): Waiver
    {
        return app(WaiverSignService::class)->createPending($this->registration());
    }

    #[Test]
    public function tracking_sign_page_renders_the_legal_text_and_a_valid_config(): void
    {
        $this->withoutVite();
        $waiver = $this->pendingWaiver();

        $html = (string) $this->get('/waiver_sign/t/'.$this->tracking($waiver))->assertOk()->getContent();

        $this->assertSame(['page' => 'sign'], $this->embeddedConfig($html));
        $this->assertStringContainsString('IN CONSIDERATION', $html);
        $this->assertStringContainsString('id="sesf-waiver-form"', $html);
        $this->assertStringContainsString('<canvas id="sesf-sig-canvas"', $html);
    }

    #[Test]
    public function tracking_sign_page_never_writes_the_tracking_id_or_member_data_into_the_html(): void
    {
        $this->withoutVite();
        $registration = $this->registration();
        $waiver = app(WaiverSignService::class)->createPending($registration);
        $member = User::query()->findOrFail($registration->user_id);

        $html = (string) $this->get('/waiver_sign/t/'.$this->tracking($waiver))->assertOk()->getContent();

        $this->assertStringNotContainsString($this->tracking($waiver), $html);
        $this->assertStringNotContainsString($member->email, $html);
        $this->assertStringNotContainsString((string) $member->password, $html);
        $this->assertStringNotContainsString((string) config('app.key'), $html);
    }

    #[Test]
    public function unknown_tracking_link_is_a_404_page(): void
    {
        $this->withoutVite();

        $this->get('/waiver_sign/t/'.str_repeat('a', 32))
            ->assertNotFound()
            ->assertSee('This waiver link is not valid.', false);
    }

    #[Test]
    public function walk_in_sign_page_is_200_for_a_show_and_404_otherwise(): void
    {
        $this->withoutVite();
        $show = $this->show();

        $html = (string) $this->get('/waiver_sign/s/'.$show->id)->assertOk()->getContent();

        $this->assertSame(['page' => 'sign'], $this->embeddedConfig($html));
        $this->assertStringContainsString($show->name, $html);
        $this->get('/waiver_sign/s/999999')->assertNotFound();
    }

    #[Test]
    public function thank_you_page_is_200_for_a_signed_link_and_404_otherwise(): void
    {
        $this->withoutVite();
        $waiver = Waiver::factory()->signed()->create(['show_registration_id' => $this->registration()->id]);

        $html = (string) $this->get('/waiver_sign/thank-you/'.$this->tracking($waiver))->assertOk()->getContent();

        $this->assertSame(['page' => 'thank-you'], $this->embeddedConfig($html));
        $this->get('/waiver_sign/thank-you/'.str_repeat('b', 32))->assertNotFound();
        $this->get('/waiver_sign/thank-you/'.$this->tracking($this->pendingWaiver()))->assertNotFound();
    }

    #[Test]
    public function signing_link_for_a_signed_waiver_shows_the_already_on_file_page(): void
    {
        $this->withoutVite();
        $waiver = Waiver::factory()->signed()->create(['show_registration_id' => $this->registration()->id]);

        $this->get('/waiver_sign/t/'.$this->tracking($waiver))
            ->assertOk()
            ->assertSee('Waiver already on file.', false)
            ->assertDontSee('id="sesf-waiver-form"', false);
    }

    #[Test]
    public function portal_page_is_200_for_a_show_and_leaks_no_member_data(): void
    {
        $this->withoutVite();
        $registration = $this->registration();
        $member = User::query()->findOrFail($registration->user_id);

        $html = (string) $this->get('/waiver/'.$registration->show_id)->assertOk()->getContent();

        $this->assertSame(['page' => 'portal'], $this->embeddedConfig($html));
        $this->assertStringContainsString('id="sesf-portal-login-form"', $html);
        $this->assertStringNotContainsString($member->email, $html);
        $this->assertStringNotContainsString((string) $member->password, $html);
        $this->get('/waiver/999999')->assertNotFound();
    }
}
