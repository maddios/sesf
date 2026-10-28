<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CheckinRequest;
use App\Http\Requests\Admin\OnSiteRegistrationRequest;
use App\Http\Resources\Admin\CheckinRegistrationResource;
use App\Models\CarShow;
use App\Models\ShowRegistration;
use App\Models\User;
use App\Services\Admin\CheckinOutreach;
use App\Services\Admin\CheckinService;
use App\Services\Admin\OnSiteRegistration;
use App\Support\Admin\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * admin-api/checkin: on-site check-in for one event (legacy CheckinList). The list is the whole event (not paged) so
 * the page can search names, guests and invoice numbers at the door without a round trip.
 */
final class CheckinController extends Controller
{
    private const RESOURCE = 'registration';

    public function __construct(
        private readonly CheckinService $checkin,
        private readonly CheckinOutreach $outreach,
        private readonly OnSiteRegistration $onSite,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return CheckinRegistrationResource::collection($this->checkin->registrations($this->showParam($request))->get())->response();
    }

    public function checkIn(CheckinRequest $request, int $registration): CheckinRegistrationResource
    {
        $override = $request->boolean('override');
        $model = $this->checkin->checkIn($registration, $override);

        AuditLog::record('registration.check_in', self::RESOURCE, [$model->id], [
            'is_checked_in' => ['old' => false, 'new' => true],
        ], ['override' => $override]);

        return $this->fresh($model->id);
    }

    public function checkOut(int $registration): CheckinRegistrationResource
    {
        $model = $this->checkin->checkOut($registration);

        AuditLog::record('registration.check_out', self::RESOURCE, [$model->id], [
            'is_checked_in' => ['old' => true, 'new' => false],
        ]);

        return $this->fresh($model->id);
    }

    public function waiverReminder(int $registration): JsonResponse
    {
        $model = $this->findActive($registration);
        $this->outreach->waiverReminder($this->showOf($model), $model);

        AuditLog::record('registration.waiver_reminder', self::RESOURCE, [$model->id]);

        return response()->json(['ok' => true, 'queued' => true]);
    }

    public function waiverSignUrl(Request $request, int $registration): JsonResponse
    {
        $model = $this->findActive($registration);
        $guest = $request->input('guest_id');
        $guestId = is_numeric($guest) && (int) $guest > 0 ? (int) $guest : null;

        $link = $this->outreach->waiverSignUrl($model, $guestId);

        AuditLog::record('registration.waiver_sign_url', self::RESOURCE, [$model->id], [], ['guest_id' => $guestId ?? 0]);

        return response()->json(['ok' => true, ...$link]);
    }

    public function payLinkEmail(int $registration): JsonResponse
    {
        $model = $this->findActive($registration);
        $this->outreach->payLinkEmail($this->showOf($model), $model);

        AuditLog::record('registration.pay_link_email', self::RESOURCE, [$model->id]);

        return response()->json(['ok' => true, 'queued' => true]);
    }

    public function options(int $show): JsonResponse
    {
        return response()->json(['data' => $this->onSite->options($this->showById($show))]);
    }

    public function garage(Request $request, int $user): JsonResponse
    {
        $member = User::query()->find($user) ?? abort(404, 'User not found.');

        return response()->json(['data' => $this->onSite->garage($this->showParam($request), $member)]);
    }

    public function store(OnSiteRegistrationRequest $request, int $show): JsonResponse
    {
        $found = $this->showById($show);
        $member = User::query()->find($request->userId()) ?? abort(404, 'User not found.');

        $registration = $this->onSite->create($found, $member, $request->payload());

        AuditLog::record('registration.create', self::RESOURCE, [$registration->id], [], ['user_id' => (int) $member->id]);

        $this->outreach->waiverReminder($found, $this->findActive($registration->id));
        $this->outreach->payLinkEmail($found, $this->findActive($registration->id));

        return (new CheckinRegistrationResource($this->findActive($registration->id)))->response()->setStatusCode(201);
    }

    private function fresh(int $id): CheckinRegistrationResource
    {
        return new CheckinRegistrationResource($this->findActive($id));
    }

    private function findActive(int $id): ShowRegistration
    {
        return $this->checkin->activeQuery()->whereKey($id)->first() ?? abort(404, 'Registration not found.');
    }

    private function showOf(ShowRegistration $registration): CarShow
    {
        return $registration->carShow ?? abort(404, 'Event not found.');
    }

    private function showById(int $id): CarShow
    {
        return CarShow::query()->find($id) ?? abort(404, 'Event not found.');
    }

    /**
     * The event named by the show_id query parameter (422 when missing, 404 when unknown).
     */
    private function showParam(Request $request): CarShow
    {
        $show = $request->query('show_id');
        if (! is_string($show) || ! ctype_digit($show)) {
            throw ValidationException::withMessages(['show_id' => ['Choose an event.']]);
        }

        return $this->showById((int) $show);
    }
}
