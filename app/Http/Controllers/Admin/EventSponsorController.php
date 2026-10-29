<?php

namespace App\Http\Controllers\Admin;

use App\Enums\SponsorType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\EventPrimarySponsorRequest;
use App\Http\Requests\Admin\EventSponsorOrderRequest;
use App\Http\Requests\Admin\EventSponsorRequest;
use App\Http\Requests\Admin\EventSponsorTypeRequest;
use App\Http\Resources\Admin\EventSponsorResource;
use App\Models\CarShow;
use App\Models\ShowSponsor;
use App\Models\Sponsor;
use App\Services\Admin\ShowItemOrder;
use App\Support\Admin\AdminQuery;
use App\Support\Admin\AuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * admin-api/events/{id}/sponsors: the sponsors of one event (show_sponsor), their order, and the event's primary
 * sponsor (car_show.primary_sponsor_id, which must be one of the event's sponsors). Every write is scoped to the
 * event: reorder never touches another event's rows.
 */
final class EventSponsorController extends Controller
{
    private const RESOURCE = 'event_sponsor';

    private const CANDIDATE_LIMIT = 20;

    public function index(int $event): JsonResponse
    {
        $show = $this->findEvent($event);

        return response()->json([
            'data' => EventSponsorResource::collection($this->links($show)->with('sponsor')->get())->resolve(),
            'primary_sponsor_id' => $show->primary_sponsor_id === null ? null : (int) $show->primary_sponsor_id,
        ]);
    }

    /**
     * Enabled or not, any sponsor not yet attached to this event (for the add picker): {data: [{id, label}]}.
     */
    public function candidates(Request $request, int $event): JsonResponse
    {
        $show = $this->findEvent($event);
        $search = is_string($request->query('search')) ? mb_substr(trim((string) $request->query('search')), 0, AdminQuery::MAX_SEARCH) : '';

        $rows = Sponsor::query()
            ->whereNotIn('id', $this->links($show)->select('sponsor_id'))
            ->when($search !== '', fn ($query) => $query->where('name', 'like', '%'.AdminQuery::escapeLike($search).'%'))
            ->orderBy('name')
            ->limit(self::CANDIDATE_LIMIT)
            ->get(['id', 'name']);

        return response()->json([
            'data' => $rows->map(fn (Sponsor $sponsor) => ['id' => (int) $sponsor->id, 'label' => $sponsor->name])->values(),
        ]);
    }

    public function store(EventSponsorRequest $request, int $event): JsonResponse
    {
        $show = $this->findEvent($event);
        $sponsorId = $request->integer('sponsor_id');

        if ($this->links($show)->where('sponsor_id', $sponsorId)->exists()) {
            return $this->alreadyLinked();
        }

        try {
            $link = new ShowSponsor;
            $link->forceFill(['show_id' => $show->id, 'sponsor_id' => $sponsorId, 'type_id' => $request->enum('type_id', SponsorType::class)])->save();
        } catch (QueryException $e) {
            // Added by someone else between the check and the insert (unique show_id + sponsor_id).
            if ((string) $e->getCode() === '23000') {
                return $this->alreadyLinked();
            }
            throw $e;
        }

        AuditLog::record('event_sponsor.add', self::RESOURCE, [$link->id], [], ['sponsor_id' => $sponsorId, 'event_id' => $show->id, 'type_id' => $link->type_id?->value]);

        return (new EventSponsorResource($link->load('sponsor')))->response()->setStatusCode(201);
    }

    /**
     * PUT admin-api/events/{id}/sponsors/order with {ids}: the event's link ids in their new order (positions 1..n).
     */
    public function reorder(EventSponsorOrderRequest $request, int $event): JsonResponse
    {
        $show = $this->findEvent($event);
        $ids = array_map(intval(...), $request->array('ids'));

        /** @var Builder<Model> $items */
        $items = $this->links($show);
        ShowItemOrder::apply($items, $ids);

        AuditLog::record('event_sponsor.reorder', self::RESOURCE, $ids, [], ['event_id' => $show->id]);

        return $this->index($show->id);
    }

    /**
     * PUT admin-api/events/{id}/sponsors/{link} with {type_id}: the tier of one of the event's sponsors (or null).
     */
    public function update(EventSponsorTypeRequest $request, int $event, int $link): JsonResponse
    {
        $show = $this->findEvent($event);
        /** @var ShowSponsor $model */
        $model = $this->links($show)->whereKey($link)->first() ?? abort(404, 'Sponsor link not found.');
        $model->forceFill(['type_id' => $request->enum('type_id', SponsorType::class)])->save();

        AuditLog::record('event_sponsor.type', self::RESOURCE, [$model->id], [], ['sponsor_id' => $model->sponsor_id, 'event_id' => $show->id, 'type_id' => $model->type_id?->value]);

        return (new EventSponsorResource($model->load('sponsor')))->response();
    }

    public function destroy(int $event, int $link): JsonResponse
    {
        $show = $this->findEvent($event);
        /** @var ShowSponsor $model */
        $model = $this->links($show)->whereKey($link)->first() ?? abort(404, 'Sponsor link not found.');

        DB::transaction(function () use ($show, $model): void {
            $locked = CarShow::query()->lockForUpdate()->findOrFail($show->id);
            if ((int) $locked->primary_sponsor_id === (int) $model->sponsor_id) {
                $locked->forceFill(['primary_sponsor_id' => null])->save();
            }
            $model->delete();
        });

        AuditLog::record('event_sponsor.remove', self::RESOURCE, [$model->id], [], ['sponsor_id' => $model->sponsor_id, 'event_id' => $show->id]);

        return response()->json(null, 204);
    }

    /**
     * PUT admin-api/events/{id}/primary-sponsor with {sponsor_id}: one of the event's sponsors, or null.
     */
    public function primary(EventPrimarySponsorRequest $request, int $event): JsonResponse
    {
        $show = $this->findEvent($event);
        $sponsorId = $request->input('sponsor_id') === null ? null : $request->integer('sponsor_id');

        if ($sponsorId !== null && ! $this->links($show)->where('sponsor_id', $sponsorId)->exists()) {
            return response()->json([
                'message' => 'The sponsor must be attached to this event first.',
                'errors' => ['sponsor_id' => ['The sponsor must be attached to this event first.']],
            ], 422);
        }

        $show->forceFill(['primary_sponsor_id' => $sponsorId])->save();

        AuditLog::record('event.primary_sponsor', 'event', [$show->id], AuditLog::diff($show));

        return response()->json(['data' => ['primary_sponsor_id' => $sponsorId]]);
    }

    private function alreadyLinked(): JsonResponse
    {
        return response()->json(['message' => 'This sponsor is already attached to this event.'], 409);
    }

    /**
     * @return Builder<ShowSponsor>
     */
    private function links(CarShow $show): Builder
    {
        return ShowSponsor::query()->where('show_id', $show->id)->orderBy('sort_order')->orderBy('id');
    }

    private function findEvent(int $id): CarShow
    {
        return CarShow::query()->find($id) ?? abort(404, 'Event not found.');
    }
}
