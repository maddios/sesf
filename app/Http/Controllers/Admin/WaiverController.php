<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\WaiverRequestSendRequest;
use App\Http\Requests\Admin\WaiverShowRequest;
use App\Http\Resources\Admin\WaiverRosterResource;
use App\Http\Resources\Admin\WalkInWaiverResource;
use App\Jobs\Waivers\RegenerateWaiverPdfs;
use App\Jobs\Waivers\SendWaiverReminder;
use App\Jobs\Waivers\SendWaiverRequest;
use App\Models\CarShow;
use App\Models\ShowRegistration;
use App\Models\UserGuest;
use App\Services\Admin\WaiverRoster;
use App\Services\Waivers\WaiverMailer;
use App\Services\Waivers\WaiverSignService;
use App\Support\Admin\AdminQuery;
use App\Support\Admin\AuditLog;
use App\Support\Admin\ShowParam;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * admin-api/waivers: the per-event waiver list and its actions (legacy WaiversList). Mail and PDF
 * work is queued (SendWaiverRequest, SendWaiverReminder, RegenerateWaiverPdfs) and audited when
 * queued. Signed PDFs are served by GET waiver-pdf; the open-sign link redirects to the sign page.
 */
final class WaiverController extends Controller
{
    private const RESOURCE = 'waiver';

    public function index(Request $request, WaiverRoster $roster): JsonResponse
    {
        $sortCheckedIn = $request->query('sort_checked_in') === '1';

        return AdminQuery::for($roster->forShow(ShowParam::required($request), $sortCheckedIn), $request)
            ->sortable(['last', 'first', 'email', 'status', 'invoice_id', 'arrival', 'signed_at', 'date_checked_in'], default: 'last')
            ->searchable(['first', 'last', 'email'])
            ->filterable(['status' => ['eq', 'in']])
            ->respond(WaiverRosterResource::class);
    }

    public function walkIns(Request $request, WaiverRoster $roster): JsonResponse
    {
        $rows = WalkInWaiverResource::collection($roster->walkIns(ShowParam::required($request)))->resolve($request);

        return response()->json(['data' => $rows]);
    }

    public function sendRequest(WaiverRequestSendRequest $request): JsonResponse
    {
        $people = $request->validated('people');
        foreach ($people as $person) {
            $registrationId = (int) $person['registration_id'];
            $guestId = isset($person['guest_id']) && $person['guest_id'] !== '' ? (int) $person['guest_id'] : null;
            SendWaiverRequest::dispatch($registrationId, $guestId);
        }
        AuditLog::record('waiver.request', self::RESOURCE, array_map(fn (array $p) => (int) $p['registration_id'], $people), [], [
            'show_id' => $request->integer('show_id'),
            'queued' => count($people),
        ]);

        return response()->json(['data' => ['queued' => count($people)]], 202);
    }

    public function reminders(WaiverShowRequest $request, WaiverMailer $mailer): JsonResponse
    {
        $show = CarShow::query()->findOrFail($request->integer('show_id'));
        $registrationIds = $mailer->reminderRecipients($show);

        foreach ($registrationIds as $registrationId) {
            SendWaiverReminder::dispatch((int) $show->id, $registrationId);
        }
        AuditLog::record('waiver.reminders', self::RESOURCE, [(int) $show->id], [], ['queued' => count($registrationIds)]);

        return response()->json(['data' => ['queued' => count($registrationIds)]], 202);
    }

    public function regeneratePdfs(WaiverShowRequest $request): JsonResponse
    {
        $showId = $request->integer('show_id');
        RegenerateWaiverPdfs::dispatch($showId);
        AuditLog::record('waiver.pdf_regenerate', self::RESOURCE, [$showId], [], ['queued' => 1]);

        return response()->json(['data' => ['queued' => 1]], 202);
    }

    /**
     * GET admin-api/waivers/open-sign?show_id&registration_id[&guest_id]: redirects to the person's sign
     * page, creating the pending waiver when there is none (legacy WaiversList::openWaiverSign).
     */
    public function openSign(Request $request, WaiverSignService $waivers): RedirectResponse
    {
        $validated = Validator::make($request->query(), [
            'show_id' => ['required', 'integer', 'min:1', 'exists:car_show,id'],
            'registration_id' => ['required', 'integer', 'min:1', 'exists:show_registration,id'],
            'guest_id' => ['nullable', 'integer', 'min:1', 'exists:user_guest,id'],
        ])->validate();

        $registration = ShowRegistration::query()->find((int) $validated['registration_id']);
        if ($registration === null || (int) $registration->show_id !== (int) $validated['show_id']) {
            throw new HttpException(404, 'Not found.');
        }
        $guest = null;
        if (isset($validated['guest_id'])) {
            $guest = UserGuest::query()->find((int) $validated['guest_id']);
            if ($guest === null || (int) $guest->registration_id !== (int) $registration->id) {
                throw new HttpException(404, 'Not found.');
            }
        }

        $waiver = $waivers->ensurePending($registration, $guest);

        return redirect()->to($waiver->signUrl());
    }

    /**
     * GET admin-api/waivers/blank-pdf?show_id=: the blank generic waiver for printing (legacy downloadBlankPdf).
     */
    public function blankPdf(Request $request, WaiverSignService $waivers): Response
    {
        $show = CarShow::query()->findOrFail(ShowParam::required($request));

        return response($waivers->blankPdf($show), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="waiver-blank-'.$show->id.'.pdf"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
