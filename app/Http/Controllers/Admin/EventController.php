<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminImageRequest;
use App\Http\Requests\Admin\EventRequest;
use App\Http\Requests\Admin\SchedulePdfRequest;
use App\Http\Resources\Admin\EventResource;
use App\Models\CarShow;
use App\Services\CloneShowConfiguration;
use App\Services\Events\SchedulePdfRejected;
use App\Services\Events\SchedulePdfStore;
use App\Services\Images\ImageStore;
use App\Services\Images\ImageUploadException;
use App\Support\Admin\AdminQuery;
use App\Support\Admin\AuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * admin-api/events: events (car_show). Replaces the frozennode carShow.php config.
 * Creating an event can copy the car body and judging setup from a previous event (CloneShowConfiguration).
 */
final class EventController extends Controller
{
    private const RESOURCE = 'event';

    /** Tables whose rows keep an event from being deleted (show_id, or car_show_id for judging_show_body). */
    private const DEPENDENTS = [
        'show_registration' => 'show_id',
        'show_sponsor' => 'show_id',
        'car_show_judge' => 'show_id',
        'car_body_show' => 'show_id',
        'judging_show_body' => 'car_show_id',
        'show_extra' => 'show_id',
        'show_agenda' => 'show_id',
        'gallery' => 'show_id',
        'shirt_adhoc_sale' => 'show_id',
        'user_guest' => 'show_id',
    ];

    public function index(Request $request): JsonResponse
    {
        return AdminQuery::for($this->query(), $request)
            ->sortable(['id', 'name', 'short_name', 'date_start', 'date_end', 'is_active', 'created_at', 'updated_at'], default: 'date_start', direction: 'desc')
            ->searchable(['name', 'short_name'])
            ->filterable([
                'is_active' => ['eq'],
                'id' => ['eq', 'in'],
                'name' => ['eq', 'like'],
                'date_start' => ['between', 'null'],
            ])
            ->respond(EventResource::class);
    }

    public function show(int $event): EventResource
    {
        return new EventResource($this->find($event));
    }

    /**
     * Events a new event can copy its setup from, newest first (id and name).
     */
    public function cloneSources(CloneShowConfiguration $cloner): JsonResponse
    {
        return response()->json([
            'data' => $cloner->candidateSources()->map(fn (CarShow $show) => [
                'id' => (int) $show->id,
                'label' => $show->name,
            ])->values(),
        ]);
    }

    public function store(EventRequest $request, CloneShowConfiguration $cloner): JsonResponse
    {
        $cloneFrom = $request->integer('clone_from_show_id') ?: null;

        try {
            $event = DB::transaction(function () use ($request, $cloner, $cloneFrom): CarShow {
                $event = new CarShow;
                $event->forceFill($request->fields() + ['is_active' => false])->save();

                if ($cloneFrom !== null) {
                    $cloner->copy($event, CarShow::query()->findOrFail($cloneFrom));
                }

                return $event;
            });
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['clone_from_show_id' => [$e->getMessage()]]], 422);
        }

        AuditLog::record('event.create', self::RESOURCE, [$event->id], AuditLog::created($event->refresh()), $cloneFrom === null ? [] : ['cloned_from_id' => $cloneFrom]);

        return (new EventResource($this->find($event->id)))->response()->setStatusCode(201);
    }

    public function update(EventRequest $request, int $event): EventResource
    {
        $model = $this->find($event);
        $model->forceFill($request->fields())->save();

        AuditLog::record('event.update', self::RESOURCE, [$model->id], AuditLog::diff($model));

        return new EventResource($this->find($model->id));
    }

    public function activate(int $event): EventResource
    {
        return $this->setActive($event, true);
    }

    public function deactivate(int $event): EventResource
    {
        return $this->setActive($event, false);
    }

    /**
     * POST admin-api/events/{id}/image (multipart "file"): the event image at images/shows/{id}.jpg (legacy
     * car-show-image-upload), re-encoded by ImageStore.
     */
    public function image(AdminImageRequest $request, int $event, ImageStore $images): JsonResponse
    {
        $model = $this->find($event);

        try {
            $images->storeAs($request->upload(), (string) config('sesf.show_image_root'), $model->id.'.jpg');
        } catch (ImageUploadException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['file' => [$e->getMessage()]]], 422);
        }

        $model->forceFill(['has_image' => true])->save();
        AuditLog::record('event.image', self::RESOURCE, [$model->id], ['image' => AuditLog::REDACTED]);

        return (new EventResource($this->find($model->id)))->response();
    }

    /**
     * POST admin-api/events/{id}/schedule-pdf (multipart "file"): the schedule at packet/{id}.pdf (legacy
     * car-show-schedule-upload); turns is_schedule_pdf on.
     */
    public function schedulePdf(SchedulePdfRequest $request, int $event, SchedulePdfStore $store): JsonResponse
    {
        $model = $this->find($event);

        try {
            $store->store($request->upload(), $model->id);
        } catch (SchedulePdfRejected $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['file' => [$e->getMessage()]]], 422);
        }

        $model->forceFill(['is_schedule_pdf' => true])->save();
        AuditLog::record('event.schedule_pdf', self::RESOURCE, [$model->id], AuditLog::diff($model) + ['file' => AuditLog::REDACTED]);

        return (new EventResource($this->find($model->id)))->response();
    }

    /**
     * Deletes an event nobody is attached to. Registrations, sponsors, judges, bodies, extras and the other
     * DEPENDENTS are a 409 with the counts (the admin should deactivate the event instead).
     */
    public function destroy(int $event): JsonResponse
    {
        $model = $this->find($event);

        try {
            $references = DB::transaction(function () use ($model): array {
                $counts = [];
                foreach (self::DEPENDENTS as $table => $column) {
                    $count = DB::table($table)->where($column, $model->id)->lockForUpdate()->count();
                    if ($count > 0) {
                        $counts[$table] = $count;
                    }
                }
                if ($counts !== []) {
                    return $counts;
                }
                $model->delete();

                return [];
            });
        } catch (QueryException $e) {
            // A row created by someone else between the check and the delete (FK violation).
            if ((string) $e->getCode() === '23000') {
                return $this->conflict(['linked' => 1]);
            }
            throw $e;
        }

        if ($references !== []) {
            return $this->conflict($references);
        }

        AuditLog::record('event.delete', self::RESOURCE, [$model->id], AuditLog::deleted($model));

        return response()->json(null, 204);
    }

    /**
     * @param  array<string, int>  $references
     */
    private function conflict(array $references): JsonResponse
    {
        return response()->json([
            'message' => 'This event has linked records ('.implode(', ', array_keys($references)).') and cannot be deleted. Deactivate it instead.',
            'references' => $references,
        ], 409);
    }

    private function setActive(int $id, bool $active): EventResource
    {
        $model = $this->find($id);
        $model->forceFill(['is_active' => $active])->save();

        AuditLog::record($active ? 'event.activate' : 'event.deactivate', self::RESOURCE, [$model->id], AuditLog::diff($model));

        return new EventResource($this->find($model->id));
    }

    /**
     * @return Builder<CarShow>
     */
    private function query(): Builder
    {
        return CarShow::query()->withCount('registrations');
    }

    private function find(int $id): CarShow
    {
        return $this->query()->find($id) ?? abort(404, 'Event not found.');
    }
}
