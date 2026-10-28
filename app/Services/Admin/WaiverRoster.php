<?php

namespace App\Services\Admin;

use App\Models\Waiver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * One row per person on an event's waiver list: each registrant and each guest (legacy
 * WaiversList union). Status is signed, not_signed or not_started (no waiver yet), as in the
 * public waiver status endpoint. Rows are Waiver models carrying the roster columns; the
 * resource maps them.
 */
final class WaiverRoster
{
    /**
     * @return Builder<Waiver>
     */
    public function forShow(int $showId, bool $sortCheckedIn = false): Builder
    {
        $registrants = DB::table('show_registration as r')
            ->join('user as u', 'u.id', '=', 'r.user_id')
            ->leftJoin('waiver as w', 'w.id', '=', 'r.waiver_id')
            ->where('r.show_id', $showId)
            ->selectRaw(
                "'registrant' AS kind, r.id AS id, r.id AS registration_id, NULL AS guest_id, u.first AS first, u.last AS last, "
                .'u.email AS email, r.invoice_id AS invoice_id, '.self::STATUS.' AS status, '
                .'w.waiver_id AS waiver_id, w.signed_at AS signed_at, r.date_checked_in AS date_checked_in, '
                .self::ARRIVAL,
            );

        $guests = DB::table('user_guest as g')
            ->join('user as u', 'u.id', '=', 'g.user_id')
            ->join('show_registration as r', function ($join): void {
                $join->on('r.user_id', '=', 'u.id')->on('r.id', '=', 'g.registration_id');
            })
            ->leftJoin('waiver as w', 'w.id', '=', 'g.waiver_id')
            ->where('r.show_id', $showId)
            ->selectRaw(
                "'guest' AS kind, g.id AS id, r.id AS registration_id, g.id AS guest_id, g.first AS first, g.last AS last, "
                .'g.email AS email, r.invoice_id AS invoice_id, '.self::STATUS.' AS status, '
                .'w.waiver_id AS waiver_id, w.signed_at AS signed_at, r.date_checked_in AS date_checked_in, '
                .self::ARRIVAL,
            );

        $roster = Waiver::query()->fromSub($registrants->unionAll($guests), 'waiver');

        return $sortCheckedIn
            ? $roster->orderByRaw('date_checked_in IS NULL ASC, date_checked_in DESC, last ASC, first ASC')
            : $roster;
    }

    private const ARRIVAL = "CONCAT(r.arrival_time, ' ', r.arrival_date) AS arrival";

    private const STATUS = "CASE WHEN w.id IS NULL THEN 'not_started' WHEN w.has_signed = 1 THEN 'signed' ELSE 'not_signed' END";

    /**
     * Walk-in waivers signed at this event (no registration), newest first.
     *
     * @return Collection<int, Waiver>
     */
    public function walkIns(int $showId): Collection
    {
        return Waiver::query()
            ->where('adhoc_show_id', $showId)
            ->where('has_signed', true)
            ->orderByDesc('signed_at')
            ->orderByDesc('id')
            ->get();
    }
}
