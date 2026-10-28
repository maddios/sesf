<?php

namespace App\Services\Admin\Reports;

use App\Models\ShowRegistration;
use App\Models\UserGuest;
use App\Support\Admin\AdminQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Event summary totals (legacy EventSummary counts) and the column maxima the export needs
 * before it writes headers. Totals match the legacy formulas: extras are counted per purchase row.
 */
final class EventSummaryReport
{
    /**
     * Registrations of one event (screen rows and export source), with everything the summary shows.
     *
     * @return AdminQuery<ShowRegistration>
     */
    public static function query(int $showId, Request $request): AdminQuery
    {
        $registrations = ShowRegistration::query()
            ->where('show_id', $showId)
            ->with(['user', 'cars.body', 'registrationCars', 'guests', 'shirts.shirtType', 'extras.showExtra']);

        $registrations->addSelect([
            'waiver_has_signed' => DB::table('waiver')->select('has_signed')
                ->whereColumn('waiver.id', 'show_registration.waiver_id')->limit(1),
        ]);

        return AdminQuery::for($registrations, $request)
            ->sortable(['invoice_id', 'is_paid', 'arrival_date'], default: 'invoice_id');
    }

    /**
     * @return array{registrations: int, guests: int, paid_registrations: int, paid_guests: int, extras: int, paid_extras: int, tshirts: int, paid_tshirts: int}
     */
    public function totals(int $showId): array
    {
        $extras = DB::table('show_registration as sr')->join('show_extra_registration as ser', 'ser.registration_id', '=', 'sr.id')->where('sr.show_id', $showId);
        $shirts = DB::table('show_registration as sr')->join('shirt as s', 's.registration_id', '=', 'sr.id')->where('sr.show_id', $showId);
        $guests = DB::table('show_registration as sr')->join('user_guest as ug', 'ug.registration_id', '=', 'sr.id')->where('sr.show_id', $showId);

        return [
            'registrations' => ShowRegistration::query()->where('show_id', $showId)->count(),
            'guests' => UserGuest::query()->where('show_id', $showId)->count(),
            'paid_registrations' => ShowRegistration::query()->where('show_id', $showId)->where('is_paid', true)->count(),
            'paid_guests' => (clone $guests)->where('sr.is_paid', true)->count(),
            'extras' => (clone $extras)->count(),
            'paid_extras' => (clone $extras)->where('sr.is_paid', true)->count(),
            'tshirts' => (clone $shirts)->count(),
            'paid_tshirts' => (clone $shirts)->where('sr.is_paid', true)->count(),
        ];
    }

    /**
     * Largest guest count and shirt count on one registration of the event (the export's column counts).
     *
     * @return array{guests: int, shirts: int}
     */
    public function maxima(int $showId): array
    {
        $maxGuests = DB::query()->fromSub(
            DB::table('user_guest as g')->join('show_registration as r', 'r.id', '=', 'g.registration_id')
                ->where('r.show_id', $showId)->selectRaw('COUNT(*) AS c')->groupBy('g.registration_id'),
            'x',
        )->max('c');

        $maxShirts = DB::query()->fromSub(
            DB::table('shirt as s')->join('show_registration as r', 'r.id', '=', 's.registration_id')
                ->where('r.show_id', $showId)->selectRaw('COUNT(*) AS c')->groupBy('s.registration_id'),
            'y',
        )->max('c');

        return ['guests' => (int) $maxGuests, 'shirts' => (int) $maxShirts];
    }
}
