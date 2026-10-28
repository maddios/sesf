<?php

use Symfony\Component\Process\Process;

/** Runs the sandbox seed with the e2e database settings and the given PayPal verify URL; it must stop before any DB work. */
function runE2eSeed(string $verifyUrl): Process
{
    $process = new Process([PHP_BINARY, base_path('scripts/e2e-seed.php')], base_path(), [
        'DB_CONNECTION' => 'mysql',
        'DB_HOST' => 'e2edb',
        'DB_DATABASE' => 'sesf_e2e',
        'DB_URL' => '',
        'PAYPAL_IPN_VERIFY_URL' => $verifyUrl,
    ]);
    $process->setTimeout(30)->run();

    return $process;
}

it('refuses to seed the sandbox when the IPN postback would reach real PayPal', function (string $url) {
    $process = runE2eSeed($url);

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toContain('PayPal verify_url host');
})->with([
    'live' => 'https://ipnpb.paypal.com/cgi-bin/webscr',
    'sandbox' => 'https://ipnpb.sandbox.paypal.com/cgi-bin/webscr',
    'apex' => 'https://PayPal.com/cgi-bin/webscr',
]);
