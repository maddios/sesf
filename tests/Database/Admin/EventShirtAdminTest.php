<?php

namespace Tests\Database\Admin;

use App\Http\Requests\Admin\EventShirtsRequest;
use App\Models\CarShow;
use App\Models\Shirt;
use App\Models\ShirtType;
use App\Models\ShowRegistration;
use Database\Factories\ShirtTypeFactory;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * admin-api/events/{id}/shirts: the shirt types an event offers and their per-event prices (show_shirt_type).
 */
class EventShirtAdminTest extends AdminTestCase
{
    private CarShow $event;

    protected function setUp(): void
    {
        parent::setUp();
        $this->event = CarShow::factory()->create(['short_name' => 'ev-'.uniqid()]);
    }

    private function url(?CarShow $event = null): string
    {
        return '/admin-api/events/'.($event ?? $this->event)->id.'/shirts';
    }

    /**
     * @return array<int, string|null> type id => stored price (2 decimals) or null
     */
    private function stored(?CarShow $event = null): array
    {
        return DB::table('show_shirt_type')->where('show_id', ($event ?? $this->event)->id)->orderBy('shirt_type_id')->get()
            ->mapWithKeys(fn ($row) => [(int) $row->shirt_type_id => $row->price === null ? null : number_format((float) $row->price, 2, '.', '')])
            ->all();
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
        $user = $this->actor($who);
        $as = fn () => $user === null ? $this : $this->actingAs($user);

        $as()->getJson($this->url())->assertStatus($status);
        $as()->putJson($this->url(), ['types' => [['id' => 1, 'price' => '20.00']]])->assertStatus($status);

        $this->assertSame($status === 200 ? [1 => '20.00'] : [], $this->stored());
    }

    #[Test]
    public function an_event_without_rows_lists_every_type_offered_at_its_default(): void
    {
        $response = $this->actingAs($this->admin())->getJson($this->url())->assertOk()
            ->assertJsonPath('data.configured', false)
            ->assertJsonCount(ShirtType::query()->count(), 'data.types');

        $first = $response->json('data.types.0');
        $this->assertSame([
            'id' => 1,
            'name' => 'Mens S T-Shirt',
            'default_price' => '35.00',
            'price' => null,
            'offered' => true,
            'orders' => 0,
        ], $first);
    }

    #[Test]
    public function saving_a_selection_offers_only_those_types_with_their_prices(): void
    {
        $this->actingAs($this->admin())->putJson($this->url(), ['types' => [
            ['id' => ShirtTypeFactory::MENS_S_TSHIRT, 'price' => '20.5'],
            ['id' => ShirtTypeFactory::MENS_M_TSHIRT, 'price' => null],
        ]])
            ->assertOk()
            ->assertJsonPath('data.configured', true)
            ->assertJsonPath('data.types.0.price', '20.50')
            ->assertJsonPath('data.types.0.offered', true)
            ->assertJsonPath('data.types.1.price', null)
            ->assertJsonPath('data.types.1.offered', true)
            ->assertJsonPath('data.types.2.offered', false)
            ->assertJsonPath('removed_with_orders', []);

        $this->assertSame([1 => '20.50', 2 => null], $this->stored());

        // A second save replaces the selection; other events are untouched.
        $other = CarShow::factory()->create(['short_name' => 'other-'.uniqid()]);
        DB::table('show_shirt_type')->insert(['show_id' => $other->id, 'shirt_type_id' => 5, 'price' => 1]);
        $this->actingAs($this->admin())->putJson($this->url(), ['types' => [['id' => 3, 'price' => '0']]])->assertOk();
        $this->assertSame([3 => '0.00'], $this->stored());
        $this->assertSame([5 => '1.00'], $this->stored($other));
    }

    #[Test]
    public function selecting_every_type_without_prices_is_stored_explicitly(): void
    {
        $all = ShirtType::query()->orderBy('id')->pluck('id')->map(fn ($id) => ['id' => (int) $id, 'price' => null])->all();

        $this->actingAs($this->admin())->putJson($this->url(), ['types' => $all])->assertOk()->assertJsonPath('data.configured', true);

        $this->assertCount(count($all), $this->stored());
    }

