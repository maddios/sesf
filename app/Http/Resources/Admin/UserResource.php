<?php

namespace App\Http\Resources\Admin;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A member as the admin sees it. Explicit fields only: password, remember_token and any other column
 * are never sent. is_disabled = soft-deleted (disable/restore). cars_count is set when the query loaded it.
 *
 * @mixin User
 */
final class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;

        return [
            'id' => (int) $user->id,
            'first' => $user->first,
            'last' => $user->last,
            'full_name' => $user->fullName,
            'email' => $user->email,
            'phone' => $user->phone,
            'phone2' => $user->phone2,
            'heard_from' => $user->heard_from,
            'address1' => $user->address1,
            'address2' => $user->address2,
            'city' => $user->city,
            'state' => $user->state,
            'zip' => $user->zip,
            'type_id' => (int) $user->getRawOriginal('type_id'),
            'type_name' => $user->type_id->label(),
            'is_confirmed' => (int) $user->is_confirmed,
            'has_image' => (bool) $user->has_image,
            'image_url' => $user->imageURL,
            'is_disabled' => $user->trashed(),
            'disabled_at' => $user->deleted_at?->toIso8601String(),
            'cars_count' => (int) ($user->getAttribute('cars_count') ?? 0),
            'date_registered' => $user->date_registered?->toIso8601String(),
            'created_at' => $user->created_at?->toIso8601String(),
            'updated_at' => $user->updated_at?->toIso8601String(),
        ];
    }
}
