<?php

namespace App\Services\Shirts;

use App\Models\CarShow;
use App\Models\Casts\Money2;
use App\Models\Shirt;
use App\Models\ShirtType;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * The one place that decides which shirt types an event offers and at what price (table show_shirt_type,
 * database/sql/003_show_shirt_type.sql).
 *
 *  - No rows for the event: every shirt type is offered at shirt_type.price (legacy behaviour).
 *  - Rows: only those types are offered. Effective price = show_shirt_type.price ?? shirt_type.price.
 *
 * Prices apply to new orders only: an order line keeps its shirt.price snapshot, and the effective price is only
 * the fallback for legacy lines without one (ShowShirtMenu::linePrice).
 */
final class ShowShirtCatalog
{
    public function forShow(int $showId): ShowShirtMenu
    {
        $rows = ShirtType::query()
            ->leftJoin('show_shirt_type as sst', function ($join) use ($showId): void {
                $join->on('sst.shirt_type_id', '=', 'shirt_type.id')->where('sst.show_id', '=', $showId);
            })
            ->orderBy('shirt_type.id')
            ->withCasts(['show_price' => Money2::class])
            ->get([
                'shirt_type.id',
                'shirt_type.name',
                'shirt_type.price',
                'sst.price as show_price',
                DB::raw('sst.shirt_type_id is not null as selected'),
            ]);

        return new ShowShirtMenu($rows->map(fn (ShirtType $type): ShirtOffer => new ShirtOffer(
            id: (int) $type->id,
            name: (string) $type->name,
            defaultPrice: $type->price ?? '0.00',
            showPrice: $type->getAttribute('show_price'),
            selected: (bool) $type->getAttribute('selected'),
        ))->values()->all());
    }

    /**
     * Shirt order lines of the event per type id (cancelled registrations included: the shirts still exist).
     *
     * @return array<int, int>
     */
    public function orderCounts(int $showId): array
    {
        return Shirt::query()
            ->where('show_id', $showId)
            ->groupBy('type_id')
            ->selectRaw('type_id, count(*) as c')
            ->pluck('c', 'type_id')
            ->mapWithKeys(fn ($count, $type): array => [(int) $type => (int) $count])
            ->all();
    }

    /**
     * Replaces the event's selection (type id => price override, null = default). The car_show row is locked
     * first, so two admins saving at once apply one after the other. Returns the audit changes per type
     * ("not offered", "default" or a 2-decimal price) and the order counts of the types that stopped being offered.
     *
     * @param  array<int, string|null>  $selection  non-empty; ids exist (checked by the request)
     * @return array{changes: array<string, array{old: string, new: string}>, removed_with_orders: array<int, int>}
     */
    public function replace(CarShow $show, array $selection): array
    {
        return DB::transaction(function () use ($show, $selection): array {
            CarShow::query()->whereKey($show->id)->lockForUpdate()->firstOrFail();
            $before = $this->forShow((int) $show->id);

            DB::table('show_shirt_type')->where('show_id', $show->id)->delete();
            DB::table('show_shirt_type')->insert(array_map(
                fn (int $typeId, ?string $price): array => [
                    'show_id' => $show->id,
                    'shirt_type_id' => $typeId,
                    'price' => $price === null ? null : self::decimal($price),
                ],
                array_keys($selection),
                array_values($selection),
            ));

            $after = $this->forShow((int) $show->id);
            $orders = $this->orderCounts((int) $show->id);
            $changes = [];
            $removed = [];
            foreach ($after->all() as $type) {
                $old = self::state($before, $type->id);
                $new = self::state($after, $type->id);
                if ($old !== $new) {
                    $changes['shirt_type.'.$type->id] = ['old' => $old, 'new' => $new];
                }
                if ($before->isOffered($type->id) && ! $after->isOffered($type->id) && ($orders[$type->id] ?? 0) > 0) {
                    $removed[$type->id] = $orders[$type->id];
                }
            }

            return ['changes' => $changes, 'removed_with_orders' => $removed];
        });
    }

    private static function state(ShowShirtMenu $menu, int $typeId): string
    {
        if (! $menu->isOffered($typeId)) {
            return 'not offered';
        }

        return $menu->find($typeId)->showPrice ?? 'default';
    }

    /** A validated decimal string as the float(6,2) value, rounded like Money2 (half up on the decimal string). */
    private static function decimal(string $price): float
    {
        return Money::toCents($price) / 100;
    }
}
