<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RegistrationUpdateRequest;
use App\Http\Resources\Admin\RegistrationResource;
use App\Models\ShowRegistration;
use App\Services\Admin\AdminRegistrationWriter;
use App\Support\Admin\AdminQuery;
use App\Support\Admin\AuditLog;
use App\Support\Admin\ChangeSet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/**
 * admin-api/registrations: a member's registration for one event (legacy CarShowRegistration admin).
 * Edits go through AdminRegistrationWriter, which uses the member registration writer.
 */
final class RegistrationController extends Controller
{
    /** Fields an admin edit can change (the audit diff). */
    private const EDITABLE = ['donation', 'arrival_date', 'arrival_time', 'is_cancelled', 'is_paid', 'amount_owed'];

    public function __construct(private readonly AdminRegistrationWriter $writer) {}

    public function index(Request $request): JsonResponse
    {
        return AdminQuery::for($this->query(), $request)
            ->sortable(['id', 'invoice_id', 'show_id', 'is_cancelled', 'is_paid', 'donation', 'amount_owed', 'amount_received', 'discount', 'created_at'], default: 'id', direction: 'desc')
            ->searchable(['invoice_id'])
            ->filterable([
                'show_id' => ['eq'],
                'is_cancelled' => ['eq'],
                'is_paid' => ['eq'],
            ])
            ->filterUsing('member', function (Builder $query, string $value): void {
                $pattern = '%'.AdminQuery::escapeLike($value).'%';
                $query->whereHas('user', fn (Builder $user) => $user->where('first', 'like', $pattern)->orWhere('last', 'like', $pattern));
            })
            ->respond(RegistrationResource::class);
    }

    public function show(int $registration): RegistrationResource
    {
        return new RegistrationResource($this->find($registration));
    }

    public function update(RegistrationUpdateRequest $request, int $registration): RegistrationResource
    {
        $model = $this->find($registration);
        $before = $this->snapshot($model);

        $this->writer->update(
            $model,
            $request->donationDollars(),
            $request->arrivalDate(),
            $request->arrivalTime(),
            $request->boolean('is_cancelled'),
        );

        $fresh = $this->find($registration);
        AuditLog::record('registration.update', 'registration', [$registration], ChangeSet::between($before, $this->snapshot($fresh)));

        return new RegistrationResource($fresh);
    }

    /**
     * @return Builder<ShowRegistration>
     */
    private function query(): Builder
    {
        return ShowRegistration::query()
            ->with(['carShow', 'user:id,first,last'])
            ->withCount(['cars', 'guests', 'shirts']);
    }

    private function find(int $id): ShowRegistration
    {
        return $this->query()->find($id) ?? abort(404, 'Registration not found.');
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(ShowRegistration $registration): array
    {
        return Arr::only($registration->getAttributes(), self::EDITABLE);
    }
}
