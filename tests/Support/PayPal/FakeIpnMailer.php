<?php

namespace Tests\Support\PayPal;

use App\Models\ShowRegistration;
use App\Services\PayPal\IpnMailer;
use App\Services\PayPal\IpnPayload;
use RuntimeException;

/**
 * Records every IpnMailer call. Set $throw = true to make every call fail after recording it.
 */
final class FakeIpnMailer implements IpnMailer
{
    /** @var list<array{method: string, registration: ?ShowRegistration, payload: IpnPayload, text: ?string}> */
    public array $calls = [];

    public bool $throw = false;

    /** @return list<string> */
    public function methods(): array
    {
        return array_map(fn (array $call): string => $call['method'], $this->calls);
    }

    private function record(string $method, ?ShowRegistration $registration, IpnPayload $payload, ?string $text = null): void
    {
        $this->calls[] = ['method' => $method, 'registration' => $registration, 'payload' => $payload, 'text' => $text];

        if ($this->throw) {
            throw new RuntimeException('mail transport down');
        }
    }

    public function customerPaid(ShowRegistration $registration, IpnPayload $payload): void
    {
        $this->record(__FUNCTION__, $registration, $payload);
    }

    public function adminPaid(ShowRegistration $registration, IpnPayload $payload): void
    {
        $this->record(__FUNCTION__, $registration, $payload);
    }

    public function customerPending(ShowRegistration $registration, IpnPayload $payload): void
    {
        $this->record(__FUNCTION__, $registration, $payload);
    }

    public function adminPending(ShowRegistration $registration, IpnPayload $payload): void
    {
        $this->record(__FUNCTION__, $registration, $payload);
    }

    public function customerInsufficient(ShowRegistration $registration, IpnPayload $payload): void
    {
        $this->record(__FUNCTION__, $registration, $payload);
    }

    public function adminInsufficient(ShowRegistration $registration, IpnPayload $payload): void
    {
        $this->record(__FUNCTION__, $registration, $payload);
    }

    public function customerDenied(ShowRegistration $registration, IpnPayload $payload): void
    {
        $this->record(__FUNCTION__, $registration, $payload);
    }

    public function adminDenied(ShowRegistration $registration, IpnPayload $payload): void
    {
        $this->record(__FUNCTION__, $registration, $payload);
    }

    public function customerUnderfund(ShowRegistration $registration, IpnPayload $payload): void
    {
        $this->record(__FUNCTION__, $registration, $payload);
    }

    public function adminUnderfund(ShowRegistration $registration, IpnPayload $payload): void
    {
        $this->record(__FUNCTION__, $registration, $payload);
    }

    public function adminInvalid(IpnPayload $payload, string $reason): void
    {
        $this->record(__FUNCTION__, null, $payload, $reason);
    }

    public function adminEmailMismatch(IpnPayload $payload): void
    {
        $this->record(__FUNCTION__, null, $payload);
    }

    public function adminWeird(?ShowRegistration $registration, IpnPayload $payload): void
    {
        $this->record(__FUNCTION__, $registration, $payload);
    }

    public function adminFatal(IpnPayload $payload, string $message): void
    {
        $this->record(__FUNCTION__, null, $payload, $message);
    }

    public function adminInvalidInvoice(IpnPayload $payload): void
    {
        $this->record(__FUNCTION__, null, $payload);
    }

    public function adminPostbackFailed(IpnPayload $payload, string $message): void
    {
        $this->record(__FUNCTION__, null, $payload, $message);
    }
}
