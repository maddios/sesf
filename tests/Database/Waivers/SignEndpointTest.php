<?php

namespace Tests\Database\Waivers;

use App\Models\Waiver;
use App\Services\Waivers\WaiverSignService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\images\ImageFixtures as F;

/** waiver_sign/t/{trackingId} prefill, sign and thank-you data. */
class SignEndpointTest extends WaiverTestCase
{
    private function pending(): Waiver
    {
        return $this->app->make(WaiverSignService::class)->createPending($this->registration(null, ['first' => 'Jane', 'last' => 'Doe', 'email' => 'jane.doe@example.com']));
    }

    #[Test]
    public function prefill_returns_names_a_masked_email_and_the_csrf_token_only(): void
    {
        $waiver = $this->pending();

        $response = $this->getJson('/waiver_sign/t/'.$this->tracking($waiver).'/prefill')->assertOk();

        $this->assertSame(['ok', 'first', 'last', 'email', 'csrf'], array_keys((array) $response->json()));
        $response->assertJson(['ok' => true, 'first' => 'Jane', 'last' => 'Doe', 'email' => 'j***@example.com']);
        $this->assertNotEmpty($response->json('csrf'));
    }

    #[Test]
    public function prefill_is_404_for_unknown_and_409_for_signed_links(): void
    {
        $this->getJson('/waiver_sign/t/'.str_repeat('0', 32).'/prefill')->assertNotFound()->assertJson(['ok' => false, 'error' => 'Invalid waiver link.']);
        $this->getJson('/waiver_sign/t/short/prefill')->assertNotFound();

        $signed = Waiver::factory()->signed()->create(['show_registration_id' => $this->registration()->id]);
        $this->getJson('/waiver_sign/t/'.$this->tracking($signed).'/prefill')->assertStatus(409)->assertJson(['error' => 'Already signed.']);

        $orphan = Waiver::factory()->create(['show_registration_id' => null, 'adhoc_show_id' => 999999]);
        $this->getJson('/waiver_sign/t/'.$this->tracking($orphan).'/prefill')->assertNotFound()->assertJson(['error' => 'Event not found']);
    }

    #[Test]
    public function signing_returns_the_thank_you_url_and_a_second_sign_is_409(): void
    {
        $waiver = $this->pending();
        $tracking = $this->tracking($waiver);

        $this->postJson('/waiver_sign/t/'.$tracking, $this->payload(['waiver_ui_mode' => 'embedded']))
            ->assertOk()
            ->assertExactJson(['ok' => true, 'thankYouUrl' => 'https://sesf.example.test/waiver_sign/thank-you/'.$tracking.'?ui=embedded']);

        $this->assertTrue($waiver->fresh()?->has_signed);
        $this->assertFileExists($this->pdfDir.'/'.Waiver::query()->findOrFail($waiver->id)->waiver_id.'.pdf');

        $this->postJson('/waiver_sign/t/'.$tracking, $this->payload(['first_name' => 'Mallory']))
            ->assertStatus(409)->assertJsonPath('ok', false);
        $this->assertSame('Sam', $waiver->fresh()?->waiver_data['first_name'] ?? null);
    }

