<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ExtraRegistrationRequest;
use App\Http\Resources\Admin\ExtraRegistrationResource;
use App\Models\ShowExtraRegistration;
use App\Support\Admin\AdminQuery;
use App\Support\Admin\AuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * admin-api/extra-registrations: extras bought on registrations. Edit and delete only; a purchase is made by the
 * member. The event filter (show_id) goes through the extra's event.
 */
final class ExtraRegistrationController extends Controller
{
    private const RESOURCE = 'extra_registration';

    public function index(Request $request): JsonResponse
    {
        return AdminQuery::for($this->query(), $request)
            ->sortable(['id', 'num_extras', 'created_at', 'updated_at'], default: 'created_at', direction: 'desc')
            ->filterable([
                'show_extra_id' => ['eq', 'in'],
                'registration_id' => ['eq'],
                'num_extras' => ['eq'],
            ])
            // Legacy filter: the registrations for the extras of one event.
            ->filterUsing('show_id', function (Builder $query, string $value): void {
                $query->whereHas('showExtra', fn (Builder $extras) => $extras->where('show_id', ctype_digit($value) ? (int) $value : 0));
            })
            ->respond(ExtraRegistrationResource::class);
    }

    public function show(int $extraRegistration): ExtraRegistrationResource
    {
        return new ExtraRegistrationResource($this->find($extraRegistration));
    }

    public function update(ExtraRegistrationRequest $request, int $extraRegistration): ExtraRegistrationResource
    {
        $model = $this->find($extraRegistration);
        $model->fill($request->fields())->save();

        AuditLog::record('extra_registration.update', self::RESOURCE, [$model->id], AuditLog::diff($model));

        return new ExtraRegistrationResource($this->find($model->id));
    }

    public function destroy(int $extraRegistration): JsonResponse
    {
        $model = $this->find($extraRegistration);
        $model->delete();

        AuditLog::record('extra_registration.delete', self::RESOURCE, [$model->id]);

        return response()->json(null, 204);
    }

    /**
     * @return Builder<ShowExtraRegistration>
     */
    private function query(): Builder
    {
        return ShowExtraRegistration::query()->with([
            'registration.user:id,first,last',
            'showExtra:id,name,show_id',
            'showExtra.carShow:id,name',
        ]);
    }

    private function find(int $id): ShowExtraRegistration
    {
        return $this->query()->find($id) ?? abort(404, 'Extra registration not found.');
    }
}
