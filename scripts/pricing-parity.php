<?php

/**
 * Read-only parity check: recompute every legacy registration with the new
 * PricingCalculator and compare against what sesf_dev stores.
 *
 *   docker run --rm --user 1000:1000 -v /var/www/sesf-new:/app -w /app sesf-php:dev \
 *       php scripts/pricing-parity.php
 *
 * Reads only DB_HOST/DB_PORT/DB_DATABASE/DB_USERNAME/DB_PASSWORD from .env, never prints them,
 * runs SELECT statements only, and prints registration ids and amounts only (no PII).
 * See docs/pricing.md for what each category means.
 */

use App\Enums\UserGuestType;
use App\Services\Registration\ExtraLine;
use App\Services\Registration\PricingCalculator;
use App\Services\Registration\PricingInput;
use App\Services\Registration\PricingResult;
use App\Support\Money;
use Dotenv\Dotenv;

require __DIR__.'/../vendor/autoload.php';

const MAX_EXAMPLES = 10;

// Options (for testing pricing hypotheses against stored data):
//   --shirt-price=type|snapshot     type (default, legacy): current shirt_type.price;
//                                   snapshot: shirt.price when non-null and > 0, else shirt_type.price
//   --free-guests=first|most-expensive  first (default, legacy): free slots go to the first guests by id;
//                                   most-expensive: free slots go to the highest-priced guests
$opts = getopt('', ['shirt-price:', 'free-guests:']);
$shirtMode = (string) ($opts['shirt-price'] ?? 'type');
$guestMode = (string) ($opts['free-guests'] ?? 'first');
if (! in_array($shirtMode, ['type', 'snapshot'], true) || ! in_array($guestMode, ['first', 'most-expensive'], true)) {
    fwrite(STDERR, "Usage: php scripts/pricing-parity.php [--shirt-price=type|snapshot] [--free-guests=first|most-expensive]\n");
    exit(2);
}
echo "Mode: shirt-price={$shirtMode} free-guests={$guestMode}\n";

$env = Dotenv::parse((string) file_get_contents(__DIR__.'/../.env'));
$db = [];
foreach (['DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'] as $key) {
    $db[$key] = (string) ($env[$key] ?? '');
}
unset($env);

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8', $db['DB_HOST'], $db['DB_PORT'] ?: '3306', $db['DB_DATABASE']),
        $db['DB_USERNAME'],
        $db['DB_PASSWORD'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_STRINGIFY_FETCHES => true, PDO::ATTR_EMULATE_PREPARES => true],
    );
} catch (PDOException) {
    // The message can contain the host or user; keep it out of the output.
    fwrite(STDERR, "Could not connect to the database.\n");
    exit(1);
}
unset($db);

