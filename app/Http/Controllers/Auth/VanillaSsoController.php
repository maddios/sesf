<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\JsConnect;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * Vanilla forum SSO endpoint (legacy UserAuth::vanilla at login/vanilla).
 *
 * Only a signed-in user gets identity data. A visitor who is not signed in gets the protocol's
 * empty-user reply (name and photourl empty). The legacy code printed nothing in that case, which
 * broke Vanilla's flow.
 */
class VanillaSsoController extends Controller
{
    public function handle(Request $request, JsConnect $jsConnect): Response
    {
        $user = Auth::user();
        $identity = $user instanceof User ? $this->identity($user) : [];

        $reply = $jsConnect->respond(
            $identity,
            $request->query(),
            (string) config('sesf.vanilla.client_id'),
            (string) config('sesf.vanilla.secret'),
            now()->getTimestamp(),
        );

        return response($reply['body'], 200, [
            'Content-Type' => $reply['contentType'],
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /**
     * The identity Vanilla stores for this member. Keys match the legacy vanilla() method.
     *
     * @return array<string, mixed>
     */
    private function identity(User $user): array
    {
        return [
            'uniqueid' => $user->id,
            'name' => $user->first.'_'.substr((string) $user->last, 0, 1).'_'.$user->id,
            'email' => $user->email,
            'photourl' => rtrim((string) config('app.url'), '/').'/'.ltrim($user->imageURL, '/'),
            'roles' => 'member'.($user->type_id === UserType::Administrator ? ',administrator' : ''),
        ];
    }
}
