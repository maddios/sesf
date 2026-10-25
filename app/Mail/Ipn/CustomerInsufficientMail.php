<?php

namespace App\Mail\Ipn;

final class CustomerInsufficientMail extends IpnMail
{
    protected function mailSubject(): string
    {
        return 'Thank you for your payment';
    }

    protected function templateName(): string
    {
        return 'customer_insufficient';
    }
}
