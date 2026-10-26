<?php

namespace Tests\Database\Admin;

use App\Models\CarShow;
use App\Models\ShowSponsor;
use App\Models\Sponsor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * admin-api/events/{id}/sponsors: attach, detach, per-event reorder, the event's primary sponsor, the add picker and audit.
 */
class EventSponsorAdminTest extends AdminTestCase
{
    private const BASE = '/admin-api/events';

    private CarShow $event;

    protected function setUp(): void
    {
        parent::setUp();
        $this->event = CarShow::factory()->create(['short_name' => 'ev-'.uniqid()]);
    }

    private function link(CarShow $event, string $name, int $sortOrder = 0): ShowSponsor
    {
        return ShowSponsor::factory()->create([
            'show_id' => $event->id,
            'sponsor_id' => Sponsor::factory()->create(['name' => $name])->id,
            'sort_order' => $sortOrder,
            'type_id' => null,
        ]);
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
        $link = $this->link($this->event, 'Acme');
        $sponsor = Sponsor::factory()->create();
        $user = $this->actor($who);
        $as = fn () => $user === null ? $this : $this->actingAs($user);
        $expect = fn (int $ok) => $status === 200 ? $ok : $status;
        $base = self::BASE.'/'.$this->event->id;

        $as()->getJson($base.'/sponsors')->assertStatus($expect(200));
        $as()->getJson($base.'/sponsor-candidates')->assertStatus($expect(200));
        $as()->postJson($base.'/sponsors', ['sponsor_id' => $sponsor->id])->assertStatus($expect(201));
        $as()->putJson($base.'/sponsors/'.$link->id, ['type_id' => 2])->assertStatus($expect(200));
        $as()->putJson($base.'/sponsors/order', ['ids' => $this->orderIds()])->assertStatus($expect(200));
        $as()->putJson($base.'/primary-sponsor', ['sponsor_id' => $link->sponsor_id])->assertStatus($expect(200));
        $as()->deleteJson($base.'/sponsors/'.$link->id)->assertStatus($expect(204));

        if ($status !== 200) {
            $this->assertNotNull(ShowSponsor::query()->find($link->id));
            $this->assertNull($this->event->refresh()->primary_sponsor_id);
        }
    }

