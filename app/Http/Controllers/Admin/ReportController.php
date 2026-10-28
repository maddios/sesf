<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\ReportExtraResource;
use App\Http\Resources\Admin\ReportMemberResource;
use App\Http\Resources\Admin\ReportRegistrationResource;
use App\Models\CarShow;
use App\Services\Admin\Reports\EventSummaryReport;
use App\Services\Admin\Reports\ExtraReport;
use App\Services\Admin\Reports\MemberReport;
use App\Support\Admin\ShowParam;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * admin-api/reports: read-only report lists for the admin pages. Exports are GET .../export.xls
 * (ReportExportController).
 */
final class ReportController extends Controller
{
    public function members(Request $request): JsonResponse
    {
        return MemberReport::query($request)->respond(ReportMemberResource::class);
    }

    public function eventSummary(Request $request): JsonResponse
    {
        return EventSummaryReport::query(ShowParam::required($request), $request)
            ->respond(ReportRegistrationResource::class);
    }

    /** The event the pages open on until one is chosen: the latest by end date (legacy: date_end DESC, first row). */
    public function defaultEvent(): JsonResponse
    {
        $id = CarShow::query()->orderByDesc('date_end')->orderByDesc('id')->value('id');

        return response()->json(['data' => $id === null ? null : ['id' => (int) $id]]);
    }

    public function eventSummaryTotals(Request $request, EventSummaryReport $report): JsonResponse
    {
        return response()->json(['data' => $report->totals(ShowParam::required($request))]);
    }

    public function extras(Request $request): JsonResponse
    {
        return ExtraReport::query(ShowParam::required($request), $request)->respond(ReportExtraResource::class);
    }
}
