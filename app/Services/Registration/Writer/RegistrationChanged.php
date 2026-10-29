<?php

namespace App\Services\Registration\Writer;

use RuntimeException;

/**
 * An admin money edit was based on stale values: the received amount or discount changed (for example a PayPal
 * payment) after the form was loaded. The transaction is rolled back.
 */
final class RegistrationChanged extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This registration changed since you opened it (for example a payment came in). Reload it and make your changes again.');
    }
}
