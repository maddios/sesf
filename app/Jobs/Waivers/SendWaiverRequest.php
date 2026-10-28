<?php

namespace App\Jobs\Waivers;

use App\Jobs\Concerns\ThrottledMailRetry;
use App\Models\ShowRegistration;
use App\Models\UserGuest;
use App\Services\Waivers\WaiverMailer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;

/**
 * Sends one waiver request email (a registrant, or a guest of that registration). Queued by the
 * admin waivers page; the mailer skips signed waivers and recent repeats, and a mail failure is
 * rethrown so the job is retried.
 */
class SendWaiverRequest implements ShouldQueue
{
    use Queueable, SerializesModels, ThrottledMailRetry;

    public function __construct(
        public readonly int $registrationId,
        public readonly ?int $guestId,
    ) {}

    public function handle(WaiverMailer $mailer): void
    {
        $person = $this->guestId !== null
            ? UserGuest::query()->find($this->guestId)
            : ShowRegistration::query()->find($this->registrationId);

        if ($person !== null) {
            $mailer->sendRequest($person);
        }
    }
}
