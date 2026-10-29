<?php

use App\Mail\Ipn\AdminDeniedMail;
use App\Mail\Ipn\AdminEmailMismatchMail;
use App\Mail\Ipn\AdminFatalMail;
use App\Mail\Ipn\AdminInsufficientMail;
use App\Mail\Ipn\AdminInvalidInvoiceMail;
use App\Mail\Ipn\AdminInvalidMail;
use App\Mail\Ipn\AdminPaidMail;
use App\Mail\Ipn\AdminPendingMail;
use App\Mail\Ipn\AdminPostbackFailedMail;
use App\Mail\Ipn\AdminUnderfundMail;
use App\Mail\Ipn\AdminWeirdMail;
use App\Mail\Ipn\CustomerDeniedMail;
use App\Mail\Ipn\CustomerInsufficientMail;
use App\Mail\Ipn\CustomerPaidMail;
use App\Mail\Ipn\CustomerPendingMail;
use App\Mail\Ipn\CustomerUnderfundMail;
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
 * Registration with a real user row, so the customer mails get a real name and address.
 *
 * @param  array<string, mixed>  $userAttributes
 * @param  array<string, mixed>  $registrationAttributes
 */
function mailRegistration(array $userAttributes = [], array $registrationAttributes = []): ShowRegistration
{
    $user = User::factory()->create(array_merge([
        'first' => 'Jane',
        'last' => 'Doe',
        'email' => 'jane@example.test',
    ], $userAttributes));

    return IpnFixtures::priced(array_merge([
        'user_id' => $user->id,
        'amount_owed' => '1234.50',
        'amount_received' => '250.00',
    ], $registrationAttributes));
}

/** @param  array<string, string>  $fields */
function mailDataFor(ShowRegistration $registration, array $fields = []): IpnMailData
{
    $payload = IpnFixtures::payload(array_merge(['item_number' => (string) $registration->id], $fields));

    return IpnFixtures::registrationData($registration, $payload);
}

it('renders the customer paid mail with the legacy wording and the registration link', function (): void {
    $registration = mailRegistration();
    $mail = new CustomerPaidMail(mailDataFor($registration));

    $html = $mail->render();

    expect($html)->toContain('Thank you for your payment')
        ->and($html)->toContain('Hi Jane Doe,')
        ->and($html)->toContain('Paid');
    expect($mail->envelope()->subject)->toBe('Thank you for your payment');
});

it('sends every customer mail from the registration address with the configured sender name', function (): void {
    $mail = new CustomerPendingMail(mailDataFor(mailRegistration()));

    expect($mail->envelope()->from->address)->toBe('registration@sesf.test')
        ->and($mail->envelope()->from->name)->toBe('Southeast Sharkfest');
});

it('renders the customer insufficient mail with formatted amounts and the billing link', function (): void {
    $registration = mailRegistration();
    $data = mailDataFor($registration, ['mc_gross' => '25.00']);
    $html = (new CustomerInsufficientMail($data))->render();

    expect($html)->toContain('$1,484.50')   // required: 1234.50 owed + 250.00 received
        ->and($html)->toContain('$250.00')  // received so far
        ->and($html)->toContain('$1,234.50') // still owed
        ->and($html)->toContain('https://sesf.example.org/#/JaneD/u'.$registration->user_id.'/billing/'.$registration->id)
        ->and($html)->toContain('Pay the difference');
});

it('uses the legacy customer subjects for each customer outcome', function (string $mailClass, string $subject): void {
    $mail = new $mailClass(mailDataFor(mailRegistration()));

    expect($mail->envelope()->subject)->toBe($subject);
})->with([
    [CustomerPaidMail::class, 'Thank you for your payment'],
    [CustomerPendingMail::class, 'Thank you for your payment'],
    [CustomerInsufficientMail::class, 'Thank you for your payment'],
    [CustomerUnderfundMail::class, 'Thank you for your payment'],
    [CustomerDeniedMail::class, 'PayPal payment failed'],
]);

it('renders the customer denied and underfund mails with the legacy wording', function (): void {
    $data = mailDataFor(mailRegistration());

    expect((new CustomerDeniedMail($data))->render())->toContain("A PayPal transaction didn't complete")
        ->and((new CustomerUnderfundMail($data))->render())->toContain('set to')
        ->and((new CustomerUnderfundMail($data))->render())->toContain('Unpaid');
});

it('uses the legacy admin subjects with the registration id in braces', function (): void {
    $registration = mailRegistration();
    $data = mailDataFor($registration);
    $ref = 'ID#: {'.$registration->id.'}';

    expect((new AdminPaidMail($data))->envelope()->subject)->toBe('PayPal payment has been received AND accepted for '.$ref)
        ->and((new AdminPendingMail($data))->envelope()->subject)->toBe('Pending PayPal payment has been received AND accepted for '.$ref)
        ->and((new AdminInsufficientMail($data))->envelope()->subject)->toBe('Insufficient PayPal payment has been received AND accepted for '.$ref)
        ->and((new AdminDeniedMail($data))->envelope()->subject)->toBe('PayPal denied for '.$ref)
        ->and((new AdminUnderfundMail($data))->envelope()->subject)->toBe('PayPal underfund for '.$ref);
});

