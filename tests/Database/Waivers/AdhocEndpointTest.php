<?php

namespace Tests\Database\Waivers;

use App\Models\Waiver;
use App\Services\Waivers\WaiverSignService;
use PHPUnit\Framework\Attributes\Test;

/** Walk-in waivers: waiver_sign/s/{showId}, one pending row per session, per-IP creation cap. */
class AdhocEndpointTest extends WaiverTestCase
{
    private function adhocCount(int $showId): int
    {
        return Waiver::query()->where('adhoc_show_id', $showId)->count();
    }

    #[Test]
    public function prefill_creates_one_waiver_per_session_and_reuses_it(): void
    {
        $show = $this->show();

        $this->getJson('/waiver_sign/s/'.$show->id.'/prefill')->assertOk()->assertJson(['ok' => true, 'first' => '', 'last' => '', 'email' => '']);
        $this->getJson('/waiver_sign/s/'.$show->id.'/prefill')->assertOk();
        $this->getJson('/waiver_sign/s/'.$show->id.'/prefill')->assertOk();

        $this->assertSame(1, $this->adhocCount($show->id));
    }

    #[Test]
    public function a_new_session_gets_a_new_waiver(): void
    {
        $show = $this->show();

        $this->getJson('/waiver_sign/s/'.$show->id.'/prefill')->assertOk();
        $this->flushSession();
        $this->getJson('/waiver_sign/s/'.$show->id.'/prefill')->assertOk();

        $this->assertSame(2, $this->adhocCount($show->id));
    }

    #[Test]
    public function signing_uses_the_session_waiver_and_then_releases_it(): void
    {
        $show = $this->show();
        $this->getJson('/waiver_sign/s/'.$show->id.'/prefill')->assertOk();

        $this->postJson('/waiver_sign/s/'.$show->id, $this->payload())->assertOk()->assertJsonStructure(['ok', 'thankYouUrl']);

        $waiver = Waiver::query()->where('adhoc_show_id', $show->id)->sole();
        $this->assertTrue($waiver->has_signed);
        $this->assertFileExists($this->pdfDir.'/'.$waiver->waiver_id.'.pdf');
        $this->assertFalse(session()->has(WaiverSignService::ADHOC_SESSION_PREFIX.$show->id));

        // The same session signing again starts over instead of touching the signed row.
        $this->postJson('/waiver_sign/s/'.$show->id, $this->payload())->assertStatus(403);
        $this->getJson('/waiver_sign/s/'.$show->id.'/prefill')->assertOk();
        $this->assertSame(2, $this->adhocCount($show->id));
    }

    #[Test]
    public function signing_without_a_session_waiver_is_403(): void
    {
        $show = $this->show();

        $this->postJson('/waiver_sign/s/'.$show->id, $this->payload())
            ->assertStatus(403)
            ->assertJsonPath('error', 'Your browser session ended before submit. Open the same event sign-in link again (or refresh), complete the form, and submit once.');
    }

    #[Test]
    public function a_session_key_for_a_signed_waiver_is_403(): void
    {
        $show = $this->show();
        $signed = Waiver::factory()->signed()->adhoc($show)->create();

        $this->withSession([WaiverSignService::ADHOC_SESSION_PREFIX.$show->id => $this->tracking($signed)])
            ->postJson('/waiver_sign/s/'.$show->id, $this->payload())
            ->assertStatus(403)
            ->assertJsonPath('error', 'This sign-in page is no longer valid. Open the event QR or sign link again from the start.');
    }

    #[Test]
    public function unknown_shows_are_404(): void
    {
        $this->getJson('/waiver_sign/s/999999/prefill')->assertNotFound()->assertJson(['ok' => false, 'error' => 'Event not found']);
        $this->postJson('/waiver_sign/s/999999', $this->payload())->assertNotFound();
        $this->getJson('/waiver_sign/s/abc/prefill')->assertNotFound();
    }

    #[Test]
    public function new_walk_in_waivers_are_capped_per_ip(): void
    {
        config(['waivers.adhoc_creates_per_ip_per_hour' => 2]);
        $show = $this->show();

        for ($i = 0; $i < 2; $i++) {
            $this->flushSession();
            $this->getJson('/waiver_sign/s/'.$show->id.'/prefill')->assertOk();
        }
        $this->flushSession();
        $this->getJson('/waiver_sign/s/'.$show->id.'/prefill')->assertStatus(429)->assertHeader('Retry-After');

        $this->assertSame(2, $this->adhocCount($show->id));
    }
}
