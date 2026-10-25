<?php

use App\Mail\ResetPasswordMail;
use Illuminate\Support\Facades\View;

it('renders the reset button, the hash-route link and the logo', function (): void {
    config(['sesf.email_logo_url' => 'https://logo.example.org/logo.png']);

    $html = (new ResetPasswordMail('abc123', 'a+b@example.org'))->render();
    $link = htmlspecialchars(rtrim((string) config('app.url'), '/').'/#/reset-password?token=abc123&email='.urlencode('a+b@example.org'), ENT_QUOTES);

    expect($html)->toContain('Reset password')
        ->and($html)->toContain('href="'.$link.'"')
        ->and($html)->toContain('/#/reset-password?token=abc123')
        ->and($html)->toContain('src="https://logo.example.org/logo.png"')
        ->and($html)->toContain('Southeast Sharkfest');
});

it('falls back to the asset logo path when no logo url is configured', function (): void {
    config(['sesf.email_logo_url' => '']);

    $html = (new ResetPasswordMail('abc123', 'a@example.org'))->render();

    expect($html)->toContain('images/sesf_final_logo_v2.jpg');
});

it('escapes values passed to the shared partials', function (): void {
    $payload = '<script>alert(1)</script>';

    $kv = View::make('emails.partials.kv', ['label' => $payload, 'value' => $payload])->render();
    $button = View::make('emails.partials.button', ['url' => 'https://x.example/?a="b"', 'label' => $payload])->render();
    $raw = View::make('emails.partials.raw_block', ['title' => $payload, 'content' => $payload])->render();
    $badge = View::make('emails.partials.badge', ['text' => $payload, 'kind' => 'warning'])->render();

    foreach ([$kv, $button, $raw, $badge] as $html) {
        expect($html)->not->toContain('<script>alert(1)</script>')
            ->and($html)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;');
    }

    expect($button)->toContain('href="https://x.example/?a=&quot;b&quot;"');
});

it('sends from the registration address with the configured sender name', function (): void {
    $envelope = (new ResetPasswordMail('abc123', 'a@example.org'))->envelope();

    expect($envelope->from?->address)->toBe(config('sesf.reg_email'))
        ->and($envelope->from?->name)->toBe(config('mail.from.name'));
});

it('builds the reset link from APP_URL', function (): void {
    config(['app.url' => 'https://sesf.example.org/']);

    expect(ResetPasswordMail::resetUrl('abc123', 'a@example.org'))
        ->toStartWith('https://sesf.example.org/#/reset-password?token=abc123&email=');
});
