<?php

namespace App\Services\Admin\Reports;

use App\Models\ShowExtra;
use App\Support\Admin\AdminQuery;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Extras summary per extra for one event (legacy ExtraList): num_total is the sum of quantities
 * bought, num_paid the sum for paid registrations.
 */
final class ExtraReport
{
    /**
     * @return AdminQuery<ShowExtra>
     */
    public static function query(int $showId, Request $request): AdminQuery
    {
        $extras = ShowExtra::query()
            ->where('show_id', $showId)
            ->addSelect(['num_total' => self::sum(false), 'num_paid' => self::sum(true)]);

        return AdminQuery::for($extras, $request)
            ->sortable(['id', 'name', 'num_total', 'num_paid'], default: 'name')
            ->searchable(['name']);
    }

    private static function sum(bool $paidOnly): Builder
    {
        $query = DB::table('show_extra_registration as ser')
            ->join('show_registration as sr', 'sr.id', '=', 'ser.registration_id')
            ->whereColumn('ser.show_extra_id', 'show_extra.id')
            ->selectRaw('COALESCE(SUM(ser.num_extras), 0)');

        return $paidOnly ? $query->where('sr.is_paid', true) : $query;
    }
}
