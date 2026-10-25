<?php

use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureJudgeForShow;
use App\Http\Middleware\EnsureJudgeOrAdmin;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The SPA login is a hash route, so there is no named "login" route for guests to be sent to.
        $middleware->redirectGuestsTo(fn () => '/#/login');

        // Ends sessions on other devices when the password changes (the stored password hash no longer matches).
        $middleware->web(append: [
            AuthenticateSession::class,
        ]);

        $middleware->alias([
            'admin' => EnsureAdmin::class,
            'judge_or_admin' => EnsureJudgeOrAdmin::class,
            'judge_for_show' => EnsureJudgeForShow::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