    #[Test]
    public function the_thank_you_data_is_only_for_signed_waivers(): void
    {
        $waiver = $this->pending();
        $this->getJson('/waiver_sign/thank-you/'.$this->tracking($waiver).'/data')->assertNotFound();

        $this->postJson('/waiver_sign/t/'.$this->tracking($waiver), $this->payload())->assertOk();
        $fresh = $waiver->fresh();

        $this->getJson('/waiver_sign/thank-you/'.$this->tracking($waiver).'/data')
            ->assertOk()
            ->assertJson(['ok' => true, 'show' => ['name' => 'Spring Fest'], 'reference' => substr((string) $fresh?->waiver_id, -8)])
            ->assertJsonMissingPath('email');
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidPayloads(): array
    {
        return [
            'missing first name' => [['first_name' => ''], 'First and last name are required.'],
            'missing last name' => [['last_name' => null], 'First and last name are required.'],
            'first name too long' => [['first_name' => str_repeat('a', 61)], ''],
            'control character in name' => [['last_name' => "Doe\x07"], ''],
            'missing email' => [['email' => ''], 'Email is required.'],
            'bad email' => [['email' => 'not-an-email'], 'Enter a valid email address.'],
            'email too long' => [['email' => str_repeat('a', 120).'@example.test'], ''],
            'bad mode' => [['signature_mode' => 'svg'], ''],
            'missing signature' => [['signature_base64' => ''], 'Signature is required.'],
            'minor without guardian' => [['signing_for_minor' => '1'], 'Parent or guardian first and last name are required when signing for a minor.'],
            'typed text with digits' => [['signature_mode' => 'type', 'signature_text' => 'Sam 123'], 'The typed signature may only contain letters, spaces, apostrophes, periods and hyphens.'],
            'typed text too long' => [['signature_mode' => 'type', 'signature_text' => str_repeat('a', 81)], ''],
            'latitude out of range' => [['geolocation' => ['lat' => 91, 'lng' => 0]], ''],
            'longitude not numeric' => [['geolocation' => ['lat' => 1, 'lng' => 'x']], ''],
            'geolocation extra key' => [['geolocation' => ['lat' => 1, 'lng' => 2, 'ip' => '1.2.3.4']], ''],
            'accuracy negative' => [['geolocation' => ['lat' => 1, 'lng' => 2, 'accuracy' => -1]], ''],
            'svg signature' => [['signature_base64' => 'data:image/svg+xml;base64,'.base64_encode(F::svgWithScript())], 'Signature could not be processed.'],
            'jpeg signature' => [['signature_base64' => 'data:image/png;base64,'.base64_encode(F::jpeg())], 'Signature could not be processed.'],
            'huge signature dimensions' => [['signature_base64' => 'data:image/png;base64,'.base64_encode(F::png(4000, 10))], 'Signature image is too large.'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[Test]
    #[DataProvider('invalidPayloads')]
    public function invalid_submissions_are_rejected_with_400(array $overrides, string $message): void
    {
        $waiver = $this->pending();

        $response = $this->postJson('/waiver_sign/t/'.$this->tracking($waiver), $this->payload($overrides))
            ->assertStatus(400)
            ->assertJsonPath('ok', false);
        if ($message !== '') {
            $response->assertJsonPath('error', $message);
        }
        $this->assertFalse($waiver->fresh()?->has_signed);
    }

    #[Test]
    public function an_oversized_signature_payload_is_rejected(): void
    {
        config(['sesf.waiver_signature_base64_max_chars' => 2000]);
        $waiver = $this->pending();

        $this->postJson('/waiver_sign/t/'.$this->tracking($waiver), $this->payload(['signature_base64' => 'data:image/png;base64,'.str_repeat('A', 5000)]))
            ->assertStatus(400)->assertJsonPath('error', 'Signature image is too large.');
    }

    #[Test]
    public function a_minor_with_a_guardian_and_geolocation_is_accepted(): void
    {
        $waiver = $this->pending();

        $this->postJson('/waiver_sign/t/'.$this->tracking($waiver), $this->payload([
            'signing_for_minor' => 'on', 'adult_first_name' => 'Pat', 'adult_last_name' => 'Signer',
            'signature_mode' => 'type', 'signature_text' => "Sam O'Neil-Smith",
            'geolocation' => ['lat' => '28.1', 'lng' => -81.2, 'accuracy' => 30],
        ]))->assertOk();

        $data = (array) $waiver->fresh()?->waiver_data;
        $this->assertTrue($data['is_minor']);
        $this->assertSame('Pat Signer', $data['guardian_name']);
        $this->assertSame("Sam O'Neil-Smith", $data['signature_text']);
        $this->assertSame(28.1, $data['geolocation']['lat']);
    }

    #[Test]
    public function a_post_without_a_csrf_token_gets_419(): void
    {
        // The test runner skips CSRF checks; pretend to be the app so the middleware runs.
        $this->app->instance('env', 'local');
        $waiver = $this->pending();

        $this->postJson('/waiver_sign/t/'.$this->tracking($waiver), $this->payload())->assertStatus(419);
        $this->assertFalse($waiver->fresh()?->has_signed);
    }

    #[Test]
    public function sign_posts_are_throttled_at_40_per_minute_per_ip(): void
    {
        $tracking = str_repeat('f', 32);
        for ($i = 0; $i < 40; $i++) {
            // Validation runs before the lookup, so an empty body is a 400; it still counts.
            $this->postJson('/waiver_sign/t/'.$tracking, [])->assertStatus(400);
        }

        $this->postJson('/waiver_sign/t/'.$tracking, [])->assertStatus(429);
    }

    #[Test]
    public function prefill_is_throttled_at_120_per_minute_per_ip(): void
    {
        $tracking = str_repeat('e', 32);
        for ($i = 0; $i < 120; $i++) {
            $this->getJson('/waiver_sign/t/'.$tracking.'/prefill')->assertStatus(404);
        }

        $this->getJson('/waiver_sign/t/'.$tracking.'/prefill')->assertStatus(429);
    }
}
