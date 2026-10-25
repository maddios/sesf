<?php

namespace Tests\Database\PayPal;

use App\Models\Car;
use App\Models\CarShow;
use App\Models\PayPalPayment;
use App\Models\ShowRegistration;
use App\Services\PayPal\IpnMailer;
use App\Services\PayPal\StoredAmountOwedBalance;
use Database\Factories\CarBodyFactory;
use Database\Factories\ShowRegistrationCarFactory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\LoadsLegacySchema;
use Tests\Support\PayPal\FakeIpnMailer;
use Tests\TestCase;

/**
 * Shared setup for the IPN tests: a fake mailer, a VERIFIED postback by default and helpers that
 * build a raw IPN body in PayPal's field order.
 */
abstract class IpnTestCase extends TestCase
{
    use LoadsLegacySchema;

    protected const VERIFY_URL = 'https://ipnpb.paypal.test/cgi-bin/webscr';

    protected const SELLER = 'seller@example.test';

    protected FakeIpnMailer $mailer;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'sesf.paypal.email' => self::SELLER,
            'sesf.paypal_email' => self::SELLER,
            'sesf.paypal.verify_url' => self::VERIFY_URL,
            'sesf.paypal.sandbox' => false,
            'sesf.paypal.currency' => 'USD',
        ]);

        $this->mailer = new FakeIpnMailer;
        $this->app->instance(IpnMailer::class, $this->mailer);
    }

    protected function paypalReplies(string $body, int $status = 200): void
    {
        Http::fake(['ipnpb.paypal.test/*' => Http::response($body, $status)]);
    }

    /**
     * A priced registration: an open show whose price_car is the registration's total, and one car
     * with an allowed body entered in it. The total is amount_owed + amount_received, so the bound
     * RegistrationBalance (the pricing adapter) computes the same owed as the stored column.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function registration(array $attributes = []): ShowRegistration
    {
        $attributes += [
            'amount_owed' => '100.00',
            'amount_received' => '0.00',
            'amount_received_actual' => null,
            'is_paid' => false,
        ];
        $totalCents = StoredAmountOwedBalance::cents($attributes['amount_owed']) + StoredAmountOwedBalance::cents($attributes['amount_received']);
        $this->assertGreaterThanOrEqual(0, $totalCents, 'fixture total must not be negative');

        $show = CarShow::factory()->create([
            'price_car' => sprintf('%d.%02d', intdiv($totalCents, 100), $totalCents % 100),
            'date_end' => now()->addMonths(2)->toDateString(),
        ]);
        DB::table('car_body_show')->insert(['show_id' => $show->id, 'body_id' => CarBodyFactory::E24]);

        $registration = ShowRegistration::factory()->create($attributes + ['show_id' => $show->id]);

        if ($totalCents > 0) {
            $car = Car::factory()->create(['user_id' => $registration->user_id, 'body_id' => CarBodyFactory::E24]);
            ShowRegistrationCarFactory::new()->create(['car_id' => $car->id, 'registration_id' => $registration->id, 'judging_type_id' => null]);
        }

        return $registration;
    }

    /**
     * IPN fields in PayPal's usual order. null in $overrides removes a field.
     *
     * @param  array<string, string|null>  $overrides
     * @return array<string, string>
     */
    protected function fields(ShowRegistration|int $registration, array $overrides = []): array
    {
        $fields = [
            'mc_gross' => '100.00',
            'protection_eligibility' => 'Eligible',
            'payer_id' => 'LPLWNMTBWMFAY',
            'payment_date' => '20:12:59 Jan 13, 2026 PST',
            'payment_status' => 'Completed',
            'charset' => 'UTF-8',
            'first_name' => 'Jane',
            'mc_fee' => '3.20',
            'notify_version' => '3.9',
            'payer_status' => 'verified',
            'business' => self::SELLER,
            'quantity' => '1',
            'verify_sign' => 'AtkOfCXbDm2hu0ZELryHFjY-Vb7PAUvS6nMXgysbElEn9v-1XcmSoGtf',
            'payer_email' => 'jane@example.test',
            'txn_id' => '61E67681CH3238416',
            'payment_type' => 'instant',
            'last_name' => 'Doe',
            'receiver_email' => self::SELLER,
            'payment_fee' => '',
            'receiver_id' => 'S8XGHLYDW9T3S',
            'txn_type' => 'web_accept',
            'item_name' => 'SESF registration',
            'mc_currency' => 'USD',
            'item_number' => (string) ($registration instanceof ShowRegistration ? $registration->id : $registration),
            'residence_country' => 'US',
            'handling_amount' => '0.00',
            'transaction_subject' => '',
            'payment_gross' => '100.00',
            'shipping' => '0.00',
        ];

        foreach ($overrides as $key => $value) {
            if ($value === null) {
                unset($fields[$key]);
            } else {
                $fields[$key] = $value;
            }
        }

        return $fields;
    }

    /**
     * @param  array<string, string>  $fields
     */
    protected function body(array $fields): string
    {
        return http_build_query($fields, '', '&', PHP_QUERY_RFC1738);
    }

    /** @return TestResponse<Response> */
    protected function postRaw(string $body): TestResponse
    {
        return $this->call('POST', '/paypal-ipn', [], [], [], [
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
            'REMOTE_ADDR' => '173.0.82.126',
        ], $body);
    }

    /**
     * @param  array<string, string|null>  $overrides
     * @return TestResponse<Response>
     */
    protected function postIpn(ShowRegistration|int $registration, array $overrides = []): TestResponse
    {
        return $this->postRaw($this->body($this->fields($registration, $overrides)));
    }

    /** @param  TestResponse<Response>  $response */
    protected function assertEmptyOk(TestResponse $response): void
    {
        $response->assertStatus(200);
        $this->assertSame('', $response->getContent());
    }

    /**
     * @param  array{owed: string, received: string, actual: ?string, paid: bool}  $expected
     */
    protected function assertMoney(ShowRegistration $registration, array $expected): void
    {
        $fresh = $registration->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame($expected['owed'], $fresh->amount_owed, 'amount_owed');
        $this->assertSame($expected['received'], $fresh->amount_received, 'amount_received');
        $this->assertSame($expected['actual'], $fresh->amount_received_actual, 'amount_received_actual');
        $this->assertSame($expected['paid'], $fresh->is_paid, 'is_paid');
    }

    protected function paymentCount(?string $txnId = null): int
    {
        return PayPalPayment::query()->when($txnId !== null, fn ($q) => $q->where('txn_id', $txnId))->count();
    }

    /** @return array<string, mixed> */
    protected function lastAudit(): array
    {
        $row = PayPalPayment::query()->latest('id')->firstOrFail();

        return (array) json_decode((string) $row->entirepost, true);
    }

    protected function assertPostbacks(int $count): void
    {
        Http::assertSentCount($count);
        if ($count > 0) {
            Http::assertSent(fn (ClientRequest $r): bool => $r->url() === self::VERIFY_URL);
        }
    }
}
