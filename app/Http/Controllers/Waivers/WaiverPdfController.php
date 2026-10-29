<?php

namespace App\Http\Controllers\Waivers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Waiver;
use App\Services\Waivers\WaiverPdf;
use App\Services\Waivers\WaiverSignService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * GET waiver-pdf?waiverId=... (legacy Waiver::getWaiverPDF): admin-only inline download of a
 * signed waiver PDF from storage (never public/). A missing file is regenerated from waiver_data.
 * The id must match WaiverPdf::WAIVER_ID_PATTERN before any lookup, so no path can be injected.
 */
class WaiverPdfController extends Controller
{
    public function __invoke(Request $request, WaiverSignService $waivers): JsonResponse|BinaryFileResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['success' => false], 401);
        }
        if (! $user->isAdmin()) {
            return response()->json(['success' => false], 403);
        }

        $waiverId = $request->query('waiverId');
        if (! is_string($waiverId) || preg_match(WaiverPdf::WAIVER_ID_PATTERN, $waiverId) !== 1) {
            return response()->json(['success' => false], 404);
        }

        $waiver = Waiver::query()->where('waiver_id', $waiverId)->first();
        $path = $waiver !== null ? $waivers->pdfPathForAdmin($waiver) : null;
        if ($path === null) {
            return response()->json(['success' => false], 404);
        }

        return response()->file($path, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="waiver-'.$waiverId.'.pdf"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
