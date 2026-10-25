<?php

use App\Mail\Ipn\AdminPaidMail;
use App\Mail\Ipn\CustomerPaidMail;
use App\Models\User;
use App\Services\PayPal\IpnMailerImpl;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
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

it('sends the customer mail to the user email and the admin mail to the registration inbox', function (): void {
    Mail::fake();
    $user = User::factory()->create(['email' => 'jane@example.test']);
    $registration = IpnFixtures::priced(['user_id' => $user->id, 'amount_owed' => '10.00', 'amount_received' => '5.00']);
    $impl = app(IpnMailerImpl::class);

    $impl->customerPaid($registration, IpnFixtures::payload(['item_number' => (string) $registration->id]));
    $impl->adminPaid($registration, IpnFixtures::payload(['item_number' => (string) $registration->id]));

    Mail::assertSent(CustomerPaidMail::class, fn (CustomerPaidMail $m): bool => $m->hasTo('jane@example.test'));
    Mail::assertSent(AdminPaidMail::class, fn (AdminPaidMail $m): bool => $m->hasTo('registration@sesf.test'));
});

it('swallows a transport failure and logs a warning with no payload or address in it', function (): void {
    Log::shouldReceive('warning')->once()->withArgs(
        fn (string $message, array $context): bool => $message === 'PayPal IPN email not sent'
            && $context['mail'] === 'customerPaid'
            && $context['exception'] === RuntimeException::class
            && ! str_contains(json_encode($context), 'secret-host')
            && ! str_contains(json_encode($context), 'jane@example.test'),
    );
    Mail::shouldReceive('to')->andThrow(new RuntimeException('smtp down: secret-host'));
    $user = User::factory()->create(['email' => 'jane@example.test']);
    $registration = IpnFixtures::priced(['user_id' => $user->id, 'amount_owed' => '10.00', 'amount_received' => '5.00']);

    app(IpnMailerImpl::class)->customerPaid($registration, IpnFixtures::payload(['item_number' => (string) $registration->id]));
});

it('does not throw when the failing send is an admin mail that has no registration', function (): void {
    Log::shouldReceive('warning')->once();
    Mail::shouldReceive('to')->andThrow(new RuntimeException('smtp down'));

    app(IpnMailerImpl::class)->adminFatal(IpnFixtures::payload(['item_number' => '9']), 'boom');
});
