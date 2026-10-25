<?php

namespace Tests\Feature\Mail\Ipn\Support;

use App\Mail\Ipn\IpnMailData;
use App\Models\Car;
use App\Models\CarShow;
use App\Models\ShowRegistration;
use App\Services\PayPal\IpnPayload;
use App\Services\PayPal\RegistrationBalance;
use App\Services\PayPal\StoredAmountOwedBalance;
use Database\Factories\CarBodyFactory;
use Database\Factories\ShowRegistrationCarFactory;
use Illuminate\Support\Facades\DB;

/**
 * Builds the real IpnPayload from raw IPN fields, and the mail data for a registration, the way
 * IpnMailerImpl does.
 */
final class IpnFixtures
{
    /** @var array<string, string> */
    private const DEFAULTS = [
        'txn_id' => 'TXN-TEST-1',
        'txn_type' => 'web_accept',
        'payment_status' => 'Completed',
        'mc_gross' => '25.00',
        'mc_fee' => '1.00',
        'item_number' => '1',
        'receiver_email' => 'registration@sesf.test',
        'payer_email' => 'payer@example.test',
        'first_name' => 'Pat',
        'last_name' => 'Payer',
    ];

    /**
     * @param  array<string, string>  $fields  Raw IPN fields; anything not given gets a default.
     */
    public static function payload(array $fields = []): IpnPayload
    {
        return IpnPayload::fromRawBody(http_build_query(array_merge(self::DEFAULTS, $fields), '', '&'));
    }

    /**
     * A priced registration, built as tests/Database/PayPal/IpnTestCase::registration() builds it:
     * an open show whose price_car is the total (owed plus received), E24 allowed at the show, and
     * one car entered when the total is positive. The bound RegistrationBalance then computes the
     * same owed as the stored amount_owed column.
     *
     * @param  array<string, mixed>  $attributes  Must include user_id.
     */
    public static function priced(array $attributes): ShowRegistration
    {
        $attributes += ['amount_owed' => '100.00', 'amount_received' => '0.00', 'amount_received_actual' => null, 'is_paid' => false];
        $totalCents = StoredAmountOwedBalance::cents((string) $attributes['amount_owed'])
            + StoredAmountOwedBalance::cents((string) $attributes['amount_received']);

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
     * Mail data for the registration, with the balance the mailer would use.
     */
    public static function registrationData(ShowRegistration $registration, IpnPayload $payload): IpnMailData
    {
        return IpnMailData::fromRegistration($registration, $payload, app(RegistrationBalance::class)->owedCents($registration));
    }
}
