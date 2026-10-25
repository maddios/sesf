<?php

namespace App\Http\Resources\Events;

use App\Models\Sponsor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One sponsor on a show. The tier and show come from the show_sponsor row: either as attributes of a
 * query that selects them, or from the pivot of a primary_sponsors relation load.
 * internal_notes is hidden on the model and is not listed here.
 *
 * @mixin Sponsor
 */
class SponsorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Sponsor $sponsor */
        $sponsor = $this->resource;
        $source = $sponsor->relationLoaded('pivot') ? $sponsor->getRelation('pivot') : $sponsor;

        return [
            'id' => (int) $sponsor->id,
            'name' => $sponsor->name,
            'URL' => $sponsor->URL,
            'description' => $sponsor->description,
            'shortDescription' => $sponsor->shortDescription,
            'imageURL' => $sponsor->imageURL,
            'type_id' => (int) $source->getAttribute('type_id'),
            'show_id' => (int) $source->getAttribute('show_id'),
        ];
    }
}
