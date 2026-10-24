<?php

namespace App\Listeners;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Dev mail guard. Mirrors the legacy `mailer.sending` hook.
 *
 * - email_debug off: nothing changes.
 * - email_debug on, dev_address set: every recipient (To, Cc, Bcc) is replaced with dev_address,
 *   and the original To list is kept in X-SESF-Debug-Original-To.
 * - email_debug on, dev_address empty: the send is cancelled (returning false stops Laravel from
 *   sending) and the message is written to the `mail-debug` log channel instead, so no real
 *   recipient gets mail.
 *
 * Laravel dispatches MessageSending with until(): the first non-null return value halts the
 * other listeners. So "allow" returns null, not true, and later listeners still run.
 *
 * Laravel auto-discovers this listener from its type-hinted handle() method.
 */
class RedirectMailInDev
{
    public const ORIGINAL_TO_HEADER = 'X-SESF-Debug-Original-To';

    private const LOG_BODY_LIMIT = 20000;

    public function handle(MessageSending $event): ?bool
    {
        if (! config('sesf.email_debug')) {
            return null;
        }

        $message = $event->message;
        $devAddress = trim((string) config('sesf.dev_address', ''));

        if ($devAddress === '') {
            $this->logCancelled($message);

            return false;
        }

        $originalTo = $this->format($message->getTo());

        $headers = $message->getHeaders();
        $headers->remove('To');
        $headers->remove('Cc');
        $headers->remove('Bcc');

        $message->to($devAddress);
        $headers->addTextHeader(self::ORIGINAL_TO_HEADER, $originalTo);

        return null;
    }

    private function logCancelled(Email $message): void
    {
        $body = $message->getTextBody()
            ?? trim(strip_tags((string) $message->getHtmlBody()));

        Log::channel('mail-debug')->info(
            '[mail not sent: email_debug on, no dev address] '.json_encode([
                'from' => $this->format($message->getFrom()),
                'to' => $this->format($message->getTo()),
                'cc' => $this->format($message->getCc()),
                'bcc' => $this->format($message->getBcc()),
                'subject' => $message->getSubject(),
                'body' => mb_substr((string) $body, 0, self::LOG_BODY_LIMIT),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)
        );
    }

    /**
     * @param  Address[]  $addresses
     */
    private function format(array $addresses): string
    {
        return implode(', ', array_map(fn (Address $address): string => $address->toString(), $addresses));
    }
}
