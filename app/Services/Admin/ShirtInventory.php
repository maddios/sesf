<?php

namespace App\Services\Admin;

use App\Models\CarShow;
use App\Models\Shirt;
use App\Models\ShirtAdhocSale;
use App\Models\ShirtAdhocSaleItem;
use App\Models\ShowRegistration;
use App\Models\User;
use App\Services\Shirts\ShirtOffer;
use App\Services\Shirts\ShowShirtCatalog;
use App\Support\Admin\DisplayName;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * T-shirt stock, hand-outs and walk-up sales for one event (legacy TshirtList).
 *
 * Stock is car_show.shirt_stock_json: type id => count. remaining = stock - handed out - ad hoc sold;
 * available for an ad hoc sale = stock - ordered - ad hoc sold (legacy rules). Every stock change and
 * every ad hoc sale locks the car_show row first, so two admins selling the last shirt cannot both win.
 *
 * Types listed: the ones the event offers (ShowShirtCatalog) plus any other type that has stock, orders or sales
 * at this event, so nothing on hand disappears when the offer changes. price_cents is the event's price.
 */
final class ShirtInventory
{
    public function __construct(private readonly ShowShirtCatalog $catalog = new ShowShirtCatalog) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(CarShow $show): array
    {
        $counts = $this->counts($show);
        $menu = $this->catalog->forShow((int) $show->id);
        $types = array_filter($menu->all(), fn (ShirtOffer $type): bool => $menu->isOffered($type->id)
            || ($counts['stock'][$type->id] ?? 0) > 0
            || ($counts['ordered'][$type->id] ?? 0) > 0
            || ($counts['adhoc'][$type->id] ?? 0) > 0);

        return [
            'show' => ['id' => (int) $show->id, 'name' => (string) $show->name],
            'types' => array_values(array_map(function (ShirtOffer $type) use ($counts, $menu): array {
                $id = $type->id;
                $stock = $counts['stock'][$id] ?? 0;
                $ordered = $counts['ordered'][$id] ?? 0;
                $handed = $counts['handed_out'][$id] ?? 0;
                $adhoc = $counts['adhoc'][$id] ?? 0;

                return [
                    'id' => $id,
                    'name' => $type->name,
                    'price_cents' => Money::toCents($type->price),
                    'offered' => $menu->isOffered($id),
                    'stock' => $stock,
                    'ordered' => $ordered,
                    'handed_out' => $handed,
                    'adhoc_sold' => $adhoc,
                    'remaining' => $stock - $handed - $adhoc,
                    'available_for_adhoc' => $stock - $ordered - $adhoc,
                ];
            }, $types)),
            'registrations' => $this->registrations($show),
            'adhoc_sales' => $this->sales($show),
        ];
    }

    /**
     * Sets stock for the given types (others keep their count). Returns the changed counts for the audit log.
     *
     * @param  array<int, int>  $stock  type id => count
     * @return array<string, array{old: int, new: int}>
     */
    public function setStock(CarShow $show, array $stock): array
    {
        return DB::transaction(function () use ($show, $stock): array {
            $locked = CarShow::query()->whereKey($show->id)->lockForUpdate()->firstOrFail();
            $current = self::stockOf($locked);
            $changes = [];

            foreach ($stock as $typeId => $count) {
                $old = $current[$typeId] ?? 0;
                if ($old !== $count) {
                    $changes['stock.'.$typeId] = ['old' => $old, 'new' => $count];
                }
                $current[$typeId] = $count;
            }

            $locked->forceFill(['shirt_stock_json' => $current])->save();

            return $changes;
        });
    }

    public function setHandedOut(Shirt $shirt, bool $handedOut): Shirt
    {
        $shirt->forceFill(['is_handed_out' => $handedOut])->save();

        return $shirt;
    }

    /**
     * Records one walk-up sale of the given types (one shirt per id). Fails with 422 when a type has fewer
     * shirts available than requested.
     *
     * @param  list<int>  $typeIds
     */
    public function recordSale(CarShow $show, array $typeIds, User $seller): ShirtAdhocSale
    {
        return DB::transaction(function () use ($show, $typeIds, $seller): ShirtAdhocSale {
            $locked = CarShow::query()->whereKey($show->id)->lockForUpdate()->firstOrFail();
            $counts = $this->counts($locked);
            $menu = $this->catalog->forShow((int) $locked->id);

            $problems = [];
            foreach (array_count_values($typeIds) as $typeId => $wanted) {
                $available = ($counts['stock'][$typeId] ?? 0) - ($counts['ordered'][$typeId] ?? 0) - ($counts['adhoc'][$typeId] ?? 0);
                if ($wanted > $available) {
                    $problems[] = ($menu->find($typeId)->name ?? 'Type #'.$typeId).' has only '.max(0, $available).' available.';
                }
            }
            if ($problems !== []) {
                throw ValidationException::withMessages(['type_ids' => $problems]);
            }

            $sale = new ShirtAdhocSale(['show_id' => $locked->id]);
            $sale->forceFill(['created_by_user_id' => $seller->id])->save();
            $sale->items()->createMany(array_map(fn (int $typeId): array => ['type_id' => $typeId], $typeIds));

            return $sale;
        });
    }

