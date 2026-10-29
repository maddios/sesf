<?php

namespace Tests\Database\Admin;

use App\Models\CarShow;
use App\Models\CarShowLocation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * admin-api/locations: CRUD, validation and widths, per-event reorder, and audit entries.
 */
class LocationAdminTest extends AdminTestCase
{
    private const BASE = '/admin-api/locations';

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

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Main lot',
            'description' => 'Enter off Main St.',
            'address1' => '12 Main St',
            'address2' => null,
            'city' => 'Raleigh',
            'state' => 'NC',
            'zip' => '27601',
            'is_primary' => true,
        ], $overrides);
    }

    #[Test]
    #[DataProvider('actors')]
    public function every_route_is_admin_only(string $who, int $status): void
    {
        $location = CarShowLocation::factory()->create();
        $show = CarShow::factory()->create();
        $user = $this->actor($who);
        $as = fn () => $user === null ? $this : $this->actingAs($user);
        $expect = fn (int $ok) => $status === 200 ? $ok : $status;

        $as()->getJson(self::BASE)->assertStatus($expect(200));
        $as()->getJson(self::BASE.'/'.$location->id)->assertStatus($expect(200));
        $as()->postJson(self::BASE, $this->payload(['show_id' => $show->id]))->assertStatus($expect(201));
        $as()->putJson(self::BASE.'/'.$location->id, $this->payload())->assertStatus($expect(200));
        $as()->postJson(self::BASE.'/reorder', ['show_id' => $location->show_id, 'ids' => [$location->id]])->assertStatus($expect(200));
        $as()->deleteJson(self::BASE.'/'.$location->id)->assertStatus($expect(204));

        if ($status !== 200) {
            $this->assertTrue(CarShowLocation::query()->whereKey($location->id)->exists());
        }
    }

    #[Test]
    public function an_admin_creates_a_location_at_the_end_of_its_event_with_its_address(): void
    {
        $show = CarShow::factory()->create();
        CarShowLocation::factory()->create(['show_id' => $show->id, 'sort_order' => 1]);
        CarShowLocation::factory()->create(['show_id' => $show->id, 'sort_order' => 2]);
        CarShowLocation::factory()->create(['sort_order' => 7]);

        $this->actingAs($this->admin())->postJson(self::BASE, $this->payload(['show_id' => $show->id, 'name' => '  Hall  ']))
            ->assertCreated()
            ->assertJsonPath('data.name', 'Hall')
            ->assertJsonPath('data.sort_order', 3)
            ->assertJsonPath('data.show_id', $show->id)
            ->assertJsonPath('data.has_coordinates', false);
    }

    #[Test]
    public function reorder_numbers_one_event_and_leaves_the_others_alone(): void
    {
        $show = CarShow::factory()->create();
        $other = CarShow::factory()->create();
        $first = CarShowLocation::factory()->create(['show_id' => $show->id, 'sort_order' => 1]);
        $second = CarShowLocation::factory()->create(['show_id' => $show->id, 'sort_order' => 2]);
        $elsewhere = CarShowLocation::factory()->create(['show_id' => $other->id, 'sort_order' => 2]);

        $this->actingAs($this->admin())->postJson(self::BASE.'/reorder', [
            'show_id' => $show->id,
            'ids' => [$second->id, $first->id],
        ])->assertOk()->assertJsonPath('data.ids', [$second->id, $first->id]);

        $this->assertSame(1, (int) $second->refresh()->sort_order);
        $this->assertSame(2, (int) $first->refresh()->sort_order);
        $this->assertSame(2, (int) $elsewhere->refresh()->sort_order);
    }

    #[Test]
    public function reorder_refuses_a_location_of_another_event(): void
    {
        $show = CarShow::factory()->create();
        $first = CarShowLocation::factory()->create(['show_id' => $show->id, 'sort_order' => 1]);
        $foreign = CarShowLocation::factory()->create(['sort_order' => 2]);

        $this->actingAs($this->admin())->postJson(self::BASE.'/reorder', ['show_id' => $show->id, 'ids' => [$first->id, $foreign->id]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['ids']);

        $this->assertSame(1, (int) $first->refresh()->sort_order);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidPayloads(): array
    {
        return [
            'name missing' => [['name' => null], 'name'],
            'name too long (128)' => [['name' => str_repeat('a', 129)], 'name'],
            'address1 too long (60)' => [['address1' => str_repeat('a', 61)], 'address1'],
            'address2 too long (60)' => [['address2' => str_repeat('a', 61)], 'address2'],
            'city missing' => [['city' => null], 'city'],
            'city too long (60)' => [['city' => str_repeat('a', 61)], 'city'],
            'state too long (10)' => [['state' => str_repeat('a', 11)], 'state'],
            'zip too long (10)' => [['zip' => str_repeat('1', 11)], 'zip'],
            'description too long (text)' => [['description' => str_repeat('a', 65536)], 'description'],
            'address not latin1' => [['address1' => "Lot \u{1F3C1}"], 'address1'],
            'is_primary not boolean' => [['is_primary' => 'maybe'], 'is_primary'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[Test]
    #[DataProvider('invalidPayloads')]
    public function invalid_input_is_a_standard_422_on_the_field(array $overrides, string $field): void
    {
        $admin = $this->admin();
        $show = CarShow::factory()->create();
        $this->actingAs($admin)->postJson(self::BASE, $this->payload(['show_id' => $show->id] + $overrides))
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => [$field]]);

        $location = CarShowLocation::factory()->create(['name' => 'Keep']);
        $this->actingAs($admin)->putJson(self::BASE.'/'.$location->id, $this->payload($overrides))->assertStatus(422)->assertJsonValidationErrors($field);
        $this->assertSame('Keep', $location->refresh()->name);
    }

    #[Test]
    public function values_at_the_column_widths_are_accepted(): void
    {
        $show = CarShow::factory()->create();

        $this->actingAs($this->admin())->postJson(self::BASE, $this->payload([
            'show_id' => $show->id,
            'name' => str_repeat('n', 128),
            'address1' => str_repeat('a', 60),
            'address2' => str_repeat('b', 60),
            'city' => str_repeat('c', 60),
            'state' => str_repeat('s', 10),
            'zip' => str_repeat('1', 10),
        ]))->assertCreated();
    }

    #[Test]
    public function the_list_filters_by_event_and_sorts_by_position(): void
    {
        $show = CarShow::factory()->create();
        $late = CarShowLocation::factory()->create(['show_id' => $show->id, 'sort_order' => 3]);
        $early = CarShowLocation::factory()->create(['show_id' => $show->id, 'sort_order' => 1]);
        CarShowLocation::factory()->create(['sort_order' => 1]);

        $this->actingAs($this->admin())->getJson(self::BASE.'?filter[show_id]='.$show->id.'&sort=sort_order&direction=asc')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $early->id)
            ->assertJsonPath('data.1.id', $late->id);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function hostileQueries(): array
    {
        return [
            'sort injection' => ['sort=name;drop table show_location'],
            'unknown operator' => ['filter[is_primary][like]=1'],
            'unknown filter column' => ['filter[zip]=1'],
        ];
    }

    #[Test]
    #[DataProvider('hostileQueries')]
    public function hostile_list_queries_are_422(string $query): void
    {
        $this->actingAs($this->admin())->getJson(self::BASE.'?'.$query)->assertStatus(422);
    }

    #[Test]
    public function a_missing_location_is_a_json_404(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->getJson(self::BASE.'/999999')->assertNotFound()->assertJsonPath('message', 'Location not found.');
        $this->actingAs($admin)->putJson(self::BASE.'/999999', $this->payload())->assertNotFound();
        $this->actingAs($admin)->deleteJson(self::BASE.'/999999')->assertNotFound();
    }

    #[Test]
    public function writes_are_audited(): void
    {
        $show = CarShow::factory()->create();
        $admin = $this->admin();
        $id = (int) $this->actingAs($admin)->postJson(self::BASE, $this->payload(['show_id' => $show->id]))->assertCreated()->json('data.id');
        $this->actingAs($admin)->putJson(self::BASE.'/'.$id, $this->payload(['city' => 'Durham']))->assertOk();
        $this->actingAs($admin)->deleteJson(self::BASE.'/'.$id)->assertNoContent();

        $actions = array_column($this->auditEntries(), 'action');
        $this->assertContains('location.create', $actions);
        $this->assertContains('location.delete', $actions);
        $this->assertContains('location.update', $actions);
    }
}
