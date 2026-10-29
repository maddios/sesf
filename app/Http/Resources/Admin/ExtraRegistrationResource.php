<?php

namespace App\Http\Resources\Admin;

use App\Models\ShowExtraRegistration;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An extra bought on a registration: who bought it, for which event, which extra and how many.
 *
 * @mixin ShowExtraRegistration
 */
final class ExtraRegistrationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ShowExtraRegistration $row */
        $row = $this->resource;
        $user = $row->registration?->user;

        return [
            'id' => (int) $row->id,
            'registration_id' => (int) $row->registration_id,
            'user_name' => $user === null ? null : trim(($user->first ?? '').' '.($user->last ?? '')),
            'show_id' => $row->showExtra?->show_id === null ? null : (int) $row->showExtra->show_id,
            'show_name' => $row->showExtra?->carShow?->name,
            'show_extra_id' => (int) $row->show_extra_id,
            'extra_name' => $row->showExtra?->name,
            'num_extras' => $row->num_extras === null ? null : (int) $row->num_extras,
            'created_at' => $row->created_at?->toIso8601String(),
            'updated_at' => $row->updated_at?->toIso8601String(),
        ];
    }
}
