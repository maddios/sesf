<?php

namespace App\Http\Resources\Members;

use App\Models\User;
use App\Support\Privacy\ViewerPrivacy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GET member/member-info. The subject and administrators get the full record (the model's hidden
 * list keeps password and remember_token out). Everyone else gets the directory form.
 *
 * @mixin User
 */
class MemberInfoResource extends JsonResource
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

        if (! $this->privacy->seesFullRecord($user)) {
            return (new MemberDirectoryResource($user, $this->privacy))->toArray($request);
        }

        $data = $user->toArray();
        unset($data['password'], $data['remember_token']);

        return $data;
    }
}
