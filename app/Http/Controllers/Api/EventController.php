<?php

namespace App\Http\Controllers\Api;

use App\Enums\AgendaType;
use App\Enums\SponsorType;
use App\Http\Controllers\Api\Concerns\ReadsEventQuery;
use App\Http\Controllers\Controller;
use App\Http\Resources\Events\AgendaItemResource;
use App\Http\Resources\Events\EventInfoResource;
use App\Http\Resources\Events\LocationResource;
use App\Http\Resources\Events\ScheduleResource;
use App\Http\Resources\Events\SponsorResource;
use App\Models\CarShow;
use App\Models\CarShowAgenda;
use App\Models\CarShowLocation;
use App\Models\Sponsor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Public event page data (GET only). Legacy: EventProfile info, agenda, locations, news, attendees,
 * sponsors and schedule. Registration, extras and cars are not here.
 */
class EventController extends Controller
{
    use ReadsEventQuery;

    /**
     * GET event/info?show_id.
     */
    public function info(Request $request): JsonResponse
    {
        $show = $this->showFrom($request);

        $visible = CarShowAgenda::query()->forShow($show->id)->visible();
        $agendaTypes = [
            'agenda' => (clone $visible)->where('type_id', AgendaType::Agenda->value)->count(),
            'judging' => (clone $visible)->where('type_id', AgendaType::Judging->value)->count(),
        ];

        return response()->json((new EventInfoResource($show, $agendaTypes))->resolve($request));
    }

    /**
     * GET event/agenda?show_id&type_id. Visible items of one type, in sort order.
     */
    public function agenda(Request $request): JsonResponse
    {
        $show = $this->showFrom($request);
        $validated = $request->validate(['type_id' => ['required', Rule::enum(AgendaType::class)]]);

        $items = CarShowAgenda::query()
            ->forShow($show->id)
            ->visible()
            ->where('type_id', $validated['type_id'])
            ->ordered()
            ->get();

        return response()->json(AgendaItemResource::collection($items)->resolve());
    }

    /**
     * GET event/locations?show_id.
     */
    public function locations(Request $request): JsonResponse
    {
        $show = $this->showFrom($request);

        $locations = CarShowLocation::query()
            ->where('show_id', $show->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return response()->json(LocationResource::collection($locations)->resolve());
    }

    /**
     * GET event/news. Legacy returned nothing, so this still returns null.
     */
    public function news(): Response
    {
        return $this->nullBody();
    }

    /**
     * GET event/attendees. Legacy returned nothing, so this still returns null.
     */
    public function attendees(): Response
    {
        return $this->nullBody();
    }

    /**
     * JsonResponse turns null into "{}", so the literal body is written here.
     */
    private function nullBody(): Response
    {
        return response('null', 200, ['Content-Type' => 'application/json']);
    }

    /**
     * GET event/sponsors?show_id&limit&all. With all=1 the newest show is used and type M is left out.
     */
    public function sponsors(Request $request): JsonResponse
    {
        $all = $request->boolean('all');
        $limit = $this->limitFrom($request, 100);

        if ($all) {
            $showId = CarShow::query()->max('id');
        } else {
            $showId = $this->showFrom($request)->id;
        }

        $sponsors = Sponsor::query()
            ->join('show_sponsor', 'show_sponsor.sponsor_id', '=', 'sponsor.id')
            ->where('sponsor.is_enabled', true)
            ->where('show_sponsor.show_id', $showId)
            ->when($all, fn ($query) => $query->where('show_sponsor.type_id', '!=', SponsorType::M->value))
            ->select('sponsor.*', 'show_sponsor.type_id as type_id', 'show_sponsor.show_id as show_id')
            ->orderBy('show_sponsor.type_id')
            ->orderBy('show_sponsor.sort_order')
            ->limit($limit)
            ->get();

        return response()->json([
            'sponsors' => SponsorResource::collection($sponsors)->resolve(),
            'show_id' => $showId === null ? null : (int) $showId,
        ]);
    }

    /**
     * GET event/schedule?show_id.
     */
    public function schedule(Request $request): JsonResponse
    {
        $show = $this->showFrom($request);

        return response()->json((new ScheduleResource($show))->resolve($request));
    }
}
