<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SpaShellTest extends TestCase
{
    public function test_root_serves_the_spa_shell(): void
    {
        $this->withoutVite();

        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee('<div id="app">', false);
        $response->assertSee('<meta name="csrf-token"', false);
    }

    public function test_shell_embeds_public_client_config_as_valid_json(): void
    {
        $this->withoutVite();
        config([
            'sesf.forum_url' => 'https://forum.example.test',
            'sesf.shop_url' => 'https://shop.example.test',
            'sesf.google.maps_js_key' => 'maps-public-key',
            'sesf.turnstile.site_key' => 'turnstile-site-key',
            'sesf.paypal_email' => 'paypal-receiver@example.test',
            'app.url' => 'https://sesf.example.test',
        ]);

        $html = $this->get('/')->assertStatus(200)->getContent();

        $this->assertSame(1, preg_match('#<script id="sesf-config" type="application/json">(.*?)</script>#s', (string) $html, $match));
        $decoded = json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame([
            'forumUrl' => 'https://forum.example.test',
            'shopUrl' => 'https://shop.example.test',
            'mapsKey' => 'maps-public-key',
            'turnstileSiteKey' => 'turnstile-site-key',
            'paypalEmail' => 'paypal-receiver@example.test',
            'appUrl' => 'https://sesf.example.test',
        ], $decoded);
    }

    public function test_shell_never_leaks_server_side_secrets(): void
    {
        $this->withoutVite();
        config([
            'sesf.turnstile.secret' => 'TURNSTILE-SECRET-MUST-NOT-LEAK',
            'sesf.vanilla.secret' => 'VANILLA-SECRET-MUST-NOT-LEAK',
            'sesf.google.geocode_key' => 'GEOCODE-KEY-MUST-NOT-LEAK',
        ]);

        $html = (string) $this->get('/')->assertStatus(200)->getContent();

        $this->assertStringNotContainsString('MUST-NOT-LEAK', $html);
        $this->assertStringNotContainsString((string) config('app.key'), $html);
    }

    public function test_shell_has_the_legacy_og_image_and_google_verification(): void
    {
        $this->withoutVite();
        config(['app.url' => 'https://sesf.example.test/']);

        $html = (string) $this->get('/')->assertStatus(200)->getContent();

        $this->assertStringContainsString('<meta property="og:image" content="https://sesf.example.test/images/sesf_final_logo_v2.jpg">', $html);
        $this->assertStringContainsString('<meta name="google-site-verification" content="Qw0JT0-fLK0PD-KsmkhYErrfOSPSePY_EmflO7WI1mI">', $html);
        $this->assertFileExists(public_path('images/sesf_final_logo_v2.jpg'));
    }

    public function test_datadog_rum_is_injected_with_public_values_only_when_configured(): void
    {
        $this->withoutVite();
        config([
            'sesf.datadog.rum.enabled' => true,
            'sesf.datadog.rum.application_id' => 'app-id-123',
            'sesf.datadog.rum.client_token' => 'pubTOKEN123',
            'sesf.datadog.rum.site' => 'us5.datadoghq.com',
            'sesf.datadog.rum.service' => 'sesf',
            'sesf.datadog.logs.api_key' => 'DD-API-KEY-MUST-NOT-LEAK',
        ]);

        $html = (string) $this->get('/')->assertStatus(200)->getContent();

        $this->assertStringContainsString('https://www.datadoghq-browser-agent.com/us5/v6/datadog-rum.js', $html);
        $this->assertSame(1, preg_match('#window\.DD_RUM\.init\((\{.*?\})\);#s', $html, $match));
        $init = json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('app-id-123', $init['applicationId']);
        $this->assertSame('pubTOKEN123', $init['clientToken']);
        $this->assertSame('us5.datadoghq.com', $init['site']);
        $this->assertSame('sesf', $init['service']);
        $this->assertSame(app()->environment(), $init['env']);
        $this->assertSame(100, $init['sessionSampleRate']);
        $this->assertSame(20, $init['sessionReplaySampleRate']);
        $this->assertTrue($init['trackResources']);
        $this->assertTrue($init['trackUserInteractions']);
        $this->assertTrue($init['trackLongTasks']);
        $this->assertStringNotContainsString('MUST-NOT-LEAK', $html);
    }

    /**
     * @return array<string, array{bool, string, string}>
     */
    public static function rumDisabled(): array
    {
        return [
            'disabled' => [false, 'app-id', 'pubtoken'],
            'no application id' => [true, '', 'pubtoken'],
            'no client token' => [true, 'app-id', ''],
        ];
    }

    #[DataProvider('rumDisabled')]
    public function test_datadog_rum_is_left_out_when_disabled_or_unset(bool $enabled, string $appId, string $token): void
    {
        $this->withoutVite();
        config([
            'sesf.datadog.rum.enabled' => $enabled,
            'sesf.datadog.rum.application_id' => $appId,
            'sesf.datadog.rum.client_token' => $token,
        ]);

        $html = (string) $this->get('/')->assertStatus(200)->getContent();

        $this->assertStringNotContainsString('datadog-rum.js', $html);
        $this->assertStringNotContainsString('DD_RUM', $html);
    }
}
