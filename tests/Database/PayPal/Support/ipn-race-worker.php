<?php

/*
 * Child process for tests/Database/PayPal/IpnConcurrencyTest.php. Not a test file.
 *
 *   php ipn-race-worker.php <registration_id> <txn_id> <mc_gross> <barrier_lock_name>
 *
 * Boots the app on testing_mariadb (same guard as the suite), fakes a VERIFIED postback and the
 * mailer, prints READY, waits on the parent's GET_LOCK barrier, then processes one Completed IPN.
 * The balance lookup sleeps 300 ms while the registration lock is held, so without the lock two
 * workers would read the same amounts and one payment would be lost.
 */

use App\Models\ShowRegistration;
use App\Services\PayPal\IpnMailer;
use App\Services\PayPal\IpnPayload;
use App\Services\PayPal\IpnProcessor;
use App\Services\PayPal\RegistrationBalance;
use App\Services\PayPal\StoredAmountOwedBalance;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\LegacyTestDatabase;
use Tests\Support\PayPal\FakeIpnMailer;

$root = dirname(__DIR__, 4);
require $root.'/vendor/autoload.php';

[, $registrationId, $txnId, $gross, $barrier] = (array) ($_SERVER['argv'] ?? []) + [null, null, null, null, null];

$emit = static function (array $payload): void {
    fwrite(STDOUT, json_encode($payload).PHP_EOL);
    fflush(STDOUT);
};

try {
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    $config = config('database.connections.'.LegacyTestDatabase::CONNECTION);
    LegacyTestDatabase::assertSafe(is_array($config) ? $config : []);
    config(['database.default' => LegacyTestDatabase::CONNECTION]);
    if ((string) DB::selectOne('select database() as db')->db !== LegacyTestDatabase::REQUIRED_DATABASE) {
        throw new RuntimeException('Worker is not connected to '.LegacyTestDatabase::REQUIRED_DATABASE);
    }

    config([
        'sesf.paypal.email' => 'seller@example.test',
        'sesf.paypal.verify_url' => 'https://ipnpb.paypal.test/cgi-bin/webscr',
        'sesf.paypal.sandbox' => false,
    ]);
    Http::fake(['ipnpb.paypal.test/*' => Http::response('VERIFIED')]);
    $mailer = new FakeIpnMailer;
    app()->instance(IpnMailer::class, $mailer);
    app()->instance(RegistrationBalance::class, new class implements RegistrationBalance
    {
        public function owedCents(ShowRegistration $registration): int
        {
            usleep(300_000);

            return (new StoredAmountOwedBalance)->owedCents($registration);
        }
    });

    $payload = IpnPayload::fromRawBody(http_build_query([
        'txn_type' => 'web_accept', 'payment_status' => 'Completed', 'mc_gross' => $gross, 'mc_fee' => '0.00',
        'mc_currency' => 'USD', 'txn_id' => $txnId, 'item_number' => $registrationId,
        'receiver_email' => 'seller@example.test',
    ]));

    $emit(['ready' => true]);

    $got = (int) DB::selectOne('select get_lock(?, 60) as got', [$barrier])->got;
    DB::selectOne('select release_lock(?) as released', [$barrier]);
    if ($got !== 1) {
        throw new RuntimeException('Barrier lock not acquired.');
    }

    $processor = app(IpnProcessor::class);
    $outcome = $processor->process($payload);
    $processor->deliver($outcome);

    $emit(['ok' => true, 'result' => $outcome->result, 'mails' => $mailer->methods()]);
    exit(0);
} catch (Throwable $e) {
    $emit(['ok' => false, 'error' => get_class($e).': '.$e->getMessage()]);
    exit(1);
}
