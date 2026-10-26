<?php

namespace App\Http\Resources\Admin;

use App\Models\CarShowAgenda;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A show agenda item for the admin: the event it belongs to, its type and its position within that event.
 *
 * @mixin CarShowAgenda
 */
final class AgendaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var CarShowAgenda $item */
        $item = $this->resource;

        return [
            'id' => (int) $item->id,
            'show_id' => (int) $item->show_id,
            'show_name' => $item->show?->name,
            'name' => $item->name,
            'text' => $item->text,
            'type_id' => $item->type_id->value,
            'type' => $item->type_id->label(),
            'is_visible' => (bool) $item->is_visible,
            'sort_order' => (int) $item->sort_order,
            'created_at' => $item->created_at?->toIso8601String(),
            'updated_at' => $item->updated_at?->toIso8601String(),
        ];
    }
}
