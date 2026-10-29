<?php

namespace App\Http\Resources\Members;

use App\Models\User;
use App\Support\Privacy\ViewerPrivacy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One member as shown in the directory, on a car owner, and as the public member-info.
 *
 * Guests get the masked form: first name and last initial, state only, no last name.
 * Members get first and last name and "City, ST". Never email, phone, address, coordinates,
 * is_admin, type_id or any token.
 *
 * @mixin User
 */
class MemberDirectoryResource extends JsonResource
{
    public function __construct($resource, private readonly ViewerPrivacy $privacy)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;
        $isMember = $this->privacy->isMember();

        $data = [
            'id' => (int) $user->id,
            'has_image' => (bool) $user->has_image,
            'profileURL' => $user->profileURL,
            'imageURL' => $user->imageURL,
            'firstNameLastInitial' => $user->firstNameLastInitial,
            'fullName' => $isMember ? $user->fullName : $user->firstNameLastInitial,
            'cityState' => $isMember ? $user->cityState : (string) $user->state,
        ];

        if ($isMember) {
            $data['first'] = $user->first;
            $data['last'] = $user->last;
        }

        return $data;
    }
}
