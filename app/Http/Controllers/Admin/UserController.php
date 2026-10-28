<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RegisterUserForShowRequest;
use App\Http\Requests\Admin\UserPasswordRequest;
use App\Http\Requests\Admin\UserRequest;
use App\Http\Requests\Registration\RegistrationErrors;
use App\Http\Resources\Admin\UserResource;
use App\Mail\ResetPasswordMail;
use App\Models\Car;
use App\Models\CarShow;
use App\Models\ShowRegistration;
use App\Models\User;
use App\Services\Registration\RegistrationWriter;
use App\Services\Registration\Writer\CarInput;
use App\Services\Registration\Writer\RegistrationData;
use App\Services\Registration\Writer\RegistrationRejected;
use App\Support\Admin\AdminQuery;
use App\Support\Admin\AuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * admin-api/users: members, with disable (soft delete) and restore. Replaces the legacy frozennode user.php
 * config. Impersonation lives in ImpersonationController.
 *
 * The list shows active members by default. filter[status]=disabled|all changes that, and any other value is a
 * 422. Like legacy, an admin can create a member with a password and set a member's password directly; the
 * reset mail (PasswordResetController's flow) is still offered. Another administrator's password, email or reset
 * cannot be touched (403).
 */
final class UserController extends Controller
{
    private const RESOURCE = 'user';

    /** Free-text or personal fields logged by name only, on top of AuditLog's own sensitive-name rules. */
    private const REDACT = ['heard_from'];

    private const STATUSES = ['active', 'disabled', 'all'];

    private const LOOKUP_LIMIT = 20;

    public function index(Request $request): JsonResponse
    {
        return AdminQuery::for($this->query($request), $request)
            ->sortable(['id', 'first', 'last', 'email', 'type_id', 'date_registered', 'deleted_at', 'created_at'], default: 'last')
            ->searchable(['first', 'last', 'email'])
            ->filterable([
                'id' => ['eq', 'in'],
                'type_id' => ['eq', 'in'],
                'first' => ['like'],
                'last' => ['like'],
                'email' => ['like'],
                'state' => ['eq'],
                'date_registered' => ['between'],
            ])
            // Status is applied by query(); this only lets the key through AdminQuery's whitelist.
            ->filterUsing('status', static function (): void {})
            ->respond(UserResource::class);
    }

    public function show(int $user): UserResource
    {
        return new UserResource($this->find($user));
    }

    /**
     * Lookup for RelationSelect: {data: [{id, label}]}, active members only, by ?search= or ?ids=1,2.
     */
    public function lookup(Request $request): JsonResponse
    {
        $query = User::query()->select(['id', 'first', 'last', 'email']);

        $ids = is_string($request->query('ids')) ? array_slice(explode(',', (string) $request->query('ids')), 0, self::LOOKUP_LIMIT) : [];
        if ($ids !== []) {
            $query->whereIn('id', array_map(fn (string $id): int => ctype_digit($id) ? (int) $id : 0, $ids));
        } else {
            $search = mb_substr(trim((string) $request->query('search', '')), 0, AdminQuery::MAX_SEARCH);
            if ($search !== '') {
                $pattern = '%'.AdminQuery::escapeLike($search).'%';
                $query->where(fn (Builder $inner) => $inner->where('first', 'like', $pattern)->orWhere('last', 'like', $pattern)->orWhere('email', 'like', $pattern));
            }
        }

        $rows = $query->orderBy('last')->orderBy('first')->limit(self::LOOKUP_LIMIT)->get();

        return response()->json([
            'data' => $rows->map(fn (User $member) => [
                'id' => $member->id,
                'label' => $member->fullName.' <'.$member->email.'>',
            ])->values(),
        ]);
    }

    /**
     * POST admin-api/users: creates a member with a password (legacy frozennode create). No welcome mail is sent,
     * as in legacy. The observer creates the preferences row.
     */
    public function store(UserRequest $request): JsonResponse
    {
        $model = new User;
        $model->fill($request->fields());
        $model->forceFill(['type_id' => $request->typeId()]);
        $this->applyPassword($model, $request->password(), (string) $request->input('password_confirmation'));
        $model->save();

        AuditLog::record('user.create', self::RESOURCE, [$model->id], AuditLog::created($model, self::REDACT));

        return (new UserResource($this->find($model->id)))->response()->setStatusCode(201);
    }

    public function update(UserRequest $request, int $user): UserResource|JsonResponse
    {
        $model = $this->find($user);
        $fields = $request->fields();
        $typeId = $request->typeId();
        if ($fields['email'] !== $model->email && $this->isOtherAdmin($request, $model)) {
            return response()->json(['message' => "You cannot change another administrator's email."], 403);
        }
        if ($typeId !== null && $typeId !== $model->type_id && $request->user()?->is($model)) {
            throw ValidationException::withMessages(['type_id' => ['You cannot change the account type of your own account.']]);
        }
        if ($fields['email'] !== $model->email && $typeId !== null && $typeId !== $model->type_id) {
            return response()->json(['message' => 'Change the account type and the email in separate saves.'], 403);
        }

        $model->fill($fields);
        if ($typeId !== null) {
            $model->forceFill(['type_id' => $typeId]);
        }
        $model->save();

        AuditLog::record('user.update', self::RESOURCE, [$model->id], AuditLog::diff($model, self::REDACT));

        return new UserResource($this->find($model->id));
    }

    /**
     * DELETE admin-api/users/{id}: disables the member (soft delete). The member's sessions end on their next request.
     */
    public function disable(Request $request, int $user): JsonResponse
    {
        $model = $this->find($user);
        if ($model->trashed()) {
            return response()->json(['message' => 'This account is already disabled.'], 409);
        }
        if ($request->user()?->is($model)) {
            return response()->json(['message' => 'You cannot disable your own account.'], 422);
        }

        $model->delete();

        AuditLog::record('user.disable', self::RESOURCE, [$model->id]);

        return response()->json(null, 204);
    }

    public function restore(int $user): UserResource|JsonResponse
    {
        $model = $this->find($user);
        if (! $model->trashed()) {
            return response()->json(['message' => 'This account is not disabled.'], 409);
        }

        $model->restore();

        AuditLog::record('user.restore', self::RESOURCE, [$model->id]);

        return new UserResource($this->find($model->id));
    }

    /**
     * POST admin-api/users/{id}/password: sets the member's password (legacy user.php password + confirm_password).
     * Rotating the remember token ends the member's "remember me" cookies; AuthenticateSession ends their other
     * sessions because the stored password hash changes.
     */
    public function setPassword(UserPasswordRequest $request, int $user): JsonResponse
    {
        $model = $this->find($user);
        if ($this->isOtherAdmin($request, $model)) {
            return response()->json(['message' => "You cannot set another administrator's password."], 403);
        }

        $this->applyPassword($model, $request->password(), (string) $request->input('password_confirmation'));
        $model->setRememberToken(Str::random(60));
        $model->save();
        if ($request->user()?->is($model)) {
            // AuthenticateSession stores the signed-in user's hash after the response; keep this session valid.
            $request->user()->refresh();
            $request->session()->regenerate();
        }

        AuditLog::record('user.set_password', self::RESOURCE, [$model->id], ['password' => AuditLog::REDACTED]);

        return response()->json(['message' => 'The password has been changed.']);
    }

    /**
     * POST admin-api/users/{id}/password-reset: sends the member the same reset mail as "forgot password".
     */
    public function sendPasswordReset(Request $request, int $user): JsonResponse
    {
        $model = $this->find($user);
        if ($this->isOtherAdmin($request, $model)) {
            return response()->json(['message' => 'You cannot send a password reset for another administrator.'], 403);
        }
        if ($model->trashed()) {
            return response()->json(['message' => 'Restore this account before sending a password reset.'], 409);
        }

        ResetPasswordMail::registerUrlResolver();
        try {
            $status = Password::broker()->sendResetLink(
                ['email' => $model->email],
                function (User $member, string $token): void {
                    Mail::to($member->email)->send(new ResetPasswordMail($token, (string) $member->email));
                },
            );
        } catch (Throwable $e) {
            Log::error('Admin password reset mail failed: '.$e::class, ['user_id' => $model->id, 'exception' => $e]);

            return response()->json(['message' => 'The password reset email could not be sent. Try again later.'], 502);
        }

        if ($status !== Password::RESET_LINK_SENT) {
            return response()->json(['message' => 'A reset email was sent recently. Try again in a minute.'], 429);
        }

        AuditLog::record('user.password_reset', self::RESOURCE, [$model->id]);

        return response()->json(['message' => 'The password reset email has been sent.']);
    }

    /**
     * POST admin-api/users/{id}/registrations {show_id, car_ids}: registers the member for an event as an
     * administrator acting on their behalf (RegistrationWriter, byAdmin). The cars must belong to the member.
     * Pricing is calculated by the writer. Guests, shirts and extras are entered on the event registration page.
     */
    public function registerForShow(RegisterUserForShowRequest $request, int $user, RegistrationWriter $writer): JsonResponse
    {
        $model = $this->find($user);
        if ($model->trashed()) {
            return response()->json(['message' => 'Restore this account before registering it for an event.'], 409);
        }

        $carIds = $request->carIds();
        $ownedCars = Car::query()->where('user_id', $model->id)->whereIn('id', $carIds)->count();
        if ($ownedCars !== count($carIds)) {
            throw ValidationException::withMessages(['car_ids' => ['Every car must belong to this member.']]);
        }

        $show = CarShow::query()->findOrFail($request->showId());
        if (ShowRegistration::query()->where('show_id', $show->id)->where('user_id', $model->id)->exists()) {
            return response()->json(['message' => 'This member is already registered for that event.'], 409);
        }

        $cars = array_map(fn (int $id): CarInput => new CarInput($id, null), $carIds);

        try {
            $registration = $writer->save($show, $model, new RegistrationData($cars, [], [], [], null, null, 0, null), true);
        } catch (RegistrationRejected $e) {
            return RegistrationErrors::response($e->errors());
        }

        AuditLog::record('user.register_for_show', self::RESOURCE, [$model->id], [], ['show_id' => $show->id, 'registration_id' => $registration->id]);

        return response()->json(['data' => ['registration_id' => $registration->id, 'show_id' => $show->id, 'user_id' => $model->id]], 201);
    }

    /**
     * Status is explicit: active (default), disabled or all. Other values are a 422.
     *
     * @return Builder<User>
     */
    private function query(Request $request): Builder
    {
        $filters = $request->query('filter');
        $status = is_array($filters) ? ($filters['status'] ?? 'active') : 'active';
        if (! is_string($status) || ! in_array($status, self::STATUSES, true)) {
            throw ValidationException::withMessages(['filter.status' => ['The status filter must be active, disabled or all.']]);
        }

        $query = User::query()
            ->withTrashed()
            ->withCount('cars');

        return match ($status) {
            'active' => $query->whereNull('deleted_at'),
            'disabled' => $query->whereNotNull('deleted_at'),
            default => $query,
        };
    }

    /** The model's legacy rule (blank or whitespace-only passwords are refused); validation already ran. */
    private function applyPassword(User $model, string $password, string $confirmation): void
    {
        if (! $model->setPasswordWithConfirmation($password, $confirmation)) {
            throw ValidationException::withMessages(['password' => ['The password is not valid.']]);
        }
    }

    /** Each administrator manages their own sign-in details; another admin's email or reset could take the account over. */
    private function isOtherAdmin(Request $request, User $model): bool
    {
        return $model->isAdmin() && ! $request->user()?->is($model);
    }

    private function find(int $id): User
    {
        return User::query()->withTrashed()->withCount('cars')->find($id) ?? abort(404, 'User not found.');
    }
}
