<?php

namespace Tests\Database\Api\Registration\Write;

use App\Enums\UserGuestType;
use App\Services\Registration\ExtraLine;
use App\Services\Registration\PricingCalculator;
use App\Services\Registration\PricingInput;
use App\Services\Registration\RegistrationPricing;
use Database\Factories\ShirtTypeFactory;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * POST event/registration/quote: same validation, nothing written, PricingCalculator totals.
 */
class RegistrationQuoteTest extends WriteTestCase
{
    /** @var list<string> */
    private const TABLES = ['show_registration', 'show_registration_car', 'user_guest', 'shirt', 'show_extra_registration'];

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function snapshot(): array
    {
        $rows = [];
        foreach (self::TABLES as $table) {
            $rows[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
        }

        return $rows;
    }

    #[Test]
    public function quote_writes_nothing_and_matches_the_calculator(): void
    {
        $show = $this->show();
        $user = $this->member();
        $cars = [$this->car($user)->id, $this->car($user)->id];
        $extra = $this->extra($show, 'dinner', '12.50');
        $before = $this->snapshot();

        $response = $this->quote($user, $show, $this->body($show, $cars, [
            'guests' => [
                ['first' => 'Kid', 'last' => 'A', 'type' => 2],
                ['first' => 'Adult', 'last' => 'B', 'type' => 1],
            ],
            'shirts' => [['type' => ShirtTypeFactory::MENS_S_POLO]],
            'extras' => [$extra->id => ['show_extra_id' => $extra->id, 'num_extras' => 2]],
            'donation' => 15,
        ]))->assertOk();

        $this->assertSame($before, $this->snapshot());

        $expected = (new PricingCalculator)->calculate(PricingInput::fromDecimals(
            '25', '20', '10', 2,
            [UserGuestType::Child, UserGuestType::Adult],
            ['55'],
            [ExtraLine::fromDecimal('12.50', 2)],
            15,
        ));

        $response->assertJsonPath('payment.cars', RegistrationPricing::decimal($expected->carsCents));
        $response->assertJsonPath('payment.guests', '20.00'); // the child is free (2nd car), the adult pays
        $response->assertJsonPath('payment.shirts', '55.00');
        $response->assertJsonPath('payment.extras', '25.00');
        $response->assertJsonPath('payment.donation', '15.00');
        $response->assertJsonPath('payment.total', RegistrationPricing::decimal($expected->totalCents));
        $response->assertJsonPath('payment.owed', '165.00');
        $response->assertJsonPath('payment.received', '0.00');
        $response->assertJsonCount(count($expected->lineItems), 'payment.lineItems');
        $response->assertJsonStructure(['payment' => ['lineItems' => [['kind', 'label', 'quantity', 'unit', 'amount', 'free']]]]);
    }

    #[Test]
    public function quote_applies_the_posted_changes_to_the_stored_registration(): void
    {
        $show = $this->show();
        $user = $this->member();
        $car = $this->car($user);
        $registration = $this->registration($show, $user, ['amount_received' => 30, 'discount' => 5]);
        $this->enterCar($registration, $car);
        $this->guest($registration, 'adult');
        $this->shirt($registration, $user, ShirtTypeFactory::MENS_S_TSHIRT, '20.00');
        $guestId = (int) $registration->guests()->value('id');
        $before = $this->snapshot();

        // Keep the 20.00 snapshot shirt, delete the guest, add a child.
        $this->quote($user, $show, $this->body($show, [$car->id], [
            'guests' => [['id' => $guestId, 'deleted' => true], ['first' => 'Kid', 'last' => 'C', 'type' => 2]],
        ]))->assertOk()
            ->assertJsonPath('payment.guests', '10.00')
            ->assertJsonPath('payment.shirts', '20.00')
            ->assertJsonPath('payment.total', '55.00')
            ->assertJsonPath('payment.owed', '20.00');

        $this->assertSame($before, $this->snapshot());
    }

    #[Test]
    public function quote_equals_what_the_save_persists(): void
    {
        $show = $this->show();
        $user = $this->member();
        $body = $this->body($show, [$this->car($user)->id, $this->car($user)->id, $this->car($user)->id], [
            'guests' => [['first' => 'A', 'last' => 'A', 'type' => 1], ['first' => 'B', 'last' => 'B', 'type' => 1], ['first' => 'C', 'last' => 'C', 'type' => 2]],
            'shirts' => [['type' => ShirtTypeFactory::MENS_M_TSHIRT]],
            'donation' => 100,
        ]);

        $quoted = $this->quote($user, $show, $body)->assertOk()->json('payment');
        $this->save($user, $show, $body)->assertOk();

        $registration = $this->storedRegistration($show, $user);
        $this->assertNotNull($registration);
        $this->assertIsArray($quoted);
        $this->assertSame($quoted['owed'], $registration->amount_owed);
        $this->assertSame($quoted['total'], (new RegistrationPricing)->totalsFor($registration)['total']);
    }

    #[Test]
    public function quote_uses_the_same_validation(): void
    {
        $show = $this->show();
        $user = $this->member();

        $this->quote($user, $show, $this->body($show, [$this->car($this->member())->id]))
            ->assertStatus(400)
            ->assertJsonPath('cars.status', 'error');
        $this->quote($user, $show, $this->body($show, [$this->car($user)->id], ['donation' => -10]))
            ->assertStatus(400)
            ->assertJsonPath('donation.status', 'error');
    }
}
