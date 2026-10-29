<?php

namespace Tests\Database\Api\Registration\Write;

use App\Enums\UserGuestType;
use App\Models\Shirt;
use App\Models\ShowRegistration;
use App\Models\UserGuest;
use App\Services\Registration\RegistrationPricing;
use Database\Factories\CarBodyFactory;
use Database\Factories\ShirtTypeFactory;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * POST event/registration: create and update paths, response shape, pricing persistence.
 */
class RegistrationWriteTest extends WriteTestCase
{
    #[Test]
    public function a_new_registration_is_created_and_the_get_payload_is_returned(): void
    {
        $show = $this->show();
        $this->allowJudging($show, CarBodyFactory::E24, 2);
        $user = $this->member();
        $car = $this->car($user);

        $body = $this->body($show, [], [
            'cars' => [['id' => $car->id, 'judging_type' => 2]],
            'guests' => [['first' => 'Ann', 'last' => "O'Neil", 'email' => 'ann@example.com', 'type' => 1]],
            'shirts' => [['type' => ShirtTypeFactory::MENS_S_POLO]],
            'arrival_time' => 'evening',
            'donation' => 25,
        ]);

        $response = $this->save($user, $show, $body)->assertOk();

        $registration = $this->storedRegistration($show, $user);
        $this->assertNotNull($registration);

        $get = $this->signIn($user)->getJson('/event/registration?show_id='.$show->id)->assertOk();
        $this->assertSame($get->json(), $response->json());

        $response->assertJsonPath('registration.id', $registration->id);
        $response->assertJsonPath('registration.cars.0.judging_type', 2);
        $response->assertJsonPath('registration.guests.0.last', "O'Neil");
        $response->assertJsonPath('registration.shirts.0.type', ShirtTypeFactory::MENS_S_POLO);
        $response->assertJsonPath('registration.arrival_date.data', $show->date_start?->format('Y-m-d'));
        $response->assertJsonPath('registration.arrival_time', 'evening');
        $response->assertJsonPath('registration.donation', 25);
        // car 25 + adult 20 (first car gives no free guest) + polo 55 + donation 25 = 125
        $response->assertJsonPath('registration.payment.total', '125.00');
        $this->assertSame('125.00', $registration->amount_owed);
        $this->assertFalse($registration->is_paid);
        $this->assertGreaterThan(0, $registration->invoice_id);
    }

    #[Test]
    public function the_invoice_number_is_allocated_once_and_stays_on_update(): void
    {
        $show = $this->show();
        $other = $this->member();
        $this->save($other, $show, $this->body($show, [$this->car($other)->id]))->assertOk();

        $user = $this->member();
        $car = $this->car($user);
        $this->save($user, $show, $this->body($show, [$car->id]))->assertOk();
        $first = $this->storedRegistration($show, $user);
        $this->assertNotNull($first);

        $this->save($user, $show, $this->body($show, [$car->id], ['donation' => 5]))->assertOk();
        $this->save($user, $show, $this->body($show, [$car->id], ['donation' => 10]))->assertOk();

        $this->assertSame(1, ShowRegistration::query()->where('show_id', $show->id)->where('user_id', $user->id)->count());
        $after = $this->storedRegistration($show, $user);
        $this->assertNotNull($after);
        $this->assertSame($first->id, $after->id);
        $this->assertSame($first->invoice_id, $after->invoice_id);
        $this->assertSame(2, $after->invoice_id);
        $this->assertSame(10, $after->donation);
    }

    #[Test]
    public function update_edits_guests_and_shirts_and_removes_the_deleted_ones(): void
    {
        $show = $this->show();
        $user = $this->member();
        $car = $this->car($user);

        $this->save($user, $show, $this->body($show, [$car->id], [
            'guests' => [
                ['first' => 'Keep', 'last' => 'Me', 'type' => 1],
                ['first' => 'Drop', 'last' => 'Me', 'type' => 2],
            ],
            'shirts' => [['type' => ShirtTypeFactory::MENS_S_TSHIRT], ['type' => ShirtTypeFactory::MENS_M_TSHIRT]],
        ]))->assertOk();

        $registration = $this->storedRegistration($show, $user);
        $this->assertNotNull($registration);
        $guests = $registration->guests()->orderBy('id')->get();
        $shirts = $registration->shirts()->orderBy('id')->get();

        $response = $this->save($user, $show, $this->body($show, [$car->id], [
            'guests' => [
                ['id' => $guests[0]->id, 'first' => 'Kept', 'last' => 'Me', 'email' => null, 'type' => 2],
                ['id' => $guests[1]->id, 'deleted' => true],
                ['first' => 'New', 'last' => 'Guest', 'type' => 1],
            ],
            'shirts' => [
                ['id' => $shirts[0]->id, 'deleted' => true],
                ['id' => $shirts[1]->id, 'type' => ShirtTypeFactory::MENS_S_POLO],
            ],
        ]))->assertOk();

        $response->assertJsonCount(2, 'registration.guests');
        $response->assertJsonPath('registration.guests.0.first', 'Kept');
        $response->assertJsonPath('registration.guests.0.type', UserGuestType::Child->value);
        $response->assertJsonPath('registration.guests.1.first', 'New');
        $response->assertJsonCount(1, 'registration.shirts');
        $response->assertJsonPath('registration.shirts.0.id', $shirts[1]->id);
        $response->assertJsonPath('registration.shirts.0.type', ShirtTypeFactory::MENS_S_POLO);

        $this->assertNull(UserGuest::query()->find($guests[1]->id));
        $this->assertNull(Shirt::query()->find($shirts[0]->id));
        // Changing the type re-snapshots the price.
        $this->assertSame('55.00', Shirt::query()->findOrFail($shirts[1]->id)->price);
    }

