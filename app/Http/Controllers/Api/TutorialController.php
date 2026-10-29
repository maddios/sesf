<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\UpdateTutorialRequest;
use App\Models\User;
use App\Models\UserPreferences;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET|POST tutorial/tutorial. Replaces legacy TutorialController. Works only on the signed-in
 * member's own preferences row: the legacy id input is ignored, so one member cannot read or
 * change another's flag. Anonymous requests get 401 from the auth middleware.
 */
class TutorialController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $preferences = $user->preferences;

        if ($preferences === null) {
            return response()->json(null);
        }

        return response()->json([
            'show_login_welcome' => (bool) $preferences->show_login_welcome,
            'id' => $preferences->id,
        ]);
    }

    public function update(UpdateTutorialRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $preferences = UserPreferences::query()->firstOrCreate(['user_id' => $user->getKey()]);
        $preferences->show_login_welcome = (bool) $request->validated('show_login_welcome');
        $preferences->save();

        return response()->json('');
    }
}
