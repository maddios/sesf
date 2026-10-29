<?php

namespace App\Http\Controllers\Admin\Judging;

use App\Enums\JudgingType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Judging\AssignJudgingTypeRequest;
use App\Models\CarShow;
use App\Models\ShowRegistration;
use App\Models\ShowRegistrationCar;
use App\Models\User;
use App\Services\Judging\JudgedCar;
use App\Services\Judging\JudgingQrCodes;
use App\Services\Judging\JudgingResults;
use App\Services\Judging\JudgingScores;
use App\Support\Admin\AuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * admin-api/judging: the judging list of a show (legacy admin2/judging_list). Judges see only the
 * shows they are assigned to (judge_for_show on every show route); admins see all. Adding or
 * removing a car's judging type is limited to registrations of that show (legacy addJudging and
 * removeJudging were not scoped), and a judge cannot change their own car's. Before results are visible a judge
 * sees no entrant emails and no score for their own car.
 */
final class JudgingEntryController extends Controller
{
    private const RESOURCE = 'judging_entry';

    public function shows(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $shows = CarShow::query()
            ->visibleTo($user->id, $user->type_id)
            ->orderByDesc('car_show.date_end')
            ->orderByDesc('car_show.id')
            ->get(['car_show.id', 'car_show.name', 'car_show.date_end', 'car_show.is_judging_released']);

        return response()->json(['data' => $shows->map(fn (CarShow $show): array => [
            'id' => $show->id,
            'name' => $show->name,
            'date_end' => $show->date_end?->toDateString(),
            'is_judging_released' => $show->is_judging_released === true,
        ])->values()]);
    }

    public function index(Request $request, int $show, JudgingScores $scores): JsonResponse
    {
        $typeId = $request->query('judging_type_id');
        if ($typeId !== null && (! is_string($typeId) || ! ctype_digit($typeId))) {
            throw ValidationException::withMessages(['judging_type_id' => 'The judging type must be a number.']);
        }

        $model = $this->show($show);
        $cars = $scores->forShow($model->id, $typeId === null ? null : (int) $typeId);
        /** @var User $user */
        $user = $request->user();
        $restricted = ! $user->isAdmin() && ! $model->judgingResultsVisible();

        return response()->json(['data' => array_map(fn (JudgedCar $car): array => [
            'id' => $car->id,
            'car_id' => $car->carId,
            'registration_id' => $car->registrationId,
            'invoice_id' => $car->invoiceId,
            'year' => $car->year,
            'body' => $car->body,
            'model' => $car->model,
            'judging_type_id' => $car->judgingTypeId,
            'judging_type' => $car->judgingType,
            'owner_name' => $car->ownerName(),
            'owner_email' => $restricted ? null : $car->ownerEmail,
            'num_questions' => $car->numQuestions,
            'num_answered' => max(0, $car->numQuestions - $car->numEmptyQuestions),
            'score' => $restricted && $car->ownerId === (int) $user->id ? null : $car->score,
            'judging_url' => JudgingQrCodes::judgingUrl($car->id),
            'answers_url' => JudgingResults::answersUrl($car->id),
            'qr_url' => '/judging_qr_code/'.$car->token(),
        ], $cars)]);
    }

    /** Registrations of the show with cars that have no judging type yet (legacy getUsersWithoutJudging). */
    public function unjudged(int $show): JsonResponse
    {
        $registrations = ShowRegistration::query()
            ->where('show_id', $this->show($show)->id)
            ->whereHas('registrationCars', fn (Builder $q) => $q->whereNull('judging_type_id'))
            ->with(['user', 'registrationCars' => fn ($q) => $q->whereNull('judging_type_id')->with('car')])
            ->get();

        $rows = $registrations->map(fn (ShowRegistration $registration): array => [
            'registration_id' => $registration->id,
            'invoice_id' => $registration->invoice_id,
            'name' => (string) $registration->user?->fullName,
            'cars' => $registration->registrationCars
                ->filter(fn (ShowRegistrationCar $entry): bool => $entry->car !== null)
                ->map(fn (ShowRegistrationCar $entry): array => [
                    'car_id' => $entry->car_id,
                    'body_id' => (int) $entry->car?->body_id,
                    'name' => (string) $entry->car?->fullName,
                ])->values(),
        ])->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values();

        return response()->json(['data' => $rows]);
    }

    /** Judging types offered for a body in this show (legacy JudgingType::restrictJudgingByShowAndBody). */
    public function types(Request $request, int $show): JsonResponse
    {
        $bodyId = $request->query('body_id');
        if (! is_string($bodyId) || ! ctype_digit($bodyId)) {
            throw ValidationException::withMessages(['body_id' => 'The body is required.']);
        }

        return response()->json(['data' => $this->allowedTypes($this->show($show)->id, (int) $bodyId)]);
    }

    public function store(AssignJudgingTypeRequest $request, int $show): JsonResponse
    {
        $showId = $this->show($show)->id;
        $entry = ShowRegistrationCar::query()
            ->where('registration_id', $request->integer('registration_id'))
            ->where('car_id', $request->integer('car_id'))
            ->whereHas('registration', fn (Builder $q) => $q->where('show_id', $showId))
            ->with('car', 'registration')
            ->first() ?? abort(404, 'That car is not registered for this event.');
        $this->refuseOwnCar($request, $entry);

        $typeId = $request->integer('judging_type_id');
        $allowed = array_column($this->allowedTypes($showId, (int) $entry->car?->body_id), 'id');
        $type = JudgingType::tryFrom($typeId);
        if ($type === null || ! in_array($typeId, $allowed, true)) {
            throw ValidationException::withMessages(['judging_type_id' => 'This judging type is not offered for this car in this event.']);
        }

        $entry->judging_type_id = $type;
        $entry->save();
        AuditLog::record('judging.entry.assign', self::RESOURCE, [$entry->id], AuditLog::diff($entry));

        return response()->json(['data' => ['id' => $entry->id, 'judging_type_id' => $typeId]], 201);
    }

    public function destroy(Request $request, int $show, int $entry): JsonResponse
    {
        $showId = $this->show($show)->id;
        $model = ShowRegistrationCar::query()
            ->whereKey($entry)
            ->whereHas('registration', fn (Builder $q) => $q->where('show_id', $showId))
            ->with('registration')
            ->first() ?? abort(404, 'Judging entry not found.');
        $this->refuseOwnCar($request, $model);

        $model->judging_type_id = null;
        $model->save();
        AuditLog::record('judging.entry.remove', self::RESOURCE, [$model->id], AuditLog::diff($model));

        return response()->json(null, 204);
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function allowedTypes(int $showId, int $bodyId): array
    {
        return DB::table('judging_show_body')
            ->join('judging_type', 'judging_type.id', '=', 'judging_show_body.judging_type_id')
            ->where('judging_show_body.car_show_id', $showId)
            ->where('judging_show_body.car_body_id', $bodyId)
            ->orderBy('judging_type.id')
            ->distinct()
            ->get(['judging_type.id', 'judging_type.name'])
            ->map(fn (object $row): array => ['id' => (int) $row->id, 'name' => (string) $row->name])
            ->all();
    }

    /** A judge never changes the judging of their own car (as the judge-car gate). */
    private function refuseOwnCar(Request $request, ShowRegistrationCar $entry): void
    {
        /** @var User $user */
        $user = $request->user();
        if (! $user->isAdmin() && (int) $entry->registration?->user_id === (int) $user->id) {
            abort(403, 'You cannot change the judging of your own car.');
        }
    }

    private function show(int $id): CarShow
    {
        return CarShow::query()->find($id) ?? abort(404, 'Event not found.');
    }
}
