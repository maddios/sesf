<?php

namespace Tests\Database\Admin;

use App\Models\CarShow;
use App\Models\ShirtAdhocSale;
use App\Models\ShirtAdhocSaleItem;
use App\Models\ShirtType;
use App\Models\ShowRegistration;
use Database\Factories\ShirtFactory;
use Database\Factories\ShirtTypeFactory;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * admin-api/shirts: stock per event, hand-outs, walk-up sales (availability, delete) and validation, audited.
 */
class ShirtInventoryAdminTest extends AdminTestCase
{
    private const BASE = '/admin-api/shirts';

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
        $show = CarShow::factory()->create(['shirt_stock_json' => [ShirtTypeFactory::MENS_M_TSHIRT => 5]]);
        $shirt = ShirtFactory::new()->create(['show_id' => $show->id]);
        $sale = $this->saleFor($show);
        $user = $this->actor($who);
        $as = fn () => $user === null ? $this : $this->actingAs($user);
        $expect = fn (int $ok) => $status === 200 ? $ok : $status;

        $as()->getJson(self::BASE.'/inventory?show_id='.$show->id)->assertStatus($expect(200));
        $as()->putJson(self::BASE."/inventory/{$show->id}/stock", ['stock' => [ShirtTypeFactory::MENS_M_TSHIRT => 6]])->assertStatus($expect(200));
        $as()->postJson(self::BASE."/inventory/{$show->id}/sales", ['type_ids' => [ShirtTypeFactory::MENS_M_TSHIRT]])->assertStatus($expect(201));
        $as()->postJson(self::BASE."/{$shirt->id}/handed-out", ['handed_out' => true])->assertStatus($expect(200));
        $as()->deleteJson(self::BASE."/sales/{$sale->id}")->assertStatus($expect(204));
    }

    #[Test]
    public function the_inventory_counts_stock_orders_hand_outs_and_sales(): void
    {
        $show = CarShow::factory()->create(['shirt_stock_json' => [ShirtTypeFactory::MENS_M_TSHIRT => 10]]);
        $registration = ShowRegistration::factory()->create(['show_id' => $show->id]);
        ShirtFactory::new()->count(3)->create(['show_id' => $show->id, 'registration_id' => $registration->id, 'type_id' => ShirtTypeFactory::MENS_M_TSHIRT]);
        ShirtFactory::new()->handedOut()->create(['show_id' => $show->id, 'registration_id' => $registration->id, 'type_id' => ShirtTypeFactory::MENS_M_TSHIRT]);
        $this->saleFor($show, [ShirtTypeFactory::MENS_M_TSHIRT, ShirtTypeFactory::MENS_M_TSHIRT]);

        $data = $this->actingAs($this->admin())
            ->getJson(self::BASE.'/inventory?show_id='.$show->id)
            ->assertOk()
            ->json('data');

        /** @var list<array<string, mixed>> $types */
        $types = $data['types'];
        $type = collect($types)->firstWhere('id', ShirtTypeFactory::MENS_M_TSHIRT);
        $this->assertSame(10, $type['stock']);
        $this->assertSame(4, $type['ordered']);
        $this->assertSame(1, $type['handed_out']);
        $this->assertSame(2, $type['adhoc_sold']);
        $this->assertSame(7, $type['remaining']);
        $this->assertSame(4, $type['available_for_adhoc']);
        $this->assertCount(1, $data['registrations']);
        $this->assertCount(4, $data['registrations'][0]['lines']);
        $this->assertCount(1, $data['adhoc_sales']);
        $this->assertSame(2, $data['adhoc_sales'][0]['items'][0]['count']);
    }

    #[Test]
    public function the_inventory_lists_the_offered_types_at_the_event_price_and_keeps_types_with_stock_or_orders(): void
    {
        $show = CarShow::factory()->create(['shirt_stock_json' => [ShirtTypeFactory::MENS_S_POLO => 3]]);
        $registration = ShowRegistration::factory()->create(['show_id' => $show->id]);
        ShirtFactory::new()->create(['show_id' => $show->id, 'registration_id' => $registration->id, 'type_id' => ShirtTypeFactory::MENS_S_TSHIRT]);
        DB::table('show_shirt_type')->insert(['show_id' => $show->id, 'shirt_type_id' => ShirtTypeFactory::MENS_M_TSHIRT, 'price' => 19.99]);

        $types = $this->actingAs($this->admin())->getJson(self::BASE.'/inventory?show_id='.$show->id)->assertOk()->json('data.types');

        $this->assertSame([ShirtTypeFactory::MENS_S_TSHIRT, ShirtTypeFactory::MENS_M_TSHIRT, ShirtTypeFactory::MENS_S_POLO], array_column($types, 'id'));
        $this->assertSame([3500, 1999, 5500], array_column($types, 'price_cents'));
        $this->assertSame([false, true, false], array_column($types, 'offered'));
    }

    #[Test]
    public function an_event_without_an_offer_lists_every_type_in_the_inventory(): void
    {
        $show = CarShow::factory()->create();

        $types = $this->actingAs($this->admin())->getJson(self::BASE.'/inventory?show_id='.$show->id)->assertOk()->json('data.types');

        $this->assertCount(ShirtType::query()->count(), $types);
        $this->assertSame([true], array_values(array_unique(array_column($types, 'offered'))));
    }

    #[Test]
    public function the_inventory_needs_an_event(): void
    {
        $this->actingAs($this->admin())->getJson(self::BASE.'/inventory')->assertStatus(422);
        $this->actingAs($this->admin())->getJson(self::BASE.'/inventory?show_id=999999')->assertNotFound();
    }

    #[Test]
    public function stock_is_set_per_type_and_changes_are_audited(): void
    {
        $show = CarShow::factory()->create(['shirt_stock_json' => [ShirtTypeFactory::MENS_M_TSHIRT => 5, ShirtTypeFactory::MENS_S_TSHIRT => 2]]);

        $this->actingAs($this->admin())
            ->putJson(self::BASE."/inventory/{$show->id}/stock", ['stock' => [ShirtTypeFactory::MENS_M_TSHIRT => 8]])
            ->assertOk();

        $stock = $show->refresh()->shirt_stock_json;
        $this->assertSame(8, $stock[ShirtTypeFactory::MENS_M_TSHIRT]);
        $this->assertSame(2, $stock[ShirtTypeFactory::MENS_S_TSHIRT]);
        $entry = collect($this->auditEntries())->last();
        $this->assertSame('shirt_stock.update', $entry['action']);
        $this->assertSame(['old' => 5, 'new' => 8], $entry['changes']['stock.'.ShirtTypeFactory::MENS_M_TSHIRT]);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function badStock(): array
    {
        return [
            'negative count' => [['stock' => ['2' => -1]]],
            'text count' => [['stock' => ['2' => 'many']]],
            'unknown type' => [['stock' => ['999999' => 1]]],
            'not a map' => [['stock' => 'five']],
            'too large' => [['stock' => ['2' => 70000]]],
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[Test]
    #[DataProvider('badStock')]
    public function stock_is_validated(array $body): void
    {
        $show = CarShow::factory()->create();

        $this->actingAs($this->admin())
            ->putJson(self::BASE."/inventory/{$show->id}/stock", $body)
            ->assertStatus(422);
    }

    #[Test]
    public function a_hand_out_is_recorded_and_audited(): void
    {
        $show = CarShow::factory()->create();
        $shirt = ShirtFactory::new()->create(['show_id' => $show->id]);

        $this->actingAs($this->admin())
            ->postJson(self::BASE."/{$shirt->id}/handed-out", ['handed_out' => true])
            ->assertOk()
            ->assertJsonPath('data.handed_out', true);

        $this->assertTrue((bool) $shirt->refresh()->is_handed_out);
        $this->assertSame('shirt.handed_out', collect($this->auditEntries())->last()['action']);

        $this->actingAs($this->admin())
            ->postJson(self::BASE."/{$shirt->id}/handed-out", ['handed_out' => false])
            ->assertOk()
            ->assertJsonPath('data.handed_out', false);
    }

    #[Test]
    public function a_hand_out_needs_a_boolean(): void
    {
        $shirt = ShirtFactory::new()->create();

        $this->actingAs($this->admin())
            ->postJson(self::BASE."/{$shirt->id}/handed-out", ['handed_out' => 'maybe'])
            ->assertStatus(422);
    }

    #[Test]
    public function a_sale_is_recorded_with_one_row_per_shirt_and_audited(): void
    {
        $show = CarShow::factory()->create(['shirt_stock_json' => [ShirtTypeFactory::MENS_M_TSHIRT => 3]]);

        $this->actingAs($this->admin())
            ->postJson(self::BASE."/inventory/{$show->id}/sales", ['type_ids' => [ShirtTypeFactory::MENS_M_TSHIRT, ShirtTypeFactory::MENS_M_TSHIRT]])
            ->assertStatus(201)
            ->assertJsonFragment(['id' => ShirtTypeFactory::MENS_M_TSHIRT, 'adhoc_sold' => 2]);

        $sale = ShirtAdhocSale::query()->where('show_id', $show->id)->firstOrFail();
        $this->assertSame(2, ShirtAdhocSaleItem::query()->where('sale_id', $sale->id)->count());
        $this->assertSame('shirt_sale.create', collect($this->auditEntries())->last()['action']);
    }

    #[Test]
    public function a_sale_refuses_more_shirts_than_are_available_and_writes_nothing(): void
    {
        $show = CarShow::factory()->create(['shirt_stock_json' => [ShirtTypeFactory::MENS_M_TSHIRT => 2]]);
        ShirtFactory::new()->create(['show_id' => $show->id, 'type_id' => ShirtTypeFactory::MENS_M_TSHIRT]);

        $this->actingAs($this->admin())
            ->postJson(self::BASE."/inventory/{$show->id}/sales", ['type_ids' => [ShirtTypeFactory::MENS_M_TSHIRT, ShirtTypeFactory::MENS_M_TSHIRT]])
            ->assertStatus(422)
            ->assertJsonPath('errors.type_ids.0', ShirtType::query()->findOrFail(ShirtTypeFactory::MENS_M_TSHIRT)->name.' has only 1 available.');

        $this->assertSame(0, ShirtAdhocSale::query()->count());
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function badSales(): array
    {
        return [
            'empty' => [['type_ids' => []]],
            'unknown type' => [['type_ids' => [999999]]],
            'text' => [['type_ids' => ['medium']]],
            'too many shirts' => [['type_ids' => array_fill(0, 51, ShirtTypeFactory::MENS_M_TSHIRT)]],
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[Test]
    #[DataProvider('badSales')]
    public function sale_lines_are_validated(array $body): void
    {
        $show = CarShow::factory()->create(['shirt_stock_json' => [ShirtTypeFactory::MENS_M_TSHIRT => 100]]);

        $this->actingAs($this->admin())
            ->postJson(self::BASE."/inventory/{$show->id}/sales", $body)
            ->assertStatus(422);
        $this->assertSame(0, ShirtAdhocSale::query()->count());
    }

    #[Test]
    public function deleting_a_sale_returns_its_shirts_to_stock_and_is_audited(): void
    {
        $show = CarShow::factory()->create(['shirt_stock_json' => [ShirtTypeFactory::MENS_M_TSHIRT => 1]]);
        $sale = $this->saleFor($show);

        $this->actingAs($this->admin())->deleteJson(self::BASE."/sales/{$sale->id}")->assertNoContent();

        $this->assertSame(0, ShirtAdhocSaleItem::query()->count());
        $this->assertSame('shirt_sale.delete', collect($this->auditEntries())->last()['action']);
    }

    /**
     * @param  list<int>|null  $typeIds
     */
    private function saleFor(CarShow $show, ?array $typeIds = null): ShirtAdhocSale
    {
        $sale = ShirtAdhocSale::factory()->create(['show_id' => $show->id]);
        foreach ($typeIds ?? [ShirtTypeFactory::MENS_M_TSHIRT] as $typeId) {
            ShirtAdhocSaleItem::factory()->create(['sale_id' => $sale->id, 'type_id' => $typeId]);
        }

        return $sale;
    }
}
