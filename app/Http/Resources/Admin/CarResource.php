<?php

namespace App\Http\Resources\Admin;

use App\Models\Car;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A car as the admin sees it. Type and body are separate keys (type_id/type_name, body_id/body_name):
 * the legacy car config mapped both to the same column name. in_use = registered for at least one show.
 *
 * @mixin Car
 */
final class CarResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Car $car */
        $car = $this->resource;
        $owner = $car->user;
        $registrations = (int) ($car->getAttribute('registrations_count') ?? 0);

        return [
            'id' => (int) $car->id,
            'user_id' => $car->user_id,
            'owner_name' => $owner?->fullName,
            'owner_email' => $owner?->email,
            'type_id' => (int) $car->type_id,
            'type_name' => $car->type?->name,
            'body_id' => (int) $car->body_id,
            'body_name' => $car->body?->name,
            'year' => $car->year,
            'model' => $car->model,
            'nickname' => $car->nickname,
            'VIN' => $car->VIN,
            'is_confirmed' => (bool) $car->is_confirmed,
            'has_image' => (bool) $car->has_image,
            'image_url' => $car->imageURL,
            'registrations_count' => $registrations,
            'in_use' => $registrations > 0,
            'created_at' => $car->created_at?->toIso8601String(),
            'updated_at' => $car->updated_at?->toIso8601String(),
        ];
    }
}
