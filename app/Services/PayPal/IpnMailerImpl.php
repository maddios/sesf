<?php

namespace App\Services\PayPal;

use App\Mail\Ipn\AdminDeniedMail;
use App\Mail\Ipn\AdminEmailMismatchMail;
use App\Mail\Ipn\AdminFatalMail;
use App\Mail\Ipn\AdminInsufficientMail;
use App\Mail\Ipn\AdminInvalidInvoiceMail;
use App\Mail\Ipn\AdminInvalidMail;
use App\Mail\Ipn\AdminPaidMail;
use App\Mail\Ipn\AdminPendingMail;
use App\Mail\Ipn\AdminPostbackFailedMail;
use App\Mail\Ipn\AdminUnderfundMail;
use App\Mail\Ipn\AdminWeirdMail;
use App\Mail\Ipn\CustomerDeniedMail;
use App\Mail\Ipn\CustomerInsufficientMail;
use App\Mail\Ipn\CustomerPaidMail;
use App\Mail\Ipn\CustomerPendingMail;
use App\Mail\Ipn\CustomerUnderfundMail;
use App\Mail\Ipn\IpnMailData;
use App\Models\ShowRegistration;
use Closure;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends the IPN emails. Each send is isolated: a mail failure is logged and never reaches the
 * IPN processor, so a payment is never rolled back or re-processed because of mail.
 *
 * Log lines carry the mail name and the registration id only. They never include the payload,
 * the PayPal report, or any address.
 */
final class IpnMailerImpl implements IpnMailer
{
    public function __construct(private RegistrationBalance $balance) {}

    public function customerPaid(ShowRegistration $r, IpnPayload $p): void
    {
        $this->toCustomer('customerPaid', $r, fn (IpnMailData $d): Mailable => new CustomerPaidMail($d), $p);
    }

    public function adminPaid(ShowRegistration $r, IpnPayload $p): void
    {
        $this->toAdmin('adminPaid', $r->id, fn (): Mailable => new AdminPaidMail($this->registrationData($r, $p)));
    }

    public function customerPending(ShowRegistration $r, IpnPayload $p): void
    {
        $this->toCustomer('customerPending', $r, fn (IpnMailData $d): Mailable => new CustomerPendingMail($d), $p);
    }

    public function adminPending(ShowRegistration $r, IpnPayload $p): void
    {
        $this->toAdmin('adminPending', $r->id, fn (): Mailable => new AdminPendingMail($this->registrationData($r, $p)));
    }

    public function customerInsufficient(ShowRegistration $r, IpnPayload $p): void
    {
        $this->toCustomer('customerInsufficient', $r, fn (IpnMailData $d): Mailable => new CustomerInsufficientMail($d), $p);
    }

    public function adminInsufficient(ShowRegistration $r, IpnPayload $p): void
    {
        $this->toAdmin('adminInsufficient', $r->id, fn (): Mailable => new AdminInsufficientMail($this->registrationData($r, $p)));
    }

    public function customerDenied(ShowRegistration $r, IpnPayload $p): void
    {
        $this->toCustomer('customerDenied', $r, fn (IpnMailData $d): Mailable => new CustomerDeniedMail($d), $p);
    }

    public function adminDenied(ShowRegistration $r, IpnPayload $p): void
    {
        $this->toAdmin('adminDenied', $r->id, fn (): Mailable => new AdminDeniedMail($this->registrationData($r, $p)));
    }

    public function customerUnderfund(ShowRegistration $r, IpnPayload $p): void
    {
        $this->toCustomer('customerUnderfund', $r, fn (IpnMailData $d): Mailable => new CustomerUnderfundMail($d), $p);
    }

    public function adminUnderfund(ShowRegistration $r, IpnPayload $p): void
    {
        $this->toAdmin('adminUnderfund', $r->id, fn (): Mailable => new AdminUnderfundMail($this->registrationData($r, $p)));
    }

    public function adminInvalid(IpnPayload $p, string $reason): void
    {
        $this->toAdmin('adminInvalid', null, fn (): Mailable => new AdminInvalidMail(IpnMailData::fromPayload($p), $reason));
    }

    public function adminWeird(?ShowRegistration $r, IpnPayload $p): void
    {
        $this->toAdmin('adminWeird', $r?->id, fn (): Mailable => new AdminWeirdMail($r === null ? IpnMailData::fromPayload($p) : $this->registrationData($r, $p)));
    }

    public function adminFatal(IpnPayload $p, string $message): void
    {
        $this->toAdmin('adminFatal', null, fn (): Mailable => new AdminFatalMail(IpnMailData::fromPayload($p), $message));
    }

    public function adminInvalidInvoice(IpnPayload $p): void
    {
        $this->toAdmin('adminInvalidInvoice', null, fn (): Mailable => new AdminInvalidInvoiceMail(IpnMailData::fromPayload($p)));
    }

    public function adminPostbackFailed(IpnPayload $p, string $message): void
    {
        $this->toAdmin('adminPostbackFailed', null, fn (): Mailable => new AdminPostbackFailedMail(IpnMailData::fromPayload($p), $message));
    }

    /**
     * Payment received, but the PayPal receiver email did not match. Not in the IpnMailer contract:
     * the legacy controller sent this from the receiver check, and it is kept here for that check.
     */
    public function adminEmailMismatch(IpnPayload $p): void
    {
        $this->toAdmin('adminEmailMismatch', null, fn (): Mailable => new AdminEmailMismatchMail(IpnMailData::fromPayload($p)));
    }

    private function registrationData(ShowRegistration $r, IpnPayload $p): IpnMailData
    {
        return IpnMailData::fromRegistration($r, $p, $this->balance->owedCents($r));
    }

    /**
     * @param  Closure(IpnMailData): Mailable  $build
     */
    private function toCustomer(string $name, ShowRegistration $r, Closure $build, IpnPayload $p): void
    {
        $this->deliver($name, $r->id, function () use ($r, $p, $build): array {
            $data = $this->registrationData($r, $p);

            return [$build($data), $data->customerEmail];
        });
    }

    /**
     * @param  Closure(): Mailable  $build
     */
    private function toAdmin(string $name, ?int $registrationId, Closure $build): void
    {
        $this->deliver($name, $registrationId, fn (): array => [$build(), (string) config('sesf.reg_email')]);
    }

    /**
     * @param  Closure(): array{0: Mailable, 1: ?string}  $compose
     */
    private function deliver(string $name, ?int $registrationId, Closure $compose): void
    {
        try {
            [$mailable, $to] = $compose();

            if ($to === null || trim($to) === '') {
                Log::warning('PayPal IPN email skipped: no recipient', ['mail' => $name, 'registration_id' => $registrationId]);

                return;
            }

            Mail::to(trim($to))->send($mailable);
        } catch (Throwable $e) {
            Log::warning('PayPal IPN email not sent', [
                'mail' => $name,
                'registration_id' => $registrationId,
                'exception' => $e::class,
            ]);
        }
    }
}
