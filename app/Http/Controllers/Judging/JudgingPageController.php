<?php

namespace App\Http\Controllers\Judging;

use App\Models\ShowRegistrationCar;
use App\Models\User;
use App\Services\Judging\InvalidJudgingSubmission;
use App\Services\Judging\JudgingSheet;
use App\Services\Judging\JudgingSubmission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * GET judging/{id} (the SurveyJS scoresheet page), GET judgingData/{id} and POST judgingSubmit/{id}
 * (legacy CarJudging::getJudging, getData, receiveJudgingData). Allowed: admins, and judges assigned
 * to the car's show who do not own the car (gate judge-car).
 */
final class JudgingPageController extends JudgingController
{
    public function show(Request $request, string $token): Response|RedirectResponse
    {
        $car = $this->findCar($token);
        $user = $request->user();
        $path = '/judging/'.$token;

        if (! $user instanceof User) {
            return $this->toLogin($path);
        }

        if (! Gate::forUser($user)->allows('judge-car', $car)) {
            return $this->isOwner($car, $user)
                ? $this->denied($request, 'Not allowed', 'You may not judge your own car.', $path)
                : $this->denied($request, 'Not a judge for this event', 'Your account is not listed as a judge for '.$car->registration?->carShow?->name.'.', $path);
        }

        return response()->view('judging.judge', ['car' => $car, 'token' => $token]);
    }

    public function data(Request $request, string $token, JudgingSheet $sheet): JsonResponse
    {
        $car = $this->findCar($token);
        if (($error = $this->apiError($request, $car)) !== null) {
            return $error;
        }

        return response()->json($sheet->surveyData($car));
    }

    public function submit(Request $request, string $token, JudgingSubmission $submission): JsonResponse
    {
        $car = $this->findCar($token);
        if (($error = $this->apiError($request, $car)) !== null) {
            return $error;
        }

        /** @var User $user */
        $user = $request->user();
        $input = $request->input('data', []);
        $files = $request->file('data', []);

        try {
            return response()->json($submission->submit($car, $user, is_array($input) ? $input : [], is_array($files) ? $files : []));
        } catch (InvalidJudgingSubmission $e) {
            return response()->json($e->body, 400);
        }
    }

    private function apiError(Request $request, ShowRegistrationCar $car): ?JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['errors' => ['Your session has expired. Please sign in again.']], 401);
        }

        if (! Gate::forUser($user)->allows('judge-car', $car)) {
            return response()->json(['errors' => [$this->isOwner($car, $user) ? 'You may not judge your own car.' : 'You must be a judge for this event.']], 403);
        }

        return null;
    }

    private function isOwner(ShowRegistrationCar $car, User $user): bool
    {
        return (int) $car->registration?->user_id === $user->id;
    }
}
