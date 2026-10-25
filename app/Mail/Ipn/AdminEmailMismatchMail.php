<?php

namespace App\Mail\Ipn;

final class AdminEmailMismatchMail extends IpnMail
{
    protected function mailSubject(): string
    {
        return 'PayPal payment received but paypal email was wrong...';
    }

    protected function templateName(): string
    {
        return 'admin_email_mismatch';
    }
}
