<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Events\EventListShowResource;
use App\Http\Resources\Events\ListFilterShowResource;
use App\Models\CarShow;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Car-show list pages. Legacy: CarShowList::getListFilters and getCarShows.
 */
class EventListController extends Controller
{
    /**
     * GET events/list-filters. The filter options for "attended show".
     */
    public function listFilters(): JsonResponse
    {
        $shows = CarShow::query()
            ->hasUsers()
            ->orderByDesc('car_show.date_start')
            ->get();

        return response()->json([
            [
                'name' => 'show_id',
                'label' => 'Attended Show',
                'data' => ListFilterShowResource::collection($shows)->resolve(),
            ],
        ]);
    }

    /**
     * GET events/car-shows?is_active=1|0. Active shows have date_end on or after today, the others are past.
     * Each show carries its primary location and its registration counts.
     */
    public function carShows(Request $request): JsonResponse
    {
        $request->validate(['is_active' => ['nullable', 'boolean']]);

        $active = $request->boolean('is_active');

        $shows = CarShow::query()
            ->where('is_active', true)
            ->where('date_end', $active ? '>=' : '<', now())
            ->with('primaryLocation')
            ->withCount(['registrations as num_registered_members' => fn ($query) => $query->where('is_cancelled', false)])
            ->addSelect(['num_registered_cars' => $this->registeredCarsSubquery()])
            ->orderByDesc('date_start')
            ->get();

        return response()->json(EventListShowResource::collection($shows)->resolve());
    }

    /**
     * Distinct cars entered in a show, per show. Same count as legacy Car::whereShowId().
     */
    private function registeredCarsSubquery(): QueryBuilder
    {
        return DB::table('show_registration')
            ->join('show_registration_car', 'show_registration_car.registration_id', '=', 'show_registration.id')
            ->whereColumn('show_registration.show_id', 'car_show.id')
            ->selectRaw('COUNT(DISTINCT show_registration_car.car_id)');
    }
}
