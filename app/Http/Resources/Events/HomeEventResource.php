<?php

namespace App\Http\Resources\Events;

use App\Models\CarShow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Event card on the home page. primary_sponsors must be eager loaded.
 *
 * @mixin CarShow
 */
class HomeEventResource extends JsonResource
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
            'imageURL' => $show->imageURL,
            'profileURL' => $show->profileURL,
            'date_start' => $show->date_start?->format('Y-m-d'),
            'date_end' => $show->date_end?->format('Y-m-d'),
            'has_image' => (bool) $show->has_image,
            'ended' => $show->ended,
            'regOpen' => $show->regOpen,
            'primary_sponsors' => SponsorResource::collection($show->primary_sponsors)->resolve(),
        ];
    }
}
