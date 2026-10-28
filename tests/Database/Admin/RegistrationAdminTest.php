<?php

namespace Tests\Database\Admin;

use App\Enums\JudgingType;
use App\Models\Car;
use App\Models\CarShow;
use App\Models\Judging\Answer;
use App\Models\PayPalPayment;
use App\Models\ShowRegistration;
use App\Models\User;
use App\Models\UserGuest;
use App\Models\Waiver;
use App\Services\Registration\RegistrationPricing;
use App\Services\Waivers\WaiverSignService;
use App\Support\Admin\AuditLog;
use Database\Factories\CarBodyFactory;
use Database\Factories\ShirtTypeFactory;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * admin-api/registrations: list with counts and filters, view, and edits (donation, arrival, cancel)
 * made through the member registration writer, with amounts and audit entries.
 */
class RegistrationAdminTest extends AdminTestCase
{
    use RegistrationFixtures;

    private const BASE = '/admin-api/registrations';

    /**
     * @return array<string, array{string, int}>
     */
    public static function actors(): array
    {
        return [
            'anonymous' => ['anonymous', 401],
            'member' => ['member', 403],
            'judge' => ['judge', 403],
            'admin' => ['admin', 200],
        ];
    }

    #[Test]
    #[DataProvider('actors')]
    public function every_route_is_admin_only(string $who, int $status): void
    {
        $show = $this->registrationShow();
        $registration = $this->registrationWithCarAndGuest($show);
        $user = $this->actor($who);
        $as = fn () => $user === null ? $this : $this->actingAs($user);
        $expect = fn (int $ok) => $status === 200 ? $ok : $status;
        $body = $this->updateBody($registration, ['donation_cents' => 1000]);

        $as()->getJson(self::BASE)->assertStatus($expect(200));
        $as()->getJson(self::BASE.'/'.$registration->id)->assertStatus($expect(200));
        $as()->putJson(self::BASE.'/'.$registration->id, $body)->assertStatus($expect(200));
        $as()->getJson(self::BASE.'/'.$registration->id.'/cars')->assertStatus($expect(200));
        $as()->deleteJson(self::BASE.'/'.$registration->id)->assertStatus($expect(204));

        if ($status !== 200) {
            $this->assertSame(0, (int) $registration->refresh()->donation);
        }
        $this->assertSame($status !== 200, ShowRegistration::query()->whereKey($registration->id)->exists());
    }

    #[Test]
    public function the_list_reports_counts_the_show_name_and_the_member(): void
    {
        $show = $this->registrationShow(['name' => 'Spring Show']);
        $member = User::factory()->create(['first' => 'Ada', 'last' => 'Lovelace']);
        $this->registrationWithCarAndGuest($show, [], $member);

        $this->actingAs($this->admin())->getJson(self::BASE)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.show_name', 'Spring Show')
            ->assertJsonPath('data.0.user_name', 'Ada Lovelace')
            ->assertJsonPath('data.0.cars_count', 1)
            ->assertJsonPath('data.0.guests_count', 1)
            ->assertJsonPath('data.0.shirts_count', 0)
            ->assertJsonPath('data.0.total_cents', 4500)
            ->assertJsonPath('data.0.amount_source', 'computed');
    }

