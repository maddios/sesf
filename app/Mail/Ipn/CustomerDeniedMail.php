<?php

namespace App\Mail\Ipn;

final class CustomerDeniedMail extends IpnMail
{
    protected function mailSubject(): string
    {
        return 'PayPal payment failed';
    }

    protected function templateName(): string
    {
        return 'customer_denied';
    }
}
