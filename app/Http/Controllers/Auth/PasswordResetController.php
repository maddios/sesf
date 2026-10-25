<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Resources\Auth\LoginUserResource;
use App\Mail\ResetPasswordMail;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PasswordResetController extends Controller
{
    /**
     * Sends a reset link when the email belongs to a user. The response is always the same, so
     * it does not show which addresses are registered.
     */
    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        ResetPasswordMail::registerUrlResolver();

        $email = (string) $request->validated('email');

        Password::broker()->sendResetLink(
            ['email' => $email],
            function (User $user, string $token): void {
                try {
                    Mail::to($user->email)->send(new ResetPasswordMail($token, (string) $user->email));
                } catch (\Throwable $e) {
                    Log::error('Password reset mail failed: '.$e->getMessage(), ['exception' => $e]);
                }
            },
        );

        return response()->json(['ok' => true]);
    }

    /**
     * Sets the new password, logs the user in, and returns the login payload. A bad token or an
     * unknown email gets the same 422, so the response does not reveal which emails exist.
     */
    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $data = $request->validated();
        $confirmation = (string) $request->input('password_confirmation');
        $resetUser = null;

        try {
            $status = Password::broker()->reset(
                [
                    'token' => $data['token'],
                    'email' => $data['email'],
                    'password' => $data['password'],
                    'password_confirmation' => $confirmation,
                ],
                function (User $user, string $password) use (&$resetUser, $confirmation): void {
                    $user->setPasswordWithConfirmation($password, $confirmation);
                    $user->setRememberToken(Str::random(60));
                    $user->save();

                    $resetUser = $user;
                },
            );
        } catch (\RuntimeException) {
            // A stored token that is not a bcrypt hash (a legacy plain-text reminder row) cannot
            // match. The hasher throws for it, so treat it as an invalid token.
            $status = Password::INVALID_TOKEN;
        }

        if ($status !== Password::PASSWORD_RESET || ! $resetUser instanceof User) {
            throw ValidationException::withMessages([
                'token' => [__('passwords.token')],
            ]);
        }

        Auth::login($resetUser, false);
        $request->session()->regenerate();

        return response()->json((new LoginUserResource($resetUser))->resolve($request));
    }
}
