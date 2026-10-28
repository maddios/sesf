<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RegistrationUpdateRequest;
use App\Http\Requests\Registration\RegistrationErrors;
use App\Http\Resources\Admin\RegistrationResource;
use App\Models\Car;
use App\Models\ShowRegistration;
use App\Services\Admin\AdminRegistrationWriter;
use App\Services\Registration\RegistrationPricing;
use App\Services\Registration\Writer\RegistrationChanged;
use App\Services\Registration\Writer\RegistrationRejected;
use App\Support\Admin\AdminQuery;
use App\Support\Admin\AuditLog;
use App\Support\Admin\ChangeSet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * admin-api/registrations: a member's registration for one event (legacy CarShowRegistration admin).
 * Edits go through AdminRegistrationWriter, which uses the member registration writer.
 */
final class RegistrationController extends Controller
{
    /** Fields an admin edit can change (the audit diff); a reassignment also clears the waiver and check-in. */
    private const EDITABLE = [
        'invoice_id', 'user_id', 'donation', 'arrival_date', 'arrival_time', 'is_cancelled', 'is_paid',
        'amount_owed', 'amount_received', 'amount_received_actual', 'discount', 'discount_note',
        'waiver_id', 'has_signed', 'is_checked_in', 'date_checked_in',
    ];

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

    /**
     * The cars an admin may put on the registration: the member's cars (or those of ?user_id=, the member it is
     * being reassigned to) plus the cars already on it.
     */
    public function cars(Request $request, int $registration): JsonResponse
    {
        $model = $this->find($registration);
        $selected = $model->cars->modelKeys();

        $cars = Car::query()
            ->where('user_id', $request->integer('user_id') ?: $model->user_id)
            ->orWhereIn('id', $selected)
            ->orderBy('year')
            ->orderBy('model')
            ->get(['id', 'year', 'model']);

        return response()->json([
            'data' => $cars->map(fn (Car $car): array => [
                'id' => (int) $car->id,
                'label' => trim($car->name),
                'selected' => in_array($car->id, $selected, true),
            ])->values(),
        ]);
    }

    public function update(RegistrationUpdateRequest $request, int $registration): RegistrationResource|JsonResponse
    {
        $model = $this->find($registration);
        $before = $this->snapshot($model);

        try {
            $this->writer->update($model, $request->edit());
        } catch (RegistrationRejected $e) {
            return RegistrationErrors::adminResponse($e);
        } catch (RegistrationChanged $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'registration_changed'], 409);
        } catch (UniqueConstraintViolationException) {
            // A concurrent edit took the invoice number or the member first (validation checks both).
            return response()->json(['message' => 'That invoice number or member is already used for this event.'], 409);
        }

        $fresh = $this->find($registration);
        AuditLog::record('registration.update', 'registration', [$registration], ChangeSet::between($before, $this->snapshot($fresh)));

        return new RegistrationResource($fresh);
    }

    /**
     * Deletes a registration (legacy frozennode delete); its cars, guests, shirts and extras go with it. A registration
     * with money received, PayPal payments, waivers (its own or a guest's, which the guest cascade would delete) or
     * judging answers (no cascade) is a 409: cancel it instead, so the records are kept.
     */
    public function destroy(int $registration): JsonResponse
    {
        $model = $this->find($registration);

        $references = DB::transaction(function () use ($model): array {
            $locked = ShowRegistration::query()->whereKey($model->id)->lockForUpdate()->firstOrFail();
            $references = array_filter([
                'amount_received' => RegistrationPricing::signedCents((string) $locked->amount_received) !== 0 ? 1 : 0,
                'paypal_payment' => DB::table('paypal_payment')->where('registration_id', $model->id)->count(),
                'waiver' => DB::table('waiver')->where('show_registration_id', $model->id)->count(),
                'guest_waiver' => DB::table('waiver')->whereIn('guest_id', DB::table('user_guest')->select('id')->where('registration_id', $model->id))->count(),
                'judging_answers' => DB::table('show_registration_car_judging')
                    ->whereIn('show_registration_car_id', DB::table('show_registration_car')->select('id')->where('registration_id', $model->id))
                    ->count(),
            ]);
            if ($references === []) {
                $locked->delete();
            }

            return $references;
        });

        if ($references !== []) {
            return response()->json([
                'message' => 'This registration has payments, waivers or judging answers ('.implode(', ', array_keys($references)).') and cannot be deleted. Cancel it instead.',
                'references' => $references,
            ], 409);
        }

        AuditLog::record('registration.delete', 'registration', [$model->id], AuditLog::deleted($model));

        return response()->json(null, 204);
    }

    /**
     * @return Builder<ShowRegistration>
     */
    private function query(): Builder
    {
        return ShowRegistration::query()->with([
            'carShow',
            'user:id,first,last',
            'cars',
            'guests',
            'shirts.shirtType',
            'extras.showExtra',
        ]);
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
        $carIds = $registration->cars->modelKeys();
        sort($carIds);

        return Arr::only($registration->getAttributes(), self::EDITABLE) + ['car_ids' => $carIds];
    }
}
