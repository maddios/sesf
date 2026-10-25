<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Cars\CarDirectoryResource;
use App\Models\Car;
use App\Models\CarBody;
use App\Models\CarShow;
use App\Models\User;
use App\Models\UserPreferences;
use App\Support\Privacy\ViewerPrivacy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * GET cars/list-filters and GET cars/list (legacy CarList).
 *
 * Only cars that have been entered in a show are listed (legacy inner join). Cars whose owner is
 * soft-deleted, and cars whose owner set show_car_on_attendees_page = false, are left out.
 * Sold cars (no owner) stay listed.
 */
class CarDirectoryController extends Controller
{
    private const DEFAULT_LIMIT = 15;

    private const MAX_LIMIT = 100;

    public function listFilters(Request $request): JsonResponse
    {
        $shows = CarShow::hasUsers()
            ->select('car_show.id', 'car_show.name')
            ->orderBy('car_show.id', 'desc')
            ->get()
            ->map(fn (CarShow $show) => ['id' => (int) $show->id, 'name' => $show->name])
            ->values();

        $bodies = CarBody::query()
            ->orderBy('id')
            ->get()
            ->map(fn (CarBody $body) => ['id' => (int) $body->id, 'name' => $body->name])
            ->values();

        return response()->json([
            [
                'type' => 'dropdown',
                'name' => 'show_id',
                'label' => 'Attended Show',
                'data' => $shows,
                'value' => $request->query('show_id'),
            ],
            [
                'type' => 'dropdown',
                'name' => 'body_id',
                'label' => 'Body',
                'data' => $bodies,
                'value' => $request->query('body_id'),
            ],
        ]);
    }

    public function list(Request $request): JsonResponse
    {
        $privacy = new ViewerPrivacy($request->user());
        $limit = $this->limit($request);
        $showId = filter_var($request->query('show_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $bodyId = filter_var($request->query('body_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        $query = Car::query()
            ->join('show_registration_car as src', 'car.id', '=', 'src.car_id')
            ->join('show_registration as sr', 'sr.id', '=', 'src.registration_id')
            ->select('car.*', DB::raw('COUNT(src.id) AS registrations_count'))
            ->groupBy('car.id')
            ->with('user')
            ->where(function ($q): void {
                $q->whereNull('car.user_id')
                    ->orWhereIn('car.user_id', User::query()
                        ->select('user.id')
                        ->whereNotIn('user.id', UserPreferences::query()
                            ->where('show_car_on_attendees_page', 0)
                            ->select('user_id')));
            });

        if ($bodyId !== false) {
            $query->where('car.body_id', $bodyId);
        }

        if ($showId !== false) {
            $query->where('sr.show_id', $showId)
                ->orderByRaw('MIN(sr.invoice_id) ASC');
        } else {
            $query->orderBy('registrations_count', 'desc')
                ->orderBy('car.created_at', 'desc');
        }

        $page = $query->paginate($limit);

        return response()->json([
            'last' => $page->lastPage(),
            'page' => $page->currentPage(),
            'perPage' => $page->perPage(),
            'data' => $page->getCollection()
                ->map(fn (Car $car) => (new CarDirectoryResource($car, $privacy))->resolve($request))
                ->values()
                ->all(),
            'total' => $page->total(),
        ]);
    }

    private function limit(Request $request): int
    {
        $limit = filter_var($request->query('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $limit === false ? self::DEFAULT_LIMIT : min($limit, self::MAX_LIMIT);
    }
}
