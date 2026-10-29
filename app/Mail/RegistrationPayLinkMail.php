<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Billing link for an event registration (legacy emails.member.registration_pay_link), sent after an administrator
 * creates a registration on site and from the check-in list. $payUrl is the member's billing page.
 */
class RegistrationPayLinkMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $userFirst,
        public string $showName,
        public int $invoiceId,
        public string $payUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: (string) config('sesf.reg_email'),
            subject: 'Complete payment: '.$this->showName.' registration',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.member.registration_pay_link',
            with: [
                'userFirst' => $this->userFirst,
                'showName' => $this->showName,
                'invoiceId' => $this->invoiceId,
                'payUrl' => $this->payUrl,
            ],
        );
    }
}
