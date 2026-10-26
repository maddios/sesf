<?php

/**
 * E2E sandbox seed (scripts/e2e.sh up|reseed). Runs INSIDE the e2eapp container only:
 *
 *   docker compose -p sesf-e2e exec -T e2eapp php scripts/e2e-seed.php
 *
 * Refuses to run unless the default connection is mysql on host "e2edb" with database exactly
 * "sesf_e2e", and the live session reports sesf_e2e. It then DROPS and recreates sesf_e2e, loads
 * the legacy schema + database/sql + lookup fixture (Tests\Support\LegacyTestDatabase::ensureSchema,
 * whose own sesf_test guard is untouched), seeds with the model factories, clears sandbox sessions
 * and cache, writes e2e/.state/seed.json for the Playwright specs and prints the logins.
 */

declare(strict_types=1);

use App\Enums\AgendaType;
use App\Enums\ExtraType;
use App\Enums\JudgingType;
use App\Enums\SponsorType;
use App\Enums\UserGuestType;
use App\Models\Car;
use App\Models\CarShow;
use App\Models\CarShowAgenda;
use App\Models\CarShowLocation;
use App\Models\Shirt;
use App\Models\ShowExtra;
use App\Models\ShowExtraRegistration;
use App\Models\ShowRegistration;
use App\Models\ShowRegistrationCar;
use App\Models\ShowSponsor;
use App\Models\Sponsor;
use App\Models\User;
use App\Models\UserGuest;
use App\Services\Registration\RegistrationPricing;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Tests\Support\LegacyTestDatabase;

const E2E_DATABASE = 'sesf_e2e';
const E2E_HOST = 'e2edb';
const E2E_PASSWORD = 'Password-123';

function e2e_fail(string $message): never
{
    fwrite(STDERR, "e2e-seed: REFUSING: {$message}\n");
    exit(2);
}

// Cheap pre-checks on the raw environment before Laravel boots.
if (getenv('DB_HOST') !== E2E_HOST || getenv('DB_DATABASE') !== E2E_DATABASE) {
    e2e_fail('DB_HOST/DB_DATABASE must be e2edb/sesf_e2e (run inside the e2eapp container via scripts/e2e.sh).');
}

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if ($app->configurationIsCached()) {
    e2e_fail('configuration is cached; the sandbox must read its own environment.');
}

$default = (string) config('database.default');
$config = config("database.connections.{$default}");
if ($default !== 'mysql' || ! is_array($config)
    || ($config['host'] ?? null) !== E2E_HOST
    || ($config['database'] ?? null) !== E2E_DATABASE) {
    e2e_fail(sprintf(
        'default connection [%s] resolves to host [%s] database [%s]; only mysql on %s/%s is allowed.',
        $default,
        is_array($config) ? (string) ($config['host'] ?? '?') : '?',
        is_array($config) ? (string) ($config['database'] ?? '?') : '?',
        E2E_HOST,
        E2E_DATABASE,
    ));
}

$connection = DB::connection();
$live = (string) $connection->selectOne('select database() as db')->db;
if ($live !== E2E_DATABASE) {
    e2e_fail("live session is connected to [{$live}], expected [".E2E_DATABASE.'].');
}

// ---------------------------------------------------------------------------------------------
// Fresh database.
// ---------------------------------------------------------------------------------------------
$connection->unprepared('DROP DATABASE IF EXISTS `'.E2E_DATABASE.'`');
$connection->unprepared('CREATE DATABASE `'.E2E_DATABASE.'` CHARACTER SET latin1 COLLATE latin1_swedish_ci');
$connection->unprepared('USE `'.E2E_DATABASE.'`');
DB::purge();
$connection = DB::connection();
if ((string) $connection->selectOne('select database() as db')->db !== E2E_DATABASE) {
    e2e_fail('could not reconnect to '.E2E_DATABASE.'.');
}

LegacyTestDatabase::ensureSchema(
    $connection,
    base_path(LegacyTestDatabase::SCHEMA_FILE),
    base_path(LegacyTestDatabase::SQL_DIRECTORY),
    base_path(LegacyTestDatabase::LOOKUP_FIXTURE),
);

