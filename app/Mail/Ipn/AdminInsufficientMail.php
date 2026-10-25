<?php

namespace App\Mail\Ipn;

final class AdminInsufficientMail extends IpnMail
{
    protected function mailSubject(): string
    {
        return 'Insufficient PayPal payment has been received AND accepted for '.$this->registrationRef();
    }

    protected function templateName(): string
    {
        return 'admin_insufficient';
    }
}
