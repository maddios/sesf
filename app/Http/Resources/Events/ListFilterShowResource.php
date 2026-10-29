<?php

namespace App\Http\Resources\Events;

use App\Models\CarShow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A show offered as a filter option.
 *
 * @mixin CarShow
 */
class ListFilterShowResource extends JsonResource
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
            'date_start' => $show->date_start?->format('Y-m-d'),
            'date_end' => $show->date_end?->format('Y-m-d'),
        ];
    }
}
