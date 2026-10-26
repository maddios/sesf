<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CarShow;
use App\Models\ShowRegistration;
use App\Models\ShowRegistrationCar;
use App\Models\Sponsor;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * GET admin-api/dashboard: the current event and a few counts for the admin home page.
 *
 * Current event: the active show (is_active) that has not ended yet with the earliest start date;
 * when none, the show with the latest start date. Counts for the event exclude cancelled
 * registrations; unpaid = not paid and not cancelled.
 */
final class DashboardController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $event = $this->currentEvent();

        $eventCounts = null;
        if ($event !== null) {
            $registrations = ShowRegistration::query()->where('show_id', $event->id)->where('is_cancelled', false);
            $eventCounts = [
                'registrations' => (clone $registrations)->count(),
                'unpaid' => (clone $registrations)->where('is_paid', false)->count(),
                'checked_in' => (clone $registrations)->where('is_checked_in', true)->count(),
                'cars' => ShowRegistrationCar::query()
                    ->whereIn('registration_id', (clone $registrations)->select('id'))
                    ->count(),
            ];
        }

        return response()->json([
            'event' => $event === null ? null : [
                'id' => $event->id,
                'name' => $event->name,
                'short_name' => $event->short_name,
                'date_start' => $event->date_start?->toDateString(),
                'date_end' => $event->date_end?->toDateString(),
                'is_active' => (bool) $event->is_active,
                'reg_open' => $event->regOpen,
            ],
            'event_counts' => $eventCounts,
            'totals' => [
                'members' => User::query()->count(),
                'events' => CarShow::query()->count(),
                'sponsors_enabled' => Sponsor::query()->where('is_enabled', true)->count(),
            ],
        ]);
    }

    private function currentEvent(): ?CarShow
    {
        return CarShow::query()
            ->where('is_active', true)
            ->whereDate('date_end', '>=', now()->toDateString())
            ->orderBy('date_start')
            ->orderBy('id')
            ->first()
            ?? CarShow::query()->orderByDesc('date_start')->orderByDesc('id')->first();
    }
}