    /** Deleting a sale puts its shirts back on the available count. */
    public function deleteSale(ShirtAdhocSale $sale): void
    {
        DB::transaction(function () use ($sale): void {
            CarShow::query()->whereKey($sale->show_id)->lockForUpdate()->firstOrFail();
            ShirtAdhocSale::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail()->delete();
        });
    }

    /**
     * @return array{stock: array<int, int>, ordered: array<int, int>, handed_out: array<int, int>, adhoc: array<int, int>}
     */
    private function counts(CarShow $show): array
    {
        $shirts = Shirt::query()->where('show_id', $show->id);
        $adhoc = ShirtAdhocSaleItem::query()
            ->join('shirt_adhoc_sale', 'shirt_adhoc_sale.id', '=', 'shirt_adhoc_sale_item.sale_id')
            ->where('shirt_adhoc_sale.show_id', $show->id);

        return [
            'stock' => self::stockOf($show),
            'ordered' => $this->countsByType((clone $shirts)),
            'handed_out' => $this->countsByType((clone $shirts)->where('is_handed_out', true)),
            'adhoc' => $this->countsByType($adhoc, 'shirt_adhoc_sale_item.type_id'),
        ];
    }

    /**
     * @param  Builder<Shirt>|Builder<ShirtAdhocSaleItem>  $query
     * @return array<int, int>
     */
    private function countsByType($query, string $column = 'type_id'): array
    {
        return $query->groupBy($column)
            ->selectRaw($column.' as type_id, count(*) as c')
            ->pluck('c', 'type_id')
            ->mapWithKeys(fn ($count, $type): array => [(int) $type => (int) $count])
            ->all();
    }

    /**
     * @return array<int, int>
     */
    public static function stockOf(CarShow $show): array
    {
        /** @var array<string|int, mixed> $raw */
        $raw = $show->shirt_stock_json ?? [];

        return collect($raw)
            ->mapWithKeys(fn ($count, $type): array => [(int) $type => max(0, (int) $count)])
            ->all();
    }

    /**
     * Active registrations of the event with a shirt line, each line with its hand-out state.
     *
     * @return list<array<string, mixed>>
     */
    private function registrations(CarShow $show): array
    {
        return ShowRegistration::query()
            ->where('show_id', $show->id)
            ->where('is_cancelled', false)
            ->whereHas('shirts')
            ->with(['user:id,first,last', 'shirts:id,registration_id,type_id,is_handed_out', 'shirts.shirtType:id,name'])
            ->orderBy('invoice_id')
            ->get()
            ->map(function (ShowRegistration $registration): array {
                $lines = $registration->shirts
                    ->sortBy([['type_id', 'asc'], ['id', 'asc']])
                    ->values()
                    ->map(fn (Shirt $shirt): array => [
                        'id' => (int) $shirt->id,
                        'type_id' => (int) $shirt->type_id,
                        'type_name' => DisplayName::shirtType($shirt->shirtType, (int) $shirt->type_id),
                        'handed_out' => (bool) $shirt->is_handed_out,
                    ])
                    ->all();
                $left = count(array_filter($lines, fn (array $line): bool => ! $line['handed_out']));

                return [
                    'id' => (int) $registration->id,
                    'invoice_id' => (int) $registration->invoice_id,
                    'primary_name' => DisplayName::person($registration->user),
                    'lines' => $lines,
                    'left_to_hand_out' => $left,
                    'all_handed_out' => $lines !== [] && $left === 0,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sales(CarShow $show): array
    {
        return ShirtAdhocSale::query()
            ->where('show_id', $show->id)
            ->with(['createdBy:id,first,last', 'items:id,sale_id,type_id', 'items.shirtType:id,name'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (ShirtAdhocSale $sale): array => [
                'id' => (int) $sale->id,
                'created_at' => $sale->created_at?->toIso8601String(),
                'sold_by' => DisplayName::person($sale->createdBy),
                'items' => $sale->items
                    ->groupBy('type_id')
                    ->map(fn (Collection $group): array => [
                        'type_id' => (int) $group->first()->type_id,
                        'type_name' => DisplayName::shirtType($group->first()->shirtType, (int) $group->first()->type_id),
                        'count' => $group->count(),
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }
}
