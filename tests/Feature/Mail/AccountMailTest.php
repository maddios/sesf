<?php

use App\Mail\AdminNewAccountMail;
use App\Mail\ContactReportMail;
use App\Mail\NewAccountMail;
use App\Mail\PrivateMessageMail;
use App\Models\User;

beforeEach(function (): void {
    config(['app.url' => 'https://sesf.example.org']);
});

/** @param array<string, mixed> $attributes */
function accountMember(array $attributes = []): User
{
    return new User(array_merge([
        'first' => 'Jane',
        'last' => 'Doe',
        'email' => 'jane@example.test',
        'address1' => '1 Main St',
        'city' => 'Orlando',
        'state' => 'FL',
        'zip' => '32803',
    ], $attributes));
}

it('renders the welcome mail with the signed-in email and the legacy wording', function (): void {
    $html = (new NewAccountMail(accountMember(['id' => 7])))->render();

    expect($html)->toContain('Welcome to the family')
        ->and($html)->toContain('jane@example.test')
        ->and($html)->toContain('Events');
});

it('escapes markup in the registration notice to the registration inbox', function (): void {
    $member = accountMember(['first' => '<script>alert(1)</script>', 'last' => 'Evil']);

    $html = (new AdminNewAccountMail($member))->render();

    expect($html)->not->toContain('<script>alert(1)</script>')
        ->and($html)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->and($html)->toContain('Orlando');
});

it('escapes the sender name and message body in a private message', function (): void {
    $recipient = accountMember(['first' => 'Rita', 'email' => 'rita@example.test']);
    $sender = accountMember(['first' => '<img src=x onerror=alert(1)>', 'last' => 'Sender']);
    $body = "Hello <script>steal()</script>\nsecond line";

    $mail = new PrivateMessageMail($recipient, $sender, 'Hi', $body);
    $html = $mail->render();

    expect($html)->not->toContain('<script>steal()</script>')
        ->and($html)->not->toContain('<img src=x')
        ->and($html)->toContain('&lt;script&gt;steal()&lt;/script&gt;')
        ->and($html)->toContain('https://sesf.example.org/#/')
        ->and($html)->toContain('/settings');
});

it('sets Reply-To to the sender of a private message and keeps the subject on one line', function (): void {
    $recipient = accountMember(['email' => 'rita@example.test']);
    $sender = accountMember(['first' => 'Sam', 'email' => 'sam@example.test']);

    $mail = new PrivateMessageMail($recipient, $sender, "Subject\r\nBcc: spy@example.test", 'Body');

    expect($mail->hasReplyTo('sam@example.test'))->toBeTrue()
        ->and($mail->envelope()->subject)->not->toContain("\r")
        ->and($mail->envelope()->subject)->not->toContain("\n");
});

it('escapes the visitor name, message and location in the contact report', function (): void {
    $mail = new ContactReportMail(
        messageSubject: 'Question',
        senderName: '<b>Visitor</b>',
        senderEmail: 'vic@example.test',
        body: '<script>x()</script>',
        location: '/#/<i>page</i>',
        senderProfileUrl: null,
    );

    $html = $mail->render();

    expect($html)->not->toContain('<script>x()</script>')
        ->and($html)->not->toContain('<b>Visitor</b>')
        ->and($html)->not->toContain('<i>page</i>')
        ->and($html)->toContain('&lt;script&gt;x()&lt;/script&gt;')
        ->and($html)->not->toContain('View profile');
});

it('shows the member profile link in the contact report only for a signed-in sender', function (): void {
    $mail = new ContactReportMail(
        messageSubject: 'Question',
        senderName: 'Jane Doe',
        senderEmail: 'jane@example.test',
        body: 'Hi',
        location: null,
        senderProfileUrl: 'https://sesf.example.org/#/JaneD/u9',
    );

    expect($mail->render())->toContain('View profile')
        ->and($mail->hasReplyTo('jane@example.test'))->toBeTrue();
});

it('sets Reply-To to the visitor on a contact report', function (): void {
    $mail = new ContactReportMail('Question', 'Vic', 'vic@example.test', 'Hi');

    expect($mail->hasReplyTo('vic@example.test'))->toBeTrue();
});