    /**
     * Every link id of the event, in its current order (used by the authz matrix).
     *
     * @return list<int>
     */
    private function orderIds(): array
    {
        return ShowSponsor::query()->where('show_id', $this->event->id)->orderBy('sort_order')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    #[Test]
    public function the_event_sponsors_are_listed_in_order_with_the_primary_sponsor(): void
    {
        $second = $this->link($this->event, 'Bolt', 2);
        $first = $this->link($this->event, 'Acme', 1);
        $this->event->forceFill(['primary_sponsor_id' => $first->sponsor_id])->save();

        $this->actingAs($this->admin())->getJson(self::BASE.'/'.$this->event->id.'/sponsors')
            ->assertOk()
            ->assertJsonPath('primary_sponsor_id', $first->sponsor_id)
            ->assertJsonPath('data.0.id', $first->id)
            ->assertJsonPath('data.0.name', 'Acme')
            ->assertJsonPath('data.1.id', $second->id)
            ->assertJsonStructure(['data' => [['id', 'sponsor_id', 'name', 'URL', 'is_enabled', 'sort_order']]]);
    }

    #[Test]
    public function the_list_of_another_event_is_not_shown(): void
    {
        $other = CarShow::factory()->create(['short_name' => 'other-'.uniqid()]);
        $this->link($other, 'Elsewhere');

        $this->actingAs($this->admin())->getJson(self::BASE.'/'.$this->event->id.'/sponsors')->assertOk()->assertJsonCount(0, 'data');
    }

    #[Test]
    public function the_add_picker_offers_only_sponsors_not_yet_attached_and_filters_by_name(): void
    {
        $attached = $this->link($this->event, 'Attached Co');
        $free = Sponsor::factory()->create(['name' => 'Free Gear']);
        Sponsor::factory()->create(['name' => 'Other Thing']);

        $response = $this->actingAs($this->admin())->getJson(self::BASE.'/'.$this->event->id.'/sponsor-candidates?search=gear')->assertOk();
        $this->assertSame([['id' => $free->id, 'label' => 'Free Gear']], $response->json('data'));

        $all = $this->actingAs($this->admin())->getJson(self::BASE.'/'.$this->event->id.'/sponsor-candidates')->assertOk()->json('data');
        $this->assertNotContains($attached->sponsor_id, array_column($all, 'id'));
    }

    #[Test]
    public function an_admin_attaches_a_sponsor_to_the_event_at_the_end(): void
    {
        $this->link($this->event, 'Existing', 5);
        $sponsor = Sponsor::factory()->create(['name' => 'New Partner']);

        $response = $this->actingAs($this->admin())->postJson(self::BASE.'/'.$this->event->id.'/sponsors', ['sponsor_id' => $sponsor->id]);

        $response->assertCreated()
            ->assertJsonPath('data.sponsor_id', $sponsor->id)
            ->assertJsonPath('data.name', 'New Partner');
        $this->assertSame($this->event->id, ShowSponsor::query()->findOrFail($response->json('data.id'))->show_id);
    }

    #[Test]
    public function an_admin_attaches_a_sponsor_with_its_tier(): void
    {
        $sponsor = Sponsor::factory()->create();

        $this->actingAs($this->admin())->postJson(self::BASE.'/'.$this->event->id.'/sponsors', ['sponsor_id' => $sponsor->id, 'type_id' => 1])
            ->assertCreated()
            ->assertJsonPath('data.type_id', 1)
            ->assertJsonPath('data.type', 'M');
        $this->assertSame(1, ShowSponsor::query()->where('show_id', $this->event->id)->where('sponsor_id', $sponsor->id)->value('type_id')?->value);
    }

    #[Test]
    public function an_unknown_tier_is_a_422_on_type_id_when_attaching(): void
    {
        $this->actingAs($this->admin())->postJson(self::BASE.'/'.$this->event->id.'/sponsors', ['sponsor_id' => Sponsor::factory()->create()->id, 'type_id' => 99])
            ->assertStatus(422)
            ->assertJsonValidationErrors('type_id');
    }

    #[Test]
    public function an_admin_changes_and_clears_the_tier_of_an_event_sponsor(): void
    {
        $link = $this->link($this->event, 'Tiered');
        $admin = $this->admin();
        $url = self::BASE.'/'.$this->event->id.'/sponsors/'.$link->id;

        $this->actingAs($admin)->putJson($url, ['type_id' => 1])->assertOk()->assertJsonPath('data.type_id', 1)->assertJsonPath('data.type', 'M');
        $this->assertSame(1, $link->refresh()->type_id?->value);

        $this->actingAs($admin)->putJson($url, ['type_id' => 3])->assertOk()->assertJsonPath('data.type', 'Silber');

        $this->actingAs($admin)->putJson($url, ['type_id' => null])->assertOk()->assertJsonPath('data.type_id', null);
        $this->assertNull($link->refresh()->type_id);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidTierPayloads(): array
    {
        return [
            'tier missing' => [[]],
            'tier unknown' => [['type_id' => 99]],
            'tier not a number' => [['type_id' => 'main']],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[Test]
    #[DataProvider('invalidTierPayloads')]
    public function an_invalid_tier_change_is_a_422_on_type_id(array $payload): void
    {
        $link = $this->link($this->event, 'Stays');

        $this->actingAs($this->admin())->putJson(self::BASE.'/'.$this->event->id.'/sponsors/'.$link->id, $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('type_id');
        $this->assertNull($link->refresh()->type_id);
    }

    #[Test]
    public function a_link_of_another_event_cannot_be_retiered_through_this_event(): void
    {
        $other = CarShow::factory()->create(['short_name' => 'other-'.uniqid()]);
        $foreign = $this->link($other, 'Foreign');

        $this->actingAs($this->admin())->putJson(self::BASE.'/'.$this->event->id.'/sponsors/'.$foreign->id, ['type_id' => 1])->assertNotFound();
        $this->assertNull($foreign->refresh()->type_id);
    }

    #[Test]
    public function a_sponsor_cannot_be_attached_twice(): void
    {
        $link = $this->link($this->event, 'Once');

        $this->actingAs($this->admin())->postJson(self::BASE.'/'.$this->event->id.'/sponsors', ['sponsor_id' => $link->sponsor_id])
            ->assertStatus(409)
            ->assertJsonPath('message', 'This sponsor is already attached to this event.');
        $this->assertSame(1, ShowSponsor::query()->where('show_id', $this->event->id)->count());
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidAttachPayloads(): array
    {
        return [
            'sponsor missing' => [[]],
            'sponsor not a number' => [['sponsor_id' => 'abc']],
            'sponsor does not exist' => [['sponsor_id' => 999999]],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[Test]
    #[DataProvider('invalidAttachPayloads')]
    public function attaching_invalid_input_is_a_422_on_sponsor_id(array $payload): void
    {
        $this->actingAs($this->admin())->postJson(self::BASE.'/'.$this->event->id.'/sponsors', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('sponsor_id');
    }

    #[Test]
    public function reorder_numbers_only_this_events_links_and_leaves_other_events_alone(): void
    {
        $a = $this->link($this->event, 'A', 1);
        $b = $this->link($this->event, 'B', 2);
        $c = $this->link($this->event, 'C', 3);
        $other = CarShow::factory()->create(['short_name' => 'other-'.uniqid()]);
        $elsewhere = $this->link($other, 'Elsewhere', 1);

        $response = $this->actingAs($this->admin())->putJson(self::BASE.'/'.$this->event->id.'/sponsors/order', [
            'ids' => [$c->id, $a->id, $b->id],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.0.id', $c->id)
            ->assertJsonPath('data.1.id', $a->id)
            ->assertJsonPath('data.2.id', $b->id);
        $this->assertSame([1, 2, 3], [$c->refresh()->sort_order, $a->refresh()->sort_order, $b->refresh()->sort_order]);
        $this->assertSame(1, $elsewhere->refresh()->sort_order);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function badOrders(): array
    {
        return [
            'a link of another event' => ['other'],
            'a link missing' => ['missing'],
            'a duplicate' => ['duplicate'],
        ];
    }

    #[Test]
    #[DataProvider('badOrders')]
    public function a_reorder_list_must_name_exactly_this_events_links(string $case): void
    {
        $a = $this->link($this->event, 'A', 1);
        $b = $this->link($this->event, 'B', 2);
        $other = CarShow::factory()->create(['short_name' => 'other-'.uniqid()]);
        $foreign = $this->link($other, 'Foreign', 1);

        $ids = match ($case) {
            'other' => [$a->id, $foreign->id],
            'missing' => [$a->id],
            default => [$a->id, $a->id],
        };

        $this->actingAs($this->admin())->putJson(self::BASE.'/'.$this->event->id.'/sponsors/order', ['ids' => $ids])
            ->assertStatus(422)
            ->assertJsonValidationErrors($case === 'duplicate' ? 'ids.1' : 'ids');
        $this->assertSame([1, 2], [$a->refresh()->sort_order, $b->refresh()->sort_order]);
    }

    #[Test]
    public function an_admin_removes_a_sponsor_from_the_event(): void
    {
        $link = $this->link($this->event, 'Gone');

        $this->actingAs($this->admin())->deleteJson(self::BASE.'/'.$this->event->id.'/sponsors/'.$link->id)->assertNoContent();

        $this->assertNull(ShowSponsor::query()->find($link->id));
    }

    #[Test]
    public function a_link_of_another_event_cannot_be_removed_through_this_event(): void
    {
        $other = CarShow::factory()->create(['short_name' => 'other-'.uniqid()]);
        $foreign = $this->link($other, 'Foreign');

        $this->actingAs($this->admin())->deleteJson(self::BASE.'/'.$this->event->id.'/sponsors/'.$foreign->id)->assertNotFound();

        $this->assertNotNull(ShowSponsor::query()->find($foreign->id));
    }

    #[Test]
    public function removing_the_primary_sponsor_clears_the_event_primary(): void
    {
        $link = $this->link($this->event, 'Primary Co');
        $this->event->forceFill(['primary_sponsor_id' => $link->sponsor_id])->save();

        $this->actingAs($this->admin())->deleteJson(self::BASE.'/'.$this->event->id.'/sponsors/'.$link->id)->assertNoContent();

        $this->assertNull($this->event->refresh()->primary_sponsor_id);
    }

    #[Test]
    public function an_admin_sets_and_clears_the_primary_sponsor(): void
    {
        $link = $this->link($this->event, 'Top');
        $admin = $this->admin();

        $this->actingAs($admin)->putJson(self::BASE.'/'.$this->event->id.'/primary-sponsor', ['sponsor_id' => $link->sponsor_id])
            ->assertOk()
            ->assertJsonPath('data.primary_sponsor_id', $link->sponsor_id);
        $this->assertSame($link->sponsor_id, $this->event->refresh()->primary_sponsor_id);

        $this->actingAs($admin)->putJson(self::BASE.'/'.$this->event->id.'/primary-sponsor', ['sponsor_id' => null])
            ->assertOk()
            ->assertJsonPath('data.primary_sponsor_id', null);
        $this->assertNull($this->event->refresh()->primary_sponsor_id);
    }

    #[Test]
    public function the_primary_sponsor_must_be_attached_to_the_event(): void
    {
        $stranger = Sponsor::factory()->create();

        $this->actingAs($this->admin())->putJson(self::BASE.'/'.$this->event->id.'/primary-sponsor', ['sponsor_id' => $stranger->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('sponsor_id');
        $this->assertNull($this->event->refresh()->primary_sponsor_id);
    }

    #[Test]
    public function the_primary_sponsor_key_is_required(): void
    {
        $this->actingAs($this->admin())->putJson(self::BASE.'/'.$this->event->id.'/primary-sponsor', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('sponsor_id');
    }

    #[Test]
    public function a_missing_event_is_a_json_404(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->getJson(self::BASE.'/999999/sponsors')->assertNotFound()->assertJsonPath('message', 'Event not found.');
        $this->actingAs($admin)->postJson(self::BASE.'/999999/sponsors', ['sponsor_id' => Sponsor::factory()->create()->id])->assertNotFound();
    }

    #[Test]
    public function every_change_is_audited(): void
    {
        $admin = $this->admin();
        $first = $this->link($this->event, 'One', 1);
        $second = $this->link($this->event, 'Two', 2);

        $this->actingAs($admin)->putJson(self::BASE.'/'.$this->event->id.'/sponsors/order', ['ids' => [$second->id, $first->id]])->assertOk();
        $this->actingAs($admin)->putJson(self::BASE.'/'.$this->event->id.'/primary-sponsor', ['sponsor_id' => $first->sponsor_id])->assertOk();
        $this->actingAs($admin)->deleteJson(self::BASE.'/'.$this->event->id.'/sponsors/'.$second->id)->assertNoContent();
        $this->actingAs($admin)->postJson(self::BASE.'/'.$this->event->id.'/sponsors', ['sponsor_id' => Sponsor::factory()->create()->id])->assertCreated();

        $actions = array_column($this->auditEntries(), 'action');
        $this->assertContains('event_sponsor.reorder', $actions);
        $this->assertContains('event.primary_sponsor', $actions);
        $this->assertContains('event_sponsor.remove', $actions);
        $this->assertContains('event_sponsor.add', $actions);
        $this->actingAs($admin)->putJson(self::BASE.'/'.$this->event->id.'/sponsors/'.$first->id, ['type_id' => 1])->assertOk();
        $this->assertContains('event_sponsor.type', array_column($this->auditEntries(), 'action'));
    }
}
