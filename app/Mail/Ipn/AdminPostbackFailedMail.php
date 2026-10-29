<?php

namespace App\Mail\Ipn;

final class AdminPostbackFailedMail extends IpnMail
{
    public function __construct(IpnMailData $data, public string $errorMessage)
    {
        parent::__construct($data);
    }

    /**
     * @return array<string, mixed>
     */
    protected function templateData(): array
    {
        return ['d' => $this->data, 'errorText' => $this->errorMessage];
    }

    protected function mailSubject(): string
    {
        return 'IPN Transaction Failure';
    }

    protected function templateName(): string
    {
        return 'admin_postback_failed';
    }
}
