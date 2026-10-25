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
 * Registration notice to the registration inbox (legacy emails.admin.new_account, subject "New Member Registration").
 */
class AdminNewAccountMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public User $user) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address((string) config('sesf.reg_email'), (string) config('mail.from.name')),
            subject: 'New Member Registration',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.admin.new_account',
        );
    }
}
