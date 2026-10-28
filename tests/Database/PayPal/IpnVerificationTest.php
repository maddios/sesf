<?php

namespace Tests\Database\PayPal;

use App\Models\PayPalPayment;
use App\Services\PayPal\IpnProcessor;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Postback verification, validation of verified messages and the HTTP contract (always an empty
 * 200 for POST).
 */
class IpnVerificationTest extends IpnTestCase
{
    #[Test]
    public function the_postback_is_cmd_notify_validate_followed_by_the_original_body_byte_for_byte(): void
    {
        $this->paypalReplies('VERIFIED');
        $registration = $this->registration();
        // Unusual order, windows-1252 bytes, %20 vs + and a CRLF: all must be posted back untouched.
        $raw = 'txn_type=web_accept&payment_status=Completed&mc_gross=100.00&mc_fee=3.20'
            .'&charset=windows-1252&first_name=J%FCrgen&last_name=M%FCller%20Sr.'
            .'&address_street=1+Main+St%0D%0AApt+2&mc_currency=USD&txn_id=61E67681CH3238416'
            .'&receiver_email=seller%40example.test&item_number='.$registration->id.'&custom=a%26b%3Dc';

        $this->assertEmptyOk($this->postRaw($raw));

        Http::assertSentCount(1);
        Http::assertSent(function (ClientRequest $request) use ($raw): bool {
            return $request->method() === 'POST'
                && $request->url() === self::VERIFY_URL
                && $request->body() === 'cmd=_notify-validate&'.$raw
                && str_starts_with($request->body(), 'cmd=_notify-validate&txn_type=web_accept&payment_status=')
                && $request->hasHeader('User-Agent', 'SESF-IPN-Verifier/1.0')
                && $request->hasHeader('Content-Type', 'application/x-www-form-urlencoded');
        });

        // Parsed fields were converted to UTF-8 for storage.
        $row = PayPalPayment::query()->firstOrFail();
        $this->assertSame('Jürgen', $row->first_name);
        $this->assertSame('a&b=c', $row->custom);
        $this->assertTrue($registration->fresh()?->is_paid);
    }

