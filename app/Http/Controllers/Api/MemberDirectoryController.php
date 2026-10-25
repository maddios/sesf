<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Members\MemberDirectoryResource;
use App\Models\CarShow;
use App\Models\User;
use App\Support\Privacy\ViewerPrivacy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * GET members/list-filters and GET members/users (legacy MemberList).
 */
class MemberDirectoryController extends Controller
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

        return response()->json([
            [
                'type' => 'dropdown',
                'name' => 'show_id',
                'label' => 'Attended Show',
                'data' => $shows,
                'value' => $request->query('show_id'),
            ],
            [
                'type' => 'textbox',
                'name' => 'name',
                'label' => 'Member Name',
                'value' => $request->query('name'),
            ],
        ]);
    }

    public function users(Request $request): JsonResponse
    {
        $privacy = new ViewerPrivacy($request->user());
        $limit = $this->limit($request);
        $showId = filter_var($request->query('show_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $name = trim((string) $request->query('name', ''));

        $query = User::query();

        if ($name !== '') {
            $like = '%'.addcslashes($name, '%_\\').'%';
            $query->where(function ($q) use ($like, $privacy): void {
                $q->where('user.first', 'LIKE', $like);

                // Legacy: last names are searched for logged-in viewers only.
                if ($privacy->isMember()) {
                    $q->orWhere('user.last', 'LIKE', $like);
                }
            });
        }

        if ($showId !== false) {
            $query->whereIn('user.id', DB::table('show_registration')
                ->where('show_id', $showId)
                ->select('user_id'));

            $query->orderByRaw(
                '(SELECT MIN(sr.invoice_id) FROM show_registration AS sr WHERE sr.user_id = user.id AND sr.show_id = ?) ASC',
                [$showId],
            );
        } else {
            $query->leftJoin('show_registration as sr', 'user.id', '=', 'sr.user_id')
                ->select('user.*', DB::raw('COUNT(sr.id) AS registrations_count'))
                ->groupBy('user.id')
                ->orderBy('user.has_image', 'desc')
                ->orderBy('registrations_count', 'desc')
                ->orderBy('user.created_at', 'desc');
        }

        $page = $query->paginate($limit);

        return response()->json([
            'last' => $page->lastPage(),
            'page' => $page->currentPage(),
            'perPage' => $page->perPage(),
            'data' => $page->getCollection()
                ->map(fn (User $user) => (new MemberDirectoryResource($user, $privacy))->resolve($request))
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
