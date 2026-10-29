<?php

namespace App\Mail\Ipn;

final class AdminInvalidMail extends IpnMail
{
    public function __construct(IpnMailData $data, public string $reason)
    {
        parent::__construct($data);
    }

    /**
     * @return array<string, mixed>
     */
    protected function templateData(): array
    {
        return ['d' => $this->data, 'reason' => $this->reason];
    }

    protected function mailSubject(): string
    {
        return 'Invalid PayPal transaction for ID#: {'.$this->data->itemNumber.'}';
    }

    protected function templateName(): string
    {
        return 'admin_invalid';
    }
}