/** @return list<array<string, string|null>> */
function select(PDO $pdo, string $sql): array
{
    if (preg_match('/^\s*SELECT\b/i', $sql) !== 1) {
        throw new LogicException('Only SELECT statements are allowed.');
    }

    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

/** Stored float(6,2) values can be negative; Money::toCents rejects that, so handle the sign here. */
function signedCents(?string $value): int
{
    $value = trim((string) $value);
    if ($value === '') {
        return 0;
    }
    // float(6,2) columns come back as e.g. "35", "12.5" or "-7.2e-5"; render to 2 dp first.
    $value = sprintf('%.2F', (float) $value);

    return str_starts_with($value, '-') ? -Money::toCents(substr($value, 1)) : Money::toCents($value);
}

$regs = select($pdo, 'SELECT r.id, r.show_id, r.amount_owed, r.amount_received, r.discount, r.donation, r.is_paid,
        r.is_cancelled, s.id AS show_exists, s.price_car, s.price_adult, s.price_child
    FROM show_registration r LEFT JOIN car_show s ON s.id = r.show_id ORDER BY r.id');

// Legacy counts only cars whose body is allowed for the show (carsByShow / restrictCarsByBodyByShow).
$cars = [];
foreach (select($pdo, 'SELECT src.registration_id AS rid, COUNT(*) AS n
    FROM show_registration_car src
    JOIN show_registration r ON r.id = src.registration_id
    JOIN car c ON c.id = src.car_id
    JOIN car_body cb ON cb.id = c.body_id
    JOIN car_body_show cbs ON cbs.body_id = c.body_id AND cbs.show_id = r.show_id
    GROUP BY src.registration_id') as $row) {
    $cars[(int) $row['rid']] = (int) $row['n'];
}
$carsRaw = [];
foreach (select($pdo, 'SELECT registration_id AS rid, COUNT(*) AS n FROM show_registration_car GROUP BY registration_id') as $row) {
    $carsRaw[(int) $row['rid']] = (int) $row['n'];
}

$guests = [];
$unknownGuestType = [];
foreach (select($pdo, 'SELECT registration_id AS rid, type_id FROM user_guest ORDER BY registration_id, id') as $row) {
    $type = UserGuestType::tryFromLegacy($row['type_id']);
    if ($type === null) {
        // Legacy prices any non-adult type as a child.
        $unknownGuestType[(int) $row['rid']] = true;
        $type = UserGuestType::Child;
    }
    $guests[(int) $row['rid']][] = $type;
}

$shirts = [];
$shirtsSnapshot = [];
$missingShirtType = [];
foreach (select($pdo, 'SELECT sh.registration_id AS rid, st.id AS type_exists, st.price AS type_price, sh.price AS row_price
    FROM shirt sh LEFT JOIN shirt_type st ON st.id = sh.type_id ORDER BY sh.registration_id, sh.id') as $row) {
    $rid = (int) $row['rid'];
    if ($row['type_exists'] === null) {
        $missingShirtType[$rid] = true;
    }
    $typePrice = $row['type_price'] ?? '0';
    $hasSnapshot = $row['row_price'] !== null && (float) $row['row_price'] > 0;
    $shirts[$rid][] = $shirtMode === 'snapshot' && $hasSnapshot ? $row['row_price'] : $typePrice;
    $shirtsSnapshot[$rid][] = $hasSnapshot ? $row['row_price'] : $typePrice;
}

// Snapshot coverage: which shows ever stored a per-shirt price, and how it compares with today's type price.
echo "shirt.price snapshots (show_id: rows with price / all rows, distinct snapshot -> current type prices):\n";
foreach (select($pdo, 'SELECT sh.show_id, COUNT(*) AS n, SUM(sh.price IS NOT NULL AND sh.price > 0) AS priced,
        GROUP_CONCAT(DISTINCT CASE WHEN sh.price > 0 THEN CONCAT(sh.price, "->", IFNULL(st.price, "missing")) END) AS pairs
    FROM shirt sh LEFT JOIN shirt_type st ON st.id = sh.type_id GROUP BY sh.show_id HAVING priced > 0 ORDER BY sh.show_id') as $row) {
    printf("  show %s: %s / %s  %s\n", $row['show_id'], $row['priced'], $row['n'], $row['pairs']);
}

$extras = [];
foreach (select($pdo, 'SELECT ser.registration_id AS rid, se.price, ser.num_extras
    FROM show_extra_registration ser LEFT JOIN show_extra se ON se.id = ser.show_extra_id ORDER BY ser.registration_id, ser.id') as $row) {
    // NULL num_extras multiplied to 0 in legacy PHP.
    $extras[(int) $row['rid']][] = ExtraLine::fromDecimal($row['price'] ?? '0', (int) ($row['num_extras'] ?? 0));
}

$calc = new PricingCalculator;

$compute = function (array $reg, int $carCount, array $shirtPrices) use ($calc, $guests, $extras, $guestMode): PricingResult {
    $rid = (int) $reg['id'];
    $regGuests = $guests[$rid] ?? [];
    if ($guestMode === 'most-expensive') {
        // Free slots are type-agnostic, so ordering by price descending gives them to the dearest guests.
        $adultFirst = Money::toCents($reg['price_adult'] ?? '0') >= Money::toCents($reg['price_child'] ?? '0');
        usort($regGuests, fn (UserGuestType $a, UserGuestType $b) => $adultFirst
            ? ($a === UserGuestType::Adult ? 0 : 1) <=> ($b === UserGuestType::Adult ? 0 : 1)
            : ($a === UserGuestType::Adult ? 1 : 0) <=> ($b === UserGuestType::Adult ? 1 : 0));
    }

    return $calc->calculate(PricingInput::fromDecimals(
        carPrice: $reg['price_car'],
        adultPrice: $reg['price_adult'],
        childPrice: $reg['price_child'],
        carCount: $carCount,
        guests: $regGuests,
        shirtPrices: $shirtPrices,
        extras: $extras[$rid] ?? [],
        donation: (int) $reg['donation'],
    ));
};

// Pass 1: compute every registration.
$rows = [];
foreach ($regs as $reg) {
    $rid = (int) $reg['id'];
    $received = signedCents($reg['amount_received']);
    $discount = signedCents($reg['discount']);
    $result = $compute($reg, $cars[$rid] ?? 0, $shirts[$rid] ?? []);
    $rows[] = [
        'reg' => $reg,
        'rid' => $rid,
        'group' => (int) $reg['is_cancelled'] === 1 ? 'cancelled' : 'active',
        'stored' => signedCents($reg['amount_owed']),
        'received' => $received,
        'discount' => $discount,
        'paid' => (int) $reg['is_paid'] === 1,
        'total' => $result->totalCents,
        // Signed received/discount here, because stored values can be negative.
        'owed' => $result->totalCents - ($received + $discount),
        'shirts' => count($shirts[$rid] ?? []),
    ];
}

// shirt_type is a global table whose prices were raised over the years (no per-show price),
// so older registrations were invoiced with cheaper shirts. Find each show's typical
// per-shirt shortfall among mismatches whose difference divides evenly by the shirt count.
$deltaVotes = [];
foreach ($rows as $r) {
    $diff = $r['owed'] - $r['stored'];
    if ($diff > 0 && $r['shirts'] > 0 && $diff % $r['shirts'] === 0) {
        $k = intdiv($diff, $r['shirts']);
        $show = (int) $r['reg']['show_id'];
        $deltaVotes[$show][$k] = ($deltaVotes[$show][$k] ?? 0) + 1;
    }
}
$shirtDelta = [];
foreach ($deltaVotes as $show => $votes) {
    arsort($votes);
    if (reset($votes) >= 3) {
        $shirtDelta[$show] = (int) array_key_first($votes);
    }
}

$report = ['active' => [], 'cancelled' => []];
$paidFlag = ['active' => [], 'cancelled' => []];
$stats = [];
$add = function (array &$bucket, string $cat, int $rid, string $detail): void {
    $bucket[$cat]['count'] = ($bucket[$cat]['count'] ?? 0) + 1;
    if (count($bucket[$cat]['examples'] ?? []) < MAX_EXAMPLES) {
        $bucket[$cat]['examples'][] = trim("#{$rid} {$detail}");
    }
};
$fmt = fn (int $c) => sprintf('%.2f', $c / 100);

foreach ($rows as $r) {
    ['reg' => $reg, 'rid' => $rid, 'group' => $group, 'stored' => $stored, 'received' => $received,
        'discount' => $discount, 'paid' => $isPaid, 'total' => $total, 'owed' => $owed] = $r;
    $stats[$group]['n'] = ($stats[$group]['n'] ?? 0) + 1;
    $stats[$group]['stored'] = ($stats[$group]['stored'] ?? 0) + $stored;
    $stats[$group]['owed'] = ($stats[$group]['owed'] ?? 0) + $owed;
    $detail = sprintf('total=%s recv=%s disc=%s stored_owed=%s calc_owed=%s', $fmt($total), $fmt($received), $fmt($discount), $fmt($stored), $fmt($owed));
    $diff = $owed - $stored;
    $k = $shirtDelta[(int) $reg['show_id']] ?? null;

    if ($reg['show_exists'] === null) {
        $cat = 'X: show row missing';
    } elseif ($received < 0 || $discount < 0) {
        $cat = 'N: negative received/discount stored';
    } elseif ($stored === $owed) {
        $cat = '0: exact match';
    } elseif ($stored === 0 && $received === 0) {
        $cat = 'A: amount_owed never written (invoice never opened)';
    } elseif ($stored === $total - $discount) {
        $cat = 'B: stale owed, payment recorded after last invoice view';
    } elseif ($stored === $total - $received || $stored === $total) {
        $cat = 'C: stale owed, discount set after last invoice view';
    } elseif ($stored === $owed - $received) {
        $cat = 'D: PayPal IPN subtracted cumulative received twice';
    } elseif ($stored === -$received) {
        $cat = 'E: PayPal IPN on never-written owed (stored = -received)';
    } elseif (($carsRaw[$rid] ?? 0) !== ($cars[$rid] ?? 0)
        && $stored === $compute($reg, $carsRaw[$rid], $shirts[$rid] ?? [])->totalCents - $received - $discount) {
        $cat = 'F: car body not allowed for show (legacy excludes; stored amount included it)';
    } elseif (isset($shirtsSnapshot[$rid])
        && $stored === $compute($reg, $cars[$rid] ?? 0, $shirtsSnapshot[$rid])->totalCents - $received - $discount) {
        $cat = 'G: shirt.price snapshot explains it (shirt_type price changed)';
    } elseif ($k !== null && $diff > 0 && $diff % $k === 0 && intdiv($diff, $k) <= $r['shirts']) {
        // Not every shirt type changed price, so accept 1..n shirts times the show's delta.
        $cat = 'S: shirt_type price raised after invoicing (diff = m shirts x show delta)';
    } elseif ($stored === 0 && $isPaid && $owed < 0) {
        $cat = 'K: paid, owed zeroed, received exceeds current total (items removed / duplicate payment)';
    } elseif ($stored === 0 && $isPaid) {
        $cat = 'H: paid, owed zeroed by hand, received short of current total';
    } elseif ($stored < $owed) {
        $cat = 'I: stored owed lower than calculated (old prices/items, or manual edit)';
    } else {
        $cat = 'J: stored owed higher than calculated (items removed/prices lowered after invoice view)';
    }
    $add($report[$group], $cat, $rid, $detail);

    if (isset($unknownGuestType[$rid])) {
        $add($report[$group], 'note: unknown guest type priced as child', $rid, '');
    }
    if (isset($missingShirtType[$rid])) {
        $add($report[$group], 'note: shirt with missing shirt_type (legacy errors; priced 0 here)', $rid, '');
    }
    if (($carsRaw[$rid] ?? 0) !== ($cars[$rid] ?? 0)) {
        $add($report[$group], 'note: cars excluded by body restriction', $rid, sprintf('linked=%d counted=%d', $carsRaw[$rid] ?? 0, $cars[$rid] ?? 0));
    }

    if ($isPaid && $owed > 0) {
        $add($paidFlag[$group], 'is_paid=1 but calculated owed > 0', $rid, $detail);
    } elseif (! $isPaid && $owed <= 0 && $total > 0) {
        $add($paidFlag[$group], 'is_paid=0 but calculated owed <= 0', $rid, $detail);
    } else {
        $add($paidFlag[$group], 'is_paid consistent with calculated owed', $rid, '');
    }
}

ksort($shirtDelta);
echo 'Per-show shirt price delta used for category S (show:cents): ';
echo implode(' ', array_map(fn ($s, $d) => "{$s}:{$d}", array_keys($shirtDelta), $shirtDelta)), "\n";

foreach (['active' => 'NON-CANCELLED', 'cancelled' => 'CANCELLED'] as $group => $title) {
    if (! isset($stats[$group])) {
        continue;
    }
    printf("\n== %s registrations: %d (sum stored owed %s, sum calculated owed %s)\n",
        $title, $stats[$group]['n'], $fmt($stats[$group]['stored']), $fmt($stats[$group]['owed']));
    foreach (['categories' => $report[$group], 'is_paid flag' => $paidFlag[$group]] as $heading => $bucket) {
        echo "  -- {$heading}\n";
        ksort($bucket);
        foreach ($bucket as $cat => $info) {
            printf("  %-92s %5d\n", $cat, $info['count']);
            if (! str_starts_with($cat, '0:') && ! str_contains($cat, 'consistent')) {
                foreach ($info['examples'] as $ex) {
                    echo "      {$ex}\n";
                }
            }
        }
    }
}
