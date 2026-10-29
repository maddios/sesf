<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Welcome email to a new member (legacy emails.member.new_account, subject "Registration Confirmation").
 */
class NewAccountMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public User $user) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address((string) config('sesf.reg_email'), (string) config('mail.from.name')),
            subject: 'Registration Confirmation',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.member.new_account',
            with: [
                'email' => (string) $this->user->email,
            ],
        );
    }
}