    #[Test]
    public function guests_and_shirts_not_in_the_body_are_kept(): void
    {
        $show = $this->show();
        $user = $this->member();
        $car = $this->car($user);
        $this->save($user, $show, $this->body($show, [$car->id], [
            'guests' => [['first' => 'Ann', 'last' => 'Lee', 'type' => 1]],
            'shirts' => [['type' => ShirtTypeFactory::MENS_S_TSHIRT]],
        ]))->assertOk();

        $this->save($user, $show, $this->body($show, [$car->id]))
            ->assertOk()
            ->assertJsonCount(1, 'registration.guests')
            ->assertJsonCount(1, 'registration.shirts');
    }

    #[Test]
    public function cars_are_synced_with_their_judging_type(): void
    {
        $show = $this->show();
        $this->allowJudging($show, CarBodyFactory::E24, 1);
        $this->allowJudging($show, CarBodyFactory::E24, 3);
        $user = $this->member();
        $a = $this->car($user);
        $b = $this->car($user);

        $this->save($user, $show, $this->body($show, [], ['cars' => [['id' => $a->id, 'judging_type' => 1], ['id' => $b->id, 'judging_type' => null]]]))->assertOk();
        $this->save($user, $show, $this->body($show, [], ['cars' => [['id' => $b->id, 'judging_type' => ['id' => 3]]]]))
            ->assertOk()
            ->assertJsonCount(1, 'registration.cars')
            ->assertJsonPath('registration.cars.0.id', $b->id)
            ->assertJsonPath('registration.cars.0.judging_type', 3);

        $registration = $this->storedRegistration($show, $user);
        $this->assertNotNull($registration);
        $this->assertSame(1, DB::table('show_registration_car')->where('registration_id', $registration->id)->count());
    }

    #[Test]
    public function extras_are_upserted_and_zero_removes_the_row(): void
    {
        $show = $this->show();
        $user = $this->member();
        $car = $this->car($user);
        $dinner = $this->extra($show, 'dinner', '12.50');
        $hat = $this->extra($show, 'hat', '8.00');

        $this->save($user, $show, $this->body($show, [$car->id], ['extras' => [
            $dinner->id => ['show_extra_id' => $dinner->id, 'num_extras' => 2],
            $hat->id => ['show_extra_id' => $hat->id, 'num_extras' => 1],
        ]]))->assertOk();

        $response = $this->save($user, $show, $this->body($show, [$car->id], ['extras' => [
            $dinner->id => ['show_extra_id' => $dinner->id, 'num_extras' => 3],
            $hat->id => ['show_extra_id' => $hat->id, 'num_extras' => 0],
        ]]))->assertOk();

        $response->assertJsonPath('registration.extras.'.$dinner->id.'.num_extras', 3);
        $response->assertJsonMissingPath('registration.extras.'.$hat->id);
        $response->assertJsonPath('registration.payment.extras', '37.50');
        $registration = $this->storedRegistration($show, $user);
        $this->assertNotNull($registration);
        $this->assertSame('62.50', $registration->amount_owed);
    }

