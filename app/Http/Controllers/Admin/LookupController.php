<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CarShow;
use App\Support\Admin\AdminQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Small option lists for RelationSelect: {data: [{id, label}]}, at most 20 rows, filtered by
 * ?search= (LIKE, escaped) or fetched by ?ids=1,2 (to show labels of already chosen values).
 * Add a method per lookup; keep them read-only.
 */
final class LookupController extends Controller
{
    private const LIMIT = 20;

    public function events(Request $request): JsonResponse
    {
        $query = CarShow::query()->select(['id', 'name', 'short_name', 'date_start']);

        $ids = self::ids($request);
        if ($ids !== null) {
            $query->whereKey($ids);
        } else {
            $search = self::search($request);
            if ($search !== '') {
                $pattern = '%'.AdminQuery::escapeLike($search).'%';
                $query->where(fn ($inner) => $inner->where('name', 'like', $pattern)->orWhere('short_name', 'like', $pattern));
            }
        }

        $rows = $query->orderByDesc('date_start')->orderByDesc('id')->limit(self::LIMIT)->get();

        return response()->json([
            'data' => $rows->map(fn (CarShow $show) => [
                'id' => $show->id,
                'label' => $show->name.($show->date_start !== null ? ' ('.$show->date_start->format('Y').')' : ''),
                'url_name' => $show->URLName,
            ])->values(),
        ]);
    }

    private static function search(Request $request): string
    {
        $search = $request->query('search');

        return is_string($search) ? mb_substr(trim($search), 0, AdminQuery::MAX_SEARCH) : '';
    }

    /**
     * @return list<int>|null
     */
    private static function ids(Request $request): ?array
    {
        $raw = $request->query('ids');
        if (! is_string($raw) || $raw === '') {
            return null;
        }
        $ids = array_values(array_filter(array_map(
            fn (string $id) => ctype_digit($id) ? (int) $id : 0,
            array_slice(explode(',', $raw), 0, self::LIMIT),
        )));

        return $ids;
    }
}
