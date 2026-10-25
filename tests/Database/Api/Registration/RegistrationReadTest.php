<?php

namespace Tests\Database\Api\Registration;

use App\Enums\UserType;
use App\Models\CarShow;
use App\Models\ShowRegistration;
use Database\Factories\CarBodyFactory;
use Database\Factories\ShirtTypeFactory;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * GET event/registration, event/cars, event/extra: shapes, access, pricing source and query shape.
 */
class RegistrationReadTest extends RegistrationTestCase
{
    private const REGISTRATION = '/event/registration';

    private const CARS = '/event/cars';

    private const EXTRA = '/event/extra';

    #[Test]
    public function anonymous_requests_get_401_json(): void
    {
        $show = $this->show();

        $this->getJson(self::REGISTRATION.'?show_id='.$show->id)->assertStatus(401);
        $this->getJson(self::CARS.'?show_id='.$show->id)->assertStatus(401);
        $this->getJson(self::EXTRA.'?extra_id=abc')->assertStatus(401);
    }

    #[Test]
    public function missing_show_id_is_422_and_unknown_show_is_404(): void
    {
        $user = $this->member();

        $this->signIn($user)->getJson(self::REGISTRATION)->assertStatus(422);
        $this->signIn($user)->getJson(self::REGISTRATION.'?show_id=999999')->assertStatus(404);
    }

    #[Test]
    public function registration_payload_has_the_legacy_keys(): void
    {
        $show = $this->show();
        $user = $this->member();
        $registration = $this->registration($show, $user, ['donation' => 5, 'discount_note' => 'Sponsor']);
        $car = $this->car($user);
        $this->enterCar($registration, $car, 1);
        $this->guest($registration);
        $this->shirt($registration, $user);
        $extra = $this->extra($show, 'key-cars');
        $this->addExtraToRegistration($registration, $extra, 2);
        $this->allowJudging($show, CarBodyFactory::E24);

        $response = $this->signIn($user)->getJson(self::REGISTRATION.'?show_id='.$show->id)->assertOk();

        $response->assertJsonStructure([
            'registration' => [
                'id', 'show_id', 'user_id', 'invoice_id', 'is_paid', 'is_cancelled',
                'arrival_date' => ['data'], 'arrival_time', 'donation', 'discount', 'discount_note',
                'amount_owed', 'amount_received',
                'cars' => [['id', 'judging_type']],
                'guests' => [['id', 'first', 'last', 'email', 'type']],
                'shirts' => [['id', 'type']],
                'extras' => [$extra->id => ['show_extra_id', 'num_extras']],
                'payment' => ['cars', 'guests', 'shirts', 'extras', 'total'],
            ],
            'cars' => [['id', 'name', 'judging_types' => [['id', 'name']]]],
            'carBodies' => [['id', 'name']],
            'guestTypes' => [['id', 'name']],
            'shirtTypes' => [['id', 'name', 'price']],
            'extras' => [['id', 'name', 'description', 'price', 'type_id', 'min_value', 'max_value', 'auth_key']],
        ]);

        $response->assertJsonMissingPath('targetUser');
        $response->assertJsonPath('registration.id', $registration->id);
        $response->assertJsonPath('registration.discount_note', 'Sponsor');
        $response->assertJsonPath('registration.cars.0.judging_type', 1);
        $response->assertJsonPath('cars.0.judging_types.0.id', 1);
        $response->assertJsonMissingPath('registration.password');
    }

    #[Test]
    public function a_member_without_a_registration_gets_the_same_shape_with_an_empty_registration(): void
    {
        $show = $this->show();
        $user = $this->member();
        $this->car($user);

        $response = $this->signIn($user)->getJson(self::REGISTRATION.'?show_id='.$show->id)->assertOk();

        $response->assertJsonPath('registration.id', null);
        $response->assertJsonPath('registration.user_id', $user->id);
        $response->assertJsonPath('registration.cars', []);
        $response->assertJsonPath('registration.guests', []);
        $response->assertJsonPath('registration.shirts', []);
        $response->assertJsonPath('registration.payment.total', '0.00');
        $response->assertJsonCount(1, 'cars');
        $response->assertJsonCount(1, 'carBodies');
    }

    #[Test]
    public function a_non_admin_user_id_is_ignored_and_the_session_user_is_read(): void
    {
        $show = $this->show();
        $me = $this->member();
        $other = $this->member();
        $this->registration($show, $other);

        $this->signIn($me)
            ->getJson(self::REGISTRATION.'?show_id='.$show->id.'&user_id='.$other->id)
            ->assertOk()
            ->assertJsonPath('registration.id', null)
            ->assertJsonPath('registration.user_id', $me->id)
            ->assertJsonMissingPath('targetUser');
    }

