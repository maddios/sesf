<?php

namespace App\Mail\Ipn;

final class AdminUnderfundMail extends IpnMail
{
    protected function mailSubject(): string
    {
        return 'PayPal underfund for '.$this->registrationRef();
    }

    protected function templateName(): string
    {
        return 'admin_underfund';
    }
}
