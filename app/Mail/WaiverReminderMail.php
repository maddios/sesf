<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Reminds a primary registrant that someone in their party has not signed
 * (legacy emails.member.waiver_reminder). $portalUrl is config('app.url').'/waiver/{showId}'.
 */
class WaiverReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $portalUrl,
        public string $userFirst,
        public string $showName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: (string) config('sesf.reg_email'),
            subject: 'Reminder: complete waivers for '.$this->showName,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.waivers.reminder',
            with: [
                'portalUrl' => $this->portalUrl,
                'userFirst' => $this->userFirst,
                'showName' => $this->showName,
            ],
        );
    }
}
