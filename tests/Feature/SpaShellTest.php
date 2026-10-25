<?php

namespace Tests\Feature;

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
}
