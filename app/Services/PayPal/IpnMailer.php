<?php

namespace App\Services\PayPal;

use App\Models\ShowRegistration;

/**
 * Mail notices for IPN outcomes. IpnProcessor calls these only after its database transaction
 * has committed (deferred to after the response), each call isolated: a throwing implementation
 * is logged and never changes the HTTP status or the stored payment.
 *
 * Customer methods mail the registration's member; admin methods mail config('sesf.reg_email').
 * The registration passed in has the IPN already applied (amounts are the new values).
 * Implemented by App\Services\PayPal\IpnMailerImpl (bound in AppServiceProvider).
 */
interface IpnMailer
{
    /** Completed payment that paid the balance in full. */
    public function customerPaid(ShowRegistration $registration, IpnPayload $payload): void;

    public function adminPaid(ShowRegistration $registration, IpnPayload $payload): void;

    /** Pending, Created, Processed or Voided: recorded, no money moved. */
    public function customerPending(ShowRegistration $registration, IpnPayload $payload): void;

    public function adminPending(ShowRegistration $registration, IpnPayload $payload): void;

    /** Completed payment applied, but a balance is still owed. */
    public function customerInsufficient(ShowRegistration $registration, IpnPayload $payload): void;

    public function adminInsufficient(ShowRegistration $registration, IpnPayload $payload): void;

    /** Denied, Failed, Expired, Canceled; or a refund/reversal that still leaves nothing owed. */
    public function customerDenied(ShowRegistration $registration, IpnPayload $payload): void;

    public function adminDenied(ShowRegistration $registration, IpnPayload $payload): void;

    /** A refund or reversal left the registration owing money. */
    public function customerUnderfund(ShowRegistration $registration, IpnPayload $payload): void;

    public function adminUnderfund(ShowRegistration $registration, IpnPayload $payload): void;

    /** PayPal answered INVALID, or a verified IPN failed validation (currency, test_ipn, txn_id, amounts...). */
    public function adminInvalid(IpnPayload $payload, string $reason): void;

    /** Verified IPN whose receiver_email/business is not the configured PayPal account (legacy invalid_email). */
    public function adminEmailMismatch(IpnPayload $payload): void;

    /** Unknown payment status, a status with an unexpected amount sign, or a payment for a cancelled registration. */
    public function adminWeird(?ShowRegistration $registration, IpnPayload $payload): void;

    /** Processing failed unexpectedly (exception); nothing was applied. */
    public function adminFatal(IpnPayload $payload, string $message): void;

    /** Verified IPN whose item_number is not an existing registration. */
    public function adminInvalidInvoice(IpnPayload $payload): void;

    /** The postback to PayPal failed (network, timeout, HTTP error, unexpected reply). */
    public function adminPostbackFailed(IpnPayload $payload, string $message): void;
}
