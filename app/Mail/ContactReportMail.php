<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Contact form message to the registration inbox (legacy emails.admin.report_page). Reply-To is the
 * visitor, so the team can answer directly.
 */
class ContactReportMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $messageSubject,
        public string $senderName,
        public string $senderEmail,
        public string $body,
        public ?string $location = null,
        public ?string $senderProfileUrl = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address((string) config('sesf.reg_email'), (string) config('mail.from.name')),
            replyTo: [new Address($this->senderEmail, $this->senderName)],
            subject: str_replace(["\r", "\n"], ' ', $this->messageSubject),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.admin.report_page',
            with: [
                'messageSubject' => $this->messageSubject,
                'senderName' => $this->senderName,
                'senderEmail' => $this->senderEmail,
                'body' => $this->body,
                'location' => $this->location,
                'senderProfileUrl' => $this->senderProfileUrl,
            ],
        );
    }
}
