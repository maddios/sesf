<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cars\CarProfileImageRequest;
use App\Http\Requests\Cars\CarProfileRequest;
use App\Http\Requests\Cars\SellCarRequest;
use App\Models\Car;
use App\Services\Images\ImageStore;
use App\Services\Images\ImageUploadException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;

/**
 * Car writes: PUT car/profile, POST car/profile-image, POST member/sell-car. Replaces legacy
 * CarProfile::putProfile and ::postProfileImage, and MemberProfile::postSellCar.
 *
 * The profile response is built here from the Car model's toArray() whitelist. When the car
 * read resource (app/Http/Resources/Cars) exists, both should use it, so the shape is kept in
 * one place.
 */
final class CarController extends Controller
{
    /** Keys of the owner's car profile, the same keys GET car/profile returns to its owner. */
    private const PROFILE_KEYS = [
        'id', 'user_id', 'has_image', 'nickname', 'VIN', 'year', 'model', 'body_id', 'type_id',
        'body', 'type', 'imageURL', 'profileURL', 'name', 'fullName', 'editableModel',
    ];

    public function __construct(private readonly ImageStore $images) {}

    public function update(CarProfileRequest $request): JsonResponse
    {
        $data = $request->validated();
        $locked = $request->isLocked();
        $car = $request->targetCar();

        if ($car === null) {
            $car = new Car;
            $car->user_id = (int) $request->user()->getAuthIdentifier();
        }

        if (! $locked) {
            $car->body_id = (int) $data['body']['id'];
            $car->type_id = (int) $data['type']['id'];
            $car->year = (string) $data['year'];
            $car->model = (string) $data['model'];
            $car->VIN = $data['VIN'] ?? null;
        }

        $car->nickname = $data['nickname'] ?? null;

        if (! $car->save()) {
            return response()->json(['Failed to save'], 400);
        }

        // Reload so column defaults (has_image on a new row) are present, then the relations.
        $car->refresh()->load(['body', 'type']);

        return response()->json($this->profile($car));
    }

    public function storeImage(CarProfileImageRequest $request): JsonResponse
    {
        $car = $request->targetCar();

        if ($car === null) {
            return response()->json(['Car not found'], 404);
        }

        try {
            $this->images->store($request->file('file'), (string) config('sesf.car_image_root'), (int) $car->id, 'profile');
        } catch (ImageUploadException $e) {
            return response()->json([$e->getMessage()], 400);
        }

        $car->has_image = true;
        $car->save();

        return response()->json('', 200);
    }

    public function sell(SellCarRequest $request): JsonResponse
    {
        $car = $request->targetCar();

        if ($car === null) {
            return response()->json(['Car not found'], 404);
        }

        $car->user_id = null;

        if (! $car->save()) {
            return response()->json(['Failed to save'], 400);
        }

        return response()->json('', 200);
    }

    /**
     * The owner's view of a car. Mirrors the keys of GET car/profile for its owner.
     *
     * @return array<string, mixed>
     */
    private function profile(Car $car): array
    {
        return Arr::only($car->toArray(), self::PROFILE_KEYS) + ['is_private' => true];
    }
}