    #[Test]
    public function an_empty_selection_is_refused_with_a_hint_to_select_all(): void
    {
        $admin = $this->admin();
        foreach ([['types' => []], []] as $body) {
            $this->actingAs($admin)->putJson($this->url(), $body)
                ->assertStatus(422)
                ->assertJsonPath('errors.types.0', EventShirtsRequest::EMPTY_SELECTION);
        }
        $this->assertSame([], $this->stored());
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidTypes(): array
    {
        return [
            'negative price' => [['id' => 1, 'price' => '-1'], 'types.0.price'],
            'price above the column' => [['id' => 1, 'price' => '10000'], 'types.0.price'],
            'three decimals' => [['id' => 1, 'price' => '1.234'], 'types.0.price'],
            'not a number' => [['id' => 1, 'price' => 'abc'], 'types.0.price'],
            'exponent' => [['id' => 1, 'price' => '1e3'], 'types.0.price'],
            'missing price key' => [['id' => 1], 'types.0.price'],
            'unknown type' => [['id' => 999, 'price' => null], 'types.0.id'],
            'type not an integer' => [['id' => 'x', 'price' => null], 'types.0.id'],
            'extra key' => [['id' => 1, 'price' => null, 'show_id' => 2], 'types.0'],
        ];
    }

    /**
     * @param  array<string, mixed>  $type
     */
    #[Test]
    #[DataProvider('invalidTypes')]
    public function invalid_entries_are_refused(array $type, string $field): void
    {
        $this->actingAs($this->admin())->putJson($this->url(), ['types' => [$type]])
            ->assertStatus(422)
            ->assertJsonValidationErrors([$field]);
        $this->assertSame([], $this->stored());
    }

    #[Test]
    public function the_price_limits_are_inclusive(): void
    {
        $this->actingAs($this->admin())->putJson($this->url(), ['types' => [
            ['id' => 1, 'price' => '9999.99'],
            ['id' => 2, 'price' => '0.00'],
        ]])->assertOk();

        $this->assertSame([1 => '9999.99', 2 => '0.00'], $this->stored());
    }

    #[Test]
    public function duplicate_type_ids_are_refused(): void
    {
        $this->actingAs($this->admin())->putJson($this->url(), ['types' => [['id' => 1, 'price' => null], ['id' => 1, 'price' => '5']]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['types.0.id']);
    }

    #[Test]
    public function an_unknown_event_is_404(): void
    {
        $this->actingAs($this->admin())->getJson('/admin-api/events/999999/shirts')->assertNotFound();
        $this->actingAs($this->admin())->putJson('/admin-api/events/999999/shirts', ['types' => [['id' => 1, 'price' => null]]])->assertNotFound();
    }

    #[Test]
    public function removing_a_type_with_orders_is_allowed_and_reports_the_count(): void
    {
        $registration = ShowRegistration::factory()->create(['show_id' => $this->event->id]);
        Shirt::factory()->count(2)->create(['registration_id' => $registration->id, 'type_id' => 2, 'price' => '30.00']);

        $this->actingAs($this->admin())->getJson($this->url())->assertOk()->assertJsonPath('data.types.1.orders', 2);

        $this->actingAs($this->admin())->putJson($this->url(), ['types' => [['id' => 1, 'price' => null]]])
            ->assertOk()
            ->assertJsonPath('removed_with_orders', [['id' => 2, 'name' => 'Mens M T-Shirt', 'orders' => 2]])
            ->assertJsonPath('data.types.1.offered', false)
            ->assertJsonPath('data.types.1.orders', 2);

        // The orders keep their snapshot.
        $this->assertSame(['30.00', '30.00'], Shirt::query()->where('registration_id', $registration->id)->pluck('price')->all());
    }

    #[Test]
    public function every_save_is_audited_with_money_as_strings(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->putJson($this->url(), ['types' => [['id' => 1, 'price' => '20.5'], ['id' => 2, 'price' => null]]])->assertOk();
        $this->actingAs($admin)->putJson($this->url(), ['types' => [['id' => 1, 'price' => '22']]])->assertOk();

        $entries = array_values(array_filter($this->auditEntries(), fn (array $entry) => $entry['action'] === 'event.shirts'));
        $this->assertCount(2, $entries);
        $this->assertSame([$this->event->id], $entries[0]['ids']);
        // From "all types at default" to two types: every other type stops being offered.
        $this->assertSame(['old' => 'default', 'new' => '20.50'], $entries[0]['changes']['shirt_type.1']);
        $this->assertArrayNotHasKey('shirt_type.2', $entries[0]['changes']);
        $this->assertSame(['old' => 'default', 'new' => 'not offered'], $entries[0]['changes']['shirt_type.3']);
        $this->assertSame(['old' => '20.50', 'new' => '22.00'], $entries[1]['changes']['shirt_type.1']);
        $this->assertSame(['old' => 'default', 'new' => 'not offered'], $entries[1]['changes']['shirt_type.2']);
        $this->assertSame(1, $entries[1]['extra']['offered']);
    }
}
