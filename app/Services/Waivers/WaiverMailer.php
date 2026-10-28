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
     * the pending waiver when needed. Cancelled registrations, disabled registrants, signed waivers,
     * empty emails and recent repeats are skipped.
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
            if ($registration === null || $registration->is_cancelled || $show === null || $email === '') {
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
     * Registration ids of the show whose party (registrant or any guest) has not signed.
     *
     * @return list<int>
     */
    public function reminderRecipients(CarShow $show): array
    {
        return array_map(
            fn (object $row): int => (int) $row->registration_id,
            $this->incompleteParties((int) $show->id, null),
        );
    }

    /**
     * Reminds one primary registrant if their party is still incomplete at send time and no reminder
     * went out within the dedupe window. Returns true when an email was sent.
     */
    public function sendReminder(int $registrationId, CarShow $show): bool
    {
        $row = $this->incompleteParties((int) $show->id, $registrationId)[0] ?? null;
        $lock = 'waiver-reminder|'.$registrationId;
        if ($row === null || ! Cache::add($lock, true, $this->dedupeSeconds())) {
            return false;
        }

        $portalUrl = rtrim((string) config('app.url'), '/').'/waiver/'.(int) $show->id;
        try {
            Mail::to(trim((string) $row->email))->send(new WaiverReminderMail($portalUrl, (string) $row->first, (string) $show->name));

            return true;
        } catch (Throwable $e) {
            Cache::forget($lock);
            Log::error('Waiver reminder email failed', ['registration' => $registrationId, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Sends a reminder to every incomplete party of the show. Returns the number of emails sent.
     */
    public function sendReminders(CarShow $show): int
    {
        return count(array_filter(
            $this->reminderRecipients($show),
            fn (int $registrationId): bool => $this->sendReminder($registrationId, $show),
        ));
    }

    /**
     * Primary registrants of active registrations with an enabled account and a reachable email whose
     * party (registrant or guest) has not signed. $registrationId narrows the check to one registration.
     *
     * @return list<object{registration_id: int, email: string, first: string}>
     */
    private function incompleteParties(int $showId, ?int $registrationId): array
    {
        return DB::select(
            'SELECT r.id AS registration_id, u.email, u.first
               FROM show_registration r
               JOIN user u ON u.id = r.user_id
              WHERE r.show_id = ?
                AND (? IS NULL OR r.id = ?)
                AND COALESCE(r.is_cancelled, 0) = 0
                AND u.deleted_at IS NULL
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
            [$showId, $registrationId, $registrationId],
        );
    }

    private function dedupeSeconds(): int
    {
        return max(1, (int) config('waivers.request_dedupe_minutes', 10)) * 60;
    }
}
