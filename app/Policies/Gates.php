<?php

namespace App\Policies;

use App\Models\CarShowJudge;
use App\Models\ShowRegistrationCar;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Role and judging gates. Registered by AppServiceProvider::boot().
 *
 * - admin: type Administrator.
 * - judge: type Judge or Administrator.
 * - judge-for-show (User, int $showId, ?int $carId): admin, or a judge assigned to the show. When a
 *   car id is given, the car must be entered in that show and must not belong to the judge.
 * - judge-car (User, ShowRegistrationCar): admin, or a judge assigned to the car's show who does not
 *   own the car (registration.user_id).
 *
 * Admins bypass the ownership rule, as the spec and the middleware require. The ownership rule
 * applies to judges only.
 *
 * Authorization never reads the URL path. Callers pass the show and car ids explicitly.
 */
final class Gates
{
    public static function register(): void
    {
        Gate::define('admin', fn (User $user): bool => $user->isAdmin());

        Gate::define('judge', fn (User $user): bool => $user->isJudgeOrAdmin());

        Gate::define('judge-for-show', function (User $user, int $showId, ?int $carId = null): bool {
            if ($user->isAdmin()) {
                return true;
            }

            if (! $user->isJudge() || ! self::assignedToShow($user, $showId)) {
                return false;
            }

            if ($carId === null) {
                return true;
            }

            $car = ShowRegistrationCar::query()->with('registration')->find($carId);

            return $car !== null && self::judgesCar($user, $car, $showId);
        });

        Gate::define('judge-car', function (User $user, ShowRegistrationCar $car): bool {
            if ($user->isAdmin()) {
                return true;
            }

            if (! $user->isJudge()) {
                return false;
            }

            $car->loadMissing('registration');

            return $car->registration !== null
                && self::judgesCar($user, $car, $car->registration->show_id);
        });
    }

    /**
     * A judge may judge a car only in a show they are assigned to, and never their own car.
     * $showId must be the show the car is entered in.
     */
    private static function judgesCar(User $user, ShowRegistrationCar $car, int $showId): bool
    {
        $registration = $car->registration;

        if ($registration === null || (int) $registration->show_id !== $showId) {
            return false;
        }

        if ((int) $registration->user_id === $user->id) {
            return false;
        }

        return self::assignedToShow($user, $showId);
    }

    private static function assignedToShow(User $user, int $showId): bool
    {
        return CarShowJudge::query()->assignedTo($user->id, $showId)->exists();
    }
}
