<?php

namespace App\Mail\Ipn;

final class AdminWeirdMail extends IpnMail
{
    protected function mailSubject(): string
    {
        return 'Weird PayPal transaction for '.$this->registrationRef();
    }

    protected function templateName(): string
    {
        return 'admin_weird';
    }
}