    #[Test]
    public function an_admin_can_read_on_behalf_of_another_user(): void
    {
        $show = $this->show();
        $admin = $this->admin();
        $member = $this->member(['first' => 'Mary', 'last' => 'Smith', 'email' => 'mary@example.test']);
        $registration = $this->registration($show, $member, ['donation' => 0]);

        $this->signIn($admin)
            ->getJson(self::REGISTRATION.'?show_id='.$show->id.'&user_id='.$member->id)
            ->assertOk()
            ->assertJsonPath('registration.id', $registration->id)
            ->assertJsonPath('registration.user_id', $member->id)
            ->assertJsonPath('targetUser.first', 'Mary')
            ->assertJsonPath('targetUser.email', 'mary@example.test');
    }

    #[Test]
    public function an_admin_asking_for_an_unknown_user_gets_null_target_user(): void
    {
        $show = $this->show();
        $admin = $this->admin();

        $this->signIn($admin)
            ->getJson(self::REGISTRATION.'?show_id='.$show->id.'&user_id=999999')
            ->assertOk()
            ->assertJsonPath('registration.id', null)
            ->assertJsonPath('targetUser', null);
    }

    #[Test]
    public function an_open_show_returns_computed_totals(): void
    {
        // Car 25 x2; adult 20 and child 10 (the first car's free guest goes to the first guest);
        // shirt 35; donation 5. Total = 50 + 10 + 35 + 5 = 100. Owed = 100 - (20 + 10) = 70.
        $show = $this->show();
        $user = $this->member();
        $registration = $this->registration($show, $user, [
            'donation' => 5,
            'discount' => 10,
            'amount_received' => 20,
        ]);
        $this->enterCar($registration, $this->car($user));
        $this->enterCar($registration, $this->car($user));
        $this->guest($registration, 'adult');
        $this->guest($registration, 'child');
        $this->shirt($registration, $user);

        $this->signIn($user)
            ->getJson(self::REGISTRATION.'?show_id='.$show->id)
            ->assertOk()
            ->assertJsonPath('registration.payment.cars', '50.00')
            ->assertJsonPath('registration.payment.guests', '10.00')
            ->assertJsonPath('registration.payment.shirts', '35.00')
            ->assertJsonPath('registration.payment.total', '100.00')
            ->assertJsonPath('registration.discount', '10.00')
            ->assertJsonPath('registration.amount_received', '20.00');
    }

    #[Test]
    public function an_ended_show_returns_the_stored_amounts_and_does_not_recompute_owed(): void
    {
        // Ended show: owed, received and discount are the stored columns. total = owed + received + discount.
        $show = $this->show(['date_end' => now()->subDay()->toDateString(), 'date_start' => now()->subDays(3)->toDateString()]);
        $user = $this->member();
        $registration = $this->registration($show, $user, [
            'donation' => 5,
            'amount_owed' => 7,
            'amount_received' => 20,
            'discount' => 3,
        ]);
        $this->enterCar($registration, $this->car($user));
        $this->shirt($registration, $user);

        $this->signIn($user)
            ->getJson(self::REGISTRATION.'?show_id='.$show->id)
            ->assertOk()
            ->assertJsonPath('registration.amount_owed', '7.00')
            ->assertJsonPath('registration.payment.total', '30.00');

        // The stored value is still 7.00 (the computed owed would be 25 + 35 + 5 - 23 = 42.00).
        $this->assertSame('7.00', (string) ShowRegistration::query()->findOrFail($registration->id)->amount_owed);
    }

    #[Test]
    public function only_cars_whose_body_is_allowed_for_the_show_are_priced(): void
    {
        $show = $this->show();
        $user = $this->member();
        $registration = $this->registration($show, $user);
        $this->enterCar($registration, $this->car($user, CarBodyFactory::E24));
        $this->enterCar($registration, $this->car($user, CarBodyFactory::E63_COUPE)); // not allowed for this show

        $this->signIn($user)
            ->getJson(self::REGISTRATION.'?show_id='.$show->id)
            ->assertOk()
            ->assertJsonPath('registration.payment.cars', '25.00')
            ->assertJsonCount(2, 'registration.cars');
    }

    #[Test]
    public function a_shirt_uses_its_snapshot_price_and_falls_back_to_the_type_price(): void
    {
        $show = $this->show();
        $user = $this->member();
        $registration = $this->registration($show, $user);
        $this->shirt($registration, $user, ShirtTypeFactory::MENS_M_TSHIRT, '30.00');   // snapshot wins
        $this->shirt($registration, $user, ShirtTypeFactory::MENS_S_TSHIRT, null);      // type price 35.00

        $this->signIn($user)
            ->getJson(self::REGISTRATION.'?show_id='.$show->id)
            ->assertOk()
            ->assertJsonPath('registration.payment.shirts', '65.00');
    }

