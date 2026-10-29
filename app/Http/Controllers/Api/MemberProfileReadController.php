<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Cars\CarSummaryResource;
use App\Http\Resources\Members\MemberAttendedEventResource;
use App\Http\Resources\Members\MemberInfoResource;
use App\Http\Resources\Members\MemberPreferencesResource;
use App\Models\Car;
use App\Models\ShowRegistration;
use App\Models\User;
use App\Support\Privacy\ViewerPrivacy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Member profile reads (legacy MemberProfile GET methods). Every endpoint takes user_id.
 *
 * Legacy bugs fixed here: member/car-list and member/attended-events never set user_id, so they
 * returned nothing. Each now reads the user_id parameter.
 */
class MemberProfileReadController extends Controller
{
    private const CAR_LIST_SIZE = 3;

    public function profile(Request $request): JsonResponse
    {
        $user = $this->findMember($request);

        if ($user === null) {
            return $this->notFound();
        }

        $privacy = new ViewerPrivacy($request->user());

        return response()->json([
            'is_private' => $privacy->isSelf($user),
            'member_info' => (new MemberInfoResource($user, $privacy))->resolve($request),
            'car_list' => $this->buildCarList($user, $request),
            'event_list' => $this->buildAttendedEvents($user, $privacy, $request),
            'member_preferences' => (new MemberPreferencesResource($user, $privacy))->resolve($request),
        ]);
    }

    public function memberInfo(Request $request): JsonResponse
    {
        $user = $this->findMember($request);

        if ($user === null) {
            return $this->notFound();
        }

        $privacy = new ViewerPrivacy($request->user());

        return response()->json((new MemberInfoResource($user, $privacy))->resolve($request));
    }

    public function memberPreferences(Request $request): JsonResponse
    {
        $user = $this->findMember($request);

        if ($user === null) {
            return $this->notFound();
        }

        return response()->json(
            (new MemberPreferencesResource($user, new ViewerPrivacy($request->user())))->resolve($request),
        );
    }

    public function cars(Request $request): JsonResponse
    {
        $user = $this->findMember($request);

        if ($user === null) {
            return $this->notFound();
        }

        return response()->json($this->ownedCars($user)
            ->map(fn (Car $car) => (new CarSummaryResource($car))->resolve($request))
            ->values()
            ->all());
    }

    public function carList(Request $request): JsonResponse
    {
        $user = $this->findMember($request);

        if ($user === null) {
            return $this->notFound();
        }

        return response()->json($this->buildCarList($user, $request));
    }

    public function attendedEvents(Request $request): JsonResponse
    {
        $user = $this->findMember($request);

        if ($user === null) {
            return $this->notFound();
        }

        return response()->json($this->buildAttendedEvents($user, new ViewerPrivacy($request->user()), $request));
    }

    /**
     * Legacy getCarList: the number of cars and the three newest.
     *
     * @return array{num_cars: int, cars: list<array<string, mixed>>}
     */
    private function buildCarList(User $user, Request $request): array
    {
        $cars = $this->ownedCars($user);

        return [
            'num_cars' => $cars->count(),
            'cars' => $cars->take(self::CAR_LIST_SIZE)
                ->map(fn (Car $car) => (new CarSummaryResource($car))->resolve($request))
                ->values()
                ->all(),
        ];
    }

    /**
     * Legacy getAttendedEvents: the count of uncancelled registrations and the list, newest show first.
     * Cars are hidden from other viewers when the owner turned off show_car_on_attendees_page.
     *
     * @return array{num_events: int, events: list<array<string, mixed>>}
     */
    private function buildAttendedEvents(User $user, ViewerPrivacy $privacy, Request $request): array
    {
        $carsVisible = $privacy->isSelf($user) || $privacy->showsCarOnAttendeesPage($user);

        $registrations = ShowRegistration::query()
            ->select('show_registration.*')
            ->join('car_show', 'car_show.id', '=', 'show_registration.show_id')
            ->where('show_registration.user_id', $user->id)
            ->where('show_registration.is_cancelled', 0)
            ->orderByRaw('car_show.date_end IS NULL, car_show.date_end DESC')
            ->with(['carShow', 'cars.body'])
            ->get();

        return [
            'num_events' => $registrations->count(),
            'events' => $registrations
                ->map(fn (ShowRegistration $registration) => (new MemberAttendedEventResource($registration, $carsVisible))->resolve($request))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return Collection<int, Car>
     */
    private function ownedCars(User $user): Collection
    {
        return Car::query()
            ->with(['body', 'registrations.carShow'])
            ->where('car.user_id', $user->id)
            ->orderBy('car.created_at', 'desc')
            ->get();
    }

    private function findMember(Request $request): ?User
    {
        $userId = filter_var($request->query('user_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($userId === false) {
            return null;
        }

        return User::query()->find($userId);
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['message' => 'Not found.'], 404);
    }
}
