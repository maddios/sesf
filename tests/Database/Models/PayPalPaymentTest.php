<?php

use App\Models\PayPalPayment;
use App\Models\ShowRegistration;
use Illuminate\Support\Facades\DB;

test('uses the paypal_payment table with timestamps and guards only id', function () {
    $payment = new PayPalPayment;

    expect($payment->getTable())->toBe('paypal_payment')
        ->and($payment->usesTimestamps())->toBeTrue()
        ->and($payment->getGuarded())->toBe(['id']);
});

test('money columns are Money2 strings with two decimals', function () {
    $payment = PayPalPayment::factory()->create(['mc_gross' => 19.99, 'mc_fee' => '1.005', 'amount1' => 0])->refresh();

    expect($payment->mc_gross)->toBe('19.99')
        ->and($payment->mc_fee)->toBe('1.01')
        ->and($payment->amount1)->toBe('0.00');
});

test('a float value read back from the legacy float column rounds to cents', function () {
    $id = DB::table('paypal_payment')->insertGetId([
        'mc_gross' => 19.99,
        'txn_id' => 'FLOATCHECK',
        'created_at' => '2024-01-01 00:00:00',
        'updated_at' => '0000-00-00 00:00:00',
    ]);

    expect(PayPalPayment::query()->findOrFail($id)->mc_gross)->toBe('19.99');
});

test('zero updated_at in a raw row reads as null', function () {
    $id = DB::table('paypal_payment')->insertGetId([
        'txn_id' => 'ZERODATE',
        'created_at' => '2024-01-01 00:00:00',
        'updated_at' => '0000-00-00 00:00:00',
    ]);

    expect(PayPalPayment::query()->findOrFail($id)->updated_at)->toBeNull();
});

test('SET columns are plain strings', function () {
    $payment = PayPalPayment::factory()->create(['payment_status' => 'Pending'])->refresh();

    expect($payment->payment_status)->toBe('Pending')
        ->and($payment->paypal_verified)->toBe('VERIFIED');
});

test('sensitive columns are hidden from toArray() and toJson()', function () {
    $payment = PayPalPayment::factory()->create()->refresh();
    $hidden = ['payer_email', 'first_name', 'last_name', 'address_street', 'address_city', 'address_state',
        'address_zip', 'address_country', 'username', 'PASSWORD', 'verify_sign', 'entirepost'];

    foreach ($hidden as $key) {
        expect($payment->toArray())->not->toHaveKey($key);
    }

    expect(json_decode($payment->toJson(), true))->not->toHaveKey('payer_email')
        ->and(json_decode($payment->toJson(), true))->not->toHaveKey('entirepost')
        ->and(json_decode($payment->toJson(), true))->not->toHaveKey('PASSWORD')
        ->and($payment->entirepost)->toBe('txn_id=raw-ipn-body');
});

test('registration_id is null by default and the relation works both ways', function () {
    $registration = ShowRegistration::factory()->create();
    $unlinked = PayPalPayment::factory()->create()->refresh();
    $linked = PayPalPayment::factory()->forRegistration($registration)->create();

    expect($unlinked->registration_id)->toBeNull()
        ->and($unlinked->registration)->toBeNull()
        ->and($linked->registration->getKey())->toBe($registration->id)
        ->and($registration->payments->pluck('id')->all())->toBe([$linked->id]);
});

test('scopes completed and forTxn', function () {
    $paid = PayPalPayment::factory()->create(['txn_id' => 'TXN-PAID']);
    PayPalPayment::factory()->pending()->create(['txn_id' => 'TXN-PENDING']);

    expect(PayPalPayment::query()->completed()->pluck('id')->all())->toBe([$paid->id])
        ->and(PayPalPayment::query()->forTxn('TXN-PENDING')->count())->toBe(1);
});
