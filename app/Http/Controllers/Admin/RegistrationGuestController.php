<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RegistrationGuestUpdateRequest;
use App\Http\Resources\Admin\RegistrationGuestResource;
use App\Models\UserGuest;
use App\Services\Admin\AdminRegistrationWriter;
use App\Support\Admin\AdminQuery;
use App\Support\Admin\AuditLog;
use App\Support\Admin\ChangeSet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/**
 * admin-api/registration-guests: guests entered on registrations (user_guest). Edits and deletes go
 * through AdminRegistrationWriter, so amounts stay in sync with the registration.
 */
final class RegistrationGuestController extends Controller
{
    /** Fields an admin edit can change (the audit diff). */
    private const EDITABLE = ['first', 'last', 'email', 'type_id'];

    public function __construct(private readonly AdminRegistrationWriter $writer) {}

    public function index(Request $request): JsonResponse
    {
        return AdminQuery::for($this->query(), $request)
            ->sortable(['id', 'first', 'last', 'type_id', 'show_id', 'created_at'], default: 'last')
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

    public function update(RegistrationGuestUpdateRequest $request, int $guest): RegistrationGuestResource
    {
        $model = $this->find($guest);
        $before = $this->snapshot($model);

        $this->writer->updateGuest($model, $request->guestInput());

        $fresh = $this->find($guest);
        AuditLog::record('registration_guest.update', 'registration_guest', [$guest], ChangeSet::between($before, $this->snapshot($fresh)));

        return new RegistrationGuestResource($fresh);
    }

    public function destroy(int $guest): JsonResponse
    {
        $model = $this->find($guest);

        $this->writer->deleteGuest($model);

        AuditLog::record('registration_guest.delete', 'registration_guest', [$guest], [], ['registration_id' => (int) $model->registration_id]);

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
