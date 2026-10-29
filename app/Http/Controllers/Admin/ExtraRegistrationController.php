<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ExtraRegistrationRequest;
use App\Http\Requests\Admin\ExtraRegistrationStoreRequest;
use App\Http\Requests\Registration\RegistrationErrors;
use App\Http\Resources\Admin\ExtraRegistrationResource;
use App\Models\ShowExtra;
use App\Models\ShowExtraRegistration;
use App\Models\ShowRegistration;
use App\Services\Registration\RegistrationWriter;
use App\Services\Registration\Writer\RegistrationRejected;
use App\Support\Admin\AdminQuery;
use App\Support\Admin\AuditLog;
use App\Support\Admin\ChangeSet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * admin-api/extra-registrations: extras bought on registrations. Create, edit and delete. The event filter
 * (show_id) goes through the extra's event. Writes go through RegistrationWriter::saveExtra as an administrator (cancelled registrations included; min/max of the
 * extra are not enforced, as in legacy).
 */
final class ExtraRegistrationController extends Controller
{
    private const RESOURCE = 'extra_registration';

    public function __construct(private readonly RegistrationWriter $writer) {}

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

    /**
     * Adds a purchase through the registration writer (amounts follow on open shows). An extra already on the
     * registration is a 409: edit its quantity instead.
     */
    public function store(ExtraRegistrationStoreRequest $request): JsonResponse
    {
        $registration = $request->registration();
        $extra = $request->extra();
        $exists = ShowExtraRegistration::query()->where('registration_id', $registration->id)->where('show_extra_id', $extra->id)->exists();
        if ($exists) {
            return response()->json(['message' => 'This registration already has that extra. Change its quantity instead.'], 409);
        }

        $quantity = $request->integer('num_extras');
        $failure = $this->write($registration, $extra, $quantity);
        if ($failure !== null) {
            return $failure;
        }

        $row = ShowExtraRegistration::query()->where('registration_id', $registration->id)->where('show_extra_id', $extra->id)->firstOrFail();
        AuditLog::record('extra_registration.create', self::RESOURCE, [$row->id], ChangeSet::between([], ['num_extras' => $quantity]), ['registration_id' => $registration->id, 'show_extra_id' => $extra->id]);

        return (new ExtraRegistrationResource($this->find($row->id)))->response()->setStatusCode(201);
    }

    /**
     * Sets the quantity through the registration writer (0 removes the purchase: 204), so amount_owed
     * and is_paid follow on open shows.
     */
    public function update(ExtraRegistrationRequest $request, int $extraRegistration): ExtraRegistrationResource|JsonResponse
    {
        $model = $this->find($extraRegistration);
        $quantity = $request->fields()['num_extras'];
        $extraId = $request->extraId() ?? (int) $model->show_extra_id;
        $before = ['show_extra_id' => (int) $model->show_extra_id, 'num_extras' => $model->num_extras === null ? null : (int) $model->num_extras];

        $failure = $extraId === (int) $model->show_extra_id
            ? $this->writeRow($model, $quantity)
            : $this->changeExtra($model, $extraId, $quantity);
        if ($failure !== null) {
            return $failure;
        }

        AuditLog::record('extra_registration.update', self::RESOURCE, [$model->id], ChangeSet::between($before, ['show_extra_id' => $extraId, 'num_extras' => $quantity]));

        if ($quantity === 0) {
            return response()->json(null, 204);
        }

        $row = ShowExtraRegistration::query()->where('registration_id', $model->registration_id)->where('show_extra_id', $extraId)->firstOrFail();

        return new ExtraRegistrationResource($this->find($row->id));
    }

    /**
     * Moves the purchase to another extra of the same event: the old extra is removed and the new one set, in one
     * transaction, so the amounts are repriced once for the final state.
     */
    private function changeExtra(ShowExtraRegistration $model, int $extraId, int $quantity): ?JsonResponse
    {
        $registration = $model->registration;
        $extra = ShowExtra::query()->find($extraId);
        if ($registration === null || $extra === null || (int) $extra->show_id !== (int) $registration->show_id) {
            return response()->json(['message' => 'The extra must belong to the registration\'s event.', 'errors' => ['show_extra_id' => ['The extra must belong to the registration\'s event.']]], 422);
        }
        if (ShowExtraRegistration::query()->where('registration_id', $registration->id)->where('show_extra_id', $extraId)->exists()) {
            return response()->json(['message' => 'This registration already has that extra. Change its quantity instead.'], 409);
        }

        $user = $registration->user;
        $current = $model->showExtra;
        if ($user === null || $current === null) {
            return response()->json(['message' => 'This purchase cannot be changed here (member disabled or extra missing).'], 409);
        }

        try {
            DB::transaction(function () use ($user, $current, $extra, $quantity): void {
                $this->writer->saveExtra($user, $current, 0, byAdmin: true);
                $this->writer->saveExtra($user, $extra, $quantity, byAdmin: true);
            });
        } catch (RegistrationRejected $e) {
            return RegistrationErrors::adminResponse($e);
        }

        return null;
    }

    public function destroy(int $extraRegistration): JsonResponse
    {
        $model = $this->find($extraRegistration);

        $failure = $this->writeRow($model, 0);
        if ($failure !== null) {
            return $failure;
        }

        AuditLog::record('extra_registration.delete', self::RESOURCE, [$model->id]);

        return response()->json(null, 204);
    }

    /** The error response, or null when the quantity was written. */
    private function writeRow(ShowExtraRegistration $model, int $quantity): ?JsonResponse
    {
        $registration = $model->registration;
        $extra = $model->showExtra;

        if ($registration === null || $extra === null || (int) $registration->show_id !== (int) $extra->show_id) {
            return response()->json(['message' => 'This purchase does not match its registration\'s event and cannot be changed here.'], 409);
        }

        return $this->write($registration, $extra, $quantity);
    }

    private function write(ShowRegistration $registration, ShowExtra $extra, int $quantity): ?JsonResponse
    {
        $user = $registration->user;
        if ($user === null) {
            return response()->json(['message' => 'The member of this registration is disabled. Restore the account first.'], 409);
        }

        try {
            $this->writer->saveExtra($user, $extra, $quantity, byAdmin: true);
        } catch (RegistrationRejected $e) {
            return RegistrationErrors::adminResponse($e);
        }

        return null;
    }

    /**
     * @return Builder<ShowExtraRegistration>
     */
    private function query(): Builder
    {
        return ShowExtraRegistration::query()->with([
            'registration:id,show_id,user_id',
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
