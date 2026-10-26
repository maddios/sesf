<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ExtraRequest;
use App\Http\Resources\Admin\ExtraResource;
use App\Models\ShowExtra;
use App\Models\ShowExtraRegistration;
use App\Support\Admin\AdminQuery;
use App\Support\Admin\AuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * admin-api/extras: paid extras of an event. An extra that somebody has registered for cannot be deleted (409):
 * it is disabled instead.
 */
final class ExtraController extends Controller
{
    private const RESOURCE = 'extra';

    public function index(Request $request): JsonResponse
    {
        return AdminQuery::for($this->query(), $request)
            ->sortable(['id', 'name', 'price', 'type_id', 'is_enabled', 'min_value', 'max_value', 'created_at', 'updated_at'], default: 'name')
            ->searchable(['name', 'auth_key'])
            ->filterable([
                'show_id' => ['eq', 'in'],
                'type_id' => ['eq', 'in'],
                'is_enabled' => ['eq'],
            ])
            ->respond(ExtraResource::class);
    }

    public function show(int $extra): ExtraResource
    {
        return new ExtraResource($this->find($extra));
    }

    public function store(ExtraRequest $request): JsonResponse
    {
        $extra = new ShowExtra;
        $extra->forceFill([
            ...$request->fields(),
            'show_id' => $request->integer('show_id'),
            'is_enabled' => $request->boolean('is_enabled'),
        ])->save();

        AuditLog::record('extra.create', self::RESOURCE, [$extra->id], AuditLog::created($extra));

        return (new ExtraResource($this->find($extra->id)))->response()->setStatusCode(201);
    }

    public function update(ExtraRequest $request, int $extra): ExtraResource
    {
        $model = $this->find($extra);
        $model->forceFill($request->fields());
        if ($request->has('is_enabled')) {
            $model->forceFill(['is_enabled' => $request->boolean('is_enabled')]);
        }
        $model->save();

        AuditLog::record('extra.update', self::RESOURCE, [$model->id], AuditLog::diff($model));

        return new ExtraResource($this->find($model->id));
    }

    /**
     * Deletes an extra nobody registered for. Registrations (show_extra_registration) are a 409 with the count.
     */
    public function destroy(int $extra): JsonResponse
    {
        $model = $this->find($extra);
        $conflict = fn (int $registrations) => response()->json([
            'message' => "This extra has {$registrations} registration(s) and cannot be deleted. Disable it instead.",
            'references' => ['registrations' => $registrations],
        ], 409);

        try {
            $registrations = DB::transaction(function () use ($model): int {
                $count = ShowExtraRegistration::query()->where('show_extra_id', $model->id)->lockForUpdate()->count();
                if ($count === 0) {
                    $model->delete();
                }

                return $count;
            });
        } catch (QueryException $e) {
            // A registration created by someone else between the check and the delete (FK violation).
            if ((string) $e->getCode() === '23000') {
                return $conflict(1);
            }
            throw $e;
        }

        if ($registrations > 0) {
            return $conflict($registrations);
        }

        AuditLog::record('extra.delete', self::RESOURCE, [$model->id]);

        return response()->json(null, 204);
    }

    /**
     * @return Builder<ShowExtra>
     */
    private function query(): Builder
    {
        return ShowExtra::query()
            ->with('carShow:id,name')
            ->withCount(['registration as registrations_count']);
    }

    private function find(int $id): ShowExtra
    {
        return $this->query()->find($id) ?? abort(404, 'Extra not found.');
    }
}
