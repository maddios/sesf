<?php

namespace Tests\Database\Admin;

use App\Enums\AgendaType;
use App\Models\CarShow;
use App\Models\CarShowAgenda;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * admin-api/agenda: CRUD, validation and widths, per-event reorder (the legacy order up/down swapped with the
 * nearest row of the whole table), HTML sanitizing, and audit entries.
 */
class AgendaAdminTest extends AdminTestCase
{
    private const BASE = '/admin-api/agenda';

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
            'name' => 'Opening remarks',
            'text' => '<p>Welcome</p>',
            'type_id' => AgendaType::Agenda->value,
            'is_visible' => true,
        ], $overrides);
    }

    #[Test]
    #[DataProvider('actors')]
    public function the_list_is_admin_only(string $who, int $status): void
    {
        CarShowAgenda::factory()->create();
        $user = $this->actor($who);
        $request = $user === null ? $this : $this->actingAs($user);

        $request->getJson(self::BASE)->assertStatus($status);
    }

    #[Test]
    #[DataProvider('actors')]
    public function every_route_is_admin_only(string $who, int $status): void
    {
        $item = CarShowAgenda::factory()->create();
        $show = CarShow::factory()->create();
        $user = $this->actor($who);
        $as = fn () => $user === null ? $this : $this->actingAs($user);
        $expect = fn (int $ok) => $status === 200 ? $ok : $status;

        $as()->getJson(self::BASE.'/'.$item->id)->assertStatus($expect(200));
        $as()->postJson(self::BASE, $this->payload(['show_id' => $show->id]))->assertStatus($expect(201));
        $as()->putJson(self::BASE.'/'.$item->id, $this->payload())->assertStatus($expect(200));
        $as()->postJson(self::BASE.'/reorder', ['show_id' => $item->show_id, 'ids' => [$item->id]])->assertStatus($expect(200));
        $as()->deleteJson(self::BASE.'/'.$item->id)->assertStatus($expect(204));

        if ($status !== 200) {
            $this->assertTrue(CarShowAgenda::query()->whereKey($item->id)->exists());
        }
    }

    #[Test]
    public function an_html_guest_is_redirected_to_the_spa_login(): void
    {
        $this->get(self::BASE)->assertRedirect('/#/login');
    }

    #[Test]
    public function an_admin_creates_an_item_at_the_end_of_its_event(): void
    {
        $show = CarShow::factory()->create();
        CarShowAgenda::factory()->create(['show_id' => $show->id, 'sort_order' => 1]);
        CarShowAgenda::factory()->create(['show_id' => $show->id, 'sort_order' => 2]);
        CarShowAgenda::factory()->create(['sort_order' => 9]);

        $response = $this->actingAs($this->admin())->postJson(self::BASE, $this->payload([
            'show_id' => $show->id,
            'name' => '  Judging  ',
            'type_id' => AgendaType::Judging->value,
        ]));

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Judging')
            ->assertJsonPath('data.type', 'Judging')
            ->assertJsonPath('data.show_id', $show->id)
            ->assertJsonPath('data.sort_order', 3)
            ->assertJsonPath('data.is_visible', true);
    }

    #[Test]
    public function an_item_is_hidden_unless_asked_and_blank_text_is_stored_as_null(): void
    {
        $show = CarShow::factory()->create();

        $response = $this->actingAs($this->admin())->postJson(self::BASE, $this->payload([
            'show_id' => $show->id,
            'text' => "   \n ",
            'is_visible' => false,
        ]));

        $response->assertCreated()->assertJsonPath('data.is_visible', false);
        $this->assertNull(CarShowAgenda::query()->findOrFail($response->json('data.id'))->text);
    }

    #[Test]
    public function the_html_is_cleaned_with_the_public_allow_list_on_write(): void
    {
        $show = CarShow::factory()->create();
        $html = '<p onclick="x()">Hi <b>there</b></p><script>alert(1)</script><a href="javascript:alert(1)">bad</a>';

        $response = $this->actingAs($this->admin())->postJson(self::BASE, $this->payload(['show_id' => $show->id, 'text' => $html]));

        $response->assertCreated();
        $stored = (string) CarShowAgenda::query()->findOrFail($response->json('data.id'))->text;
        $this->assertStringContainsString('<b>there</b>', $stored);
        $this->assertStringNotContainsString('<script', $stored);
        $this->assertStringNotContainsString('onclick', $stored);
        $this->assertStringNotContainsString('javascript:', $stored);
    }

    #[Test]
    public function an_update_keeps_the_event_and_the_position(): void
    {
        $show = CarShow::factory()->create();
        $item = CarShowAgenda::factory()->create(['show_id' => $show->id, 'sort_order' => 4, 'is_visible' => true]);
        $other = CarShow::factory()->create();

        $this->actingAs($this->admin())->putJson(self::BASE.'/'.$item->id, [
            'name' => 'Renamed', 'text' => null, 'type_id' => AgendaType::Judging->value, 'show_id' => $other->id, 'sort_order' => 1,
        ])->assertOk()->assertJsonPath('data.name', 'Renamed');

        $item->refresh();
        $this->assertSame('Renamed', $item->name);
        $this->assertSame($show->id, (int) $item->show_id);
        $this->assertSame(4, (int) $item->sort_order);
        $this->assertTrue((bool) $item->is_visible, 'is_visible is kept when not sent');
    }

    #[Test]
    public function reorder_numbers_one_event_and_leaves_the_others_alone(): void
    {
        $show = CarShow::factory()->create();
        $other = CarShow::factory()->create();
        $first = CarShowAgenda::factory()->create(['show_id' => $show->id, 'sort_order' => 1]);
        $second = CarShowAgenda::factory()->create(['show_id' => $show->id, 'sort_order' => 2]);
        $third = CarShowAgenda::factory()->create(['show_id' => $show->id, 'sort_order' => 3]);
        $elsewhere = CarShowAgenda::factory()->create(['show_id' => $other->id, 'sort_order' => 2]);

        $this->actingAs($this->admin())->postJson(self::BASE.'/reorder', [
            'show_id' => $show->id,
            'ids' => [$third->id, $first->id, $second->id],
        ])->assertOk()->assertJsonPath('data.ids', [$third->id, $first->id, $second->id]);

        $this->assertSame(1, (int) $third->refresh()->sort_order);
        $this->assertSame(2, (int) $first->refresh()->sort_order);
        $this->assertSame(3, (int) $second->refresh()->sort_order);
        $this->assertSame(2, (int) $elsewhere->refresh()->sort_order);
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function badReorders(): array
    {
        return [
            'an item of the event is missing' => ['missing', 422],
            'an item of another event is named' => ['foreign', 422],
            'an id is repeated' => ['duplicate', 422],
            'no ids' => ['empty', 422],
        ];
    }

    #[Test]
    #[DataProvider('badReorders')]
    public function reorder_refuses_a_list_that_is_not_the_events_items(string $case, int $status): void
    {
        $show = CarShow::factory()->create();
        $other = CarShow::factory()->create();
        $first = CarShowAgenda::factory()->create(['show_id' => $show->id, 'sort_order' => 1]);
        $second = CarShowAgenda::factory()->create(['show_id' => $show->id, 'sort_order' => 2]);
        $foreign = CarShowAgenda::factory()->create(['show_id' => $other->id, 'sort_order' => 1]);

        $ids = match ($case) {
            'missing' => [$first->id],
            'foreign' => [$first->id, $foreign->id],
            'duplicate' => [$first->id, $first->id],
            default => [],
        };

        $this->actingAs($this->admin())->postJson(self::BASE.'/reorder', ['show_id' => $show->id, 'ids' => $ids])
            ->assertStatus($status)
            ->assertJsonStructure(['message', 'errors']);

        $this->assertSame(1, (int) $first->refresh()->sort_order);
        $this->assertSame(2, (int) $second->refresh()->sort_order);
    }

    #[Test]
    public function reorder_needs_a_real_event(): void
    {
        $item = CarShowAgenda::factory()->create();

        $this->actingAs($this->admin())->postJson(self::BASE.'/reorder', ['show_id' => 999999, 'ids' => [$item->id]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['show_id']);
    }

    #[Test]
    public function the_list_filters_by_event_and_sorts_by_position(): void
    {
        $show = CarShow::factory()->create();
        $other = CarShow::factory()->create();
        $late = CarShowAgenda::factory()->create(['show_id' => $show->id, 'sort_order' => 5, 'name' => 'Late']);
        $early = CarShowAgenda::factory()->create(['show_id' => $show->id, 'sort_order' => 1, 'name' => 'Early']);
        CarShowAgenda::factory()->create(['show_id' => $other->id, 'sort_order' => 2, 'name' => 'Elsewhere']);

        $response = $this->actingAs($this->admin())->getJson(self::BASE.'?filter[show_id]='.$show->id.'&sort=sort_order&direction=asc');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $early->id)
            ->assertJsonPath('data.1.id', $late->id)
            ->assertJsonPath('meta.total', 2);
    }

    #[Test]
    public function the_list_pages_and_searches(): void
    {
        CarShowAgenda::factory()->count(3)->create(['name' => 'Judging panel']);
        CarShowAgenda::factory()->create(['name' => 'Lunch']);

        $this->actingAs($this->admin())->getJson(self::BASE.'?per_page=2&page=2&search=judging')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function hostileQueries(): array
    {
        return [
            'sort injection' => ['sort=name;drop table show_agenda'],
            'unknown sort column' => ['sort=text'],
            'unknown operator' => ['filter[type_id][like]=1'],
            'unknown filter column' => ['filter[internal]=1'],
            'bad direction' => ['direction=sideways'],
        ];
    }

    #[Test]
    #[DataProvider('hostileQueries')]
    public function hostile_list_queries_are_422(string $query): void
    {
        $this->actingAs($this->admin())->getJson(self::BASE.'?'.$query)->assertStatus(422);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidPayloads(): array
    {
        return [
            'name missing' => [['name' => null], 'name'],
            'name too long (255)' => [['name' => str_repeat('a', 256)], 'name'],
            'name not latin1' => [['name' => "Shark \u{1F988}"], 'name'],
            'text too long (text)' => [['text' => str_repeat('a', 65536)], 'text'],
            'text not latin1' => [['text' => "Fish \u{1F988}"], 'text'],
            'type unknown' => [['type_id' => 99], 'type_id'],
            'type missing' => [['type_id' => null], 'type_id'],
            'is_visible not boolean' => [['is_visible' => 'maybe'], 'is_visible'],
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

        $item = CarShowAgenda::factory()->create(['name' => 'Keep']);
        $this->actingAs($admin)->putJson(self::BASE.'/'.$item->id, $this->payload($overrides))->assertStatus(422)->assertJsonValidationErrors($field);
        $this->assertSame('Keep', $item->refresh()->name);
    }

    #[Test]
    public function the_event_is_required_on_create_and_must_exist(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->postJson(self::BASE, $this->payload())->assertStatus(422)->assertJsonValidationErrors(['show_id']);
        $this->actingAs($admin)->postJson(self::BASE, $this->payload(['show_id' => 999999]))->assertStatus(422)->assertJsonValidationErrors(['show_id']);
    }

    #[Test]
    public function values_at_the_column_widths_are_accepted(): void
    {
        $show = CarShow::factory()->create();

        $this->actingAs($this->admin())->postJson(self::BASE, $this->payload([
            'show_id' => $show->id,
            'name' => str_repeat('n', 255),
            'text' => str_repeat('t', 65535),
        ]))->assertCreated();
    }

    #[Test]
    public function a_missing_item_is_a_json_404(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->getJson(self::BASE.'/999999')->assertNotFound()->assertJsonPath('message', 'Agenda item not found.');
        $this->actingAs($admin)->putJson(self::BASE.'/999999', $this->payload())->assertNotFound();
        $this->actingAs($admin)->deleteJson(self::BASE.'/999999')->assertNotFound();
        $this->actingAs($admin)->getJson(self::BASE.'/abc')->assertNotFound();
    }

    #[Test]
    public function delete_removes_the_item_and_is_audited(): void
    {
        $item = CarShowAgenda::factory()->create();

        $this->actingAs($this->admin())->deleteJson(self::BASE.'/'.$item->id)->assertNoContent();

        $this->assertFalse(CarShowAgenda::query()->whereKey($item->id)->exists());
        $this->assertContains('agenda.delete', array_column($this->auditEntries(), 'action'));
    }

    #[Test]
    public function every_write_is_audited_with_its_changes(): void
    {
        $show = CarShow::factory()->create();
        $admin = $this->admin();

        $created = $this->actingAs($admin)->postJson(self::BASE, $this->payload(['show_id' => $show->id, 'name' => 'First']))->assertCreated();
        $id = (int) $created->json('data.id');
        $this->actingAs($admin)->putJson(self::BASE.'/'.$id, $this->payload(['name' => 'Second']))->assertOk();
        $this->actingAs($admin)->postJson(self::BASE.'/reorder', ['show_id' => $show->id, 'ids' => [$id]])->assertOk();

        $entries = collect($this->auditEntries())->keyBy('action');
        $this->assertSame([$id], $entries['agenda.create']['ids']);
        $this->assertSame(['old' => 'First', 'new' => 'Second'], $entries['agenda.update']['changes']['name']);
        $this->assertSame([$id], $entries['agenda.reorder']['ids']);
        $this->assertSame($show->id, $entries['agenda.reorder']['extra']['show_id']);
    }
}