    #[Test]
    public function the_extra_endpoint_returns_only_the_extra_with_that_auth_key(): void
    {
        $show = $this->show();
        $user = $this->member();
        $this->registration($show, $user);
        $wanted = $this->extra($show, 'key-wanted');
        $this->extra($show, 'key-other');

        $this->signIn($user)
            ->getJson(self::EXTRA.'?extra_id=key-wanted&show_id='.$show->id)
            ->assertOk()
            ->assertJsonCount(1, 'extras')
            ->assertJsonPath('extras.0.id', $wanted->id)
            ->assertJsonPath('extras.0.auth_key', 'key-wanted');
    }

    #[Test]
    public function an_unknown_extra_key_or_a_show_mismatch_is_404_json(): void
    {
        $show = $this->show();
        $other = $this->show();
        $user = $this->member();
        $this->extra($show, 'key-here');

        $this->signIn($user)->getJson(self::EXTRA.'?extra_id=missing')->assertStatus(404)->assertJsonPath('message', 'Not found.');
        $this->signIn($user)->getJson(self::EXTRA.'?extra_id=')->assertStatus(404);
        $this->signIn($user)->getJson(self::EXTRA.'?extra_id=key-here&show_id='.$other->id)->assertStatus(404);
    }

    #[Test]
    public function event_cars_lists_the_viewers_cars_with_their_judging_types(): void
    {
        $show = $this->show();
        $user = $this->member();
        $this->car($user, CarBodyFactory::E24);
        $this->car($user, CarBodyFactory::E63_COUPE);
        $this->allowJudging($show, CarBodyFactory::E24, 1);
        $this->allowJudging($show, CarBodyFactory::E24, 2);

        $this->signIn($user)
            ->getJson(self::CARS.'?show_id='.$show->id)
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.judging_types.0.id', 1)
            ->assertJsonPath('0.judging_types.1.id', 2)
            ->assertJsonPath('1.judging_types', []);
    }

    #[Test]
    public function reads_do_not_write_to_the_database(): void
    {
        $show = $this->show();
        $user = $this->member();
        $registration = $this->registration($show, $user, ['amount_owed' => 3, 'amount_received' => 0]);
        $this->enterCar($registration, $this->car($user));
        $this->extra($show, 'key-read');

        DB::connection()->flushQueryLog();
        DB::connection()->enableQueryLog();

        $this->signIn($user)->getJson(self::REGISTRATION.'?show_id='.$show->id)->assertOk();
        $this->signIn($user)->getJson(self::CARS.'?show_id='.$show->id)->assertOk();
        $this->signIn($user)->getJson(self::EXTRA.'?extra_id=key-read')->assertOk();

        $writes = array_filter(
            DB::connection()->getQueryLog(),
            fn (array $query): bool => preg_match('/^\s*(insert|update|delete|replace|create|alter|drop)\b/i', $query['query']) === 1,
        );
        DB::connection()->disableQueryLog();

        $this->assertSame([], array_values($writes));
        $this->assertSame('3.00', (string) ShowRegistration::query()->findOrFail($registration->id)->amount_owed);
    }

    #[Test]
    public function the_query_count_does_not_grow_with_the_number_of_items(): void
    {
        $user = $this->member();

        $populate = function (CarShow $show, int $items) use ($user): void {
            $registration = $this->registration($show, $user);
            for ($i = 0; $i < $items; $i++) {
                $this->enterCar($registration, $this->car($user));
                $this->guest($registration, $i % 2 === 0 ? 'adult' : 'child');
                $this->shirt($registration, $user);
                $this->addExtraToRegistration($registration, $this->extra($show, 'k-'.$show->id.'-'.$i), 1);
            }
        };

        $small = $this->show();
        $populate($small, 1);
        $large = $this->show();
        $populate($large, 6);

        $count = function (CarShow $show) use ($user): int {
            DB::connection()->flushQueryLog();
            DB::connection()->enableQueryLog();
            $this->signIn($user)->getJson(self::REGISTRATION.'?show_id='.$show->id)->assertOk();
            $queries = count(DB::connection()->getQueryLog());
            DB::connection()->disableQueryLog();

            return $queries;
        };

        $smallCount = $count($small);
        $largeCount = $count($large);

        $this->assertLessThanOrEqual(20, $largeCount);
        $this->assertSame($smallCount, $largeCount);
    }

    #[Test]
    public function a_negative_stored_discount_on_an_open_show_is_422_not_a_500(): void
    {
        $show = $this->show();
        $user = $this->member();
        $this->registration($show, $user, ['discount' => -30]);

        $this->signIn($user)
            ->getJson(self::REGISTRATION.'?show_id='.$show->id)
            ->assertStatus(422);
    }

    #[Test]
    public function the_user_type_admin_check_uses_the_enum(): void
    {
        $this->assertTrue($this->admin()->type_id === UserType::Administrator);
    }
}
