<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Registration\RegistrationErrors;
use App\Http\Requests\Registration\SaveRegistrationRequest;
use App\Http\Requests\Registration\UpdateExtraRequest;
use App\Services\Registration\RegistrationWriter;
use App\Services\Registration\Writer\RegistrationProjector;
use App\Services\Registration\Writer\RegistrationRejected;
use Illuminate\Http\JsonResponse;

/**
 * Registration writes for the Vue event pages (legacy EventProfile::postRegistration, postExtra).
 * Contract and rules: docs/registration-write.md. Every route requires sign-in (401 JSON) and a
 * CSRF token (web middleware), and is throttled per user.
 *
 * Responses reuse the read endpoints, so a write returns exactly what the matching GET returns.
 */
class EventRegistrationWriteController extends Controller
{
    public function __construct(
        private readonly RegistrationWriter $writer,
        private readonly RegistrationProjector $projector,
        private readonly EventRegistrationReadController $read,
    ) {}

    /** POST event/registration?show_id[&user_id]: 200 = the GET event/registration payload. */
    public function save(SaveRegistrationRequest $request): JsonResponse
    {
        try {
            $this->writer->save($request->show(), $request->targetUser(), $request->registrationData(), $request->isOnBehalf());
        } catch (RegistrationRejected $e) {
            return RegistrationErrors::response($e->errors());
        }

        return $this->read->registration($request);
    }

    /** POST event/registration/quote: same body and checks, nothing written; {payment: {...}}. */
    public function quote(SaveRegistrationRequest $request): JsonResponse
    {
        try {
            $result = $this->projector->quote($request->show(), $request->existingRegistration(), $request->registrationData());
        } catch (RegistrationRejected $e) {
            return RegistrationErrors::response($e->errors());
        }

        return response()->json(['payment' => RegistrationProjector::payment($result)]);
    }

    /** POST event/extra?extra_id=<auth_key>[&show_id][&user_id] {num_extras}: 200 = the GET event/extra payload. */
    public function extra(UpdateExtraRequest $request): JsonResponse
    {
        try {
            $this->writer->saveExtra($request->targetUser(), $request->extra(), $request->quantity(), $request->isOnBehalf());
        } catch (RegistrationRejected $e) {
            return RegistrationErrors::response($e->errors());
        }

        return $this->read->extra($request);
    }
}