// Throttles, cached lookups and sessions from a previous run must not leak into this one.
Artisan::call('cache:clear');
foreach (File::files(storage_path('framework/sessions')) as $file) {
    File::delete($file->getPathname());
}

// Deterministic fake data.
fake()->seed(20261025);
mt_srand(20261025);

// ---------------------------------------------------------------------------------------------
// People and cars.
// ---------------------------------------------------------------------------------------------
$hash = Hash::make(E2E_PASSWORD);

/** @param array<string, mixed> $attributes */
$makeUser = static function (array $attributes, bool $admin = false) use ($hash): User {
    $factory = User::factory();
    if ($admin) {
        $factory = $factory->admin();
    }

    return $factory->create($attributes + ['password' => $hash]);
};

$admin = $makeUser(['first' => 'Ada', 'last' => 'Admin', 'email' => 'admin@sesf.test', 'phone' => '555-010-0001'], true);
$mia = $makeUser(['first' => 'Mia', 'last' => 'Member', 'email' => 'member@sesf.test', 'phone' => '555-010-0002']);
$ben = $makeUser(['first' => 'Ben', 'last' => 'Second', 'email' => 'member2@sesf.test', 'phone' => '555-010-0003']);

// Dedicated accounts, one per stateful spec, so specs never disturb each other.
$lockout = $makeUser(['first' => 'Lou', 'last' => 'Lockout', 'email' => 'lockout@sesf.test', 'phone' => '555-010-0004']);
$codeUser = $makeUser(['first' => 'Cody', 'last' => 'Code', 'email' => 'code@sesf.test', 'phone' => '555-010-0005']);
$codeLock = $makeUser(['first' => 'Cora', 'last' => 'Codelock', 'email' => 'codelock@sesf.test', 'phone' => '555-010-0006']);
$magic = $makeUser(['first' => 'Max', 'last' => 'Magic', 'email' => 'magic@sesf.test', 'phone' => '555-010-0007']);
$resetUser = $makeUser(['first' => 'Rita', 'last' => 'Reset', 'email' => 'reset@sesf.test', 'phone' => '555-010-0008']);
$garage = $makeUser(['first' => 'Gary', 'last' => 'Garage', 'email' => 'garage@sesf.test', 'phone' => '555-010-0009']);
$payer = $makeUser(['first' => 'Pat', 'last' => 'Payer', 'email' => 'payer@sesf.test', 'phone' => '555-010-0010']);
$partial = $makeUser(['first' => 'Quinn', 'last' => 'Partial', 'email' => 'partial@sesf.test', 'phone' => '555-010-0011']);
$invalid = $makeUser(['first' => 'Ivy', 'last' => 'Invalid', 'email' => 'invalid@sesf.test', 'phone' => '555-010-0012']);
$a11y = $makeUser(['first' => 'Alex', 'last' => 'Access', 'email' => 'a11y@sesf.test', 'phone' => '555-010-0013']);

const BODY_E24 = 1;
const BODY_E63 = 2;
const BODY_E31 = 4;

$miaAllowed = Car::factory()->create(['user_id' => $mia->id, 'year' => '1987', 'model' => '635CSi', 'nickname' => 'Blue Shark', 'body_id' => BODY_E24]);
$miaOther = Car::factory()->create(['user_id' => $mia->id, 'year' => '1995', 'model' => '850Ci', 'nickname' => 'Eight', 'body_id' => BODY_E31]);
$benCar = Car::factory()->create(['user_id' => $ben->id, 'year' => '1984', 'model' => '633CSi', 'nickname' => null, 'body_id' => BODY_E24]);
$payerCar = Car::factory()->create(['user_id' => $payer->id, 'year' => '1988', 'model' => 'M6', 'body_id' => BODY_E24]);
$partialCar = Car::factory()->create(['user_id' => $partial->id, 'year' => '1983', 'model' => '635CSi', 'body_id' => BODY_E24]);
$invalidCar = Car::factory()->create(['user_id' => $invalid->id, 'year' => '1986', 'model' => '635CSi', 'body_id' => BODY_E24]);
$a11yCar = Car::factory()->create(['user_id' => $a11y->id, 'year' => '1989', 'model' => '635CSi', 'body_id' => BODY_E24]);

