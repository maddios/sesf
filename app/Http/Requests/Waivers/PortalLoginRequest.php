<?php

namespace App\Http\Requests\Waivers;

use App\Http\Requests\Auth\LoginRequest;

/**
 * POST waiver/{showId}/login. Same rules and failure shape as the password login (HTTP 401 with a
 * JSON array of messages), so the portal goes through SessionController::login unchanged.
 */
class PortalLoginRequest extends LoginRequest {}
