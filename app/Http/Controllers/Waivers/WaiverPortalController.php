<?php

namespace App\Http\Controllers\Waivers;

use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\Controller;
use App\Http\Requests\Waivers\PortalLoginRequest;
use App\Models\CarShow;
use App\Models\ShowRegistration;
use App\Models\User;
use App\Models\UserGuest;
use App\Models\Waiver;
use App\Services\Waivers\WaiverNotFound;
use App\Services\Waivers\WaiverSignService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * JSON endpoints for the waiver portal page (legacy WaiverPortalController; the page is rendered
 * elsewhere). A member only ever sees their own registration for the show and its guests: every
 * lookup is scoped by show_id and the signed-in user_id.
 */
class WaiverPortalController extends Controller
{
    public function __construct(private readonly WaiverSignService $waivers) {}

    /**
     * Password login for the portal. Delegates to SessionController::login, so the messages,
     * session regeneration, deleted-account handling and timing padding are the same as the main
     * login (no account enumeration).
     */
    public function login(PortalLoginRequest $request, int $showId, SessionController $sessions): JsonResponse
    {
        if (CarShow::query()->find($showId) === null) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $response = $sessions->login($request);
        if ($response->getStatusCode() !== 200) {
            return $response;
        }

        return response()->json(['ok' => true, 'redirect' => '/waiver/'.$showId]);
    }

    /** Sign link for the member (no guest_id) or one of their guests. */
    public function signUrl(Request $request, int $showId): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['error' => 'unauthorized'], 401);
        }
        $show = CarShow::query()->find($showId);
        if ($show === null) {
            return response()->json(['error' => 'not_found'], 404);
        }
        $registration = $this->registrationFor($show, $user);
        if ($registration === null) {
            return response()->json(['error' => 'not_registered', 'message' => 'You are not registered for this event.'], 403);
        }

        $guest = null;
        $guestId = $request->query('guest_id');
        if ($guestId !== null && $guestId !== '') {
            $guest = is_string($guestId) && ctype_digit($guestId)
                ? UserGuest::query()->where('registration_id', $registration->id)->whereKey((int) $guestId)->first()
                : null;
            if ($guest === null) {
                return response()->json(['error' => 'prepare', 'message' => 'Invalid guest.'], 400);
            }
        }

        try {
            $waiver = $this->waivers->ensurePending($registration, $guest);
        } catch (WaiverNotFound $e) {
            return response()->json(['error' => 'prepare', 'message' => $e->getMessage()], 400);
        }
        if ($waiver->has_signed) {
            return response()->json(['error' => 'already_signed', 'message' => 'That waiver is already signed.'], 400);
        }

        $personName = $guest !== null
            ? trim($guest->first.' '.$guest->last)
            : trim($user->first.' '.$user->last);

        return response()->json([
            'url' => $waiver->signUrl(),
            'person_name' => $personName,
            'template_id' => $show->waiver_id !== null ? (string) $show->waiver_id : '',
        ]);
    }

    /** Signed / not signed for the member and each guest of their registration for the show. */
    public function status(Request $request, int $showId): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['error' => 'unauthorized'], 401);
        }
        $show = CarShow::query()->find($showId);
        if ($show === null) {
            return response()->json(['error' => 'not_found'], 404);
        }
        $registration = $this->registrationFor($show, $user);
        if ($registration === null) {
            return response()->json(['error' => 'not_registered', 'message' => 'You are not registered for this event.'], 403);
        }

        $guests = UserGuest::query()->where('registration_id', $registration->id)->orderBy('last')->orderBy('first')->get();
        $waiverIds = $guests->pluck('waiver_id')->push($registration->waiver_id)->filter()->all();
        $signed = Waiver::query()->whereIn('id', $waiverIds)->pluck('has_signed', 'id');

        $row = fn (string $name, ?int $guestId, ?int $waiverId): array => [
            'name' => $name,
            'guest_id' => $guestId,
            'status' => $waiverId === null || ! $signed->has($waiverId) ? 'not_started' : ($signed[$waiverId] ? 'signed' : 'not_signed'),
            'has_signed' => $waiverId !== null && $signed->has($waiverId) ? (bool) $signed[$waiverId] : null,
        ];

        $people = [$row(trim($user->first.' '.$user->last), null, $registration->waiver_id)];
        foreach ($guests as $guest) {
            $people[] = $row(trim($guest->first.' '.$guest->last), (int) $guest->id, $guest->waiver_id);
        }

        return response()->json([
            'show' => ['id' => (int) $show->id, 'name' => (string) $show->name],
            'people' => $people,
        ]);
    }

    /**
     * Logout (GET kept for the legacy link; POST needs the CSRF token). Redirects to the portal,
     * or to ?redirect= when it is a safe local path. A GET logout can be triggered cross-site; it
     * only signs the member out (same trade-off as login/logout).
     */
    public function logout(Request $request, int $showId): JsonResponse|RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(['message' => 'Logged out']);
        }

        $next = $request->input('redirect');
        if (is_string($next) && preg_match('#\A/(?![/\\\\])[^\\\\\x00-\x20\x7f]*\z#', $next) === 1) {
            return redirect($next);
        }

        return redirect('/waiver/'.$showId);
    }

    private function registrationFor(CarShow $show, User $user): ?ShowRegistration
    {
        return ShowRegistration::query()
            ->where('show_id', $show->id)
            ->where('user_id', $user->id)
            ->first();
    }
}
