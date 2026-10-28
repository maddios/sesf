<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RegistrationGuestRequest;
use App\Http\Requests\Registration\RegistrationErrors;
use App\Http\Resources\Admin\RegistrationGuestResource;
use App\Models\ShowRegistration;
use App\Models\UserGuest;
use App\Services\Admin\AdminRegistrationWriter;
use App\Services\Registration\Writer\RegistrationRejected;
use App\Support\Admin\AdminQuery;
use App\Support\Admin\AuditLog;
use App\Support\Admin\ChangeSet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/**
 * admin-api/registration-guests: guests entered on registrations (user_guest). Creates, edits and deletes go
 * through AdminRegistrationWriter, so amounts stay in sync with the registration.
 */
final class RegistrationGuestController extends Controller
{
    /** Fields an admin edit can change (the audit diff). */
    private const EDITABLE = ['registration_id', 'user_id', 'first', 'last', 'email', 'type_id'];

    public function __construct(private readonly AdminRegistrationWriter $writer) {}

    public function index(Request $request): JsonResponse
    {
        return AdminQuery::for($this->query(), $request)
            ->sortable(['id', 'first', 'last', 'type_id', 'show_id', 'registration_id', 'created_at'], default: 'last')
            ->searchable(['first', 'last', 'email'])
            ->filterable([
                'show_id' => ['eq'],
                'registration_id' => ['eq'],
                'type_id' => ['eq'],
            ])
            ->respond(RegistrationGuestResource::class);
    }

    public function show(int $guest): RegistrationGuestResource
    {
        return new RegistrationGuestResource($this->find($guest));
    }

    public function store(RegistrationGuestRequest $request): JsonResponse
    {
        /** @var ShowRegistration $registration */
        $registration = $request->targetRegistration();

        try {
            $guest = $this->writer->addGuest($registration, $request->guestInput());
        } catch (RegistrationRejected $e) {
            return RegistrationErrors::adminResponse($e);
        }

        AuditLog::record('registration_guest.create', 'registration_guest', [$guest->id], ChangeSet::between([], $this->snapshot($guest)), ['registration_id' => $registration->id]);

        return (new RegistrationGuestResource($this->find($guest->id)))->response()->setStatusCode(201);
    }

    public function update(RegistrationGuestRequest $request, int $guest): RegistrationGuestResource|JsonResponse
    {
        $model = $this->find($guest);
        $before = $this->snapshot($model);

        try {
            if ($request->moves()) {
                /** @var ShowRegistration $target */
                $target = $request->targetRegistration();
                $this->writer->moveGuest($model, $target, $request->guestInput());
            } else {
                $this->writer->updateGuest($model, $request->guestInput());
            }
        } catch (RegistrationRejected $e) {
            return RegistrationErrors::adminResponse($e);
        }

        $fresh = $this->find($guest);
        AuditLog::record('registration_guest.update', 'registration_guest', [$guest], ChangeSet::between($before, $this->snapshot($fresh)));

        return new RegistrationGuestResource($fresh);
    }

    public function destroy(int $guest): JsonResponse
    {
        $model = $this->find($guest);

        try {
            $this->writer->deleteGuest($model);
        } catch (RegistrationRejected $e) {
            return RegistrationErrors::adminResponse($e);
        }

        AuditLog::record('registration_guest.delete', 'registration_guest', [$guest], AuditLog::deleted($model), ['registration_id' => (int) $model->registration_id]);

        return response()->json(null, 204);
    }

    /**
     * @return Builder<UserGuest>
     */
    private function query(): Builder
    {
        return UserGuest::query()->with(['carShow:id,name', 'registration.user:id,first,last']);
    }

    private function find(int $id): UserGuest
    {
        return $this->query()->find($id) ?? abort(404, 'Registration guest not found.');
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(UserGuest $guest): array
    {
        return Arr::only($guest->getAttributes(), self::EDITABLE);
    }
}
