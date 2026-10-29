<?php

namespace App\Http\Controllers\Admin\Judging;

use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Judging\AssignJudgeRequest;
use App\Models\CarShow;
use App\Models\CarShowJudge;
use App\Models\User;
use App\Support\Admin\AuditLog;
use Illuminate\Http\JsonResponse;

/**
 * admin-api/judging/shows/{show}/judges (admins only): judges assigned to an event (legacy
 * JudgingList::getEventJudges, getJudgeUsersForShow, addEventJudge, removeEventJudge).
 * Only judge-type accounts can be assigned.
 */
final class JudgingJudgeController extends Controller
{
    private const RESOURCE = 'car_show_judge';

    public function index(int $show): JsonResponse
    {
        $users = User::query()
            ->whereIn('id', CarShowJudge::query()->where('show_id', $this->show($show)->id)->select('user_id'))
            ->orderBy('first')->orderBy('last')
            ->get();

        return response()->json(['data' => $users->map($this->row(...))->values()]);
    }

    public function eligible(int $show): JsonResponse
    {
        $users = User::query()
            ->where('type_id', UserType::Judge->value)
            ->whereNotIn('id', CarShowJudge::query()->where('show_id', $this->show($show)->id)->select('user_id'))
            ->orderBy('first')->orderBy('last')
            ->get();

        return response()->json(['data' => $users->map($this->row(...))->values()]);
    }

    public function store(AssignJudgeRequest $request, int $show): JsonResponse
    {
        $showId = $this->show($show)->id;
        $user = User::query()->find($request->integer('user_id'));
        if ($user === null || ! $user->isJudge()) {
            return response()->json(['message' => 'Only judge accounts can be assigned.', 'errors' => ['user_id' => ['Only judge accounts can be assigned.']]], 422);
        }

        if (CarShowJudge::findByKeys($user->id, $showId) === null) {
            CarShowJudge::query()->create(['user_id' => $user->id, 'show_id' => $showId]);
            AuditLog::record('judging.judge.assign', self::RESOURCE, [$user->id, $showId]);
        }

        return response()->json(['data' => $this->row($user)], 201);
    }

    public function destroy(int $show, int $user): JsonResponse
    {
        $deleted = CarShowJudge::query()->assignedTo($user, $this->show($show)->id)->delete();
        if ($deleted === 0) {
            abort(404, 'That judge is not assigned to this event.');
        }

        AuditLog::record('judging.judge.remove', self::RESOURCE, [$user, $show]);

        return response()->json(null, 204);
    }

    /**
     * @return array{user_id: int, name: string, email: string}
     */
    private function row(User $user): array
    {
        return ['user_id' => $user->id, 'name' => $user->fullName, 'email' => (string) $user->email];
    }

    private function show(int $id): CarShow
    {
        return CarShow::query()->find($id) ?? abort(404, 'Event not found.');
    }
}
