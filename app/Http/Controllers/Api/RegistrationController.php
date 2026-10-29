<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Account\RegisterRequest;
use App\Http\Resources\Auth\LoginUserResource;
use App\Mail\AdminNewAccountMail;
use App\Mail\NewAccountMail;
use App\Models\User;
use App\Support\AfterResponse;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * POST member/register. Replaces legacy MemberProfile::postRegister.
 *
 * Security changes from the legacy code:
 *  - no name lookup, so no masked email address is returned for an existing name;
 *  - a duplicate email gets the same generic message whether or not the account is deleted;
 *  - the welcome and registration-notice mails are sent after the response.
 */
class RegistrationController extends Controller
{
    public function store(RegisterRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = new User;
        $user->forceFill([
            // Set in memory as well as in the database default, because the login payload reads it.
            'type_id' => UserType::Regular,
            'first' => $data['first_name'],
            'last' => $data['last_name'],
            'email' => $data['email'],
            'phone' => $data['phone'],
            'address1' => $data['street'],
            'address2' => $data['street2'] ?? null,
            'city' => $data['city'],
            'state' => $data['state'],
            'zip' => $data['zip'],
        ]);

        if (! $user->setPasswordWithConfirmation((string) $data['password'], (string) $request->input('password_confirmation'))) {
            return response()->json(['New password is not valid'], 400);
        }

        try {
            // The observer creates the preferences row and queues geocoding in the same save.
            $user->save();
        } catch (UniqueConstraintViolationException) {
            // A concurrent registration won the race for this email.
            return response()->json([RegisterRequest::DUPLICATE_MESSAGE], 400);
        }

        Auth::login($user);
        $request->session()->regenerate();

        AfterResponse::run(function () use ($user): void {
            $this->sendMail(fn () => Mail::to($user->email)->send(new NewAccountMail($user)));
            $this->sendMail(fn () => Mail::to((string) config('sesf.reg_email'))->send(new AdminNewAccountMail($user)));
        });

        return response()->json((new LoginUserResource($user))->resolve($request));
    }

    private function sendMail(\Closure $send): void
    {
        try {
            $send();
        } catch (\Throwable $e) {
            Log::error('Registration mail failed: '.$e->getMessage(), ['exception' => $e]);
        }
    }
}