    #[Test]
    public function the_list_filters_by_event_cancellation_and_member_name(): void
    {
        $show = $this->registrationShow();
        $other = $this->registrationShow();
        $kept = $this->registrationWithCarAndGuest($show, [], User::factory()->create(['last' => 'Zephyr']));
        $this->registrationWithCarAndGuest($show, ['is_cancelled' => true]);
        $this->registrationWithCarAndGuest($other);
        $admin = $this->admin();

        $this->actingAs($admin)->getJson(self::BASE.'?filter[show_id]='.$show->id)->assertJsonPath('meta.total', 2);
        $this->actingAs($admin)->getJson(self::BASE.'?filter[show_id]='.$show->id.'&filter[is_cancelled]=0')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $kept->id);
        $this->actingAs($admin)->getJson(self::BASE.'?filter[member]=Zephyr')->assertJsonPath('meta.total', 1);
    }

    #[Test]
    public function unknown_sort_filter_or_operator_is_a_422(): void
    {
        $this->registrationWithCarAndGuest($this->registrationShow());
        $admin = $this->admin();

        $this->actingAs($admin)->getJson(self::BASE.'?sort=id;drop table')->assertStatus(422);
        $this->actingAs($admin)->getJson(self::BASE.'?sort=user_id')->assertStatus(422);
        $this->actingAs($admin)->getJson(self::BASE.'?filter[show_id][drop]=1')->assertStatus(422);
    }

    #[Test]
    public function the_view_shows_stored_amounts_for_an_ended_show(): void
    {
        $show = CarShow::factory()->past()->create(['name' => 'Last Year']);
        $registration = ShowRegistration::factory()->create([
            'show_id' => $show->id,
            'amount_owed' => '12.34',
            'amount_received' => '5.00',
            'discount' => '1.00',
            'donation' => 25,
        ]);

        $this->actingAs($this->admin())->getJson(self::BASE.'/'.$registration->id)
            ->assertOk()
            ->assertJsonPath('data.show_name', 'Last Year')
            ->assertJsonPath('data.amount_source', 'stored')
            ->assertJsonPath('data.owed_cents', 1234)
            ->assertJsonPath('data.received_cents', 500)
            ->assertJsonPath('data.discount_cents', 100)
            ->assertJsonPath('data.total_cents', 1834)
            ->assertJsonPath('data.donation_cents', 2500);
    }

    #[Test]
    public function a_donation_of_five_dollars_is_accepted_and_recomputes_owed_on_an_open_show(): void
    {
        $registration = $this->registrationWithCarAndGuest($this->registrationShow());

        $this->actingAs($this->admin())->putJson(self::BASE.'/'.$registration->id, $this->updateBody($registration, ['donation_cents' => 500]))
            ->assertOk()
            ->assertJsonPath('data.donation_cents', 500)
            ->assertJsonPath('data.owed_cents', 5000);

        $registration->refresh();
        $this->assertSame(5, (int) $registration->donation);
        $this->assertSame('50.00', (string) $registration->amount_owed);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidUpdates(): array
    {
        return [
            'donation not a choice' => [['donation_cents' => 700], 'donation_cents'],
            'donation missing' => [['donation_cents' => null], 'donation_cents'],
            'arrival before the show' => [['arrival_date' => '2000-01-01'], 'arrival_date'],
            'arrival not a date' => [['arrival_date' => '2030-02-31'], 'arrival_date'],
            'arrival time unknown' => [['arrival_time' => 'noon'], 'arrival_time'],
            'is_cancelled not boolean' => [['is_cancelled' => 'maybe'], 'is_cancelled'],
        ];
    }

    /**
     * @param  array<string, mixed>  $change
     */
    #[Test]
    #[DataProvider('invalidUpdates')]
    public function an_invalid_edit_is_a_422_and_changes_nothing(array $change, string $field): void
    {
        $registration = $this->registrationWithCarAndGuest($this->registrationShow());
        $body = $this->updateBody($registration, $change);
        if (array_key_exists('arrival_date', $change)) {
            $body['arrival_date'] = $change['arrival_date'];
        }

        $this->actingAs($this->admin())->putJson(self::BASE.'/'.$registration->id, $body)
            ->assertStatus(422)
            ->assertJsonValidationErrors($field);
        $this->assertSame(0, (int) $registration->refresh()->donation);
    }

    #[Test]
    public function cancelling_keeps_the_cars_and_is_audited(): void
    {
        $registration = $this->registrationWithCarAndGuest($this->registrationShow());

        $this->actingAs($this->admin())->putJson(self::BASE.'/'.$registration->id, $this->updateBody($registration, ['is_cancelled' => true]))
            ->assertOk()
            ->assertJsonPath('data.is_cancelled', true)
            ->assertJsonPath('data.cars_count', 1);

        $registration->refresh();
        $this->assertTrue((bool) $registration->is_cancelled);
        $this->assertSame(1, $registration->cars()->count());

        $entry = collect($this->auditEntries())->firstWhere('action', 'registration.update');
        $this->assertNotNull($entry);
        $this->assertSame(['old' => 0, 'new' => 1], $entry['changes']['is_cancelled']);
    }

    #[Test]
    public function cancelling_keeps_each_cars_judging_type(): void
    {
        $registration = $this->registrationWithCarAndGuest($this->registrationShow());
        $registration->registrationCars()->update(['judging_type_id' => JudgingType::Clean->value]);

        $this->actingAs($this->admin())->putJson(self::BASE.'/'.$registration->id, $this->updateBody($registration, ['is_cancelled' => true]))->assertOk();

        $this->assertSame(JudgingType::Clean->value, (int) $registration->registrationCars()->sole()->getRawOriginal('judging_type_id'));
    }

    #[Test]
    public function an_admin_edits_a_cancelled_registration_on_behalf(): void
    {
        $registration = $this->registrationWithCarAndGuest($this->registrationShow(), ['is_cancelled' => true]);

        $this->actingAs($this->admin())->putJson(self::BASE.'/'.$registration->id, $this->updateBody($registration, ['donation_cents' => 1000, 'is_cancelled' => true]))
            ->assertOk()
            ->assertJsonPath('data.is_cancelled', true);

        $this->assertSame(10, (int) $registration->refresh()->donation);
    }

    #[Test]
    public function an_ended_show_keeps_its_stored_owed_when_the_donation_changes(): void
    {
        $show = CarShow::factory()->past()->create();
        $registration = ShowRegistration::factory()->create([
            'show_id' => $show->id,
            'amount_owed' => '12.34',
            'donation' => 0,
        ]);

        $this->actingAs($this->admin())->putJson(self::BASE.'/'.$registration->id, $this->updateBody($registration, ['donation_cents' => 1000]))
            ->assertOk()
            ->assertJsonPath('data.amount_source', 'stored')
            ->assertJsonPath('data.owed_cents', 1234)
            ->assertJsonPath('data.donation_cents', 1000);

        $registration->refresh();
        $this->assertSame('12.34', (string) $registration->amount_owed);
        $this->assertSame(10, (int) $registration->donation);
    }

    #[Test]
    public function an_arrival_date_inside_the_show_dates_is_saved(): void
    {
        $show = $this->registrationShow();
        $registration = $this->registrationWithCarAndGuest($show);
        $day = $show->date_end?->format('Y-m-d');

        $this->actingAs($this->admin())->putJson(self::BASE.'/'.$registration->id, $this->updateBody($registration, ['arrival_date' => $day, 'arrival_time' => 'night']))
            ->assertOk()
            ->assertJsonPath('data.arrival_date', $day)
            ->assertJsonPath('data.arrival_time', 'night');
    }

    #[Test]
    public function the_audit_log_holds_the_change_but_no_member_details(): void
    {
        $registration = $this->registrationWithCarAndGuest($this->registrationShow());

        $this->actingAs($this->admin())->putJson(self::BASE.'/'.$registration->id, $this->updateBody($registration, ['donation_cents' => 1000]))->assertOk();

        $entry = collect($this->auditEntries())->firstWhere('action', 'registration.update');
        $this->assertSame(['old' => 0, 'new' => 10], $entry['changes']['donation']);
        $this->assertStringNotContainsString('@', $this->auditText());
    }

    #[Test]
    public function a_missing_registration_or_show_is_a_json_404(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->getJson(self::BASE.'/999999')->assertNotFound()->assertJsonPath('message', 'Registration not found.');
        $this->actingAs($admin)->putJson(self::BASE.'/999999', ['invoice_id' => 1])->assertNotFound();
    }

    #[Test]
    public function legacy_rows_with_negative_discount_or_received_never_500(): void
    {
        // The 3 sesf_dev rows of docs/pricing.md row N hold discount = -30; a refund can make received negative.
        $open = $this->registrationShow();
        $openDiscount = $this->registrationWithCarAndGuest($open, ['discount' => '-30.00', 'amount_owed' => '75.00']);
        $openRefund = $this->registrationWithCarAndGuest($open, ['discount' => '-30.00', 'amount_received' => '-10.00']);
        $ended = ShowRegistration::factory()->create([
            'show_id' => CarShow::factory()->past()->create()->id,
            'discount' => '-30.00',
            'amount_owed' => '130.00',
        ]);
        $admin = $this->admin();

        $this->actingAs($admin)->getJson(self::BASE)->assertOk()->assertJsonPath('meta.total', 3);
        $this->actingAs($admin)->getJson(self::BASE.'/'.$openDiscount->id)
            ->assertOk()
            ->assertJsonPath('data.total_cents', 4500)
            ->assertJsonPath('data.discount_cents', -3000)
            ->assertJsonPath('data.owed_cents', 7500);
        $this->actingAs($admin)->getJson(self::BASE.'/'.$openRefund->id)->assertOk()->assertJsonPath('data.owed_cents', 8500);
        $this->actingAs($admin)->getJson(self::BASE.'/'.$ended->id)
            ->assertOk()
            ->assertJsonPath('data.owed_cents', 13000)
            ->assertJsonPath('data.total_cents', 10000);

        $this->actingAs($admin)->putJson(self::BASE.'/'.$openDiscount->id, $this->updateBody($openDiscount, ['donation_cents' => 1000]))
            ->assertOk()
            ->assertJsonPath('data.owed_cents', 8500);
        $this->assertSame('85.00', (string) $openDiscount->refresh()->amount_owed);
        $this->actingAs($admin)->putJson(self::BASE.'/'.$ended->id, $this->updateBody($ended))->assertOk();
    }

    #[Test]
    public function a_gate_payment_on_an_open_show_recomputes_owed_and_marks_paid(): void
    {
        $registration = $this->registrationWithCarAndGuest($this->registrationShow());

        $this->actingAs($this->admin())->putJson(self::BASE.'/'.$registration->id, $this->updateBody($registration, [
            'amount_received_cents' => 4000,
            'amount_received_actual_cents' => 3850,
            'discount_cents' => 500,
            'discount_note' => 'Volunteer',
        ]))
            ->assertOk()
            ->assertJsonPath('data.owed_cents', 0)
            ->assertJsonPath('data.received_cents', 4000)
            ->assertJsonPath('data.received_actual_cents', 3850)
            ->assertJsonPath('data.discount_cents', 500)
            ->assertJsonPath('data.discount_note', 'Volunteer')
            ->assertJsonPath('data.is_paid', true);

        $registration->refresh();
        $this->assertSame('0.00', (string) $registration->amount_owed);
        $this->assertTrue($registration->is_paid);

        $entry = collect($this->auditEntries())->firstWhere('action', 'registration.update');
        $this->assertSame(0.0, (float) $entry['changes']['amount_received']['old']);
        $this->assertSame(40.0, (float) $entry['changes']['amount_received']['new']);
        $this->assertSame(AuditLog::REDACTED, $entry['changes']['discount_note']);
        $this->assertStringNotContainsString('Volunteer', $this->auditText());
    }

    #[Test]
    public function a_payment_after_the_form_was_loaded_is_a_409_and_is_kept(): void
    {
        $registration = $this->registrationWithCarAndGuest($this->registrationShow());
        $stale = $this->updateBody($registration, ['discount_note' => 'Edited']);
        DB::table('show_registration')->where('id', $registration->id)->update(['amount_received' => '150.00', 'is_paid' => true]);

        $this->actingAs($this->admin())->putJson(self::BASE.'/'.$registration->id, $stale)
            ->assertStatus(409)
            ->assertJsonPath('code', 'registration_changed');

        $registration->refresh();
        $this->assertSame('150.00', (string) $registration->amount_received);
        $this->assertNull($registration->discount_note);
    }

    #[Test]
    public function a_discount_changed_after_the_form_was_loaded_is_a_409(): void
    {
        $registration = $this->registrationWithCarAndGuest($this->registrationShow());
        $stale = $this->updateBody($registration);
        DB::table('show_registration')->where('id', $registration->id)->update(['discount' => '5.00']);

        $this->actingAs($this->admin())->putJson(self::BASE.'/'.$registration->id, $stale)->assertStatus(409);
        $this->assertSame('5.00', (string) $registration->refresh()->discount);
    }

    #[Test]
    public function the_expected_amounts_are_required(): void
    {
        $registration = $this->registrationWithCarAndGuest($this->registrationShow());
        $body = $this->updateBody($registration);
        unset($body['expected_amount_received_cents'], $body['expected_discount_cents']);

        $this->actingAs($this->admin())->putJson(self::BASE.'/'.$registration->id, $body)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['expected_amount_received_cents', 'expected_discount_cents']);
    }

    #[Test]
    public function paid_and_owed_overrides_win_over_the_computed_values(): void
    {
        $registration = $this->registrationWithCarAndGuest($this->registrationShow());

        $this->actingAs($this->admin())->putJson(self::BASE.'/'.$registration->id, $this->updateBody($registration, [
            'is_paid' => true,
            'amount_owed_cents' => -250,
        ]))->assertOk()->assertJsonPath('data.is_paid', true)->assertJsonPath('data.stored_owed_cents', -250);

        $registration->refresh();
        $this->assertSame('-2.50', (string) $registration->amount_owed);
        $this->assertTrue($registration->is_paid);
    }

    #[Test]
    public function an_ended_show_moves_the_stored_owed_by_the_change_in_received_and_discount(): void
    {
        $show = CarShow::factory()->past()->create();
        $registration = ShowRegistration::factory()->create([
            'show_id' => $show->id,
            'amount_owed' => '100.00',
            'amount_received' => '20.00',
        ]);

        $this->actingAs($this->admin())->putJson(self::BASE.'/'.$registration->id, $this->updateBody($registration, [
            'amount_received_cents' => 9000,
            'discount_cents' => 1000,
            'is_paid' => true,
        ]))
            ->assertOk()
            ->assertJsonPath('data.amount_source', 'stored')
            ->assertJsonPath('data.owed_cents', 2000)
            ->assertJsonPath('data.is_paid', true);

        $this->assertSame('20.00', (string) $registration->refresh()->amount_owed);
    }

    #[Test]
    public function an_ended_owed_beyond_the_column_is_a_422_and_changes_nothing(): void
    {
        $registration = ShowRegistration::factory()->create([
            'show_id' => CarShow::factory()->past()->create()->id,
            'amount_owed' => '9000.00',
        ]);

        $this->actingAs($this->admin())->putJson(self::BASE.'/'.$registration->id, $this->updateBody($registration, ['amount_received_cents' => -999_999]))
            ->assertStatus(422)
            ->assertJsonPath('errors', ['general.total' => ['The registration total is larger than we can record. Please contact us.']]);
        $this->assertSame('0.00', (string) $registration->refresh()->amount_received);
    }

    #[Test]
    public function a_writer_rejection_is_a_422_with_its_message(): void
    {
        $show = $this->registrationShow(['price_car' => 9000, 'price_adult' => 900]);
        $registration = $this->registrationWithCarAndGuest($show);

        $this->actingAs($this->admin())->putJson(self::BASE.'/'.$registration->id, $this->updateBody($registration, ['donation_cents' => 50000]))
            ->assertStatus(422)
            ->assertJsonPath('message', 'The registration total is larger than we can record. Please contact us.');
        $this->assertSame(0, (int) $registration->refresh()->donation);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidMoney(): array
    {
        return [
            'received above the column' => [['amount_received_cents' => 1_000_000], 'amount_received_cents'],
            'received below the column' => [['amount_received_cents' => -1_000_000], 'amount_received_cents'],
            'received not cents' => [['amount_received_cents' => '12.50'], 'amount_received_cents'],
            'actual negative (unsigned column)' => [['amount_received_actual_cents' => -1], 'amount_received_actual_cents'],
            'discount missing' => [['discount_cents' => null], 'discount_cents'],
            'owed override above the column' => [['amount_owed_cents' => 1_000_000], 'amount_owed_cents'],
            'is_paid not boolean' => [['is_paid' => 'yes'], 'is_paid'],
            'note not latin1' => [['discount_note' => 'Thanks 🙂'], 'discount_note'],
            'invoice zero' => [['invoice_id' => 0], 'invoice_id'],
            'invoice above smallint' => [['invoice_id' => 65536], 'invoice_id'],
            'cars not a list' => [['car_ids' => null], 'car_ids'],
        ];
    }

    /**
     * @param  array<string, mixed>  $change
     */
    #[Test]
    #[DataProvider('invalidMoney')]
    public function invalid_money_or_invoice_is_a_422(array $change, string $field): void
    {
        $registration = $this->registrationWithCarAndGuest($this->registrationShow());

        $this->actingAs($this->admin())->putJson(self::BASE.'/'.$registration->id, $this->updateBody($registration, $change))
            ->assertStatus(422)
            ->assertJsonValidationErrors($field);
        $this->assertSame('0.00', (string) $registration->refresh()->amount_received);
    }

    #[Test]
    public function the_invoice_number_can_change_but_not_to_one_used_in_the_same_event(): void
    {
        $show = $this->registrationShow();
        $registration = $this->registrationWithCarAndGuest($show);
        $other = $this->registrationWithCarAndGuest($show);
        $admin = $this->admin();

        $this->actingAs($admin)->putJson(self::BASE.'/'.$registration->id, $this->updateBody($registration, ['invoice_id' => (int) $other->invoice_id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('invoice_id');

        $this->actingAs($admin)->putJson(self::BASE.'/'.$registration->id, $this->updateBody($registration, ['invoice_id' => 4321]))
            ->assertOk()
            ->assertJsonPath('data.invoice_id', 4321);
    }

    #[Test]
    public function cars_are_added_and_removed_keeping_judging_types_and_repricing(): void
    {
        $show = $this->registrationShow();
        $registration = $this->registrationWithCarAndGuest($show);
        $registration->registrationCars()->update(['judging_type_id' => JudgingType::Clean->value]);
        $kept = (int) $registration->registrationCars()->sole()->car_id;
        $added = Car::factory()->create(['user_id' => $registration->user_id, 'body_id' => CarBodyFactory::E24]);

        $this->actingAs($this->admin())->getJson(self::BASE.'/'.$registration->id.'/cars')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['id' => $kept, 'selected' => true])
            ->assertJsonFragment(['id' => $added->id, 'selected' => false]);

        $this->actingAs($this->admin())->putJson(self::BASE.'/'.$registration->id, $this->updateBody($registration, ['car_ids' => [$kept, $added->id]]))
            ->assertOk()
            ->assertJsonPath('data.cars_count', 2)
            ->assertJsonPath('data.owed_cents', 5000);

        $types = $registration->registrationCars()->get()->mapWithKeys(fn ($row) => [(int) $row->car_id => $row->getRawOriginal('judging_type_id')]);
        $this->assertSame(JudgingType::Clean->value, (int) $types[$kept]);
        $this->assertNull($types[$added->id]);

        $this->actingAs($this->admin())->putJson(self::BASE.'/'.$registration->id, $this->updateBody($registration, ['car_ids' => [$added->id]]))
            ->assertOk()
            ->assertJsonPath('data.car_ids', [$added->id]);

        $entry = collect($this->auditEntries())->where('action', 'registration.update')->last();
        $this->assertSame(['old' => [$kept, $added->id], 'new' => [$added->id]], $entry['changes']['car_ids']);
    }

    #[Test]
    public function a_car_of_another_member_is_a_422(): void
    {
        $registration = $this->registrationWithCarAndGuest($this->registrationShow());
        $stranger = Car::factory()->create(['user_id' => User::factory()->create()->id, 'body_id' => CarBodyFactory::E24]);

        $this->actingAs($this->admin())->putJson(self::BASE.'/'.$registration->id, $this->updateBody($registration, ['car_ids' => [$stranger->id]]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('car_ids');
        $this->assertSame(1, $registration->cars()->count());
    }

    #[Test]
    public function the_list_runs_the_same_number_of_queries_for_one_or_many_rows(): void
    {
        $show = $this->registrationShow();
        $admin = $this->admin();
        $count = function () use ($admin): int {
            $this->app->forgetScopedInstances();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($admin)->getJson(self::BASE.'?per_page=100')->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $this->registrationWithCarAndGuest($show);
        $one = $count();
        for ($i = 0; $i < 6; $i++) {
            $this->registrationWithCarAndGuest($show);
        }

        $this->assertSame($one, $count());
    }

    #[Test]
    public function deleting_a_registration_without_money_or_waivers_removes_it_and_its_rows(): void
    {
        $registration = $this->registrationWithCarAndGuest($this->registrationShow());

        $this->actingAs($this->admin())->deleteJson(self::BASE.'/'.$registration->id)->assertNoContent();

        $this->assertFalse(ShowRegistration::query()->whereKey($registration->id)->exists());
        $this->assertSame(0, DB::table('user_guest')->where('registration_id', $registration->id)->count());
        $this->assertSame(0, DB::table('show_registration_car')->where('registration_id', $registration->id)->count());
        $entry = collect($this->auditEntries())->firstWhere('action', 'registration.delete');
        $this->assertSame(['old' => (int) $registration->invoice_id, 'new' => null], $entry['changes']['invoice_id']);
    }

    /**
     * @return array<string, array{\Closure(ShowRegistration): mixed, string}>
     */
    public static function protectedRegistrations(): array
    {
        return [
            'money received' => [fn (ShowRegistration $r) => $r->forceFill(['amount_received' => '10.00'])->save(), 'amount_received'],
            'a refund' => [fn (ShowRegistration $r) => $r->forceFill(['amount_received' => '-10.00'])->save(), 'amount_received'],
            'a paypal payment' => [fn (ShowRegistration $r) => PayPalPayment::factory()->create(['registration_id' => $r->id]), 'paypal_payment'],
            'a waiver' => [fn (ShowRegistration $r) => Waiver::factory()->create(['show_registration_id' => $r->id]), 'waiver'],
            'a guest waiver' => [fn (ShowRegistration $r) => Waiver::factory()->forGuest(UserGuest::query()->where('registration_id', $r->id)->firstOrFail())->create(), 'guest_waiver'],
            'a judging answer' => [fn (ShowRegistration $r) => Answer::factory()->create(['show_registration_car_id' => $r->registrationCars()->firstOrFail()->id]), 'judging_answers'],
            'a deleted judging answer' => [fn (ShowRegistration $r) => Answer::factory()->deleted()->create(['show_registration_car_id' => $r->registrationCars()->firstOrFail()->id]), 'judging_answers'],
        ];
    }

    #[Test]
    #[DataProvider('protectedRegistrations')]
    public function a_registration_with_money_or_waivers_is_a_409_and_kept(\Closure $arrange, string $reference): void
    {
        $registration = $this->registrationWithCarAndGuest($this->registrationShow());
        $arrange($registration);

        $this->actingAs($this->admin())->deleteJson(self::BASE.'/'.$registration->id)
            ->assertStatus(409)
            ->assertJsonPath('references.'.$reference, 1);
        $this->assertTrue(ShowRegistration::query()->whereKey($registration->id)->exists());
    }

    #[Test]
    public function the_view_links_the_full_on_behalf_editor(): void
    {
        $show = $this->registrationShow(['name' => 'Spring Show!']);
        $registration = $this->registrationWithCarAndGuest($show);

        $this->actingAs($this->admin())->getJson(self::BASE.'/'.$registration->id)
            ->assertOk()
            ->assertJsonPath('data.editor_path', '/SpringShow/e'.$show->id.'/register?user_id='.$registration->user_id);
    }

    #[Test]
    public function a_disabled_members_registration_is_listed_and_must_be_reassigned_to_edit(): void
    {
        $registration = $this->registrationWithCarAndGuest($this->registrationShow());
        $body = $this->updateBody($registration, ['donation_cents' => 1000]);
        User::query()->whereKey($registration->user_id)->firstOrFail()->delete();

        $this->actingAs($this->admin())->getJson(self::BASE.'/'.$registration->id)->assertOk()->assertJsonPath('data.user_name', null);
        $this->actingAs($this->admin())->putJson(self::BASE.'/'.$registration->id, $body)
            ->assertStatus(422)
            ->assertJsonValidationErrors('user_id');
    }

    #[Test]
    public function a_registration_is_reassigned_to_another_member_with_its_guests_and_shirts(): void
    {
        $show = $this->registrationShow();
        $registration = $this->registrationWithCarAndGuest($show);
        $kept = (int) $registration->registrationCars()->sole()->car_id;
        $newMember = User::factory()->create(['first' => 'Grace', 'last' => 'Hopper']);
        $newCar = Car::factory()->create(['user_id' => $newMember->id, 'body_id' => CarBodyFactory::E24]);
        DB::table('shirt')->insert(['user_id' => $registration->user_id, 'show_id' => $show->id, 'registration_id' => $registration->id, 'type_id' => ShirtTypeFactory::MENS_S_TSHIRT]);

        $this->actingAs($this->admin())->getJson(self::BASE.'/'.$registration->id.'/cars?user_id='.$newMember->id)
            ->assertOk()
            ->assertJsonFragment(['id' => $newCar->id, 'selected' => false])
            ->assertJsonFragment(['id' => $kept, 'selected' => true]);

        $this->actingAs($this->admin())->putJson(self::BASE.'/'.$registration->id, $this->updateBody($registration, ['user_id' => $newMember->id, 'car_ids' => [$newCar->id]]))
            ->assertOk()
            ->assertJsonPath('data.user_id', $newMember->id)
            ->assertJsonPath('data.user_name', 'Grace Hopper')
            ->assertJsonPath('data.car_ids', [$newCar->id]);

        $this->assertSame(1, ShowRegistration::query()->where('show_id', $show->id)->count());
        $this->assertSame([$newMember->id], DB::table('user_guest')->where('registration_id', $registration->id)->pluck('user_id')->map(fn ($id) => (int) $id)->unique()->values()->all());
        $this->assertSame($newMember->id, (int) DB::table('shirt')->where('registration_id', $registration->id)->value('user_id'));
        $entry = collect($this->auditEntries())->firstWhere('action', 'registration.update');
        $this->assertSame(['old' => $registration->user_id, 'new' => $newMember->id], $entry['changes']['user_id']);
    }

    #[Test]
    public function a_reassigned_registration_drops_the_previous_members_waiver_and_check_in(): void
    {
        $show = $this->registrationShow();
        $registration = $this->registrationWithCarAndGuest($show);
        $guest = UserGuest::query()->where('registration_id', $registration->id)->firstOrFail();
        $signed = Waiver::factory()->signed()->create(['show_registration_id' => $registration->id]);
        $pending = Waiver::factory()->create(['show_registration_id' => $registration->id]);
        $guestWaiver = Waiver::factory()->signed()->forGuest($guest)->create();
        DB::table('show_registration')->where('id', $registration->id)->update(['waiver_id' => $signed->id, 'has_signed' => true, 'is_checked_in' => true, 'date_checked_in' => now()]);
        DB::table('user_guest')->where('id', $guest->id)->update(['waiver_id' => $guestWaiver->id]);
        $newMember = User::factory()->create();
        $carId = (int) $registration->registrationCars()->sole()->car_id;

        $this->actingAs($this->admin())->putJson(self::BASE.'/'.$registration->id, $this->updateBody($registration, ['user_id' => $newMember->id, 'car_ids' => [$carId]]))
            ->assertOk();

        $registration->refresh();
        $this->assertNull($registration->waiver_id);
        $this->assertFalse((bool) $registration->has_signed);
        $this->assertFalse((bool) $registration->is_checked_in);
        $this->assertNull($registration->date_checked_in);
        $this->assertNull($signed->refresh()->show_registration_id);
        $this->assertNull($pending->refresh()->show_registration_id);
        $this->assertTrue((bool) $signed->has_signed);
        $this->assertSame($guestWaiver->id, (int) $guest->refresh()->waiver_id);

        $waiver = app(WaiverSignService::class)->ensurePending($registration);
        $this->assertNotContains($waiver->id, [$signed->id, $pending->id]);
        $this->assertFalse((bool) $waiver->has_signed);
        $entry = collect($this->auditEntries())->firstWhere('action', 'registration.update');
        $this->assertSame(['old' => $signed->id, 'new' => null], $entry['changes']['waiver_id']);
    }

    #[Test]
    public function reassigning_to_a_member_already_registered_or_disabled_is_a_422(): void
    {
        $show = $this->registrationShow();
        $registration = $this->registrationWithCarAndGuest($show);
        $other = $this->registrationWithCarAndGuest($show);
        $disabled = User::factory()->create();
        $disabled->delete();
        $admin = $this->admin();

        foreach ([$other->user_id, $disabled->id, 999999] as $userId) {
            $this->actingAs($admin)->putJson(self::BASE.'/'.$registration->id, $this->updateBody($registration, ['user_id' => $userId]))
                ->assertStatus(422)
                ->assertJsonValidationErrors('user_id');
        }
        $this->assertSame($registration->user_id, (int) $registration->refresh()->user_id);
    }

    /**
     * The registration's current values (as the edit form sends them), with $overrides applied.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function updateBody(ShowRegistration $registration, array $overrides = []): array
    {
        $registration->refresh();

        return array_merge([
            'donation_cents' => 0,
            'arrival_date' => null,
            'arrival_time' => 'morning',
            'is_cancelled' => false,
            'user_id' => (int) $registration->user_id,
            'invoice_id' => (int) $registration->invoice_id,
            'car_ids' => $registration->cars()->pluck('car.id')->map(fn ($id): int => (int) $id)->all(),
            'amount_received_cents' => RegistrationPricing::signedCents((string) $registration->amount_received),
            'amount_received_actual_cents' => null,
            'discount_cents' => RegistrationPricing::signedCents((string) $registration->discount),
            'discount_note' => $registration->discount_note,
            'is_paid' => null,
            'amount_owed_cents' => null,
            'expected_amount_received_cents' => RegistrationPricing::signedCents((string) $registration->amount_received),
            'expected_discount_cents' => RegistrationPricing::signedCents((string) $registration->discount),
        ], $overrides);
    }
}
