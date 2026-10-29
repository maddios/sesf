<?php

namespace App\Http\Controllers\Api;

use App\Enums\SponsorType;
use App\Http\Controllers\Api\Concerns\ReadsEventQuery;
use App\Http\Controllers\Controller;
use App\Http\Resources\Events\HomeEventResource;
use App\Http\Resources\Events\SponsorResource;
use App\Models\CarShow;
use App\Models\Sponsor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public home page data. Legacy: HomeController::getEvents and getSponsors.
 */
class HomeController extends Controller
{
    use ReadsEventQuery;

    /**
     * GET home/events?limit=N. Active shows, newest end date first.
     */
    public function events(Request $request): JsonResponse
    {
        $limit = $this->limitFrom($request, 20);

        $shows = CarShow::query()
            ->where('is_active', true)
            ->with(['primary_sponsors' => fn ($query) => $query
                ->where('sponsor.is_enabled', true)
                ->orderBy('show_sponsor.sort_order')
                ->orderBy('sponsor.id')])
            ->orderByDesc('date_end')
            ->limit($limit)
            ->get();

        return response()->json(HomeEventResource::collection($shows)->resolve());
    }

    /**
     * GET home/sponsors?limit=N. Type M sponsors that are not marked primary, newest show first.
     */
    public function sponsors(Request $request): JsonResponse
    {
        $limit = $this->limitFrom($request, 20);

        // Admins turn a sponsor off with is_enabled; a disabled sponsor is hidden everywhere public.
        $sponsors = Sponsor::query()
            ->join('show_sponsor', 'show_sponsor.sponsor_id', '=', 'sponsor.id')
            ->where('sponsor.is_enabled', true)
            ->where('show_sponsor.type_id', SponsorType::M->value)
            ->where(fn ($query) => $query
                ->whereNull('show_sponsor.is_primary')
                ->orWhere('show_sponsor.is_primary', false))
            ->select('sponsor.*', 'show_sponsor.type_id as type_id', 'show_sponsor.show_id as show_id')
            ->orderByDesc('show_sponsor.show_id')
            ->orderBy('show_sponsor.sort_order')
            ->limit($limit)
            ->get();

        return response()->json(SponsorResource::collection($sponsors)->resolve());
    }
}
