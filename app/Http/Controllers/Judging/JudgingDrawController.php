<?php

namespace App\Http\Controllers\Judging;

use App\Models\CarShow;
use App\Models\User;
use App\Services\Judging\JudgingScores;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * GET judging_draw/{show}: top five per body and judging type (legacy CarJudging::getDraw).
 * Admins and judges assigned to the show; legacy let any judge see any show.
 */
final class JudgingDrawController extends JudgingController
{
    public function __invoke(Request $request, int $show, JudgingScores $scores): Response|RedirectResponse
    {
        $carShow = CarShow::query()->find($show) ?? abort(404);
        $user = $request->user();
        $path = '/judging_draw/'.$show;

        if (! $user instanceof User) {
            return $this->toLogin($path);
        }

        if (! Gate::forUser($user)->allows('judge-for-show', [$carShow->id])) {
            return $this->denied($request, 'Not a judge for this event', 'Your account is not listed as a judge for '.$carShow->name.'.', $path);
        }

        return response()->view('judging.draw', ['show' => $carShow, 'groups' => $scores->draw($carShow->id)]);
    }
}
