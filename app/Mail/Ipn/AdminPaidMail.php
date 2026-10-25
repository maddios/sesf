<?php

namespace App\Mail\Ipn;

final class AdminPaidMail extends IpnMail
{
    protected function mailSubject(): string
    {
        return 'PayPal payment has been received AND accepted for '.$this->registrationRef();
    }

    protected function templateName(): string
    {
        return 'admin_paid';
    }
}
