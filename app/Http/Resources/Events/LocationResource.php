<?php

namespace App\Http\Resources\Events;

use App\Models\CarShowLocation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A show venue. The description is HTML-escaped first and then gets line breaks, so the raw text never
 * reaches the client as markup.
 *
 * @mixin CarShowLocation
 */
class LocationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var CarShowLocation $location */
        $location = $this->resource;

        return [
            'name' => $location->name,
            'description' => nl2br(htmlspecialchars((string) $location->description, ENT_QUOTES, 'UTF-8')),
            'address' => [
                'address1' => $location->address1,
                'address2' => $location->address2,
                'city' => $location->city,
                'state' => $location->state,
                'zip' => $location->zip,
                'cityState' => $location->cityState,
                'fullAddress' => $location->fullAddress,
                'lattitude' => $location->lattitude,
                'longitude' => $location->longitude,
            ],
        ];
    }
}
