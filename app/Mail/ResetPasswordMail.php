<?php

namespace App\Mail;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Password reset link (ported from legacy emails.auth.reminder). The link opens the SPA hash route,
 * so the token stays in the fragment and is never sent to the server by the browser.
 */
class ResetPasswordMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $token,
        public string $email,
    ) {}

    /**
     * Builds the hash-route reset URL used by the email and by the framework's own notification.
     */
    public static function resetUrl(string $token, string $email): string
    {
        return url('/#/reset-password?token='.$token.'&email='.urlencode($email));
    }

    /**
     * Makes Laravel's ResetPassword notification (if it is ever sent) use the same hash URL.
     * Called by PasswordResetController before the broker runs.
     */
    public static function registerUrlResolver(): void
    {
        ResetPassword::createUrlUsing(
            fn (object $notifiable, string $token): string => self::resetUrl($token, (string) $notifiable->getEmailForPasswordReset()),
        );
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Reset your password — Southeast Sharkfest',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.auth.reminder',
            with: [
                'resetUrl' => self::resetUrl($this->token, $this->email),
            ],
        );
    }
}