// Directory paging filler: 8 more members, each with a car.
$fillers = [];
for ($i = 1; $i <= 8; $i++) {
    $user = $makeUser(['email' => "filler{$i}@sesf.test", 'phone' => sprintf('555-020-%04d', $i)]);
    Car::factory()->create(['user_id' => $user->id,
        'year' => (string) (1976 + $i),
        'model' => ['630CS', '633CSi', '635CSi', 'M635CSi', 'L6', '840Ci', '850i', 'CSi'][$i - 1],
        'body_id' => [BODY_E24, BODY_E24, BODY_E63, BODY_E24, BODY_E24, BODY_E31, BODY_E31, 5][$i - 1],
    ]);
    $fillers[] = $user;
}

// ---------------------------------------------------------------------------------------------
// Current event (registration open) and a past event.
// ---------------------------------------------------------------------------------------------
// The past event is created first, so the current one has the higher id (as in production).
$past = CarShow::factory()->past()->create([
    'short_name' => 'sharkfest-e2e-past',
    'name' => 'Sharkfest E2E Past',
    'description' => '<p>Last year in the sandbox.</p>',
    'price_car' => 80.00,
    'price_adult' => 40.00,
    'price_child' => 20.00,
    'is_active' => true,
]);
DB::table('car_body_show')->insert(['body_id' => BODY_E24, 'show_id' => $past->id]);

$current = CarShow::factory()->create([
    'short_name' => 'sharkfest-e2e',
    'name' => 'Southeast Sharkfest E2E',
    'description' => '<p>The E2E sandbox show. Bring your shark.</p>',
    'short_description' => 'The E2E sandbox show.',
    'schedule' => '<p>Friday arrival, Saturday show, Sunday drive.</p>',
    'price_car' => 100.00,
    'price_adult' => 50.00,
    'price_child' => 25.00,
    'date_start' => now()->addDays(30)->toDateString(),
    'date_end' => now()->addDays(32)->toDateString(),
    'date_active' => now()->subDays(7),
    'date_registration_deactivation' => now()->addDays(20),
    'is_active' => true,
]);

foreach ([BODY_E24, BODY_E63, 3] as $bodyId) {
    DB::table('car_body_show')->insert(['body_id' => $bodyId, 'show_id' => $current->id]);
}
foreach ([[BODY_E24, JudgingType::Clean], [BODY_E24, JudgingType::SuperClean], [BODY_E24, JudgingType::Concours], [BODY_E63, JudgingType::Clean]] as [$bodyId, $type]) {
    DB::table('judging_show_body')->insert([
        'judging_type_id' => $type->value,
        'car_body_id' => $bodyId,
        'car_show_id' => $current->id,
        'updated_at' => now(),
    ]);
}

CarShowAgenda::factory()->create(['show_id' => $current->id, 'name' => 'Friday', 'text' => 'Arrival and welcome dinner', 'type_id' => AgendaType::Agenda, 'sort_order' => 1]);
CarShowAgenda::factory()->create(['show_id' => $current->id, 'name' => 'Saturday', 'text' => 'Show field opens at 9am', 'type_id' => AgendaType::Agenda, 'sort_order' => 2]);
CarShowAgenda::factory()->create(['show_id' => $current->id, 'name' => 'Judging classes', 'text' => 'Clean, Super Clean and Concours', 'type_id' => AgendaType::Judging, 'sort_order' => 1]);
CarShowAgenda::factory()->create(['show_id' => $current->id, 'name' => 'Judging rules', 'text' => 'Judges decide on Saturday afternoon', 'type_id' => AgendaType::Judging, 'sort_order' => 2]);

CarShowLocation::factory()->primary()->create([
    'show_id' => $current->id,
    'name' => 'Sandbox Speedway',
    'description' => 'Main show field',
    'address1' => '100 Test Track Rd',
    'city' => 'Daytona Beach',
    'state' => 'FL',
    'zip' => '32114',
]);

