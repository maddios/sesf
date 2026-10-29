<?php

namespace App\Mail\Ipn;

final class AdminInvalidInvoiceMail extends IpnMail
{
    protected function mailSubject(): string
    {
        return 'Paypal payment received with an invalid item_number '.$this->data->itemNumber;
    }

    protected function templateName(): string
    {
        return 'admin_invalid_invoice';
    }
}
