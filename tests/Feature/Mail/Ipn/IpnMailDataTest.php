<?php

use App\Mail\Ipn\IpnMailData;
use App\Models\ShowRegistration;
use App\Models\User;
use Tests\Feature\Mail\Ipn\Support\IpnFixtures;
use Tests\Support\LoadsLegacySchema;

uses(LoadsLegacySchema::class);

beforeEach(function (): void {
    config([
        'app.url' => 'https://sesf.example.org',
        'sesf.reg_email' => 'registration@sesf.test',
        'mail.from.name' => 'Southeast Sharkfest',
    ]);
});

/**
 * @param  array<string, mixed>  $userAttributes
 * @param  array<string, mixed>  $registrationAttributes
 */
function ipnRegistration(array $userAttributes = [], array $registrationAttributes = []): ShowRegistration
{
    $user = User::factory()->create(array_merge([
        'first' => 'Jane',
        'last' => 'Doe',
        'email' => 'jane@example.test',
    ], $userAttributes));

    return IpnFixtures::priced(array_merge([
        'user_id' => $user->id,
        'amount_owed' => '10.00',
        'amount_received' => '5.00',
    ], $registrationAttributes));
}

it('maps the registration and payment into mail values', function (): void {
    $registration = ipnRegistration();
    $payload = IpnFixtures::payload(['mc_gross' => '1234.50', 'mc_fee' => '2.00', 'txn_id' => 'TXN-9', 'payment_status' => 'Pending']);

    $data = IpnFixtures::registrationData($registration, $payload);

    expect($data->registrationId)->toBe($registration->id)
        ->and($data->userId)->toBe($registration->user_id)
        ->and($data->customerEmail)->toBe('jane@example.test')
        ->and($data->firstName)->toBe('Jane')
        ->and($data->lastName)->toBe('Doe')
        ->and($data->requiredCents)->toBe(1500)
        ->and($data->totalReceivedCents)->toBe(500)
        ->and($data->stillOwedCents)->toBe(1000)
        ->and($data->grossCents)->toBe(123450)
        ->and($data->feeCents)->toBe(200)
        ->and($data->txnId)->toBe('TXN-9')
        ->and($data->paymentStatus)->toBe('Pending');
});

it('never reports a negative amount still owed', function (): void {
    $registration = ipnRegistration([], ['amount_owed' => '0.00', 'amount_received' => '30.00']);

    expect(IpnFixtures::registrationData($registration, IpnFixtures::payload())->stillOwedCents)->toBe(0);
});

it('builds the hash billing link with the registration id, the way the legacy link did', function (): void {
    $registration = ipnRegistration();

    $data = IpnFixtures::registrationData($registration, IpnFixtures::payload());

    expect($data->payUrl)->toBe("https://sesf.example.org/#/JaneD/u{$registration->user_id}/billing/{$registration->id}");
});

it('URL-encodes the member segment of the billing link', function (): void {
    $registration = ipnRegistration(['first' => 'Shawn B', 'last' => 'Doe']);

    $url = IpnFixtures::registrationData($registration, IpnFixtures::payload())->payUrl;

    expect($url)->toContain('/#/Shawn%20BD/u'.$registration->user_id.'/billing/'.$registration->id)
        ->and($url)->not->toContain(' ');
});

it('keeps the raw IPN fields in the report and drops the username and password fields', function (): void {
    $payload = IpnFixtures::payload(['item_name' => 'Registration', 'username' => 'secret-user', 'password' => 'secret-pass']);

    $report = IpnMailData::report($payload);

    expect($report)->toContain('txn_id = TXN-TEST-1')
        ->and($report)->toContain('item_name = Registration')
        ->and($report)->not->toContain('secret-user')
        ->and($report)->not->toContain('secret-pass');
});

it('builds data without a registration for invalid, fatal and postback mails', function (): void {
    $data = IpnMailData::fromPayload(IpnFixtures::payload(['item_number' => '77']));

    expect($data->registrationId)->toBeNull()
        ->and($data->userId)->toBeNull()
        ->and($data->customerEmail)->toBeNull()
        ->and($data->itemNumber)->toBe('77')
        ->and($data->payUrl)->toBe('https://sesf.example.org/');
});