$tiers = [SponsorType::M, SponsorType::Gold, SponsorType::Silber, SponsorType::Bronzit];
foreach ($tiers as $index => $tier) {
    $sponsor = Sponsor::factory()->create([
        'name' => "E2E {$tier->name} Sponsor",
        'URL' => 'https://sponsor'.($index + 1).'.example.test',
        'is_enabled' => true,
        'sort_order' => $index,
    ]);
    ShowSponsor::factory()->create([
        'show_id' => $current->id,
        'sponsor_id' => $sponsor->id,
        'type_id' => $tier,
        'sort_order' => $index,
    ]);
}

$checkboxExtra = ShowExtra::factory()->create([
    'show_id' => $current->id,
    'name' => 'Track Session',
    'description' => 'One track session on Sunday.',
    'price' => 15.00,
    'type_id' => ExtraType::Registration,
    'min_value' => 1,
    'max_value' => 1,
    'auth_key' => 'e2e-track-session',
]);
$numericExtra = ShowExtra::factory()->create([
    'show_id' => $current->id,
    'name' => 'Raffle Tickets',
    'description' => 'Raffle tickets, 5 dollars each.',
    'price' => 5.00,
    'type_id' => ExtraType::NumericList,
    'min_value' => 0,
    'max_value' => 4,
    'auth_key' => 'e2e-raffle',
]);

// ---------------------------------------------------------------------------------------------
// Registrations.
// ---------------------------------------------------------------------------------------------
$pricing = app(RegistrationPricing::class);

/** Creates a registration with cars/guests and stores amount_owed from the app's own pricing. */
$register = static function (CarShow $show, User $user, array $cars, array $guests = [], array $overrides = []) use ($pricing): ShowRegistration {
    $registration = ShowRegistration::factory()->create(array_merge([
        'show_id' => $show->id,
        'user_id' => $user->id,
        'arrival_date' => $show->date_start instanceof DateTimeInterface ? $show->date_start->format('Y-m-d') : (string) $show->date_start,
        'arrival_time' => 'morning',
    ], $overrides));
    foreach ($cars as $car) {
        ShowRegistrationCar::factory()->create(['car_id' => $car->id, 'registration_id' => $registration->id, 'judging_type_id' => JudgingType::Clean]);
    }
    foreach ($guests as [$first, $last, $type]) {
        UserGuest::factory()->forRegistration($registration)->create(['first' => $first, 'last' => $last, 'email' => null, 'type_id' => $type]);
    }
    $registration->refresh();
    $owedCents = $pricing->owedCents($registration);
    $registration->forceFill(['amount_owed' => $owedCents / 100])->save();

    return $registration->refresh();
};

// Past event (ended: invoices show the STORED amounts). Mia paid in full: car 80 + adult 40 + shirt 35 = 155.
// Ben still owes his car (80).
$miaPast = $register($past, $mia, [$miaAllowed], [['Sam', 'Member', UserGuestType::Adult]]);
Shirt::factory()->create(['user_id' => $mia->id, 'registration_id' => $miaPast->id, 'show_id' => $past->id]);
$miaPast->forceFill(['amount_owed' => 0, 'amount_received' => 155, 'amount_received_actual' => 150.20, 'is_paid' => true])->save();
$benPast = $register($past, $ben, [$benCar]);
$benPast->forceFill(['amount_owed' => 80, 'amount_received' => 0, 'is_paid' => false])->save();

// Current event: IPN targets, each with its own registration.
$payerReg = $register($current, $payer, [$payerCar], [['Paula', 'Payer', UserGuestType::Adult]]);
$partialReg = $register($current, $partial, [$partialCar]);
$invalidReg = $register($current, $invalid, [$invalidCar]);
$a11yReg = $register($current, $a11y, [$a11yCar], [['Kid', 'Access', UserGuestType::Child]]);
ShowExtraRegistration::factory()->create(['show_extra_id' => $numericExtra->id, 'registration_id' => $a11yReg->id, 'num_extras' => 2]);
$a11yReg->forceFill(['amount_owed' => $pricing->owedCents($a11yReg->refresh()) / 100])->save();

