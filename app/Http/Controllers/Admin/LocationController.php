<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LocationRequest;
use App\Http\Requests\Admin\ReorderShowItemsRequest;
use App\Http\Resources\Admin\LocationResource;
use App\Models\CarShowLocation;
use App\Services\Admin\ShowItemOrder;
use App\Support\Admin\AdminQuery;
use App\Support\Admin\AuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * admin-api/locations: venues of a show. Position is per event: reorder sends one event's ids in the new order.
 */
final class LocationController extends Controller
{
    private const RESOURCE = 'location';

    public function index(Request $request): JsonResponse
    {
        return AdminQuery::for($this->query(), $request)
            ->sortable(['id', 'name', 'sort_order', 'city', 'is_primary', 'created_at', 'updated_at'], default: 'sort_order')
            ->searchable(['name', 'address1', 'city'])
            ->filterable([
                'show_id' => ['eq', 'in'],
                'is_primary' => ['eq'],
            ])
            ->respond(LocationResource::class);
    }

    public function show(int $location): LocationResource
    {
        return new LocationResource($this->find($location));
    }

    public function store(LocationRequest $request): JsonResponse
    {
        $showId = $request->integer('show_id');
        $location = new CarShowLocation([...$request->fields(), 'show_id' => $showId]);
        $location->forceFill([
            'is_primary' => $request->boolean('is_primary'),
            'sort_order' => $this->nextSortOrder($showId),
        ])->save();

        AuditLog::record('location.create', self::RESOURCE, [$location->id], AuditLog::created($location));

        return (new LocationResource($this->find($location->id)))->response()->setStatusCode(201);
    }

    public function update(LocationRequest $request, int $location): LocationResource
    {
        $model = $this->find($location);
        $model->fill($request->fields());
        if ($request->has('is_primary')) {
            $model->forceFill(['is_primary' => $request->boolean('is_primary')]);
        }
        $model->save();

        AuditLog::record('location.update', self::RESOURCE, [$model->id], AuditLog::diff($model));

        return new LocationResource($this->find($model->id));
    }

    public function destroy(int $location): JsonResponse
    {
        $model = $this->find($location);
        $model->delete();

        AuditLog::record('location.delete', self::RESOURCE, [$model->id]);

        return response()->json(null, 204);
    }

    /**
     * POST admin-api/locations/reorder: {show_id, ids} with every location of the event, in the new order.
     */
    public function reorder(ReorderShowItemsRequest $request): JsonResponse
    {
        $showId = $request->showId();
        $ids = $request->ids();
        ShowItemOrder::apply(CarShowLocation::query()->where('show_id', $showId), $ids);

        AuditLog::record('location.reorder', self::RESOURCE, $ids, [], ['show_id' => $showId]);

        return response()->json(['data' => ['show_id' => $showId, 'ids' => $ids]]);
    }

    private function nextSortOrder(int $showId): int
    {
        return (int) CarShowLocation::query()->where('show_id', $showId)->max('sort_order') + 1;
    }

    /**
     * @return Builder<CarShowLocation>
     */
    private function query(): Builder
    {
        return CarShowLocation::query()->with('carShow:id,name');
    }

    private function find(int $id): CarShowLocation
    {
        return $this->query()->find($id) ?? abort(404, 'Location not found.');
    }
}
