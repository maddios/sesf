<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\EmailSigninRequest;
use App\Http\Requests\Auth\EmailSigninVerifyRequest;
use App\Http\Resources\Auth\LoginUserResource;
use App\Models\LoginEmailSignin;
use App\Models\User;
use App\Services\Auth\EmailSigninService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EmailSigninController extends Controller
{
    /**
     * Starts an emailed sign-in. The 200 body is the same whether or not the email belongs to a
     * user and whether or not mail was sent. Only the daily quota gets a different answer, and it
     * says nothing about any one address.
     */
    public function requestCode(EmailSigninRequest $request, EmailSigninService $service): JsonResponse
    {
        if (! LoginEmailSignin::isAvailable()) {
            return response()->json([
                'ok' => false,
                'message' => 'Email sign-in is temporarily unavailable. Please use your password.',
            ], 503);
        }

        $user = User::query()->where('email', $request->validated('email'))->first();

        if ($user) {
            $service->issue($user, $request->input('redirect_path'), $request->ip());
        }

        return response()->json(['ok' => true]);
    }

    public function verifyCode(EmailSigninVerifyRequest $request, EmailSigninService $service): JsonResponse
    {
        $validated = $request->validated();
        $row = $service->consumeCode((string) $validated['email'], (string) $validated['code']);

        if (! $row || ! $row->user) {
            return response()->json([EmailSigninService::INVALID_MESSAGE], 401);
        }

        Auth::login($row->user, false);
        $request->session()->regenerate();

        return response()->json(
            (new LoginUserResource($row->user))->resolve($request) + ['redirect_path' => $row->redirect_path],
        );
    }

    public function consume(Request $request, string $token, EmailSigninService $service): RedirectResponse
    {
        $row = $service->consumeToken($token);

        if (! $row || ! $row->user) {
            return redirect('/#/login?email_signin=invalid');
        }

        Auth::login($row->user, false);
        $request->session()->regenerate();

        return redirect(EmailSigninService::redirectTarget($row->redirect_path));
    }
}