// ---------------------------------------------------------------------------------------------
// State file for the specs, and the summary table.
// ---------------------------------------------------------------------------------------------
$appUrl = rtrim((string) config('app.url'), '/');
$userRow = static fn (User $u): array => [
    'id' => $u->id,
    'first' => $u->first,
    'last' => $u->last,
    'email' => $u->email,
    'phone' => $u->phone,
    'profilePath' => ltrim($u->profileURL, '#'),
];
$money = static fn (ShowRegistration $r): string => number_format((float) $r->refresh()->amount_owed, 2, '.', '');

$state = [
    'generatedAt' => now()->toIso8601String(),
    'appUrl' => $appUrl,
    'password' => E2E_PASSWORD,
    'paypalEmail' => (string) config('sesf.paypal.email'),
    'users' => [
        'admin' => $userRow($admin),
        'mia' => $userRow($mia),
        'ben' => $userRow($ben),
        'lockout' => $userRow($lockout),
        'code' => $userRow($codeUser),
        'codelock' => $userRow($codeLock),
        'magic' => $userRow($magic),
        'reset' => $userRow($resetUser),
        'garage' => $userRow($garage),
        'payer' => $userRow($payer),
        'partial' => $userRow($partial),
        'invalid' => $userRow($invalid),
        'a11y' => $userRow($a11y),
    ],
    'fillers' => array_map($userRow, $fillers),
    'cars' => [
        'miaAllowed' => ['id' => $miaAllowed->id, 'name' => $miaAllowed->refresh()->name],
        'miaOther' => ['id' => $miaOther->id, 'name' => $miaOther->refresh()->name],
        'ben' => ['id' => $benCar->id, 'name' => $benCar->refresh()->name],
    ],
    'events' => [
        'current' => ['id' => $current->id, 'name' => $current->name, 'path' => ltrim($current->profileURL, '#'), 'priceCar' => 100, 'priceAdult' => 50, 'priceChild' => 25],
        'past' => ['id' => $past->id, 'name' => $past->name, 'path' => ltrim($past->profileURL, '#')],
    ],
    'extras' => [
        'checkbox' => ['id' => $checkboxExtra->id, 'name' => $checkboxExtra->name, 'price' => 15],
        'numeric' => ['id' => $numericExtra->id, 'name' => $numericExtra->name, 'price' => 5],
    ],
    'registrations' => [
        'payer' => ['id' => $payerReg->id, 'owed' => $money($payerReg)],
        'partial' => ['id' => $partialReg->id, 'owed' => $money($partialReg)],
        'invalid' => ['id' => $invalidReg->id, 'owed' => $money($invalidReg)],
        'a11y' => ['id' => $a11yReg->id, 'owed' => $money($a11yReg)],
        'miaPast' => ['id' => $miaPast->id],
        'benPast' => ['id' => $benPast->id],
    ],
];

$stateDir = base_path('e2e/.state');
File::ensureDirectoryExists($stateDir);
File::put($stateDir.'/seed.json', json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

$line = str_repeat('-', 78);
echo "\n{$line}\n SESF E2E sandbox seeded (database ".E2E_DATABASE.' on '.E2E_HOST.")\n{$line}\n";
printf(" %-10s %-24s %-14s %s\n", 'Role', 'Email', 'Password', 'Profile');
foreach (['admin' => $admin, 'member' => $mia, 'member 2' => $ben, 'payer' => $payer, 'garage' => $garage] as $role => $user) {
    printf(" %-10s %-24s %-14s %s\n", $role, $user->email, E2E_PASSWORD, $appUrl.'/#'.$userRow($user)['profilePath']);
}
echo " (all seeded accounts use the same password; full list in e2e/.state/seed.json)\n{$line}\n";
printf(" %-22s %s\n", 'App', $appUrl.'/');
printf(" %-22s %s\n", 'Current event', $appUrl.'/#'.$state['events']['current']['path']);
printf(" %-22s %s\n", 'Register (as Mia)', $appUrl.'/#'.$state['events']['current']['path'].'/register');
printf(" %-22s %s\n", 'Mailpit', 'http://localhost:8025/');
printf(" %-22s %s\n", 'PayPal stub control', 'http://localhost:9001/last');
echo "{$line}\n";
