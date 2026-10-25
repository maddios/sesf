<?php

namespace Tests\Database\PayPal;

use App\Enums\PaymentMethod;
use App\Models\PayPalPayment;
use App\Models\ShowRegistration;
use App\Services\PayPal\RegistrationBalance;
use App\Services\PayPal\StoredAmountOwedBalance;
use App\Services\Registration\RegistrationPricing;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * Money rules for verified IPNs, in exact cents, including the cases the legacy arithmetic got
 * wrong (partial payments marked paid, cumulative gross subtracted from amount_owed twice,
 * refunds increasing amount_received).
 */
class IpnMoneyTest extends IpnTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->paypalReplies('VERIFIED');
    }

    #[Test]
    public function a_completed_full_payment_marks_the_registration_paid_with_exact_amounts(): void
    {
        $registration = $this->registration(['payment_method_id' => PaymentMethod::Check->value]);

        $this->assertEmptyOk($this->postIpn($registration));

        $this->assertMoney($registration, ['owed' => '0.00', 'received' => '100.00', 'actual' => '96.80', 'paid' => true]);
        $this->assertSame(PaymentMethod::PayPal, $registration->fresh()?->payment_method_id);
        $this->assertSame(1, $this->paymentCount());

        $row = PayPalPayment::query()->firstOrFail();
        $this->assertSame($registration->id, $row->registration_id);
        $this->assertSame('Completed', $row->payment_status);
        $this->assertSame('VERIFIED', $row->paypal_verified);
        $this->assertSame('100.00', $row->mc_gross);
        $this->assertSame('3.20', $row->mc_fee);
        $this->assertSame('61E67681CH3238416', $row->txn_id);

        $audit = $this->lastAudit();
        $this->assertSame('applied', $audit['sesf']['outcome']);
        $this->assertSame(10000, $audit['sesf']['gross_cents']);
        $this->assertSame('Jane', $audit['post']['first_name']);

        $this->assertSame(['customerPaid', 'adminPaid'], $this->mailer->methods());
        $this->assertSame($registration->id, $this->mailer->calls[0]['registration']?->id);
    }

    #[Test]
    public function a_partial_payment_is_not_marked_paid_and_sends_insufficient_notices(): void
    {
        $registration = $this->registration();

        $this->postIpn($registration, ['mc_gross' => '40.00', 'mc_fee' => '1.46']);

        // Legacy computed total = received_actual(net) + mc_gross = 38.54 + 40 and could set is_paid.
        $this->assertMoney($registration, ['owed' => '60.00', 'received' => '40.00', 'actual' => '38.54', 'paid' => false]);
        $this->assertSame(['customerInsufficient', 'adminInsufficient'], $this->mailer->methods());
    }

    #[Test]
    public function legacy_paid_test_bug_is_gone_net_plus_gross_covering_owed_is_still_partial(): void
    {
        // Legacy: total = 0 + (60 - 1.90) + 60 = 118.10 >= 100 => is_paid = 1. Correct: 60 of 100.
        $registration = $this->registration();

        $this->postIpn($registration, ['mc_gross' => '60.00', 'mc_fee' => '1.90']);

        $this->assertMoney($registration, ['owed' => '40.00', 'received' => '60.00', 'actual' => '58.10', 'paid' => false]);
    }

    #[Test]
    public function a_second_partial_payment_completes_it_without_double_subtracting_gross(): void
    {
        $registration = $this->registration();

        $this->postIpn($registration, ['mc_gross' => '40.00', 'mc_fee' => '1.46', 'txn_id' => 'TXNPARTIAL0000001']);
        $this->postIpn($registration, ['mc_gross' => '60.00', 'mc_fee' => '2.04', 'txn_id' => 'TXNPARTIAL0000002']);

        // Legacy: amount_owed = 60 - cumulative 100 = -40.
        $this->assertMoney($registration, ['owed' => '0.00', 'received' => '100.00', 'actual' => '96.50', 'paid' => true]);
        $this->assertSame(2, $this->paymentCount());
        $this->assertSame(['customerInsufficient', 'adminInsufficient', 'customerPaid', 'adminPaid'], $this->mailer->methods());
    }

    #[Test]
    public function an_overpayment_leaves_a_credit(): void
    {
        $registration = $this->registration();

        $this->postIpn($registration, ['mc_gross' => '120.00', 'mc_fee' => '3.78']);

        $this->assertMoney($registration, ['owed' => '-20.00', 'received' => '120.00', 'actual' => '116.22', 'paid' => true]);
    }

    #[Test]
    public function the_owed_amount_comes_from_the_registration_pricing_not_the_stale_stored_column(): void
    {
        $this->assertInstanceOf(RegistrationPricing::class, app(RegistrationBalance::class));
        // Priced at 100.00, but the stored column was never written (legacy category A: still 0).
        $registration = $this->registration();
        DB::table('show_registration')->where('id', $registration->id)->update(['amount_owed' => 0]);

        $this->postIpn($registration, ['mc_gross' => '40.00', 'mc_fee' => '1.46']);

        $this->assertMoney($registration, ['owed' => '60.00', 'received' => '40.00', 'actual' => '38.54', 'paid' => false]);
    }

    #[Test]
    public function the_stored_amount_fallback_subtracts_the_payment_from_stored_owed(): void
    {
        $this->app->bind(RegistrationBalance::class, StoredAmountOwedBalance::class);
        $registration = $this->registration();

        $this->postIpn($registration, ['mc_gross' => '40.00', 'mc_fee' => '1.46', 'txn_id' => 'TXNFALLBACK000001']);
        $this->assertMoney($registration, ['owed' => '60.00', 'received' => '40.00', 'actual' => '38.54', 'paid' => false]);

        $this->postIpn($registration, ['mc_gross' => '60.00', 'mc_fee' => '2.04', 'txn_id' => 'TXNFALLBACK000002']);
        $this->assertMoney($registration, ['owed' => '0.00', 'received' => '100.00', 'actual' => '96.50', 'paid' => true]);

        // Its documented limitation: a stale stored 0 makes a partial payment look like a credit.
        $stale = $this->registration();
        DB::table('show_registration')->where('id', $stale->id)->update(['amount_owed' => 0]);
        $this->postIpn($stale, ['mc_gross' => '40.00', 'mc_fee' => '1.46', 'txn_id' => 'TXNFALLBACK000003']);
        $this->assertMoney($stale, ['owed' => '-40.00', 'received' => '40.00', 'actual' => '38.54', 'paid' => true]);
    }

    #[Test]
    public function a_duplicate_ipn_with_the_same_txn_id_and_status_changes_nothing(): void
    {
        $registration = $this->registration();

        $this->postIpn($registration, ['mc_gross' => '40.00', 'mc_fee' => '1.46']);
        $this->assertEmptyOk($this->postIpn($registration, ['mc_gross' => '40.00', 'mc_fee' => '1.46']));

        $this->assertMoney($registration, ['owed' => '60.00', 'received' => '40.00', 'actual' => '38.54', 'paid' => false]);
        $this->assertSame(1, $this->paymentCount());
        $this->assertSame(['customerInsufficient', 'adminInsufficient'], $this->mailer->methods());
    }

    #[Test]
    public function the_same_txn_id_moving_from_pending_to_completed_applies_once(): void
    {
        $registration = $this->registration();

        $this->postIpn($registration, ['payment_status' => 'Pending', 'pending_reason' => 'echeck', 'mc_fee' => null]);
        $this->assertMoney($registration, ['owed' => '100.00', 'received' => '0.00', 'actual' => null, 'paid' => false]);

        $this->postIpn($registration);
        $this->postIpn($registration);

        $this->assertMoney($registration, ['owed' => '0.00', 'received' => '100.00', 'actual' => '96.80', 'paid' => true]);
        $this->assertSame(2, $this->paymentCount('61E67681CH3238416'));
        $this->assertSame(['customerPending', 'adminPending', 'customerPaid', 'adminPaid'], $this->mailer->methods());
    }

    #[Test]
    public function a_txn_already_processed_by_the_legacy_system_is_not_applied_again(): void
    {
        $registration = $this->registration();
        DB::table('paypal_payment')->insert([
            'txn_id' => '61E67681CH3238416',
            'payment_status' => 'Completed',
            'entirepost' => '[mc_gross]=100.00|[payment_status]=Completed|[txn_id]=61E67681CH3238416|',
            'created_at' => '2025-01-01 00:00:00',
            'updated_at' => '2025-01-01 00:00:00',
        ]);

        $this->postIpn($registration);

        $this->assertMoney($registration, ['owed' => '100.00', 'received' => '0.00', 'actual' => null, 'paid' => false]);
        $this->assertSame([], $this->mailer->methods());
    }

    #[Test]
    public function pending_records_the_ipn_without_moving_money(): void
    {
        $registration = $this->registration();

        $this->postIpn($registration, ['payment_status' => 'Pending', 'pending_reason' => 'echeck']);

        $this->assertMoney($registration, ['owed' => '100.00', 'received' => '0.00', 'actual' => null, 'paid' => false]);
        $row = PayPalPayment::query()->firstOrFail();
        $this->assertSame('Pending', $row->payment_status);
        $this->assertSame('echeck', $row->pending_reason);
        $this->assertSame('recorded', $this->lastAudit()['sesf']['outcome']);
        $this->assertSame(['customerPending', 'adminPending'], $this->mailer->methods());
    }

    #[Test]
    public function denied_failed_and_expired_move_no_money_and_send_denied_notices(): void
    {
        foreach (['Denied', 'Failed', 'Expired'] as $i => $status) {
            $registration = $this->registration();
            $this->mailer->calls = [];

            $this->postIpn($registration, ['payment_status' => $status, 'txn_id' => 'TXNDENIED00000'.$i]);

            $this->assertMoney($registration, ['owed' => '100.00', 'received' => '0.00', 'actual' => null, 'paid' => false]);
            $this->assertSame(['customerDenied', 'adminDenied'], $this->mailer->methods(), $status);
        }

        $this->assertSame(['Denied', 'Failed', 'Failed'], PayPalPayment::query()->orderBy('id')->pluck('payment_status')->all());
    }

    #[Test]
    public function a_partial_refund_lowers_received_and_unpays_the_registration(): void
    {
        $registration = $this->registration(['amount_owed' => '0.00', 'amount_received' => '100.00', 'amount_received_actual' => '96.80', 'is_paid' => true]);

        $this->postIpn($registration, [
            'payment_status' => 'Refunded', 'mc_gross' => '-30.00', 'mc_fee' => '-0.90',
            'txn_id' => '9RF55555RF5555555', 'parent_txn_id' => '61E67681CH3238416', 'reason_code' => 'refund',
        ]);

        // Legacy subtracted the (negative) gross, so a refund increased amount_received.
        $this->assertMoney($registration, ['owed' => '30.00', 'received' => '70.00', 'actual' => '67.70', 'paid' => false]);
        $this->assertSame('Failed', PayPalPayment::query()->firstOrFail()->payment_status);
        $this->assertSame('Refunded', $this->lastAudit()['sesf']['status']);
        $this->assertSame(['customerUnderfund', 'adminUnderfund'], $this->mailer->methods());
    }

    #[Test]
    public function a_refund_of_a_credit_keeps_it_paid_and_sends_denied_notices(): void
    {
        $registration = $this->registration(['amount_owed' => '-20.00', 'amount_received' => '120.00', 'amount_received_actual' => '116.22', 'is_paid' => true]);

        $this->postIpn($registration, ['payment_status' => 'Refunded', 'mc_gross' => '-20.00', 'mc_fee' => '-0.58', 'txn_id' => '9RF55555RF5555556']);

        $this->assertMoney($registration, ['owed' => '0.00', 'received' => '100.00', 'actual' => '96.80', 'paid' => true]);
        $this->assertSame(['customerDenied', 'adminDenied'], $this->mailer->methods());
    }

    #[Test]
    public function a_reversal_then_a_canceled_reversal_restores_the_payment(): void
    {
        $registration = $this->registration(['amount_owed' => '0.00', 'amount_received' => '100.00', 'amount_received_actual' => '96.80', 'is_paid' => true]);

        $this->postIpn($registration, ['payment_status' => 'Reversed', 'mc_gross' => '-100.00', 'mc_fee' => '-3.20', 'txn_id' => '1RV11111RV1111111', 'reason_code' => 'chargeback']);
        $this->assertMoney($registration, ['owed' => '100.00', 'received' => '0.00', 'actual' => '0.00', 'paid' => false]);
        $this->assertSame(['customerUnderfund', 'adminUnderfund'], $this->mailer->methods());

        $this->postIpn($registration, ['payment_status' => 'Canceled_Reversal', 'mc_gross' => '100.00', 'mc_fee' => '3.20', 'txn_id' => '2CR22222CR2222222']);
        $this->assertMoney($registration, ['owed' => '0.00', 'received' => '100.00', 'actual' => '96.80', 'paid' => true]);
        $this->assertSame(['customerUnderfund', 'adminUnderfund', 'adminPaid'], $this->mailer->methods());
        $this->assertSame(2, $this->paymentCount());
    }

    #[Test]
    public function a_refund_larger_than_the_net_received_floors_received_actual_at_zero(): void
    {
        $registration = $this->registration(['amount_owed' => '0.00', 'amount_received' => '100.00', 'amount_received_actual' => '96.80', 'is_paid' => true]);

        $this->postIpn($registration, ['payment_status' => 'Refunded', 'mc_gross' => '-100.00', 'mc_fee' => '0.00', 'txn_id' => '9RF55555RF5555557']);

        $this->assertMoney($registration, ['owed' => '100.00', 'received' => '0.00', 'actual' => '0.00', 'paid' => false]);
        // The audit row keeps the true figures (96.80 - 100.00 = -3.20 net).
        $audit = $this->lastAudit()['sesf'];
        $this->assertSame([9680, -10000, 0], [$audit['before']['received_actual'], $audit['gross_cents'], $audit['after']['received_actual']]);
    }

    #[Test]
    public function an_unknown_status_is_recorded_and_reported_as_weird(): void
    {
        $registration = $this->registration();

        $this->postIpn($registration, ['payment_status' => 'Bogus_Status']);

        $this->assertMoney($registration, ['owed' => '100.00', 'received' => '0.00', 'actual' => null, 'paid' => false]);
        $this->assertSame(['adminWeird'], $this->mailer->methods());
        $this->assertSame(1, $this->paymentCount());
    }

    #[Test]
    public function a_completed_ipn_with_a_negative_gross_moves_no_money(): void
    {
        $registration = $this->registration();

        $this->postIpn($registration, ['mc_gross' => '-5.00']);

        $this->assertMoney($registration, ['owed' => '100.00', 'received' => '0.00', 'actual' => null, 'paid' => false]);
        $this->assertSame(['adminWeird'], $this->mailer->methods());
    }

    #[Test]
    public function a_payment_for_a_cancelled_registration_is_applied_and_flagged(): void
    {
        $registration = $this->registration(['is_cancelled' => true]);

        $this->postIpn($registration);

        $this->assertMoney($registration, ['owed' => '0.00', 'received' => '100.00', 'actual' => '96.80', 'paid' => true]);
        $this->assertSame(['customerPaid', 'adminPaid', 'adminWeird'], $this->mailer->methods());
    }

    #[Test]
    public function a_payment_that_would_overflow_the_money_columns_is_rejected(): void
    {
        $registration = $this->registration(['amount_owed' => '0.00', 'amount_received' => '9990.00']);

        $this->postIpn($registration, ['mc_gross' => '50.00']);

        $this->assertMoney($registration, ['owed' => '0.00', 'received' => '9990.00', 'actual' => null, 'paid' => false]);
        $this->assertSame(['adminInvalid'], $this->mailer->methods());
        $this->assertSame('rejected', $this->lastAudit()['sesf']['outcome']);
    }

    #[Test]
    public function a_mail_failure_does_not_change_the_status_or_roll_back_the_payment(): void
    {
        $registration = $this->registration();
        $this->mailer->throw = true;

        $this->assertEmptyOk($this->postIpn($registration));

        $this->assertMoney($registration, ['owed' => '0.00', 'received' => '100.00', 'actual' => '96.80', 'paid' => true]);
        $this->assertSame(1, $this->paymentCount());
        // Both notices were attempted: one failing mail does not stop the next.
        $this->assertSame(['customerPaid', 'adminPaid'], $this->mailer->methods());
    }

    #[Test]
    public function an_exception_while_applying_rolls_back_and_reports_fatal(): void
    {
        $registration = $this->registration();
        $this->app->instance(RegistrationBalance::class, new class implements RegistrationBalance
        {
            public function owedCents(ShowRegistration $registration): int
            {
                throw new RuntimeException('pricing exploded');
            }
        });

        $this->assertEmptyOk($this->postIpn($registration));

        $this->assertMoney($registration, ['owed' => '100.00', 'received' => '0.00', 'actual' => null, 'paid' => false]);
        $this->assertSame(0, $this->paymentCount());
        $this->assertSame(['adminFatal'], $this->mailer->methods());
        $this->assertStringContainsString('pricing exploded', (string) $this->mailer->calls[0]['text']);
    }

    #[Test]
    public function the_registration_row_is_locked_for_update_before_the_duplicate_check(): void
    {
        $registration = $this->registration();
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $this->postIpn($registration);

        $lock = array_values(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'from `show_registration`') && str_ends_with($sql, 'for update')));
        $this->assertCount(1, $lock);
        $lockAt = array_search($lock[0], $queries, true);
        $dupAt = array_search(true, array_map(fn (string $sql): bool => str_contains($sql, 'from `paypal_payment`'), $queries), true);
        $this->assertIsInt($dupAt);
        $this->assertLessThan($dupAt, $lockAt);
    }
}
