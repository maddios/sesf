<?php

namespace Tests\Database\Admin;

use App\Models\CarShow;
use App\Models\ShowExtra;
use App\Models\ShowExtraRegistration;
use App\Models\ShowRegistration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * admin-api/extra-registrations: edit the quantity, delete, and list with the per-event filter.
 */
class ExtraRegistrationAdminTest extends AdminTestCase
{
    private const BASE = '/admin-api/extra-registrations';

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
        $row = ShowExtraRegistration::factory()->create();
        $user = $this->actor($who);
        $as = fn () => $user === null ? $this : $this->actingAs($user);
        $expect = fn (int $ok) => $status === 200 ? $ok : $status;

        $as()->getJson(self::BASE)->assertStatus($expect(200));
        $as()->getJson(self::BASE.'/'.$row->id)->assertStatus($expect(200));
        $as()->putJson(self::BASE.'/'.$row->id, ['num_extras' => 2])->assertStatus($expect(200));
        $as()->deleteJson(self::BASE.'/'.$row->id)->assertStatus($expect(204));

        if ($status !== 200) {
            $this->assertTrue(ShowExtraRegistration::query()->whereKey($row->id)->exists());
        }
    }

    #[Test]
    public function the_list_shows_who_bought_what_for_which_event(): void
    {
        $row = ShowExtraRegistration::factory()->create(['num_extras' => 2]);

        $this->actingAs($this->admin())->getJson(self::BASE.'/'.$row->id)
            ->assertOk()
            ->assertJsonPath('data.num_extras', 2)
            ->assertJsonPath('data.extra_name', $row->showExtra->name)
            ->assertJsonPath('data.show_id', $row->showExtra->show_id)
            ->assertJsonPath('data.show_name', $row->showExtra->carShow->name)
            ->assertJsonPath('data.registration_id', $row->registration_id);
    }

    #[Test]
    public function the_list_filters_by_event_and_by_extra(): void
    {
        $show = CarShow::factory()->create();
        $extra = ShowExtra::factory()->create(['show_id' => $show->id]);
        $inEvent = ShowExtraRegistration::factory()->create(['show_extra_id' => $extra->id]);
        ShowExtraRegistration::factory()->create();

        $this->actingAs($this->admin())->getJson(self::BASE.'?filter[show_id]='.$show->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $inEvent->id);

        $this->actingAs($this->admin())->getJson(self::BASE.'?filter[show_extra_id]='.$extra->id)
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function hostileQueries(): array
    {
        return [
            'sort injection' => ['sort=num_extras;drop table show_extra_registration'],
            'unknown operator' => ['filter[num_extras][like]=1'],
            'unknown filter column' => ['filter[donation]=1'],
        ];
    }

    #[Test]
    #[DataProvider('hostileQueries')]
    public function hostile_list_queries_are_422(string $query): void
    {
        $this->actingAs($this->admin())->getJson(self::BASE.'?'.$query)->assertStatus(422);
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function invalidQuantities(): array
    {
        return [
            'missing' => [null, 'num_extras'],
            'negative' => [-1, 'num_extras'],
            'not an integer' => ['two', 'num_extras'],
            'above smallint unsigned' => [65536, 'num_extras'],
        ];
    }

    #[Test]
    #[DataProvider('invalidQuantities')]
    public function invalid_quantities_are_a_422_and_change_nothing(mixed $value, string $field): void
    {
        $row = ShowExtraRegistration::factory()->create(['num_extras' => 1]);

        $this->actingAs($this->admin())->putJson(self::BASE.'/'.$row->id, ['num_extras' => $value])
            ->assertStatus(422)
            ->assertJsonValidationErrors([$field]);
        $this->assertSame(1, (int) $row->refresh()->num_extras);
    }

    #[Test]
    public function the_quantity_can_be_set_to_the_column_maximum(): void
    {
        $row = ShowExtraRegistration::factory()->create([
            'show_extra_id' => ShowExtra::factory()->create(['price' => 0]),
            'num_extras' => 1,
        ]);

        $this->actingAs($this->admin())->putJson(self::BASE.'/'.$row->id, ['num_extras' => 65535])
            ->assertOk()
            ->assertJsonPath('data.num_extras', 65535);
    }

    #[Test]
    public function raising_the_quantity_on_a_paid_open_registration_recomputes_owed_and_paid(): void
    {
        $row = ShowExtraRegistration::factory()->create(['num_extras' => 1]);
        $row->registration->forceFill(['amount_received' => '5.00', 'amount_owed' => '0.00', 'is_paid' => true])->save();

        $this->actingAs($this->admin())->putJson(self::BASE.'/'.$row->id, ['num_extras' => 3])
            ->assertOk()
            ->assertJsonPath('data.num_extras', 3);

        $registration = $row->registration->refresh();
        $this->assertSame('10.00', (string) $registration->amount_owed);
        $this->assertFalse($registration->is_paid);
    }

    #[Test]
    public function a_quantity_of_zero_removes_the_purchase_and_reprices(): void
    {
        $row = ShowExtraRegistration::factory()->create(['num_extras' => 2]);
        $row->registration->forceFill(['amount_owed' => '10.00'])->save();

        $this->actingAs($this->admin())->putJson(self::BASE.'/'.$row->id, ['num_extras' => 0])->assertNoContent();

        $this->assertFalse(ShowExtraRegistration::query()->whereKey($row->id)->exists());
        $this->assertSame('0.00', (string) $row->registration->refresh()->amount_owed);
    }

    #[Test]
    public function deleting_a_purchase_reprices_the_registration(): void
    {
        $row = ShowExtraRegistration::factory()->create(['num_extras' => 2]);
        $row->registration->forceFill(['amount_received' => '10.00', 'amount_owed' => '0.00', 'is_paid' => true])->save();

        $this->actingAs($this->admin())->deleteJson(self::BASE.'/'.$row->id)->assertNoContent();

        $registration = $row->registration->refresh();
        $this->assertSame('-10.00', (string) $registration->amount_owed);
        $this->assertTrue($registration->is_paid);
    }

    #[Test]
    public function an_ended_show_keeps_its_stored_amounts(): void
    {
        $extra = ShowExtra::factory()->create(['show_id' => CarShow::factory()->past()]);
        $row = ShowExtraRegistration::factory()->create(['show_extra_id' => $extra->id, 'num_extras' => 1]);
        $row->registration->forceFill(['amount_owed' => '12.34'])->save();

        $this->actingAs($this->admin())->putJson(self::BASE.'/'.$row->id, ['num_extras' => 4])->assertOk();

        $this->assertSame('12.34', (string) $row->registration->refresh()->amount_owed);
    }

    #[Test]
    public function a_total_beyond_the_columns_is_a_422_and_changes_nothing(): void
    {
        $row = ShowExtraRegistration::factory()->create(['num_extras' => 1]);

        $this->actingAs($this->admin())->putJson(self::BASE.'/'.$row->id, ['num_extras' => 65535])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The registration total is larger than we can record. Please contact us.');
        $this->assertSame(1, (int) $row->refresh()->num_extras);
    }

    #[Test]
    public function a_purchase_whose_extra_is_for_another_event_is_a_409(): void
    {
        $row = ShowExtraRegistration::factory()->create(['num_extras' => 1]);
        $row->forceFill(['show_extra_id' => ShowExtra::factory()->create()->id])->save();

        $this->actingAs($this->admin())->putJson(self::BASE.'/'.$row->id, ['num_extras' => 2])->assertStatus(409);
        $this->actingAs($this->admin())->deleteJson(self::BASE.'/'.$row->id)->assertStatus(409);
        $this->assertSame(1, (int) $row->refresh()->num_extras);
    }

    #[Test]
    #[DataProvider('actors')]
    public function creating_a_purchase_is_admin_only(string $who, int $status): void
    {
        $extra = ShowExtra::factory()->create();
        $registration = ShowRegistration::factory()->create(['show_id' => $extra->show_id]);
        $user = $this->actor($who);
        $as = fn () => $user === null ? $this : $this->actingAs($user);

        $as()->postJson(self::BASE, ['registration_id' => $registration->id, 'show_extra_id' => $extra->id, 'num_extras' => 2])
            ->assertStatus($status === 200 ? 201 : $status);
        $this->assertSame($status === 200, ShowExtraRegistration::query()->where('registration_id', $registration->id)->exists());
    }

    #[Test]
    public function an_admin_adds_an_extra_and_the_amount_follows(): void
    {
        $extra = ShowExtra::factory()->create(['price' => 7.5]);
        $registration = ShowRegistration::factory()->create(['show_id' => $extra->show_id]);

        $this->actingAs($this->admin())->postJson(self::BASE, ['registration_id' => $registration->id, 'show_extra_id' => $extra->id, 'num_extras' => 2])
            ->assertCreated()
            ->assertJsonPath('data.num_extras', 2)
            ->assertJsonPath('data.registration_id', $registration->id);

        $this->assertSame('15.00', (string) $registration->refresh()->amount_owed);
        $this->assertNotNull(collect($this->auditEntries())->firstWhere('action', 'extra_registration.create'));
    }

    #[Test]
    public function adding_an_extra_twice_is_a_409_and_one_of_another_event_a_422(): void
    {
        $row = ShowExtraRegistration::factory()->create(['num_extras' => 1]);
        $admin = $this->admin();

        $this->actingAs($admin)->postJson(self::BASE, ['registration_id' => $row->registration_id, 'show_extra_id' => $row->show_extra_id, 'num_extras' => 3])
            ->assertStatus(409);
        $this->actingAs($admin)->postJson(self::BASE, ['registration_id' => $row->registration_id, 'show_extra_id' => ShowExtra::factory()->create()->id, 'num_extras' => 3])
            ->assertStatus(422)
            ->assertJsonValidationErrors('show_extra_id');
        $this->actingAs($admin)->postJson(self::BASE, ['registration_id' => $row->registration_id, 'show_extra_id' => $row->show_extra_id, 'num_extras' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors('num_extras');
        $this->assertSame(1, (int) $row->refresh()->num_extras);
    }

    #[Test]
    public function a_purchase_moves_to_another_extra_of_the_event_and_reprices(): void
    {
        $row = ShowExtraRegistration::factory()->create(['num_extras' => 2]);
        $other = ShowExtra::factory()->create(['show_id' => $row->showExtra->show_id, 'price' => 20]);

        $this->actingAs($this->admin())->putJson(self::BASE.'/'.$row->id, ['num_extras' => 1, 'show_extra_id' => $other->id])
            ->assertOk()
            ->assertJsonPath('data.show_extra_id', $other->id)
            ->assertJsonPath('data.num_extras', 1);

        $this->assertSame([$other->id], ShowExtraRegistration::query()->where('registration_id', $row->registration_id)->pluck('show_extra_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame('20.00', (string) $row->registration->refresh()->amount_owed);
        $entry = collect($this->auditEntries())->firstWhere('action', 'extra_registration.update');
        $this->assertSame(['old' => $row->show_extra_id, 'new' => $other->id], $entry['changes']['show_extra_id']);
    }

    #[Test]
    public function moving_to_an_extra_of_another_event_or_one_already_bought_is_refused(): void
    {
        $row = ShowExtraRegistration::factory()->create(['num_extras' => 2]);
        $bought = ShowExtraRegistration::factory()->create([
            'registration_id' => $row->registration_id,
            'show_extra_id' => ShowExtra::factory()->create(['show_id' => $row->showExtra->show_id]),
        ]);
        $admin = $this->admin();

        $this->actingAs($admin)->putJson(self::BASE.'/'.$row->id, ['num_extras' => 1, 'show_extra_id' => ShowExtra::factory()->create()->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('show_extra_id');
        $this->actingAs($admin)->putJson(self::BASE.'/'.$row->id, ['num_extras' => 1, 'show_extra_id' => $bought->show_extra_id])
            ->assertStatus(409);
        $this->assertSame(2, (int) $row->refresh()->num_extras);
    }

    #[Test]
    public function a_missing_row_is_a_json_404(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->getJson(self::BASE.'/999999')->assertNotFound()->assertJsonPath('message', 'Extra registration not found.');
        $this->actingAs($admin)->putJson(self::BASE.'/999999', ['num_extras' => 1])->assertNotFound();
        $this->actingAs($admin)->deleteJson(self::BASE.'/999999')->assertNotFound();
    }

    #[Test]
    public function edits_and_deletes_are_audited(): void
    {
        $row = ShowExtraRegistration::factory()->create(['num_extras' => 1]);
        $admin = $this->admin();

        $this->actingAs($admin)->putJson(self::BASE.'/'.$row->id, ['num_extras' => 3])->assertOk();
        $this->actingAs($admin)->deleteJson(self::BASE.'/'.$row->id)->assertNoContent();

        $entries = collect($this->auditEntries())->keyBy('action');
        $this->assertSame(['old' => 1, 'new' => 3], $entries['extra_registration.update']['changes']['num_extras']);
        $this->assertSame([$row->id], $entries['extra_registration.delete']['ids']);
        $this->assertFalse(ShowExtraRegistration::query()->whereKey($row->id)->exists());
    }
}
