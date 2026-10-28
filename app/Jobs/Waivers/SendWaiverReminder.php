<?php

namespace App\Jobs\Waivers;

use App\Models\CarShow;
use App\Services\Waivers\WaiverMailer;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;

/**
 * Reminds one primary registrant that someone in their party has not signed. The mailer re-checks
 * the party and the dedupe window when the job runs, so a stale queue entry sends nothing.
 */
class SendWaiverReminder implements ShouldBeUnique
{
    use Queueable, SerializesModels;

    public int $uniqueFor = 600;

    public function __construct(
        public readonly int $showId,
        public readonly int $registrationId,
    ) {}

    public function uniqueId(): string
    {
        return 'waiver-reminder-job|'.$this->registrationId;
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new ThrottleWaiverMail];
    }

    public function handle(WaiverMailer $mailer): void
    {
        $show = CarShow::query()->find($this->showId);

        if ($show !== null) {
            $mailer->sendReminder($this->registrationId, $show);
        }
    }
}
