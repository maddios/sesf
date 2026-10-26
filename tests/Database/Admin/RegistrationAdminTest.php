<?php

namespace Tests\Database\Admin;

use App\Enums\JudgingType;
use App\Models\CarShow;
use App\Models\ShowRegistration;
use App\Models\User;
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
        $body = $this->updateBody(['donation_cents' => 1000]);

        $as()->getJson(self::BASE)->assertStatus($expect(200));
        $as()->getJson(self::BASE.'/'.$registration->id)->assertStatus($expect(200));
        $as()->putJson(self::BASE.'/'.$registration->id, $body)->assertStatus($expect(200));

        if ($status !== 200) {
            $this->assertSame(0, (int) $registration->refresh()->donation);
        }
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

        $this->actingAs($this->admin())->putJson(self::BASE.'/'.$registration->id, $this->updateBody(['donation_cents' => 500]))
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
        $body = $this->updateBody($change);
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

        $this->actingAs($this->admin())->putJson(self::BASE.'/'.$registration->id, $this->updateBody(['is_cancelled' => true]))
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

        $this->actingAs($this->admin())->putJson(self::BASE.'/'.$registration->id, $this->updateBody(['is_cancelled' => true]))->assertOk();

        $this->assertSame(JudgingType::Clean->value, (int) $registration->registrationCars()->sole()->getRawOriginal('judging_type_id'));
    }

    #[Test]
    public function an_admin_edits_a_cancelled_registration_on_behalf(): void
    {
        $registration = $this->registrationWithCarAndGuest($this->registrationShow(), ['is_cancelled' => true]);

        $this->actingAs($this->admin())->putJson(self::BASE.'/'.$registration->id, $this->updateBody(['donation_cents' => 1000, 'is_cancelled' => true]))
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

        $this->actingAs($this->admin())->putJson(self::BASE.'/'.$registration->id, $this->updateBody(['donation_cents' => 1000]))
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

        $this->actingAs($this->admin())->putJson(self::BASE.'/'.$registration->id, $this->updateBody(['arrival_date' => $day, 'arrival_time' => 'night']))
            ->assertOk()
            ->assertJsonPath('data.arrival_date', $day)
            ->assertJsonPath('data.arrival_time', 'night');
    }

    #[Test]
    public function the_audit_log_holds_the_change_but_no_member_details(): void
    {
        $registration = $this->registrationWithCarAndGuest($this->registrationShow());

        $this->actingAs($this->admin())->putJson(self::BASE.'/'.$registration->id, $this->updateBody(['donation_cents' => 1000]))->assertOk();

        $entry = collect($this->auditEntries())->firstWhere('action', 'registration.update');
        $this->assertSame(['old' => 0, 'new' => 10], $entry['changes']['donation']);
        $this->assertStringNotContainsString('@', $this->auditText());
    }

    #[Test]
    public function a_missing_registration_or_show_is_a_json_404(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->getJson(self::BASE.'/999999')->assertNotFound()->assertJsonPath('message', 'Registration not found.');
        $this->actingAs($admin)->putJson(self::BASE.'/999999', $this->updateBody())->assertNotFound();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function updateBody(array $overrides = []): array
    {
        return array_merge([
            'donation_cents' => 0,
            'arrival_date' => null,
            'arrival_time' => 'morning',
            'is_cancelled' => false,
        ], $overrides);
    }
}
