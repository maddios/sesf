<?php

namespace App\Http\Resources\Admin;

use App\Models\ShowSponsor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One sponsor attached to an event. id is the show_sponsor link id (what reorder sends); sponsor_id is the sponsor.
 *
 * @mixin ShowSponsor
 */
final class EventSponsorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ShowSponsor $link */
        $link = $this->resource;

        return [
            'id' => (int) $link->id,
            'sponsor_id' => (int) $link->sponsor_id,
            'name' => $link->sponsor?->name,
            'URL' => $link->sponsor?->URL,
            'is_enabled' => (bool) $link->sponsor?->is_enabled,
            'sort_order' => (int) $link->sort_order,
            'type_id' => $link->type_id?->value,
            'type' => $link->type_id?->label(),
        ];
    }
}
