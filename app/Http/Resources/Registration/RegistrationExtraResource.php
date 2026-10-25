<?php

namespace App\Http\Resources\Registration;

use App\Models\ShowExtra;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A show extra on the registration page (legacy ShowExtra::toArray for the `extras` list).
 * auth_key is included on purpose: it is the public key used in extras landing URLs.
 *
 * @mixin ShowExtra
 */
class RegistrationExtraResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ShowExtra $extra */
        $extra = $this->resource;

        return [
            'id' => (int) $extra->id,
            'is_enabled' => (bool) $extra->is_enabled,
            'show_id' => (int) $extra->show_id,
            'name' => $extra->name,
            'description' => $extra->description,
            'auth_key' => $extra->auth_key,
            'price' => (string) $extra->price,
            'type_id' => $extra->type_id->value,
            'min_value' => (int) $extra->min_value,
            'max_value' => (int) $extra->max_value,
        ];
    }
}
