<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells an owner their judging sheet is ready (legacy emails.member.judging_result).
 * $url is {APP_URL}/judging_answers/{JudgingId}.
 */
class JudgingResultMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $url,
        public string $userFirst,
        public string $showName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: (string) config('sesf.reg_email'),
            subject: 'Southeast Sharkfest Judging Results',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.member.judging_result',
            with: [
                'url' => $this->url,
                'userFirst' => $this->userFirst,
                'showName' => $this->showName,
            ],
        );
    }
}
