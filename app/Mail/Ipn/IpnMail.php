<?php

namespace App\Mail\Ipn;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Base for the PayPal IPN emails. Every IPN mail is sent from the registration address, as the
 * legacy controller did. The recipient is set by the sender (IpnMailerImpl), so it can be
 * the customer or the registration inbox.
 */
abstract class IpnMail extends Mailable
{
    public function __construct(public IpnMailData $data) {}

    /** Subject line, as the legacy controller wrote it. Built from our data only. */
    abstract protected function mailSubject(): string;

    /** Blade view name under emails.ipn. */
    abstract protected function templateName(): string;

    /**
     * Extra view variables. Subclasses add what their view needs (for example a reason).
     *
     * @return array<string, mixed>
     */
    protected function templateData(): array
    {
        return ['d' => $this->data];
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address((string) config('sesf.reg_email'), (string) config('mail.from.name')),
            subject: $this->headerSafe($this->mailSubject()),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.ipn.'.$this->templateName(),
            with: $this->templateData(),
        );
    }

    /**
     * Removes line breaks and other control characters, and caps the length. Subjects can carry
     * a PayPal item number, which must not be able to inject headers.
     */
    protected function headerSafe(string $value): string
    {
        $clean = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value));

        return mb_substr($clean, 0, 200);
    }

    /** The "ID#" part of the legacy subjects, with the legacy braces. */
    protected function registrationRef(): string
    {
        return 'ID#: {'.($this->data->registrationId ?? $this->data->itemNumber).'}';
    }
}