it('renders the admin paid mail with expected, received and total amounts and the PayPal report', function (): void {
    $html = (new AdminPaidMail(mailDataFor(mailRegistration(), ['mc_gross' => '25.00'])))->render();

    expect($html)->toContain('Expected')
        ->and($html)->toContain('$1,484.50')
        ->and($html)->toContain('$250.00')
        ->and($html)->toContain('$25.00')
        ->and($html)->toContain('PayPal contents')
        ->and($html)->toContain('txn_id = TXN-TEST-1');
});

it('renders the admin underfund and denied mails for the registration', function (): void {
    $registration = mailRegistration();
    $data = mailDataFor($registration);

    expect((new AdminUnderfundMail($data))->render())->toContain('NOT paid')
        ->and((new AdminUnderfundMail($data))->render())->toContain('user_id '.$registration->user_id)
        ->and((new AdminDeniedMail($data))->render())->toContain('PayPal transaction did not arrive');
});

it('takes the reason, the message and the item number into the invalid, fatal and postback mails', function (): void {
    $payload = IpnFixtures::payload(['item_number' => '88']);
    $data = IpnMailData::fromPayload($payload);

    $invalid = new AdminInvalidMail($data, 'signature mismatch');
    $fatal = new AdminFatalMail($data, 'unexpected reply');
    $postback = new AdminPostbackFailedMail($data, 'connection refused');

    expect($invalid->envelope()->subject)->toBe('Invalid PayPal transaction for ID#: {88}')
        ->and($invalid->render())->toContain('signature mismatch')
        ->and($fatal->envelope()->subject)->toBe('Fatal PayPal transaction for ID#: {88}')
        ->and($fatal->render())->toContain('unexpected reply')
        ->and($postback->envelope()->subject)->toBe('IPN Transaction Failure')
        ->and($postback->render())->toContain('connection refused')
        ->and($postback->render())->toContain('item_number');
});

it('renders the weird, invoice and email mismatch mails with their legacy subjects', function (): void {
    $payload = IpnFixtures::payload(['item_number' => '99']);
    $data = IpnMailData::fromPayload($payload);

    expect((new AdminWeirdMail($data))->envelope()->subject)->toBe('Weird PayPal transaction for ID#: {99}')
        ->and((new AdminWeirdMail($data))->render())->toContain("didn't match any case")
        ->and((new AdminInvalidInvoiceMail($data))->envelope()->subject)->toBe('Paypal payment received with an invalid item_number 99')
        ->and((new AdminInvalidInvoiceMail($data))->render())->toContain('PayPal report')
        ->and((new AdminEmailMismatchMail($data))->envelope()->subject)->toBe('PayPal payment received but paypal email was wrong...')
        ->and((new AdminEmailMismatchMail($data))->render())->toContain('PayPal report');
});

it('escapes a hostile payer name and item name from the IPN, in every admin mail that prints the report', function (): void {
    $hostile = '<script>alert(1)</script>';
    $data = IpnMailData::fromPayload(IpnFixtures::payload([
        'first_name' => $hostile,
        'item_name' => '<img src=x onerror=alert(1)>',
        'item_number' => '5',
    ]));

    $html = (new AdminInvalidInvoiceMail($data))->render();

    expect($html)->not->toContain($hostile)
        ->and($html)->not->toContain('<img src=x')
        ->and($html)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->and($html)->toContain('&lt;img src=x onerror=alert(1)&gt;');
});

it('escapes a hostile first name on the customer mail, which takes it from the user record', function (): void {
    $registration = mailRegistration(['first' => '<script>a</script>', 'last' => 'Evil']);

    $html = (new CustomerPaidMail(mailDataFor($registration)))->render();

    expect($html)->not->toContain('<script>a</script>')
        ->and($html)->toContain('&lt;script&gt;a&lt;/script&gt;');
});

it('strips line breaks from the subject so an item number cannot inject headers', function (): void {
    $data = IpnMailData::fromPayload(IpnFixtures::payload(['item_number' => "5\r\nBcc: spy@example.test"]));

    $subject = (new AdminInvalidMail($data, 'x'))->envelope()->subject;

    expect($subject)->not->toContain("\r")
        ->and($subject)->not->toContain("\n");
});

it('sends the admin mails from the registration address', function (): void {
    $mail = new AdminPaidMail(mailDataFor(mailRegistration()));

    expect($mail->envelope()->from->address)->toBe('registration@sesf.test');
});
