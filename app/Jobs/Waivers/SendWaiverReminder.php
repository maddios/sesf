<?php

namespace App\Jobs\Waivers;

use App\Jobs\Concerns\ThrottledMailRetry;
use App\Models\CarShow;
use App\Services\Waivers\WaiverMailer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;

/**
 * Reminds one primary registrant that someone in their party has not signed. The mailer re-checks
 * the party and the dedupe window when the job runs, so a stale queue entry sends nothing. A mail
 * failure is rethrown so the job is retried.
 */
class SendWaiverReminder implements ShouldQueue
{
    use Queueable, SerializesModels, ThrottledMailRetry;

    public function __construct(
        public readonly int $showId,
        public readonly int $registrationId,
    ) {}

    public function handle(WaiverMailer $mailer): void
    {
        $show = CarShow::query()->find($this->showId);

        if ($show !== null) {
            $mailer->remind($this->registrationId, $show);
        }
    }
}
