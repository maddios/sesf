<?php

namespace App\Http\Resources\Admin;

use App\Models\UserGuest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A registration guest for the admin list. Email and names are personal: the admin API shows them,
 * the audit log does not (AuditLog::isSensitive).
 *
 * @mixin UserGuest
 */
final class RegistrationGuestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var UserGuest $guest */
        $guest = $this->resource;

        return [
            'id' => (int) $guest->id,
            'registration_id' => (int) $guest->registration_id,
            'show_id' => (int) $guest->show_id,
            'show_name' => $guest->carShow?->name,
            'user_id' => (int) $guest->user_id,
            'member_name' => $guest->registration?->user?->fullName,
            'first' => $guest->first,
            'last' => $guest->last,
            'email' => $guest->email,
            'type_id' => $guest->type_id->value,
            'type' => $guest->type_id->label(),
            'has_signed' => (bool) $guest->has_signed,
            'created_at' => $guest->created_at?->toIso8601String(),
            'updated_at' => $guest->updated_at?->toIso8601String(),
        ];
    }
}
