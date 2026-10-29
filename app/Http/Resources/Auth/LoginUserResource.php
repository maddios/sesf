<?php

namespace App\Http\Resources\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The session payload the Vue SPA reads from POST /login/authenticate and POST /login/login.
 *
 * Keys and value types are the legacy UserAuth::loginUserData() contract. Values are the
 * member's own unmasked data. Credentials and tokens are never included.
 *
 * @mixin User
 */
class LoginUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;
        $preferences = $user->preferences;

        return [
            'user_id' => (int) $user->id,
            'user_level' => $user->type_id->value,
            'type_id' => $user->type_id->value,
            'email' => $user->email,
            'first_name' => $user->first,
            'profileURL' => $user->profileURL,
            'slideOutName' => $preferences !== null && $preferences->show_login_welcome ? 1 : 0,
            'fullAddress' => $user->fullAddress,
        ];
    }
}
