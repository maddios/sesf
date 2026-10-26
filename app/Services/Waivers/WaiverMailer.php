<?php

namespace App\Services\Waivers;

use App\Mail\WaiverReminderMail;
use App\Mail\WaiverRequestMail;
use App\Models\CarShow;
use App\Models\ShowRegistration;
use App\Models\User;
use App\Models\UserGuest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Waiver request and reminder emails for the admin app (legacy WaiversList::sendWaivers and
 * sendWaiverReminders). Each send is deduped with a cache lock: at most one request per person
 * (waiver row) and one reminder per registration per config('waivers.request_dedupe_minutes').
 * Sign links are built from config('app.url'); the link itself is never logged.
 */
class WaiverMailer
{
    public function __construct(private readonly WaiverSignService $waivers) {}

    /**
     * Sends a sign request to each registrant (ShowRegistration) or guest (UserGuest), creating
     * the pending waiver when needed. Signed waivers, empty emails and recent repeats are skipped.
     *
     * @param  iterable<ShowRegistration|UserGuest>  $people
     * @return array{sent: int, skipped: int, failed: int}
     */
    public function sendRequests(iterable $people): array
    {
        $result = ['sent' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($people as $person) {
            if ($person instanceof UserGuest) {
                $registration = ShowRegistration::query()->find($person->registration_id);
                $email = trim((string) $person->email);
                $first = (string) $person->first;
                $guest = $person;
            } else {
                $registration = $person;
                $user = User::query()->find($person->user_id);
                $email = trim((string) $user?->email);
                $first = (string) $user?->first;
                $guest = null;
            }

            $show = $registration !== null ? CarShow::query()->find($registration->show_id) : null;
            if ($registration === null || $show === null || $email === '') {
                $result['skipped']++;

                continue;
            }

            $waiver = $this->waivers->ensurePending($registration, $guest);
            $lock = 'waiver-request|'.$waiver->id;
            if ($waiver->has_signed || ! Cache::add($lock, true, $this->dedupeSeconds())) {
                $result['skipped']++;

                continue;
            }

            try {
                Mail::to($email)->send(new WaiverRequestMail($waiver->signUrl(), $first, (string) $show->name));
                $result['sent']++;
            } catch (Throwable $e) {
                Cache::forget($lock);
                Log::error('Waiver request email failed', ['waiver_row' => $waiver->id, 'error' => $e->getMessage()]);
                $result['failed']++;
            }
        }

        return $result;
    }

    /**
     * Reminds each primary registrant of the show whose party (registrant or any guest) has not
     * signed. Returns the number of emails sent.
     */
    public function sendReminders(CarShow $show): int
    {
        $rows = DB::select(
            'SELECT r.id AS registration_id, u.email, u.first
               FROM show_registration r
               JOIN user u ON u.id = r.user_id
              WHERE r.show_id = ?
                AND u.email IS NOT NULL
                AND TRIM(u.email) != \'\'
                AND (
                     r.waiver_id IS NULL
                     OR EXISTS (SELECT 1 FROM waiver w WHERE w.id = r.waiver_id AND COALESCE(w.has_signed, 0) != 1)
                     OR EXISTS (
                          SELECT 1 FROM user_guest g
                          LEFT JOIN waiver w ON w.id = g.waiver_id
                           WHERE g.registration_id = r.id
                             AND (g.waiver_id IS NULL OR COALESCE(w.has_signed, 0) != 1)
                     )
                )',
            [(int) $show->id],
        );

        $portalUrl = rtrim((string) config('app.url'), '/').'/waiver/'.(int) $show->id;
        $sent = 0;
        foreach ($rows as $row) {
            $lock = 'waiver-reminder|'.(int) $row->registration_id;
            if (! Cache::add($lock, true, $this->dedupeSeconds())) {
                continue;
            }
            try {
                Mail::to(trim((string) $row->email))->send(new WaiverReminderMail($portalUrl, (string) $row->first, (string) $show->name));
                $sent++;
            } catch (Throwable $e) {
                Cache::forget($lock);
                Log::error('Waiver reminder email failed', ['registration' => (int) $row->registration_id, 'error' => $e->getMessage()]);
            }
        }

        return $sent;
    }

    private function dedupeSeconds(): int
    {
        return max(1, (int) config('waivers.request_dedupe_minutes', 10)) * 60;
    }
}