    #[Test]
    public function persisted_owed_equals_the_pricing_service_for_the_stored_rows(): void
    {
        $show = $this->show(['price_car' => 30, 'price_adult' => 22.5, 'price_child' => 11]);
        $user = $this->member();
        $cars = [$this->car($user)->id, $this->car($user)->id, $this->car($user)->id];
        $extra = $this->extra($show, 'lunch', '9.99');

        $this->save($user, $show, $this->body($show, $cars, [
            // 3 cars give 2 free guests: the first two guests (in order) are free.
            'guests' => [
                ['first' => 'Kid', 'last' => 'One', 'type' => 2],
                ['first' => 'Adult', 'last' => 'One', 'type' => 1],
                ['first' => 'Adult', 'last' => 'Two', 'type' => 1],
                ['first' => 'Kid', 'last' => 'Two', 'type' => 2],
            ],
            'shirts' => [['type' => ShirtTypeFactory::MENS_S_TSHIRT], ['type' => ShirtTypeFactory::MENS_S_POLO]],
            'extras' => [$extra->id => ['show_extra_id' => $extra->id, 'num_extras' => 3]],
            'donation' => 50,
        ]))->assertOk()
            ->assertJsonPath('registration.payment.cars', '90.00')
            ->assertJsonPath('registration.payment.guests', '33.50')
            ->assertJsonPath('registration.payment.shirts', '90.00')
            ->assertJsonPath('registration.payment.extras', '29.97');

        $registration = $this->storedRegistration($show, $user);
        $this->assertNotNull($registration);
        $totals = (new RegistrationPricing)->totalsFor($registration);
        // 90 + 33.50 + 90 + 29.97 + 50
        $this->assertSame('293.47', $totals['owed']);
        $this->assertSame($totals['owed'], $registration->amount_owed);
    }

    #[Test]
    public function new_shirts_snapshot_the_type_price(): void
    {
        $show = $this->show();
        $user = $this->member();
        $this->save($user, $show, $this->body($show, [$this->car($user)->id], [
            'shirts' => [['type' => ShirtTypeFactory::MENS_S_POLO]],
        ]))->assertOk();

        $shirt = Shirt::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame('55.00', $shirt->price);

        // A later price change does not alter the registration.
        DB::table('shirt_type')->where('id', ShirtTypeFactory::MENS_S_POLO)->update(['price' => 70]);
        $registration = $this->storedRegistration($show, $user);
        $this->assertNotNull($registration);
        $this->assertSame('55.00', (new RegistrationPricing)->totalsFor($registration)['shirts']);
    }

    #[Test]
    public function is_paid_follows_owed_and_received(): void
    {
        $show = $this->show();
        $user = $this->member();
        $car = $this->car($user);
        $registration = $this->registration($show, $user, ['amount_received' => 25, 'is_paid' => false]);
        $this->enterCar($registration, $car);

        // owed 0 and something received: paid
        $this->save($user, $show, $this->body($show, [$car->id]))->assertOk()->assertJsonPath('registration.is_paid', true);
        $this->assertSame('0.00', $registration->refresh()->amount_owed);

        // a guest makes it owed > 0: not paid
        $this->save($user, $show, $this->body($show, [$car->id], ['guests' => [['first' => 'A', 'last' => 'B', 'type' => 1]]]))
            ->assertOk()
            ->assertJsonPath('registration.is_paid', false);
        $this->assertSame('20.00', $registration->refresh()->amount_owed);
    }

    #[Test]
    public function is_paid_is_unchanged_when_nothing_is_owed_or_received(): void
    {
        $show = $this->show(['price_car' => 0]);
        $user = $this->member();
        $car = $this->car($user);
        $registration = $this->registration($show, $user, ['is_paid' => true, 'discount' => 0, 'amount_received' => 0]);
        $this->enterCar($registration, $car);

        $this->save($user, $show, $this->body($show, [$car->id]))->assertOk()->assertJsonPath('registration.is_paid', true);
    }

    #[Test]
    public function client_money_fields_are_ignored(): void
    {
        $show = $this->show();
        $user = $this->member();

        $this->save($user, $show, $this->body($show, [$this->car($user)->id], [
            'amount_owed' => 0, 'amount_received' => 9999, 'discount' => 9999, 'is_paid' => true, 'invoice_id' => 77,
        ]))->assertOk();

        $registration = $this->storedRegistration($show, $user);
        $this->assertNotNull($registration);
        $this->assertSame('25.00', $registration->amount_owed);
        $this->assertSame('0.00', $registration->amount_received);
        $this->assertSame('0.00', $registration->discount);
        $this->assertFalse($registration->is_paid);
        $this->assertNotSame(77, $registration->invoice_id);
    }

    #[Test]
    public function a_members_cancel_flag_is_ignored(): void
    {
        $show = $this->show();
        $user = $this->member();
        $car = $this->car($user);

        // Legacy `cancel` was the cancellation-policy acknowledgement, not a cancel action.
        $this->save($user, $show, $this->body($show, [$car->id], ['cancel' => true]))
            ->assertOk()
            ->assertJsonPath('registration.is_cancelled', false);

        // cancel:true does not relax the car requirement for members either.
        $this->save($user, $show, ['registration' => ['cars' => [], 'cancel' => true]])
            ->assertStatus(400)
            ->assertJsonStructure(['cars' => ['response' => ['need_a_car']]]);
        $this->assertFalse($this->storedRegistration($show, $user)?->is_cancelled);
    }

