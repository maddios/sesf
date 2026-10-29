<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\DeleteAccountRequest;
use App\Http\Requests\Account\UpdateAccountInfoRequest;
use App\Http\Requests\Account\UpdateLocationRequest;
use App\Http\Requests\Account\UpdatePrivatePreferencesRequest;
use App\Models\User;
use App\Models\UserPreferences;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * Signed-in member's own account: address, contact details, preferences and deletion.
 *
 * Every action works on the authenticated member only. The legacy user_id input (an IDOR) is never
 * read. The routes sit behind the auth middleware, so anonymous requests get 401 first.
 */
class AccountController extends Controller
{
    /** GET member/location */
    public function showLocation(Request $request): JsonResponse
    {
        return response()->json($this->member($request)->only(['address1', 'address2', 'city', 'state', 'zip']));
    }

    /** POST member/location. Saving an address queues geocoding through UserObserver. */
    public function updateLocation(UpdateLocationRequest $request): JsonResponse
    {
        $user = $this->member($request);
        $user->forceFill($request->validated());
        $user->save();

        return response()->json($user->toArray());
    }

    /** GET member/private-preferences */
    public function showPreferences(Request $request): JsonResponse
    {
        $preferences = $this->member($request)->preferences;

        if ($preferences === null) {
            return response()->json(['Preferences not found'], 404);
        }

        return response()->json($preferences);
    }

    /** POST member/private-preferences. Only the authenticated member's own row is written. */
    public function updatePreferences(UpdatePrivatePreferencesRequest $request): JsonResponse
    {
        $preferences = UserPreferences::query()->firstOrCreate(['user_id' => $this->member($request)->getKey()]);
        $preferences->forceFill($request->validated());
        $preferences->save();

        return response()->json($preferences);
    }

    /** POST member/account-info */
    public function updateAccountInfo(UpdateAccountInfoRequest $request): JsonResponse
    {
        $user = $this->member($request);
        $user->forceFill($request->safe()->only(['first', 'last', 'email', 'phone']));
        $user->save();

        return response()->json('');
    }

    /**
     * POST member/delete-account. Soft delete: the row stays, so the email stays reserved and the
     * member can no longer sign in (every login path ignores soft-deleted rows).
     */
    public function deleteAccount(DeleteAccountRequest $request): JsonResponse
    {
        $user = $this->member($request);

        if (! Hash::check((string) $request->validated('password'), $user->password)) {
            return response()->json(['Password is incorrect'], 400);
        }

        $user->delete();

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json('');
    }

    private function member(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
