<?php

namespace App\Jobs\Waivers;

use App\Models\ShowRegistration;
use App\Models\UserGuest;
use App\Services\Waivers\WaiverMailer;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;

/**
 * Sends one waiver request email (a registrant, or a guest of that registration). Queued by the
 * admin waivers page; the mailer skips signed waivers and recent repeats.
 */
class SendWaiverRequest implements ShouldBeUnique
{
    use Queueable, SerializesModels;

    public int $uniqueFor = 600;

    public function __construct(
        public readonly int $registrationId,
        public readonly ?int $guestId,
    ) {}

    public function uniqueId(): string
    {
        return 'waiver-request|'.$this->registrationId.'|'.($this->guestId ?? '0');
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
        $person = $this->guestId !== null
            ? UserGuest::query()->find($this->guestId)
            : ShowRegistration::query()->find($this->registrationId);

        if ($person !== null) {
            $mailer->sendRequests([$person]);
        }
    }
}
