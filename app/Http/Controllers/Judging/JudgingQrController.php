<?php

namespace App\Http\Controllers\Judging;

use App\Models\CarShow;
use App\Models\User;
use App\Services\Judging\JudgingQrCodes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * GET judging_qr_code/{id}: the QR PNG for one car (legacy returned an <img> tag to anyone).
 * GET judging_qr_code/show/{show}: the printable QR sheet PDF for a show (legacy
 * admin2/judging_list/generateJudgingQRPDF). Both need an admin or a judge assigned to the show.
 */
final class JudgingQrController extends JudgingController
{
    private const HEADERS = ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'];

    public function car(Request $request, string $token, JudgingQrCodes $codes): Response|JsonResponse
    {
        $car = $this->findCar($token);
        if (($error = $this->authorizeShow($request, (int) $car->registration?->show_id)) !== null) {
            return $error;
        }

        return response($codes->png($car->id), 200, ['Content-Type' => 'image/png'] + self::HEADERS);
    }

    public function sheet(Request $request, int $show, JudgingQrCodes $codes): Response|JsonResponse
    {
        $carShow = CarShow::query()->find($show) ?? abort(404);
        if (($error = $this->authorizeShow($request, $carShow->id)) !== null) {
            return $error;
        }

        return response($codes->sheetPdf($carShow), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="judging-qr-'.$carShow->id.'.pdf"',
        ] + self::HEADERS);
    }

    private function authorizeShow(Request $request, int $showId): ?JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return Gate::forUser($user)->allows('judge-for-show', [$showId])
            ? null
            : response()->json(['message' => 'Forbidden.'], 403);
    }
}
