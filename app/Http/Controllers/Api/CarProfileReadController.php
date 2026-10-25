<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Cars\CarLookupResource;
use App\Http\Resources\Cars\CarProfileResource;
use App\Models\Car;
use App\Models\CarBody;
use App\Models\CarType;
use App\Support\Privacy\ViewerPrivacy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET car/profile, car/body, car/type (legacy CarProfile read methods).
 */
class CarProfileReadController extends Controller
{
    public function profile(Request $request): JsonResponse
    {
        $carId = filter_var($request->query('car_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($carId === false) {
            return $this->notFound();
        }

        $car = Car::query()
            ->with([
                'body',
                'type',
                'user' => fn ($q) => $q->withTrashed(),
                'registrations' => fn ($q) => $q->orderBy('show_registration.id'),
                'registrations.carShow',
            ])
            ->find($carId);

        // A car of a deleted member is not public, and nor is its owner's view of it.
        if ($car === null || $car->user?->trashed()) {
            return $this->notFound();
        }

        return response()->json(
            (new CarProfileResource($car, new ViewerPrivacy($request->user())))->resolve($request),
        );
    }

    public function body(): JsonResponse
    {
        return response()->json(
            CarBody::query()->orderBy('id')->get()
                ->map(fn (CarBody $body) => (new CarLookupResource($body))->resolve())
                ->values()
                ->all(),
        );
    }

    public function type(): JsonResponse
    {
        return response()->json(
            CarType::query()->orderBy('id')->get()
                ->map(fn (CarType $type) => (new CarLookupResource($type))->resolve())
                ->values()
                ->all(),
        );
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['message' => 'Not found.'], 404);
    }
}
