<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Asks one person (registrant or guest) to sign their waiver (legacy emails.member.waiver_request).
 * $url is the personal sign link (config('app.url').'/waiver_sign/t/{tracking_id}').
 */
class WaiverRequestMail extends Mailable
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
            subject: 'Southeast Sharkfest Waiver Signature - Please Complete',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.waivers.request',
            with: [
                'url' => $this->url,
                'userFirst' => $this->userFirst,
                'showName' => $this->showName,
            ],
        );
    }
}
