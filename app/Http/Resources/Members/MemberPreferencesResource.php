<?php

namespace App\Http\Resources\Members;

use App\Models\User;
use App\Support\Privacy\ViewerPrivacy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GET member/member-preferences. Legacy exposed only allow_private_message (0 when no row).
 *
 * @mixin User
 */
class MemberPreferencesResource extends JsonResource
{
    public function __construct($resource, private readonly ViewerPrivacy $privacy)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, int>
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;

        return ['allow_private_message' => $this->privacy->allowsPrivateMessage($user) ? 1 : 0];
    }
}
