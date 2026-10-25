<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Http\Resources\Registration\RegistrationCarResource;
use App\Http\Resources\Registration\RegistrationExtraResource;
use App\Http\Resources\Registration\RegistrationResource;
use App\Models\CarBody;
use App\Models\CarShow;
use App\Models\ShowExtra;
use App\Models\User;
use App\Services\Registration\RegistrationPayloadBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Registration page reads for the Vue event pages (legacy EventProfile::getRegistration,
 * getCars, getExtra). Every route requires sign-in (401 JSON from the auth middleware).
 *
 * The target user is the session user. An administrator may pass user_id to act on behalf of
 * another user (legacy registrationUserId). Other users' user_id is ignored.
 *
 * Read-only: nothing here writes to the database.
 */
class EventRegistrationReadController extends Controller
{
    public function __construct(private readonly RegistrationPayloadBuilder $payload) {}

    /** GET event/registration?show_id[&user_id] */
    public function registration(Request $request): JsonResponse
    {
        return $this->withShow($request, function (CarShow $show) use ($request): JsonResponse {
            return $this->registrationPayload($request, $show, null);
        });
    }

    /** GET event/cars?show_id: the signed-in user's cars with judging_types for the show. */
    public function cars(Request $request): JsonResponse
    {
        return $this->withShow($request, function (CarShow $show) use ($request): JsonResponse {
            $target = $this->targetUser($request);

            if ($target === null) {
                return response()->json([]);
            }

            return response()->json(
                $this->payload->cars($target, $show)
                    ->map(fn ($car): array => (new RegistrationCarResource($car))->resolve($request))
                    ->values()
                    ->all(),
            );
        });
    }

    /**
     * GET event/extra?extra_id=<auth_key>[&show_id]: the registration page data restricted to one
     * extra. Unknown key, or a show_id that is not the extra's show, is 404 JSON.
     */
    public function extra(Request $request): JsonResponse
    {
        $key = (string) $request->query('extra_id', '');

        if ($key === '') {
            return $this->notFound();
        }

        $extra = ShowExtra::query()->where('auth_key', $key)->first();

        if ($extra === null) {
            return $this->notFound();
        }

        $showId = $this->positiveInt($request->query('show_id'));

        if ($showId !== null && $showId !== (int) $extra->show_id) {
            return $this->notFound();
        }

        $show = $extra->carShow;

        if ($show === null) {
            return $this->notFound();
        }

        return $this->registrationPayload($request, $show, $extra);
    }

    /**
     * @param  (\Closure(CarShow): JsonResponse)  $callback
     */
    private function withShow(Request $request, \Closure $callback): JsonResponse
    {
        $showId = $this->positiveInt($request->query('show_id'));

        if ($showId === null) {
            return response()->json(['message' => 'show_id is required.'], 422);
        }

        $show = CarShow::query()->find($showId);

        if ($show === null) {
            return $this->notFound();
        }

        return $callback($show);
    }

    /**
     * The registration page payload. When $onlyExtra is set, the top-level extras list holds only
     * that extra (legacy getExtra).
     */
    private function registrationPayload(Request $request, CarShow $show, ?ShowExtra $onlyExtra): JsonResponse
    {
        $target = $this->targetUser($request);
        $registration = $target === null ? null : $this->payload->registrationFor($target, $show);

        try {
            $totals = $this->payload->totals($registration);
        } catch (InvalidArgumentException) {
            return response()->json(['message' => 'The registration amounts cannot be priced.'], 422);
        }

        $body = [
            'registration' => $registration === null
                ? RegistrationResource::emptyFor($show->id, (int) ($target === null ? ($this->requestedUserId($request) ?? 0) : $target->id), $totals)
                : (new RegistrationResource($registration, $totals))->resolve($request),
            'cars' => $target === null
                ? []
                : $this->payload->cars($target, $show)
                    ->map(fn ($car): array => (new RegistrationCarResource($car))->resolve($request))
                    ->values()
                    ->all(),
            'carBodies' => $this->payload->carBodies($show)
                ->map(fn (CarBody $carBody): array => ['id' => (int) $carBody->id, 'name' => $carBody->name])
                ->values()
                ->all(),
            'guestTypes' => $this->payload->guestTypes(),
            'shirtTypes' => $this->payload->shirtTypes()
                ->map(fn ($type): array => [
                    'id' => (int) $type->id,
                    'name' => $type->name,
                    'price' => (string) $type->price,
                ])
                ->values()
                ->all(),
            'extras' => $this->extrasList($request, $show, $onlyExtra),
        ];

        if ($this->isAdmin($request) && $this->requestedUserId($request) !== null) {
            $body['targetUser'] = $target === null
                ? null
                : ['first' => $target->first, 'last' => $target->last, 'email' => $target->email];
        }

        return response()->json($body);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function extrasList(Request $request, CarShow $show, ?ShowExtra $onlyExtra): array
    {
        /** @var Collection<int, ShowExtra> $extras */
        $extras = $onlyExtra === null
            ? $this->payload->enabledExtras($show)
            : collect([$onlyExtra]);

        return $extras
            ->map(fn (ShowExtra $extra): array => (new RegistrationExtraResource($extra))->resolve($request))
            ->values()
            ->all();
    }

    /**
     * The user whose registration is read: the session user, or the admin's user_id.
     */
    private function targetUser(Request $request): ?User
    {
        $sessionUser = $request->user();

        if ($sessionUser === null) {
            return null;
        }

        $requested = $this->requestedUserId($request);

        if ($requested !== null && $this->isAdmin($request)) {
            return User::query()->find($requested);
        }

        return $sessionUser;
    }

    /** The user_id query parameter, when it is a positive integer. */
    private function requestedUserId(Request $request): ?int
    {
        return $this->positiveInt($request->query('user_id'));
    }

    private function isAdmin(Request $request): bool
    {
        $user = $request->user();

        return $user instanceof User && $user->type_id === UserType::Administrator;
    }

    private function positiveInt(mixed $value): ?int
    {
        $int = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $int === false ? null : $int;
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['message' => 'Not found.'], 404);
    }
}
