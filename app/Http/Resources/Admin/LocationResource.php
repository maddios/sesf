<?php

namespace App\Http\Resources\Admin;

use App\Models\CarShowLocation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A show venue for the admin, with its event and its position within that event.
 *
 * @mixin CarShowLocation
 */
final class LocationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var CarShowLocation $location */
        $location = $this->resource;

        return [
            'id' => (int) $location->id,
            'show_id' => $location->show_id === null ? null : (int) $location->show_id,
            'show_name' => $location->carShow?->name,
            'name' => $location->name,
            'description' => $location->description,
            'is_primary' => (bool) $location->is_primary,
            'sort_order' => (int) $location->sort_order,
            'address1' => $location->address1,
            'address2' => $location->address2,
            'city' => $location->city,
            'state' => $location->state,
            'zip' => $location->zip,
            'has_coordinates' => $location->lattitude !== null && $location->longitude !== null,
            'created_at' => $location->created_at?->toIso8601String(),
            'updated_at' => $location->updated_at?->toIso8601String(),
        ];
    }
}
