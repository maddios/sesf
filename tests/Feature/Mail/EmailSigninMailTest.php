<?php

use App\Mail\EmailSigninMail;

it('renders the code and the sign-in button (standalone legacy template)', function (): void {
    config(['sesf.email_logo_url' => 'https://logo.example.org/logo.png']);
    $url = 'https://app.example.org/login/email-signin/consume/'.str_repeat('ab', 32).'?x=1&y=2';

    $html = (new EmailSigninMail($url, '048213'))->render();

    expect($html)->toContain('048213')
        ->and($html)->toContain('href="'.htmlspecialchars($url, ENT_QUOTES).'"')
        ->and($html)->toContain('Sign in to Southeast Sharkfest');
});
