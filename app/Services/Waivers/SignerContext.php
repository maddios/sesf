<?php

namespace App\Services\Waivers;

use App\Models\CarShow;
use App\Models\ShowRegistration;
use App\Models\UserGuest;

/** The event and person behind a waiver (legacy validateSignerContextForWaiver). */
final class SignerContext
{
    public function __construct(
        public readonly CarShow $show,
        public readonly ?ShowRegistration $registration,
        public readonly ?UserGuest $guest,
        public readonly string $first,
        public readonly string $last,
        public readonly string $email,
    ) {}
}
