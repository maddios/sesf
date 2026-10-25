<?php

namespace App\Mail\Ipn;

final class AdminPendingMail extends IpnMail
{
    protected function mailSubject(): string
    {
        return 'Pending PayPal payment has been received AND accepted for '.$this->registrationRef();
    }

    protected function templateName(): string
    {
        return 'admin_pending';
    }
}
