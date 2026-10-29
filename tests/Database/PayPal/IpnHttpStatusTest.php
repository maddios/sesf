<?php

namespace Tests\Database\PayPal;

use App\Models\ShowRegistration;
use App\Services\PayPal\RegistrationBalance;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * The HTTP status per outcome: 200 for everything handled (PayPal stops re-sending), 503 when the
 * money event could not be handled (PayPal retries; the duplicate check makes that safe).
 */
class IpnHttpStatusTest extends IpnTestCase
{
    #[Test]
    public function verified_and_applied_is_200(): void
    {
        $this->paypalReplies('VERIFIED');

        $this->assertEmptyOk($this->postIpn($this->registration()));
    }

    #[Test]
    public function duplicate_is_200(): void
    {
        $this->paypalReplies('VERIFIED');
        $registration = $this->registration();
        $this->postIpn($registration);

        $this->assertEmptyOk($this->postIpn($registration));
        $this->assertSame('100.00', $registration->fresh()?->amount_received);
    }

    #[Test]
    public function rejected_by_validation_is_200(): void
    {
        $this->paypalReplies('VERIFIED');

        $this->assertEmptyOk($this->postIpn($this->registration(), ['mc_currency' => 'EUR']));
    }

    #[Test]
    public function invalid_is_200(): void
    {
        $this->paypalReplies('INVALID');

        $this->assertEmptyOk($this->postIpn($this->registration()));
    }

    #[Test]
    public function postback_http_failure_is_503_logged_at_error_with_the_txn_id_and_notified(): void
    {
        $this->paypalReplies('oops', 500);
        $log = Log::spy();

        $this->assertEmptyStatus($this->postIpn($this->registration()), 503);
        // A retry inside the notice window is still logged, but not mailed again.
        $this->assertEmptyStatus($this->postIpn($this->registration()), 503);

        $this->assertSame(['adminPostbackFailed'], $this->mailer->methods());
        $log->shouldHaveReceived('error')->twice()->withArgs(
            fn (string $message, array $context): bool => str_contains($message, 'postback failed') && $context['txn_id'] === '61E67681CH3238416',
        );
    }

    #[Test]
    public function fatal_is_503_logged_with_the_txn_id_and_notified(): void
    {
        $this->paypalReplies('VERIFIED');
        $this->app->instance(RegistrationBalance::class, new class implements RegistrationBalance
        {
            public function owedCents(ShowRegistration $registration): int
            {
                throw new RuntimeException('boom');
            }
        });
        $log = Log::spy();

        $this->assertEmptyStatus($this->postIpn($this->registration()), 503);

        $this->assertSame(['adminFatal'], $this->mailer->methods());
        $log->shouldHaveReceived('critical')->once()->withArgs(
            fn (string $message, array $context): bool => $context['txn_id'] === '61E67681CH3238416',
        );
    }
}
