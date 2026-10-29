<?php

namespace App\Http\Controllers\Admin\Judging;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Judging\ReleaseJudgingRequest;
use App\Models\CarShow;
use App\Services\Judging\JudgingResults;
use App\Services\Judging\ResultsMailRecentlySent;
use App\Support\Admin\AuditLog;
use Illuminate\Http\JsonResponse;

/**
 * admin-api/judging/shows/{show}/release and /results-mail (admins only): the legacy carShow
 * is_judging_released flag and the send_judging_results action.
 */
final class JudgingReleaseController extends Controller
{
    public function update(ReleaseJudgingRequest $request, int $show, JudgingResults $results): JsonResponse
    {
        $model = $this->show($show);
        $results->setReleased($model, $request->boolean('released'));
        AuditLog::record('judging.release', 'car_show', [$model->id], AuditLog::diff($model));

        return response()->json(['data' => ['id' => $model->id, 'is_judging_released' => $model->is_judging_released === true]]);
    }

    public function mail(int $show, JudgingResults $results): JsonResponse
    {
        $model = $this->show($show);
        if ($model->is_judging_released !== true) {
            return response()->json(['message' => 'Judging results can only be sent after they are released.'], 409);
        }

        try {
            $sent = $results->mailOwners($model);
        } catch (ResultsMailRecentlySent $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
        AuditLog::record('judging.results_mail', 'car_show', [$model->id], [], ['sent' => $sent]);

        return response()->json(['data' => ['sent' => $sent]]);
    }

    private function show(int $id): CarShow
    {
        return CarShow::query()->find($id) ?? abort(404, 'Event not found.');
    }
}
