<?php

namespace Tests\Database\PayPal;

use App\Services\PayPal\IpnPayload;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Parsing of untrusted IPN bodies (no database). */
class IpnPayloadTest extends TestCase
{
    #[Test]
    public function fields_keep_their_order_and_are_decoded(): void
    {
        $payload = IpnPayload::fromRawBody('b=2&a=x+y%20z&empty=&=skipped&&noval&c=%26%3D');

        $this->assertSame(['b' => '2', 'a' => 'x y z', 'empty' => '', 'noval' => '', 'c' => '&='], $payload->fields);
        $this->assertNull($payload->get('empty'));
        $this->assertSame('b=2&a=x+y%20z&empty=&=skipped&&noval&c=%26%3D', $payload->rawBody);
    }

    #[Test]
    public function a_windows_1252_ipn_is_converted_to_utf8_and_invalid_bytes_are_scrubbed(): void
    {
        $this->assertSame('Jürgen', IpnPayload::fromRawBody('charset=windows-1252&first_name=J%FCrgen')->get('first_name'));
        $this->assertTrue(mb_check_encoding((string) IpnPayload::fromRawBody('first_name=J%FCrgen')->get('first_name'), 'UTF-8'));
    }

    /**
     * @return array<string, array{0: ?string, 1: ?int}>
     */
    public static function amounts(): array
    {
        return [
            'plain' => ['100.00', 10000],
            'one decimal' => ['5.5', 550],
            'integer' => ['7', 700],
            'negative' => ['-30.00', -3000],
            'zero' => ['0.00', 0],
            'three decimals' => ['1.005', null],
            'exponent' => ['1e3', null],
            'comma' => ['1,000.00', null],
            'spaces inside' => ['1 000', null],
            'too many digits' => ['12345678.00', null],
            'text' => ['abc', null],
            'plus sign' => ['+5.00', null],
            'missing' => [null, null],
        ];
    }

    #[Test]
    #[DataProvider('amounts')]
    public function amounts_parse_strictly_to_signed_cents(?string $input, ?int $expected): void
    {
        $this->assertSame($expected, IpnPayload::parseCents($input));
    }

    #[Test]
    public function a_missing_fee_is_zero_but_a_malformed_fee_is_null(): void
    {
        $this->assertSame(0, IpnPayload::fromRawBody('mc_gross=1.00')->feeCents());
        $this->assertNull(IpnPayload::fromRawBody('mc_fee=x')->feeCents());
    }

    #[Test]
    public function item_number_must_be_a_positive_integer(): void
    {
        $this->assertSame(42, IpnPayload::fromRawBody('item_number=42')->itemNumber());
        foreach (['0', '-1', '4.2', '42abc', '99999999999', ''] as $bad) {
            $this->assertNull(IpnPayload::fromRawBody('item_number='.$bad)->itemNumber(), $bad);
        }
    }

    #[Test]
    public function audit_fields_drop_password_like_keys_and_log_context_has_no_personal_data(): void
    {
        $payload = IpnPayload::fromRawBody('txn_id=ABC&password=x&PASSWORD=y&payer_email=a%40b.test&auth_token=z');

        $this->assertSame(['txn_id' => 'ABC', 'payer_email' => 'a@b.test'], $payload->auditFields());
        $this->assertArrayNotHasKey('payer_email', $payload->logContext());
        $this->assertSame('a@b.test', $payload->payerEmail());
    }
}
