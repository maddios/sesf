<?php

namespace Tests\Database\PayPal;

use App\Models\ShowRegistration;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\LoadsLegacySchema;
use Tests\TestCase;

/**
 * Real multi-process races on one registration (pattern from tests/Concurrency). Workers are
 * separate PHP processes with their own sessions, so fixtures are committed (no per-test
 * rollback) and deleted in finally blocks. Ephemeral sesf_test only.
 */
#[Group('concurrency')]
class IpnConcurrencyTest extends TestCase
{
    use LoadsLegacySchema;

    protected function legacyTransactional(): bool
    {
        return false;
    }

    #[Test]
    public function simultaneous_ipns_with_different_txn_ids_on_one_registration_all_apply(): void
    {
        $registration = ShowRegistration::factory()->create(['amount_owed' => '100.00', 'amount_received' => '0.00']);

        try {
            $results = $this->race([
                [$registration->id, 'RACETXN0000000001', '25.00'],
                [$registration->id, 'RACETXN0000000002', '30.00'],
                [$registration->id, 'RACETXN0000000003', '45.00'],
            ]);

            $this->assertSame(['applied', 'applied', 'applied'], array_column($results, 'result'), json_encode($results) ?: '');
            $fresh = $registration->fresh();
            $this->assertNotNull($fresh);
            $this->assertSame('100.00', $fresh->amount_received);
            $this->assertSame('100.00', $fresh->amount_received_actual);
            $this->assertSame('0.00', $fresh->amount_owed);
            $this->assertTrue($fresh->is_paid);
            $this->assertSame(3, DB::table('paypal_payment')->where('registration_id', $registration->id)->count());
        } finally {
            $this->cleanup($registration);
        }
    }

    #[Test]
    public function simultaneous_copies_of_one_ipn_apply_exactly_once(): void
    {
        $registration = ShowRegistration::factory()->create(['amount_owed' => '100.00', 'amount_received' => '0.00']);

        try {
            $results = $this->race([
                [$registration->id, 'RACEDUP0000000001', '100.00'],
                [$registration->id, 'RACEDUP0000000001', '100.00'],
                [$registration->id, 'RACEDUP0000000001', '100.00'],
            ]);

            $outcomes = array_column($results, 'result');
            sort($outcomes);
            $this->assertSame(['applied', 'duplicate', 'duplicate'], $outcomes, json_encode($results) ?: '');
            $this->assertSame('100.00', $registration->fresh()?->amount_received);
            $this->assertSame(1, DB::table('paypal_payment')->where('txn_id', 'RACEDUP0000000001')->count());
            // Only the worker that applied it sent notices.
            $this->assertSame(2, array_sum(array_map(fn (array $r): int => count($r['mails']), $results)));
        } finally {
            $this->cleanup($registration);
        }
    }

    /**
     * @param  list<array{0: int, 1: string, 2: string}>  $jobs  [registration_id, txn_id, mc_gross]
     * @return list<array{ok: bool, result: string, mails: list<string>}>
     */
    private function race(array $jobs): array
    {
        $barrier = 'sesf_ipn_race_'.getmypid().'_'.bin2hex(random_bytes(4));
        $worker = __DIR__.'/Support/ipn-race-worker.php';
        $env = array_merge(getenv(), ['APP_ENV' => 'testing']);

        if ((int) DB::selectOne('select get_lock(?, 10) as got', [$barrier])->got !== 1) {
            throw new RuntimeException('Could not take the barrier lock.');
        }

        $procs = [];
        try {
            foreach ($jobs as [$registrationId, $txnId, $gross]) {
                $pipes = [];
                $proc = proc_open(
                    [PHP_BINARY, $worker, (string) $registrationId, $txnId, $gross, $barrier],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $pipes,
                    base_path(),
                    $env,
                );
                if (! is_resource($proc)) {
                    throw new RuntimeException('proc_open failed');
                }
                fclose($pipes[0]);
                $procs[] = ['proc' => $proc, 'out' => $pipes[1], 'err' => $pipes[2]];
            }

            foreach ($procs as $i => $p) {
                $line = fgets($p['out']);
                if ($line === false || ! str_contains($line, '"ready"')) {
                    throw new RuntimeException("Worker {$i} did not get ready: ".$line.stream_get_contents($p['err']));
                }
            }

            $deadline = microtime(true) + 20;
            do {
                $waiting = (int) DB::selectOne(
                    'select count(*) as n from information_schema.processlist where info like ?',
                    ['select get_lock(%'],
                )->n;
                if ($waiting >= count($procs)) {
                    break;
                }
                usleep(20_000);
            } while (microtime(true) < $deadline);
        } finally {
            DB::selectOne('select release_lock(?) as released', [$barrier]);
        }

        $results = [];
        foreach ($procs as $p) {
            $line = (string) stream_get_contents($p['out']);
            $stderr = (string) stream_get_contents($p['err']);
            fclose($p['out']);
            fclose($p['err']);
            $exit = proc_close($p['proc']);
            $decoded = json_decode(trim($line), true);
            if ($exit !== 0 || ! is_array($decoded) || ($decoded['ok'] ?? false) !== true) {
                throw new RuntimeException('Worker failed: '.$line.$stderr);
            }
            $results[] = $decoded;
        }

        /** @var list<array{ok: bool, result: string, mails: list<string>}> $results */
        return $results;
    }

    private function cleanup(ShowRegistration $registration): void
    {
        DB::table('paypal_payment')->where('registration_id', $registration->id)->delete();
        DB::table('show_registration')->where('id', $registration->id)->delete();
        DB::table('user_preferences')->where('user_id', $registration->user_id)->delete();
        DB::table('user')->where('id', $registration->user_id)->delete();
        DB::table('car_show')->where('id', $registration->show_id)->delete();
    }
}
