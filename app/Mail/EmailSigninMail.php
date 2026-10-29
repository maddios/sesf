<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sign-in code and link (legacy emails.auth.email_signin, sent from sesf.reg_email as before).
 * Goes through the normal mailer, so RedirectMailInDev applies.
 */
class EmailSigninMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $signInUrl,
        public string $code,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: (string) config('sesf.reg_email'),
            subject: 'Your Southeast Sharkfest sign-in code',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.auth.email_signin',
            with: [
                'signInUrl' => $this->signInUrl,
                'code' => $this->code,
            ],
        );
    }
}
