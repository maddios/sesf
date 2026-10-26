<?php

namespace Tests\Database\Admin;

use App\Models\CarShow;
use App\Models\ShowExtra;
use App\Models\ShowExtraRegistration;
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
        $row = ShowExtraRegistration::factory()->create(['num_extras' => 1]);

        $this->actingAs($this->admin())->putJson(self::BASE.'/'.$row->id, ['num_extras' => 65535])
            ->assertOk()
            ->assertJsonPath('data.num_extras', 65535);
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
