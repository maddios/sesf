<?php

namespace App\Mail\Ipn;

final class AdminDeniedMail extends IpnMail
{
    protected function mailSubject(): string
    {
        return 'PayPal denied for '.$this->registrationRef();
    }

    protected function templateName(): string
    {
        return 'admin_denied';
    }
}
