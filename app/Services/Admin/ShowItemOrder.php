<?php

namespace App\Services\Admin;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Per-event ordering for admin lists (agenda items, locations). The legacy order up/down actions swapped
 * sort_order with the nearest row of the whole table. Here the order belongs to one event: the ids sent must be
 * exactly that event's rows, and they become positions 1..n.
 */
final class ShowItemOrder
{
    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $items  the rows of one event only
     * @param  list<int>  $ids  every row of the event, in the new order
     *
     * @throws ValidationException when $ids is not exactly the event's rows
     */
    public static function apply(Builder $items, array $ids): void
    {
        DB::transaction(function () use ($items, $ids): void {
            $rows = $items->lockForUpdate()->get();

            $existing = array_map(intval(...), $rows->modelKeys());
            sort($existing);
            $wanted = $ids;
            sort($wanted);
            if ($existing !== $wanted) {
                throw ValidationException::withMessages(['ids' => ['The list must name every item of this event exactly once.']]);
            }

            $positions = array_flip($ids);
            foreach ($rows as $row) {
                $position = $positions[(int) $row->getKey()] + 1;
                if ((int) $row->getAttribute('sort_order') !== $position) {
                    $row->forceFill(['sort_order' => $position])->save();
                }
            }
        });
    }
}
