<?php

namespace App\Jobs\Admin;

use App\Jobs\Waivers\ThrottleWaiverMail;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * One admin-triggered mail (check-in waiver reminder, pay link). Throttled with the other admin waiver mail; a
 * throttled release does not use up the retry budget, three real failures end in the failed log.
 */
class SendOutreachMail implements ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    public int $maxExceptions = 3;

    public function __construct(
        public readonly string $to,
        public readonly Mailable $mail,
        public readonly string $label,
    ) {}

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHour();
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new ThrottleWaiverMail];
    }

    public function handle(): void
    {
        Mail::to($this->to)->send($this->mail);
    }

    public function failed(Throwable $e): void
    {
        Log::error('Check-in email failed', ['mail' => $this->label, 'to' => $this->to, 'error' => $e->getMessage()]);
    }
}
