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
 * A member-to-member private message (legacy emails.member.private_message). Reply-To is the sender,
 * so replies go straight to them. Links are built from APP_URL.
 */
class PrivateMessageMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $recipient,
        public User $sender,
        public string $messageSubject,
        public string $body,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address((string) config('sesf.reg_email'), (string) config('mail.from.name')),
            replyTo: [new Address((string) $this->sender->email, (string) $this->sender->fullName)],
            subject: str_replace(["\r", "\n"], ' ', $this->messageSubject),
        );
    }

    public function content(): Content
    {
        $base = rtrim((string) config('app.url'), '/');

        return new Content(
            view: 'emails.member.private_message',
            with: [
                'recipientFirst' => (string) $this->recipient->first,
                'senderName' => (string) $this->sender->fullName,
                'senderProfileUrl' => $base.'/'.$this->sender->profileURL,
                'settingsUrl' => $base.'/'.$this->recipient->profileURL.'/settings',
            ],
        );
    }
}
