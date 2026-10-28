<?php

namespace App\Http\Resources\Gallery;

use App\Models\CarShow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A car show as the gallery pages use it (legacy CarShow::toArray() public keys). `galleries` is
 * present when the relation was loaded; the controller loads only the galleries the viewer may see.
 *
 * @mixin CarShow
 */
class GalleryShowResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var CarShow $show */
        $show = $this->resource;

        $data = [
            'id' => (int) $show->id,
            'name' => $show->name,
            'short_name' => $show->short_name,
            'date_start' => $show->date_start?->format('Y-m-d'),
            'date_end' => $show->date_end?->format('Y-m-d'),
            'is_active' => $show->is_active ? 1 : 0,
            'has_image' => $show->has_image ? 1 : 0,
            'imageURL' => $show->imageURL,
            'profileURL' => $show->profileURL,
            'shortDescription' => $show->shortDescription,
            'regOpen' => $show->regOpen,
            'ended' => $show->ended,
        ];

        if ($show->relationLoaded('galleries')) {
            $data['galleries'] = GalleryResource::collection($show->galleries)->resolve($request);
        }

        return $data;
    }
}
