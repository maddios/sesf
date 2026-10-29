<?php

namespace App\Listeners;

use App\Support\Admin\ImpersonationSession;
use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Session\Session;

/**
 * Every web login (password, emailed code or link, waiver portal, password reset, registration, remember-me cookie)
 * drops the impersonation marker, so a session can never carry an administrator's id into someone else's login.
 * ImpersonationController::start sets the marker after its own login.
 *
 * Laravel auto-discovers this listener from its type-hinted handle() method.
 */
class ClearImpersonationOnLogin
{
    public function __construct(private readonly Session $session) {}

    public function handle(Login $event): void
    {
        if ($event->guard === 'web') {
            ImpersonationSession::forget($this->session);
        }
    }
}
