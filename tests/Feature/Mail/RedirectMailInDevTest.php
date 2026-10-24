<?php

use App\Listeners\RedirectMailInDev;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Email;

beforeEach(function () {
    config([
        'mail.default' => 'array',
        'sesf.email_debug' => true,
        'sesf.dev_address' => 'dev@example.org',
    ]);
});

/**
 * @return list<Email>
 */
function deliveredMessages(): array
{
    /** @var ArrayTransport $transport */
    $transport = app('mail.manager')->mailer('array')->getSymfonyTransport();

    return $transport->messages()
        ->map(fn ($sent) => $sent->getOriginalMessage())
        ->values()
        ->all();
}

function sendToAllRecipients(): void
{
    Mail::raw('Hello body', function ($message) {
        $message->from('registration@bmwsharkfest.org', 'Southeast Sharkfest')
            ->to(['alice@example.com', 'bob@example.com'])
            ->cc('carol@example.com')
            ->bcc('dan@example.com')
            ->subject('Registration confirmed');
    });
}

it('redirects To, Cc and Bcc to the dev address', function () {
    sendToAllRecipients();

    $messages = deliveredMessages();
    expect($messages)->toHaveCount(1);

    $message = $messages[0];
    expect(array_map(fn ($a) => $a->getAddress(), $message->getTo()))->toBe(['dev@example.org'])
        ->and($message->getCc())->toBe([])
        ->and($message->getBcc())->toBe([]);
});

it('keeps the original To addresses in X-SESF-Debug-Original-To', function () {
    sendToAllRecipients();

    $header = deliveredMessages()[0]->getHeaders()->get(RedirectMailInDev::ORIGINAL_TO_HEADER);

    expect($header)->not->toBeNull()
        ->and($header->getBodyAsString())->toBe('alice@example.com, bob@example.com');
});

it('leaves recipients alone when email_debug is off', function () {
    config(['sesf.email_debug' => false]);

    sendToAllRecipients();

    $message = deliveredMessages()[0];
    expect(array_map(fn ($a) => $a->getAddress(), $message->getTo()))->toBe(['alice@example.com', 'bob@example.com'])
        ->and(array_map(fn ($a) => $a->getAddress(), $message->getCc()))->toBe(['carol@example.com'])
        ->and(array_map(fn ($a) => $a->getAddress(), $message->getBcc()))->toBe(['dan@example.com'])
        ->and($message->getHeaders()->has(RedirectMailInDev::ORIGINAL_TO_HEADER))->toBeFalse();
});

it('cancels the send and logs it when email_debug is on and dev_address is empty', function () {
    config(['sesf.dev_address' => '']);
    $log = Log::spy();

    sendToAllRecipients();

    expect(deliveredMessages())->toBe([]);

    $log->shouldHaveReceived('info')->once()->with(Mockery::on(function (string $line) {
        return str_starts_with($line, '[mail not sent: email_debug on, no dev address] ')
            && str_contains($line, 'alice@example.com')
            && str_contains($line, 'bob@example.com')
            && str_contains($line, 'carol@example.com')
            && str_contains($line, 'dan@example.com')
            && str_contains($line, 'registration@bmwsharkfest.org')
            && str_contains($line, 'Registration confirmed')
            && str_contains($line, 'Hello body');
    }));
});

it('logs the html body stripped of tags when there is no text part', function () {
    config(['sesf.dev_address' => '']);
    $log = Log::spy();

    Mail::html('<p>Hi <b>there</b></p>', function ($message) {
        $message->to('alice@example.com')->subject('HTML only');
    });

    $log->shouldHaveReceived('info')->once()->with(Mockery::on(function (string $line) {
        return str_contains($line, 'Hi there') && ! str_contains($line, '<b>');
    }));
});

it('truncates the logged body to 20000 characters', function () {
    config(['sesf.dev_address' => '']);
    $log = Log::spy();

    Mail::raw(str_repeat('x', 25000), function ($message) {
        $message->to('alice@example.com')->subject('Long');
    });

    $log->shouldHaveReceived('info')->once()->with(Mockery::on(function (string $line) {
        return str_contains($line, str_repeat('x', 20000)) && ! str_contains($line, str_repeat('x', 20001));
    }));
});
