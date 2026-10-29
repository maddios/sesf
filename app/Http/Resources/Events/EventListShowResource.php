<?php

namespace App\Http\Resources\Events;

use App\Models\CarShow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A show on the car-show list. num_registered_members and num_registered_cars are set by the
 * controller's query.
 *
 * @mixin CarShow
 */
class EventListShowResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var CarShow $show */
        $show = $this->resource;

        return [
            'id' => (int) $show->id,
            'name' => $show->name,
            'short_name' => $show->short_name,
            'description' => $show->description,
            'short_description' => $show->getRawOriginal('short_description'),
            'shortDescription' => $show->shortDescription,
            'shortDescriptionHtml' => $show->shortDescriptionHtml(),
            'imageURL' => $show->imageURL,
            'profileURL' => $show->profileURL,
            'date_start' => $show->date_start?->format('Y-m-d'),
            'date_end' => $show->date_end?->format('Y-m-d'),
            'date_active' => $show->date_active?->format('Y-m-d H:i:s'),
            'date_registration_deactivation' => $show->date_registration_deactivation?->format('Y-m-d H:i:s'),
            'has_image' => (bool) $show->has_image,
            'ended' => $show->ended,
            'regOpen' => $show->regOpen,
            'primaryLocation' => $show->primaryLocation === null ? null : (new LocationResource($show->primaryLocation))->resolve(),
            'num_registered_members' => (int) $show->getAttribute('num_registered_members'),
            'num_registered_cars' => (int) $show->getAttribute('num_registered_cars'),
        ];
    }
}
