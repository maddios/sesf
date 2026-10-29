<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AgendaRequest;
use App\Http\Requests\Admin\ReorderShowItemsRequest;
use App\Http\Resources\Admin\AgendaResource;
use App\Models\CarShowAgenda;
use App\Services\Admin\ShowItemOrder;
use App\Support\Admin\AdminQuery;
use App\Support\Admin\AuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * admin-api/agenda: show agenda items. Position is per event: reorder sends one event's ids in the new order.
 */
final class AgendaController extends Controller
{
    private const RESOURCE = 'agenda';

    public function index(Request $request): JsonResponse
    {
        return AdminQuery::for($this->query(), $request)
            ->sortable(['id', 'name', 'sort_order', 'type_id', 'is_visible', 'created_at', 'updated_at'], default: 'sort_order')
            ->searchable(['name'])
            ->filterable([
                'show_id' => ['eq', 'in'],
                'type_id' => ['eq', 'in'],
                'is_visible' => ['eq'],
            ])
            ->respond(AgendaResource::class);
    }

    public function show(int $agenda): AgendaResource
    {
        return new AgendaResource($this->find($agenda));
    }

    public function store(AgendaRequest $request): JsonResponse
    {
        $showId = $request->integer('show_id');
        $item = new CarShowAgenda([...$request->fields(), 'show_id' => $showId]);
        $item->forceFill([
            'is_visible' => $request->boolean('is_visible'),
            'sort_order' => $this->nextSortOrder($showId),
        ])->save();

        AuditLog::record('agenda.create', self::RESOURCE, [$item->id], AuditLog::created($item));

        return (new AgendaResource($this->find($item->id)))->response()->setStatusCode(201);
    }

    public function update(AgendaRequest $request, int $agenda): AgendaResource
    {
        $model = $this->find($agenda);
        $model->fill($request->fields());
        if ($request->has('is_visible')) {
            $model->forceFill(['is_visible' => $request->boolean('is_visible')]);
        }
        $model->save();

        AuditLog::record('agenda.update', self::RESOURCE, [$model->id], AuditLog::diff($model));

        return new AgendaResource($this->find($model->id));
    }

    public function destroy(int $agenda): JsonResponse
    {
        $model = $this->find($agenda);
        $model->delete();

        AuditLog::record('agenda.delete', self::RESOURCE, [$model->id], AuditLog::deleted($model));

        return response()->json(null, 204);
    }

    /**
     * POST admin-api/agenda/reorder: {show_id, ids} with every item of the event, in the new order.
     */
    public function reorder(ReorderShowItemsRequest $request): JsonResponse
    {
        $showId = $request->showId();
        $ids = $request->ids();
        ShowItemOrder::apply(CarShowAgenda::query()->where('show_id', $showId), $ids);

        AuditLog::record('agenda.reorder', self::RESOURCE, $ids, [], ['show_id' => $showId]);

        return response()->json(['data' => ['show_id' => $showId, 'ids' => $ids]]);
    }

    private function nextSortOrder(int $showId): int
    {
        return (int) CarShowAgenda::query()->where('show_id', $showId)->max('sort_order') + 1;
    }

    /**
     * @return Builder<CarShowAgenda>
     */
    private function query(): Builder
    {
        return CarShowAgenda::query()->with('show:id,name');
    }

    private function find(int $id): CarShowAgenda
    {
        return $this->query()->find($id) ?? abort(404, 'Agenda item not found.');
    }
}