    #[Test]
    public function an_admin_on_behalf_cancels_and_reinstates_and_the_rows_are_kept(): void
    {
        $show = $this->show();
        $admin = $this->admin();
        $user = $this->member();
        $car = $this->car($user);
        $this->save($user, $show, $this->body($show, [$car->id], ['guests' => [['first' => 'A', 'last' => 'B', 'type' => 1]]]))->assertOk();

        $this->save($admin, $show, ['registration' => ['cars' => [], 'cancel' => true]], $user->id)
            ->assertOk()
            ->assertJsonPath('registration.is_cancelled', true)
            ->assertJsonCount(1, 'registration.cars')
            ->assertJsonCount(1, 'registration.guests');

        $this->save($admin, $show, $this->body($show, [$car->id], ['cancel' => false]), $user->id)
            ->assertOk()
            ->assertJsonPath('registration.is_cancelled', false);
    }

    #[Test]
    public function an_admin_cancel_without_a_registration_still_needs_a_car(): void
    {
        $show = $this->show();
        $user = $this->member();

        $this->save($this->admin(), $show, ['registration' => ['cars' => [], 'cancel' => true]], $user->id)
            ->assertStatus(400)
            ->assertJsonStructure(['cars' => ['response' => ['need_a_car']]]);
        $this->assertNull($this->storedRegistration($show, $user));
    }

    #[Test]
    public function a_member_cannot_edit_or_reinstate_a_cancelled_registration(): void
    {
        $show = $this->show();
        $user = $this->member();
        $car = $this->car($user);
        $registration = $this->registration($show, $user, ['is_cancelled' => true, 'donation' => 0]);
        $this->enterCar($registration, $car);
        $this->extra($show, 'cancelled-key');

        $this->save($user, $show, $this->body($show, [$car->id], ['donation' => 5, 'cancel' => false]))
            ->assertStatus(400)
            ->assertExactJson(['general' => ['response' => ['cancelled' => ['This registration was cancelled; please contact us']], 'status' => 'error']]);
        $this->quote($user, $show, $this->body($show, [$car->id]))->assertStatus(400);
        $this->signIn($user)->postJson(self::EXTRA.'?extra_id=cancelled-key', ['num_extras' => 1])
            ->assertStatus(400)
            ->assertJsonPath('general.status', 'error');

        $registration->refresh();
        $this->assertTrue($registration->is_cancelled);
        $this->assertSame(0, $registration->donation);
        $this->assertSame(0, $registration->extras()->count());
    }

    #[Test]
    public function legacy_object_ids_are_accepted(): void
    {
        $show = $this->show();
        $user = $this->member();

        $this->save($user, $show, $this->body($show, [$this->car($user)->id], [
            'guests' => [['first' => 'Ann', 'last' => 'Lee', 'type' => ['id' => 2, 'name' => 'Child']]],
            'shirts' => [['type' => ['id' => ShirtTypeFactory::MENS_M_TSHIRT]]],
            'arrival_date' => ['data' => $show->date_end?->format('Y-m-d'), 'name' => 'Sunday'],
        ]))->assertOk()
            ->assertJsonPath('registration.guests.0.type', 2)
            ->assertJsonPath('registration.shirts.0.type', ShirtTypeFactory::MENS_M_TSHIRT);
    }

    #[Test]
    public function an_ended_show_keeps_the_stored_amounts_on_admin_edits(): void
    {
        $show = $this->show([
            'date_start' => now()->subMonth()->toDateString(),
            'date_end' => now()->subMonth()->addDay()->toDateString(),
            'date_registration_deactivation' => now()->subMonths(2),
        ]);
        $user = $this->member();
        $car = $this->car($user);
        $registration = $this->registration($show, $user, ['amount_owed' => 12.5, 'amount_received' => 25, 'is_paid' => true]);
        $this->enterCar($registration, $car);

        // Adding a guest would make owed > 0 at today's prices; the stored amounts must stay.
        $this->save($this->admin(), $show, $this->body($show, [$car->id], [
            'guests' => [['first' => 'Late', 'last' => 'Guest', 'type' => 1]],
        ]), $user->id)->assertOk()->assertJsonCount(1, 'registration.guests');

        $registration->refresh();
        $this->assertSame(1, $registration->guests()->count());
        $this->assertSame('12.50', $registration->amount_owed);
        $this->assertTrue($registration->is_paid);
    }
}
