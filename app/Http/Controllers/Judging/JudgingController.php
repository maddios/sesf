<?php

namespace App\Http\Controllers\Judging;

use App\Http\Controllers\Controller;
use App\Models\ShowRegistrationCar;
use App\Models\User;
use App\Support\Judging\JudgingId;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Shared lookups for the standalone judging pages. Authorization never reads the URL path: the
 * car comes from the decoded judging id and the show from the car's registration.
 */
abstract class JudgingController extends Controller
{
    protected function findCar(string $token): ShowRegistrationCar
    {
        $id = JudgingId::decode($token);
        $car = $id === null ? null : ShowRegistrationCar::query()
            ->with(['registration.carShow', 'registration.user', 'car.body'])
            ->find($id);

        if ($car === null || $car->registration === null || $car->registration->carShow === null) {
            abort(404);
        }

        return $car;
    }

    /** Sends a guest to the SPA login, coming back to $path afterwards. */
    protected function toLogin(string $path): RedirectResponse
    {
        return redirect('/#/login?redirect='.rawurlencode($path));
    }

    protected function denied(Request $request, string $title, string $message, string $returnPath): Response
    {
        $user = $request->user();

        return response()->view('judging.denied', [
            'title' => $title,
            'message' => $message,
            'email' => $user instanceof User ? (string) $user->email : '',
            'signOutRedirect' => '/#/login?redirect='.rawurlencode($returnPath),
        ], 403);
    }
}