    #[Test]
    public function an_invalid_reply_stores_nothing_and_notifies_the_admin_at_most_once_per_window(): void
    {
        $this->paypalReplies('INVALID');
        $registration = $this->registration();

        $this->assertEmptyOk($this->postIpn($registration));
        $this->assertEmptyOk($this->postIpn($registration, ['txn_id' => 'OTHERTXN000000001']));

        $this->assertMoney($registration, ['owed' => '100.00', 'received' => '0.00', 'actual' => null, 'paid' => false]);
        $this->assertSame(0, $this->paymentCount());
        $this->assertSame(['adminInvalid'], $this->mailer->methods());
        $this->assertSame('PayPal answered INVALID', $this->mailer->calls[0]['text']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function nonExactReplies(): array
    {
        return [
            'substring (legacy accepted it)' => ['NOT VERIFIED'],
            'html wrapper' => ['<html>VERIFIED</html>'],
            'lowercase' => ['verified'],
            'empty' => [''],
        ];
    }

    #[Test]
    #[DataProvider('nonExactReplies')]
    public function only_an_exact_verified_reply_counts(string $reply): void
    {
        $this->paypalReplies($reply);
        $registration = $this->registration();

        $this->assertEmptyStatus($this->postIpn($registration), 503);

        $this->assertMoney($registration, ['owed' => '100.00', 'received' => '0.00', 'actual' => null, 'paid' => false]);
        $this->assertSame(0, $this->paymentCount());
        $this->assertSame(['adminPostbackFailed'], $this->mailer->methods());
    }

    #[Test]
    public function a_verified_reply_with_surrounding_whitespace_is_accepted(): void
    {
        $this->paypalReplies("VERIFIED\r\n");
        $registration = $this->registration();

        $this->postIpn($registration);

        $this->assertTrue($registration->fresh()?->is_paid);
    }

    #[Test]
    public function a_postback_http_error_credits_nothing_and_reports_postback_failed(): void
    {
        $this->paypalReplies('Service Unavailable', 503);
        $registration = $this->registration();

        $this->assertEmptyStatus($this->postIpn($registration), 503);

        $this->assertMoney($registration, ['owed' => '100.00', 'received' => '0.00', 'actual' => null, 'paid' => false]);
        $this->assertSame(0, $this->paymentCount());
        $this->assertSame(['adminPostbackFailed'], $this->mailer->methods());
        $this->assertStringContainsString('HTTP 503', (string) $this->mailer->calls[0]['text']);
    }

    #[Test]
    public function a_postback_timeout_credits_nothing_and_reports_postback_failed(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 15000 milliseconds'));
        $registration = $this->registration();

        $this->assertEmptyStatus($this->postIpn($registration), 503);

        $this->assertMoney($registration, ['owed' => '100.00', 'received' => '0.00', 'actual' => null, 'paid' => false]);
        $this->assertSame(['adminPostbackFailed'], $this->mailer->methods());
        $this->assertStringContainsString('timed out', (string) $this->mailer->calls[0]['text']);
    }

    #[Test]
    public function a_non_https_verify_url_is_refused(): void
    {
        config(['sesf.paypal.verify_url' => 'http://ipnpb.paypal.test/cgi-bin/webscr']);
        $this->paypalReplies('VERIFIED');
        $registration = $this->registration();

        $this->assertEmptyStatus($this->postIpn($registration), 503);

        Http::assertNothingSent();
        $this->assertSame(['adminPostbackFailed'], $this->mailer->methods());
    }

    /**
     * @return array<string, array{0: array<string, string|null>, 1: string}>
     */
    public static function rejectedMessages(): array
    {
        return [
            'wrong currency' => [['mc_currency' => 'EUR'], 'mc_currency'],
            'missing currency' => [['mc_currency' => null], 'mc_currency'],
            'sandbox ipn on live' => [['test_ipn' => '1'], 'sandbox'],
            'missing txn_id' => [['txn_id' => null], 'txn_id'],
            'malformed txn_id' => [['txn_id' => "61E6'; DROP TABLE x"], 'txn_id'],
            'missing payment_status' => [['payment_status' => null], 'payment_status'],
            'unknown payment_status' => [['payment_status' => 'Bogus_Status'], 'payment_status'],
            'non-numeric gross' => [['mc_gross' => 'abc'], 'mc_gross'],
            'exponent gross' => [['mc_gross' => '1e2'], 'mc_gross'],
            'comma gross' => [['mc_gross' => '1,000.00'], 'mc_gross'],
            'missing gross' => [['mc_gross' => null], 'mc_gross'],
            'huge gross' => [['mc_gross' => '99999999999999.99'], 'mc_gross'],
            'gross over column range' => [['mc_gross' => '10000.00'], 'out of range'],
            'malformed fee' => [['mc_fee' => '0.1.2'], 'mc_fee'],
        ];
    }

    /**
     * @param  array<string, string|null>  $overrides
     */
    #[Test]
    #[DataProvider('rejectedMessages')]
    public function an_invalid_message_is_rejected_before_the_postback_and_stored_as_unverified(array $overrides, string $reason): void
    {
        $this->paypalReplies('VERIFIED');
        $registration = $this->registration();

        $this->assertEmptyOk($this->postIpn($registration, $overrides));

        Http::assertNothingSent();
        $this->assertMoney($registration, ['owed' => '100.00', 'received' => '0.00', 'actual' => null, 'paid' => false]);
        // A message without a usable txn_id cannot be deduplicated, so it is only logged.
        if (array_key_exists('txn_id', $overrides)) {
            $this->assertSame(0, $this->paymentCount());
        } else {
            $this->assertUnverifiedRejection($reason);
        }
        $this->assertSame(['adminInvalid'], $this->mailer->methods());
        $this->assertStringContainsString($reason, (string) $this->mailer->calls[0]['text']);
    }

    #[Test]
    public function a_wrong_receiver_is_rejected_before_the_postback_with_one_mismatch_notice_per_window(): void
    {
        $this->paypalReplies('VERIFIED');
        $registration = $this->registration();
        $wrong = ['receiver_email' => 'attacker@example.test', 'business' => 'attacker@example.test'];

        $this->assertEmptyOk($this->postIpn($registration, $wrong));
        $this->assertEmptyOk($this->postIpn($registration, $wrong + ['txn_id' => 'TXNWRONGRCV000002']));

        Http::assertNothingSent();
        $this->assertMoney($registration, ['owed' => '100.00', 'received' => '0.00', 'actual' => null, 'paid' => false]);
        $this->assertSame(2, $this->paymentCount());
        $this->assertUnverifiedRejection(IpnProcessor::RECEIVER_MISMATCH);
        $this->assertSame(['adminEmailMismatch'], $this->mailer->methods());
    }

    #[Test]
    public function forged_posts_are_throttled_per_ip_with_429(): void
    {
        $this->paypalReplies('VERIFIED');

        for ($i = 0; $i < 120; $i++) {
            $this->postRaw('')->assertStatus(200);
        }

        $this->postRaw('')->assertStatus(429);
        Http::assertNothingSent();
    }

    #[Test]
    public function the_receiver_email_match_is_case_insensitive_and_business_also_counts(): void
    {
        $this->paypalReplies('VERIFIED');
        $first = $this->registration();
        $second = $this->registration();

        $this->postIpn($first, ['receiver_email' => 'SELLER@Example.TEST', 'business' => null]);
        $this->postIpn($second, ['receiver_email' => 'primary@example.test', 'business' => self::SELLER, 'txn_id' => 'TXNBUSINESS000001']);

        $this->assertTrue($first->fresh()?->is_paid);
        $this->assertTrue($second->fresh()?->is_paid);
    }

    #[Test]
    public function sandbox_mode_accepts_test_ipns_and_rejects_live_ones(): void
    {
        config(['sesf.paypal.sandbox' => true]);
        $this->paypalReplies('VERIFIED');
        $sandbox = $this->registration();
        $live = $this->registration();

        $this->postIpn($sandbox, ['test_ipn' => '1']);
        $this->postIpn($live, ['txn_id' => 'TXNLIVE0000000001']);

        $this->assertTrue($sandbox->fresh()?->is_paid);
        $this->assertFalse($live->fresh()?->is_paid);
        $this->assertSame(['customerPaid', 'adminPaid', 'adminInvalid'], $this->mailer->methods());
        Http::assertSentCount(1);
    }

    #[Test]
    public function an_unknown_item_number_is_rejected_before_the_postback_and_reported_once_per_window(): void
    {
        $this->paypalReplies('VERIFIED');

        foreach (['999999999', 'abc', '0', '-5'] as $i => $item) {
            $this->assertEmptyOk($this->postIpn(0, ['item_number' => $item, 'txn_id' => 'TXNUNKNOWN00000'.$i]));
        }

        Http::assertNothingSent();
        $this->assertSame(4, $this->paymentCount());
        $this->assertUnverifiedRejection(IpnProcessor::UNKNOWN_INVOICE);
        $this->assertSame(['adminInvalidInvoice'], $this->mailer->methods());
    }

    #[Test]
    public function a_resent_rejected_message_is_stored_once_and_does_not_block_the_corrected_message(): void
    {
        $this->paypalReplies('VERIFIED');
        $registration = $this->registration();
        $wrong = ['receiver_email' => 'attacker@example.test', 'business' => 'attacker@example.test'];

        $this->assertEmptyOk($this->postIpn($registration, $wrong));
        $this->assertEmptyOk($this->postIpn($registration, $wrong));
        $this->assertSame(1, $this->paymentCount());

        $this->assertEmptyOk($this->postIpn($registration));

        $this->assertSame(2, $this->paymentCount());
        $this->assertTrue($registration->fresh()?->is_paid);
        $this->assertSame('applied', $this->lastAudit()['sesf']['outcome']);
    }

    private function assertUnverifiedRejection(string $reason): void
    {
        $row = PayPalPayment::query()->latest('id')->firstOrFail();
        $this->assertSame('', $row->paypal_verified);
        $this->assertNull($row->registration_id);
        $meta = $this->lastAudit()['sesf'];
        $this->assertSame('rejected', $meta['outcome']);
        $this->assertStringContainsString($reason, (string) $meta['reason']);
    }

    #[Test]
    public function an_empty_body_is_ignored_without_a_postback(): void
    {
        $this->paypalReplies('VERIFIED');

        $this->assertEmptyOk($this->postRaw(''));

        Http::assertNothingSent();
        $this->assertSame([], $this->mailer->methods());
    }

    #[Test]
    public function a_body_over_64kb_is_ignored_without_a_postback(): void
    {
        $this->paypalReplies('VERIFIED');
        $registration = $this->registration();

        $this->assertEmptyOk($this->postRaw($this->body($this->fields($registration, ['custom' => str_repeat('x', 70000)]))));

        Http::assertNothingSent();
        $this->assertSame(0, $this->paymentCount());
    }

    #[Test]
    public function password_like_fields_are_not_stored(): void
    {
        $this->paypalReplies('VERIFIED');
        $registration = $this->registration();

        $this->postIpn($registration, ['password' => 'hunter2', 'username' => 'jane']);

        $row = PayPalPayment::query()->firstOrFail();
        $this->assertNull($row->PASSWORD);
        $this->assertStringNotContainsString('hunter2', (string) $row->entirepost);
        $this->assertArrayNotHasKey('entirepost', $row->toArray());
    }

    #[Test]
    public function only_post_is_routed(): void
    {
        $this->get('/paypal-ipn')->assertStatus(405);
    }

    #[Test]
    public function a_crash_before_processing_answers_503_so_paypal_retries(): void
    {
        $this->paypalReplies('VERIFIED');
        $this->app->bind(IpnProcessor::class, fn () => throw new \TypeError('bad wiring'));

        $this->assertEmptyStatus($this->postIpn($this->registration()), 503);
        $this->assertSame(['adminFatal'], $this->mailer->methods());
    }
}
