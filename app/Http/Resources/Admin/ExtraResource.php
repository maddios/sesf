<?php

namespace App\Http\Resources\Admin;

use App\Models\ShowExtra;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A show extra for the admin. price is the decimal string (Money2). auth_key is the public landing key.
 * in_use = somebody registered for it, so it cannot be deleted.
 *
 * @mixin ShowExtra
 */
final class ExtraResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ShowExtra $extra */
        $extra = $this->resource;
        $registrations = (int) ($extra->getAttribute('registrations_count') ?? 0);

        return [
            'id' => (int) $extra->id,
            'show_id' => (int) $extra->show_id,
            'show_name' => $extra->carShow?->name,
            'name' => $extra->name,
            'description' => $extra->description,
            'auth_key' => $extra->auth_key,
            'price' => $extra->price,
            'type_id' => $extra->type_id->value,
            'type' => $extra->type_id->label(),
            'min_value' => (int) $extra->min_value,
            'max_value' => (int) $extra->max_value,
            'is_enabled' => (bool) $extra->is_enabled,
            'registrations_count' => $registrations,
            'in_use' => $registrations > 0,
            'created_at' => $extra->created_at?->toIso8601String(),
            'updated_at' => $extra->updated_at?->toIso8601String(),
        ];
    }
}
