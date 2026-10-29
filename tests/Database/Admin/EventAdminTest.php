<?php

namespace Tests\Database\Admin;

use App\Models\CarShow;
use App\Models\ShowRegistration;
use App\Models\ShowSponsor;
use Database\Factories\CarBodyFactory;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * admin-api/events (car_show): CRUD, activation, validation, list queries, safe deletes, clone-from setup and audit.
 */
class EventAdminTest extends AdminTestCase
{
    private const BASE = '/admin-api/events';

    /** A previous event that has car bodies, so it can be cloned from. */
    private CarShow $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->source = $this->sourceEvent();
    }

    private function sourceEvent(): CarShow
    {
        $show = CarShow::factory()->create(['name' => 'Previous Show', 'short_name' => 'prev-'.uniqid()]);
        DB::connection('testing_mariadb')->table('car_body_show')->insert(['body_id' => CarBodyFactory::E24, 'show_id' => $show->id]);

        return $show;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Spring Show',
            'short_name' => 'spring-'.uniqid(),
            'clone_from_show_id' => $this->source->id,
        ], $overrides);
    }

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
        $event = CarShow::factory()->create(['short_name' => 'target']);
        $user = $this->actor($who);
        $as = fn () => $user === null ? $this : $this->actingAs($user);
        $expect = fn (int $ok) => $status === 200 ? $ok : $status;

        $as()->getJson(self::BASE)->assertStatus($expect(200));
        $as()->getJson(self::BASE.'/clone-sources')->assertStatus($expect(200));
        $as()->getJson(self::BASE.'/'.$event->id)->assertStatus($expect(200));
        $as()->putJson(self::BASE.'/'.$event->id, ['name' => 'Renamed', 'short_name' => 'target'])->assertStatus($expect(200));
        $as()->postJson(self::BASE.'/'.$event->id.'/activate')->assertStatus($expect(200));
        $as()->postJson(self::BASE.'/'.$event->id.'/deactivate')->assertStatus($expect(200));
        $as()->postJson(self::BASE, $this->validPayload())->assertStatus($expect(201));
        $as()->deleteJson(self::BASE.'/'.$event->id)->assertStatus($expect(204));

        if ($status !== 200) {
            $this->assertNotNull(CarShow::query()->find($event->id));
            $this->assertSame('target', $event->refresh()->short_name);
        }
    }

    #[Test]
    public function an_html_guest_is_redirected_to_the_spa_login(): void
    {
        $this->get(self::BASE)->assertRedirect('/#/login');
    }

    #[Test]
    public function an_admin_creates_an_inactive_event_with_every_field(): void
    {
        $response = $this->actingAs($this->admin())->postJson(self::BASE, $this->validPayload([
            'description' => ' Long text ',
            'short_description' => 'Short',
            'schedule' => 'Noon',
            'is_schedule_pdf' => true,
            'price_car' => '12.5',
            'price_adult' => 20,
            'price_child' => null,
            'date_start' => '2027-05-01',
            'date_end' => '2027-05-02',
            'date_active' => '2027-03-01 09:00:00',
            'date_registration_deactivation' => '2027-04-20 17:00:00',
        ]));

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Spring Show')
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.price_car', '12.50')
            ->assertJsonPath('data.price_child', null)
            ->assertJsonPath('data.date_start', '2027-05-01')
            ->assertJsonPath('data.date_active', '2027-03-01 09:00:00')
            ->assertJsonPath('data.description', 'Long text');

        $event = CarShow::query()->findOrFail($response->json('data.id'));
        $this->assertFalse($event->is_active);
        $this->assertTrue($event->is_schedule_pdf);
        $this->assertSame('Long text', $event->description);
    }

    #[Test]
    public function a_later_event_must_name_a_clone_source(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->postJson(self::BASE, ['name' => 'No source', 'short_name' => 'nosrc'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('clone_from_show_id');

        $this->assertNull(CarShow::query()->where('short_name', 'nosrc')->first());
    }

    #[Test]
    public function creating_from_a_source_copies_its_car_bodies_in_one_transaction(): void
    {
        $response = $this->actingAs($this->admin())->postJson(self::BASE, $this->validPayload(['short_name' => 'copy-me']));

        $response->assertCreated();
        $newId = (int) $response->json('data.id');
        $this->assertSame(
            [CarBodyFactory::E24],
            DB::connection('testing_mariadb')->table('car_body_show')->where('show_id', $newId)->pluck('body_id')->map(fn ($id) => (int) $id)->all(),
        );
    }

    #[Test]
    public function a_source_without_car_bodies_is_a_422_and_no_event_is_created(): void
    {
        $empty = CarShow::factory()->create(['short_name' => 'empty-src']);

        $this->actingAs($this->admin())->postJson(self::BASE, $this->validPayload(['clone_from_show_id' => $empty->id, 'short_name' => 'ghost']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('clone_from_show_id');

        $this->assertNull(CarShow::query()->where('short_name', 'ghost')->first());
    }

    #[Test]
    public function a_clone_source_that_does_not_exist_is_a_422(): void
    {
        $this->actingAs($this->admin())->postJson(self::BASE, $this->validPayload(['clone_from_show_id' => 999999]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('clone_from_show_id');
    }

    #[Test]
    public function clone_sources_list_newest_first_with_id_and_name(): void
    {
        $newer = CarShow::factory()->create(['name' => 'Newest', 'short_name' => 'newest', 'date_start' => '2030-01-01']);

        $response = $this->actingAs($this->admin())->getJson(self::BASE.'/clone-sources')->assertOk();

        $this->assertSame($newer->id, $response->json('data.0.id'));
        $this->assertSame(['id', 'label'], array_keys($response->json('data.0')));
    }

    #[Test]
    public function an_update_changes_fields_and_never_touches_activation_or_money_it_is_not_given(): void
    {
        $event = CarShow::factory()->create(['short_name' => 'upd', 'is_active' => true, 'price_car' => 25.00]);

        $this->actingAs($this->admin())->putJson(self::BASE.'/'.$event->id, [
            'name' => 'Updated', 'short_name' => 'upd', 'is_active' => false, 'id' => 9999,
        ])->assertOk()->assertJsonPath('data.name', 'Updated');

        $event->refresh();
        $this->assertSame('Updated', $event->name);
        $this->assertTrue($event->is_active);
        $this->assertNull(CarShow::query()->find(9999));
    }

    #[Test]
    public function activate_and_deactivate_flip_is_active(): void
    {
        $event = CarShow::factory()->create(['is_active' => false]);
        $admin = $this->admin();

        $this->actingAs($admin)->postJson(self::BASE.'/'.$event->id.'/activate')->assertOk()->assertJsonPath('data.is_active', true);
        $this->assertTrue($event->refresh()->is_active);
        $this->actingAs($admin)->postJson(self::BASE.'/'.$event->id.'/deactivate')->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertFalse($event->refresh()->is_active);
    }

    #[Test]
    public function the_waiver_switch_is_edited_and_blank_turns_it_off(): void
    {
        $event = CarShow::factory()->create(['short_name' => 'waivers']);
        $admin = $this->admin();

        $this->actingAs($admin)->putJson(self::BASE.'/'.$event->id, ['name' => 'W', 'short_name' => 'waivers', 'waiver_id' => ' internal '])
            ->assertOk()
            ->assertJsonPath('data.waiver_id', 'internal');
        $this->actingAs($admin)->putJson(self::BASE.'/'.$event->id, ['name' => 'W', 'short_name' => 'waivers', 'waiver_id' => ''])
            ->assertOk()
            ->assertJsonPath('data.waiver_id', null);
    }

    #[Test]
    public function a_clone_copies_the_waiver_switch_unless_one_is_given(): void
    {
        $this->source->forceFill(['waiver_id' => 'tpl-1'])->save();
        $admin = $this->admin();

        $copied = $this->actingAs($admin)->postJson(self::BASE, $this->validPayload())->assertCreated();
        $this->assertSame('tpl-1', $copied->json('data.waiver_id'));

        $kept = $this->actingAs($admin)->postJson(self::BASE, $this->validPayload(['waiver_id' => 'own']))->assertCreated();
        $this->assertSame('own', $kept->json('data.waiver_id'));
    }

    #[Test]
    public function a_missing_event_is_a_json_404(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->getJson(self::BASE.'/999999')->assertNotFound()->assertJsonPath('message', 'Event not found.');
        $this->actingAs($admin)->putJson(self::BASE.'/999999', ['name' => 'x', 'short_name' => 'x'])->assertNotFound();
        $this->actingAs($admin)->postJson(self::BASE.'/999999/activate')->assertNotFound();
        $this->actingAs($admin)->deleteJson(self::BASE.'/999999')->assertNotFound();
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidPayloads(): array
    {
        return [
            'name missing' => [['name' => ''], 'name'],
            'name too long (60)' => [['name' => str_repeat('a', 61)], 'name'],
            'name not latin1' => [['name' => "Show \u{1F988}"], 'name'],
            'short_name too long (20)' => [['short_name' => str_repeat('a', 21)], 'short_name'],
            'short_name taken' => [['short_name' => 'taken'], 'short_name'],
            'description too long (1000)' => [['description' => str_repeat('a', 1001)], 'description'],
            'short_description too long (1024)' => [['short_description' => str_repeat('a', 1025)], 'short_description'],
            'price above float(6,2)' => [['price_car' => '10000'], 'price_car'],
            'price negative' => [['price_adult' => '-1'], 'price_adult'],
            'price not numeric' => [['price_child' => 'ten'], 'price_child'],
            'date_start not a date' => [['date_start' => '2027-13-01'], 'date_start'],
            'date_active without time' => [['date_active' => '2027-03-01'], 'date_active'],
            'deactivation not a datetime' => [['date_registration_deactivation' => 'soon'], 'date_registration_deactivation'],
            'is_schedule_pdf not boolean' => [['is_schedule_pdf' => 'maybe'], 'is_schedule_pdf'],
            'waiver_id too long (32)' => [['waiver_id' => str_repeat('a', 33)], 'waiver_id'],
            'waiver_id not latin1' => [['waiver_id' => "w\u{1F988}"], 'waiver_id'],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[Test]
    #[DataProvider('invalidPayloads')]
    public function invalid_input_is_a_standard_422_on_the_field(array $payload, string $field): void
    {
        $admin = $this->admin();
        CarShow::factory()->create(['short_name' => 'taken']);
        $this->actingAs($admin)->postJson(self::BASE, $this->validPayload($payload))
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => [$field]]);

        $event = CarShow::factory()->create(['name' => 'Keep', 'short_name' => 'keep']);
        $this->actingAs($admin)->putJson(self::BASE.'/'.$event->id, array_merge(['name' => 'Keep', 'short_name' => 'keep'], $payload))
            ->assertStatus(422)
            ->assertJsonValidationErrors($field);
        $this->assertSame('Keep', $event->refresh()->name);
    }

    #[Test]
    public function values_at_the_column_widths_are_accepted(): void
    {
        $this->actingAs($this->admin())->postJson(self::BASE, $this->validPayload([
            'name' => str_repeat('n', 60),
            'short_name' => str_repeat('s', 20),
            'description' => str_repeat('d', 1000),
            'short_description' => str_repeat('x', 1024),
            'price_car' => '9999.99',
        ]))->assertCreated();
    }

    #[Test]
    public function rich_text_is_stored_verbatim_and_its_length_counts_the_markup(): void
    {
        $admin = $this->admin();
        $event = CarShow::factory()->create(['name' => 'Keep', 'short_name' => 'keep', 'description' => 'Old']);
        $description = '<p>'.str_repeat('d', 993).'</p>';
        $schedule = '<center><big>Legacy</big></center><table border="1"><tr><td valign="top">Fri</td></tr></table><iframe src="https://v.example/1"></iframe>';
        $short = '<p><span style="color: #b91c1c; font-family: Georgia, serif">'.str_repeat('s', 950).'</span></p>';

        $this->actingAs($admin)->putJson(self::BASE.'/'.$event->id, ['name' => 'Keep', 'short_name' => 'keep', 'description' => $description, 'schedule' => $schedule, 'short_description' => $short])
            ->assertOk();
        $event->refresh();
        $this->assertSame(1000, mb_strlen($description));
        $this->assertSame($description, $event->description);
        $this->assertSame($schedule, $event->schedule);
        $this->assertSame($short, $event->getRawOriginal('short_description'));

        $this->actingAs($admin)->putJson(self::BASE.'/'.$event->id, ['name' => 'Keep', 'short_name' => 'keep', 'description' => '<p>'.str_repeat('d', 994).'</p>'])
            ->assertStatus(422)
            ->assertJsonPath('errors.description.0', 'The description is too long: 1,001 of 1,000 characters, counting its formatting (HTML). Remove some text or formatting.');
        $this->actingAs($admin)->putJson(self::BASE.'/'.$event->id, ['name' => 'Keep', 'short_name' => 'keep', 'short_description' => '<p>'.str_repeat('s', 1019).'</p>'])
            ->assertStatus(422)
            ->assertJsonPath('errors.short_description.0', 'The short description is too long: 1,026 of 1,024 characters, counting its formatting (HTML). Remove some text or formatting.');
        $this->actingAs($admin)->putJson(self::BASE.'/'.$event->id, ['name' => 'Keep', 'short_name' => 'keep', 'schedule' => str_repeat('x', 65536)])
            ->assertStatus(422)
            ->assertJsonPath('errors.schedule.0', 'The schedule is too long: 65,536 of 65,535 characters, counting its formatting (HTML). Remove some text or formatting.');
        $this->assertSame($description, $event->refresh()->description);
    }

    #[Test]
    public function the_list_is_paginated_with_meta(): void
    {
        CarShow::factory()->count(7)->create();
        $total = CarShow::query()->count();

        $this->actingAs($this->admin())->getJson(self::BASE.'?per_page=3&page=3')
            ->assertOk()
            ->assertJsonPath('meta', ['page' => 3, 'per_page' => 3, 'total' => $total, 'last_page' => (int) ceil($total / 3)])
            ->assertJsonCount($total - 6, 'data');
    }

    #[Test]
    public function the_list_sorts_searches_and_filters(): void
    {
        CarShow::factory()->create(['name' => 'Bravo Show', 'short_name' => 'bravo', 'is_active' => true]);
        CarShow::factory()->create(['name' => 'Alpha Show', 'short_name' => 'alpha', 'is_active' => false]);
        $admin = $this->admin();

        $asc = array_column($this->actingAs($admin)->getJson(self::BASE.'?sort=name&direction=asc')->json('data'), 'name');
        $desc = array_column($this->actingAs($admin)->getJson(self::BASE.'?sort=name&direction=desc')->json('data'), 'name');
        $this->assertSame(collect($asc)->sort()->values()->all(), $asc);
        $this->assertSame(collect($desc)->sortDesc()->values()->all(), $desc);

        $this->actingAs($admin)->getJson(self::BASE.'?search=bravo')->assertJsonCount(1, 'data')->assertJsonPath('data.0.short_name', 'bravo');

        $active = $this->actingAs($admin)->getJson(self::BASE.'?filter[is_active]=1')->assertOk()->json('data');
        $this->assertNotEmpty($active);
        $this->assertSame([true], array_values(array_unique(array_column($active, 'is_active'))));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function hostileQueries(): array
    {
        return [
            'sort injection' => ['sort=name;drop table car_show'],
            'unknown sort column' => ['sort=password'],
            'unknown filter column' => ['filter[password]=x'],
            'unknown operator' => ['filter[name][regexp]=.*'],
            'bad direction' => ['direction=sideways'],
        ];
    }

    #[Test]
    #[DataProvider('hostileQueries')]
    public function hostile_list_queries_are_a_422(string $query): void
    {
        $this->actingAs($this->admin())->getJson(self::BASE.'?'.$query)->assertStatus(422);
        $this->assertTrue(CarShow::query()->exists());
    }

    #[Test]
    public function deleting_an_event_nobody_uses_removes_it(): void
    {
        $event = CarShow::factory()->create(['short_name' => 'lonely']);

        $this->actingAs($this->admin())->deleteJson(self::BASE.'/'.$event->id)->assertNoContent();

        $this->assertNull(CarShow::query()->find($event->id));
    }

    #[Test]
    public function an_event_with_registrations_cannot_be_deleted(): void
    {
        $event = CarShow::factory()->create(['short_name' => 'busy']);
        ShowRegistration::factory()->create(['show_id' => $event->id]);

        $response = $this->actingAs($this->admin())->deleteJson(self::BASE.'/'.$event->id);

        $response->assertStatus(409)
            ->assertJsonPath('references.show_registration', 1)
            ->assertJson(fn ($json) => $json->whereType('message', 'string')->etc());
        $this->assertStringContainsString('Deactivate it instead', (string) $response->json('message'));
        $this->assertNotNull(CarShow::query()->find($event->id));
    }

    #[Test]
    public function an_event_with_sponsors_cannot_be_deleted(): void
    {
        $event = CarShow::factory()->create(['short_name' => 'sponsored']);
        ShowSponsor::factory()->create(['show_id' => $event->id]);

        $this->actingAs($this->admin())->deleteJson(self::BASE.'/'.$event->id)
            ->assertStatus(409)
            ->assertJsonPath('references.show_sponsor', 1);

        $this->assertNotNull(CarShow::query()->find($event->id));
    }

    #[Test]
    public function every_write_is_audited_and_the_entries_name_the_action(): void
    {
        $admin = $this->admin();
        $created = $this->actingAs($admin)->postJson(self::BASE, $this->validPayload(['short_name' => 'audited']))->assertCreated();
        $id = (int) $created->json('data.id');
        $this->actingAs($admin)->putJson(self::BASE.'/'.$id, ['name' => 'Audited renamed', 'short_name' => 'audited'])->assertOk();
        $this->actingAs($admin)->postJson(self::BASE.'/'.$id.'/activate')->assertOk();
        $plain = CarShow::factory()->create(['short_name' => 'plain-'.uniqid()]);
        $this->actingAs($admin)->deleteJson(self::BASE.'/'.$plain->id)->assertNoContent();

        $actions = array_column($this->auditEntries(), 'action');
        $this->assertContains('event.create', $actions);
        $this->assertContains('event.update', $actions);
        $this->assertContains('event.activate', $actions);
        $this->assertContains('event.delete', $actions);
    }

    #[Test]
    public function a_clone_is_recorded_with_its_source(): void
    {
        $this->actingAs($this->admin())->postJson(self::BASE, $this->validPayload(['short_name' => 'cl']))->assertCreated();

        $create = collect($this->auditEntries())->firstWhere('action', 'event.create');
        $this->assertSame($this->source->id, $create['extra']['cloned_from_id'] ?? null);
    }
}
