<?php

use App\Enums\SponsorType;
use App\Enums\UserType;
use App\Models\CarShow;
use App\Models\CarShowLocation;
use App\Models\Casts\Money2;
use App\Models\Concerns\HasLegacyDates;
use App\Models\ShowExtra;
use App\Models\ShowSponsor;
use App\Models\Sponsor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

afterEach(fn () => Carbon::setTestNow());

/**
 * Inserts a legacy car_show row with raw SQL so zero dates can be stored.
 *
 * @param  array<string, mixed>  $overrides
 */
function carShowRawRow(array $overrides = []): int
{
    return (int) DB::table('car_show')->insertGetId(array_merge([
        'short_name' => 'raw-'.uniqid(),
        'name' => 'Raw Show',
        'description' => 'Raw description',
        'is_schedule_pdf' => 0,
        'is_active' => 1,
        'has_image' => 0,
        'created_at' => '2024-01-01 00:00:00',
        'updated_at' => '0000-00-00 00:00:00',
        'date_start' => '0000-00-00',
        'date_end' => '0000-00-00',
        'date_active' => '0000-00-00 00:00:00',
        'date_registration_deactivation' => '0000-00-00 00:00:00',
    ], $overrides));
}

/**
 * Inserts a legacy user, location, show_registration and car linked to the show, returning the user id.
 * Only the columns the scopes need are set; the rest use table defaults.
 */
function carShowInsertRegistration(int $showId, string $tag): int
{
    $db = DB::connection();
    $locationId = (int) $db->table('location')->insertGetId([
        'address1' => '1 Main', 'city' => 'Raleigh', 'state' => 'NC', 'zip' => '27601',
    ]);
    $userId = (int) $db->table('user')->insertGetId([
        'date_registered' => '2024-01-01 00:00:00', 'email' => "{$tag}@example.test", 'location_id' => $locationId,
        'password' => 'x', 'address1' => '1 Main', 'city' => 'Raleigh', 'state' => 'NC', 'zip' => '27601',
    ]);
    $carId = (int) $db->table('car')->insertGetId([
        'user_id' => $userId, 'body_id' => 1, 'type_id' => 1, 'nickname' => $tag,
    ]);
    $registrationId = (int) $db->table('show_registration')->insertGetId([
        'show_id' => $showId, 'user_id' => $userId,
        'invoice_id' => (int) $db->table('show_registration')->where('show_id', $showId)->count() + 1,
    ]);
    $db->table('show_registration_car')->insert(['car_id' => $carId, 'registration_id' => $registrationId]);

    return $userId;
}

test('uses the car_show table, id key, timestamps and guards only id', function () {
    $show = new CarShow;

    expect($show->getTable())->toBe('car_show')
        ->and($show->getKeyName())->toBe('id')
        ->and($show->usesTimestamps())->toBeTrue()
        ->and($show->getGuarded())->toBe(['id'])
        ->and($show->getFillable())->toBe([]);
});

test('uses the legacy date and flag casts and the money cast', function () {
    $show = CarShow::factory()->create([
        'price_car' => '12.345',
        'price_adult' => 7,
        'price_child' => null,
        'shirt_stock_json' => ['3' => 10, '5' => 0],
        'is_schedule_pdf' => 1,
    ])->refresh();

    expect($show->price_car)->toBe('12.35')
        ->and($show->price_adult)->toBe('7.00')
        ->and($show->price_child)->toBeNull()
        ->and($show->date_start)->toBeInstanceOf(Carbon::class)
        ->and($show->date_active)->toBeInstanceOf(Carbon::class)
        ->and($show->is_schedule_pdf)->toBeTrue()
        ->and($show->is_active)->toBeTrue()
        ->and($show->is_judging_released)->toBeFalse()
        ->and($show->has_image)->toBeFalse()
        ->and($show->shirt_stock_json)->toBe(['3' => 10, '5' => 0]);

    expect($show->getCasts())->toMatchArray([
        'price_car' => Money2::class,
        'date_start' => 'date',
        'date_active' => 'datetime',
        'is_schedule_pdf' => 'boolean',
        'shirt_stock_json' => 'array',
    ]);
});

test('uses HasLegacyDates on the model', function () {
    expect(in_array(HasLegacyDates::class, class_uses_recursive(CarShow::class)))->toBeTrue();
});

test('zero and null dates read as null through the model', function () {
    $id = carShowRawRow();

    $show = CarShow::query()->findOrFail($id);

    expect($show->date_start)->toBeNull()
        ->and($show->date_end)->toBeNull()
        ->and($show->date_active)->toBeNull()
        ->and($show->date_registration_deactivation)->toBeNull()
        ->and($show->updated_at)->toBeNull();
});

test('accessor JSON keys are present in toArray and toJson', function () {
    $show = CarShow::factory()->create(['description' => 'x']);

    $keys = ['imageURL', 'profileURL', 'shortDescription', 'regOpen', 'ended'];

    foreach ($keys as $key) {
        expect($show->toArray())->toHaveKey($key)
            ->and(json_decode($show->toJson(), true))->toHaveKey($key);
    }
});

test('imageURL uses the generic image unless the show has an image', function () {
    $without = CarShow::factory()->create(['has_image' => false]);
    $with = CarShow::factory()->create(['has_image' => true]);

    expect($without->imageURL)->toBe('images/generic-show.jpg')
        ->and($with->imageURL)->toBe('images/shows/'.$with->id.'.jpg');
});

test('URLName strips non-alphanumerics and profileURL builds the hash URL', function () {
    $show = CarShow::factory()->create(['name' => "Hershey's Car-Show 2026!"]);

    expect($show->URLName)->toBe('HersheysCarShow2026')
        ->and($show->profileURL)->toBe('#/HersheysCarShow2026/e'.$show->id);
});

test('shortDescription returns the stored value, or 350 characters plus ellipsis', function () {
    $stored = CarShow::factory()->create(['short_description' => 'Short one', 'description' => 'Long']);
    $fallback = CarShow::factory()->create([
        'short_description' => null,
        'description' => str_repeat('a', 400),
    ]);
    $emptyStored = CarShow::factory()->create(['short_description' => '', 'description' => 'Body']);

    expect($stored->shortDescription)->toBe('Short one')
        ->and($fallback->shortDescription)->toBe(str_repeat('a', 350).'...')
        ->and($emptyStored->shortDescription)->toBe('Body...');
});

test('ended is true once date_end has passed, and when date_end is missing', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-24 12:00:00'));

    $yesterday = CarShow::factory()->create(['date_end' => '2026-10-23']);
    $today = CarShow::factory()->create(['date_end' => '2026-10-24']);
    $tomorrow = CarShow::factory()->create(['date_end' => '2026-10-25']);
    $missing = CarShow::factory()->create(['date_end' => null]);
    $zero = CarShow::query()->findOrFail(carShowRawRow(['date_end' => '0000-00-00']));

    expect($yesterday->ended)->toBeTrue()
        ->and($today->ended)->toBeTrue()
        ->and($tomorrow->ended)->toBeFalse()
        ->and($missing->ended)->toBeTrue()
        ->and($zero->ended)->toBeTrue();
});

test('regOpen is true only between date_active and date_registration_deactivation', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-24 12:00:00'));

    $open = CarShow::factory()->registrationOpen()->create();
    $notYet = CarShow::factory()->create([
        'date_active' => '2026-10-24 13:00:00',
        'date_registration_deactivation' => '2026-11-01 00:00:00',
    ]);
    $deactivated = CarShow::factory()->registrationClosed()->create();

    expect($open->regOpen)->toBeTrue()
        ->and($notYet->regOpen)->toBeFalse()
        ->and($deactivated->regOpen)->toBeFalse();
});

test('regOpen boundaries: exactly at activation or deactivation is not open', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-24 12:00:00'));

    $atActivation = CarShow::factory()->create([
        'date_active' => '2026-10-24 12:00:00',
        'date_registration_deactivation' => '2026-11-01 00:00:00',
    ]);
    $atDeactivation = CarShow::factory()->create([
        'date_active' => '2026-10-01 00:00:00',
        'date_registration_deactivation' => '2026-10-24 12:00:00',
    ]);

    expect($atActivation->regOpen)->toBeFalse()
        ->and($atDeactivation->regOpen)->toBeFalse();
});

test('regOpen with a missing or zero activation date counts as started (legacy behaviour)', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-24 12:00:00'));

    $nullActive = CarShow::factory()->create([
        'date_active' => null,
        'date_registration_deactivation' => '2026-11-01 00:00:00',
    ]);
    $zeroActive = CarShow::query()->findOrFail(carShowRawRow([
        'date_active' => '0000-00-00 00:00:00',
        'date_registration_deactivation' => '2026-11-01 00:00:00',
    ]));

    expect($nullActive->regOpen)->toBeTrue()
        ->and($zeroActive->regOpen)->toBeTrue();
});

test('regOpen with a missing or zero deactivation date is closed (legacy behaviour)', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-24 12:00:00'));

    $nullDeactivation = CarShow::factory()->create([
        'date_active' => '2026-10-01 00:00:00',
        'date_registration_deactivation' => null,
    ]);
    $zeroDeactivation = CarShow::query()->findOrFail(carShowRawRow([
        'date_active' => '2026-10-01 00:00:00',
        'date_registration_deactivation' => '0000-00-00 00:00:00',
    ]));

    expect($nullDeactivation->regOpen)->toBeFalse()
        ->and($zeroDeactivation->regOpen)->toBeFalse();
});

test('hasUsers returns each show with at least one registration, once', function () {
    $withUsers = CarShow::factory()->create();
    CarShow::factory()->create();
    carShowInsertRegistration($withUsers->id, 'a');
    carShowInsertRegistration($withUsers->id, 'b');

    $ids = CarShow::query()->hasUsers()->pluck('id')->all();

    expect($ids)->toBe([$withUsers->id]);
});

test('calculateNumCars counts cars per show', function () {
    $show = CarShow::factory()->create();
    CarShow::factory()->create();
    carShowInsertRegistration($show->id, 'cars');

    $row = CarShow::query()->calculateNumCars()->findOrFail($show->id);

    expect((int) $row->getAttribute('num_cars'))->toBe(1)
        ->and($row->id)->toBe($show->id);
});

test('visibleTo shows everything to administrators', function () {
    CarShow::factory()->count(2)->create();

    expect(CarShow::query()->visibleTo(1, UserType::Administrator)->count())->toBe(2);
});

test('visibleTo limits non-admins to shows where they are a judge', function () {
    $judged = CarShow::factory()->create();
    CarShow::factory()->create();
    $userId = (int) DB::table('user')->insertGetId([
        'date_registered' => '2024-01-01 00:00:00', 'email' => 'judge@example.test',
        'location_id' => (int) DB::table('location')->insertGetId([
            'address1' => '1 Main', 'city' => 'Raleigh', 'state' => 'NC', 'zip' => '27601',
        ]),
        'password' => 'x', 'address1' => '1 Main', 'city' => 'Raleigh', 'state' => 'NC', 'zip' => '27601',
    ]);
    DB::table('car_show_judge')->insert(['user_id' => $userId, 'show_id' => $judged->id]);

    $ids = CarShow::query()->visibleTo($userId, UserType::Judge)->pluck('id')->all();

    expect($ids)->toBe([$judged->id]);
});

test('locations and primaryLocation relate to CarShowLocation in both directions', function () {
    $show = CarShow::factory()->create();
    $primary = CarShowLocation::factory()->primary()->create(['show_id' => $show->id]);
    $secondary = CarShowLocation::factory()->create(['show_id' => $show->id]);

    expect($show->locations()->pluck('show_location.id')->all())->toEqualCanonicalizing([$primary->id, $secondary->id])
        ->and($show->primaryLocation->id)->toBe($primary->id)
        ->and($primary->carShow->id)->toBe($show->id)
        ->and($secondary->carShow->id)->toBe($show->id);
});

test('showExtras relates to ShowExtra in both directions', function () {
    $show = CarShow::factory()->create();
    $extra = ShowExtra::factory()->create(['show_id' => $show->id]);

    expect($show->showExtras->pluck('id')->all())->toBe([$extra->id])
        ->and($extra->carShow->id)->toBe($show->id);
});

test('sponsors and primary_sponsors filter the show_sponsor pivot by type', function () {
    $show = CarShow::factory()->create();
    $m = Sponsor::factory()->create();
    $gold = Sponsor::factory()->create();
    ShowSponsor::factory()->ofType(SponsorType::M)->create(['show_id' => $show->id, 'sponsor_id' => $m->id]);
    ShowSponsor::factory()->ofType(SponsorType::Gold)->create(['show_id' => $show->id, 'sponsor_id' => $gold->id]);

    expect($show->sponsors()->count())->toBe(2)
        ->and($show->primary_sponsors->pluck('id')->all())->toBe([$m->id])
        ->and($m->carShow->pluck('id')->all())->toBe([$show->id]);
});

test('primary_sponsor belongsTo Sponsor through primary_sponsor_id, and is null when unset', function () {
    $sponsor = Sponsor::factory()->create();
    $withSponsor = CarShow::factory()->create(['primary_sponsor_id' => $sponsor->id]);
    $without = CarShow::factory()->create(['primary_sponsor_id' => null]);

    expect($withSponsor->primary_sponsor->id)->toBe($sponsor->id)
        ->and($without->primary_sponsor)->toBeNull();
});

test('factory states set the documented fields', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-24 12:00:00'));

    expect(CarShow::factory()->past()->create()->ended)->toBeTrue()
        ->and(CarShow::factory()->past()->create()->regOpen)->toBeFalse()
        ->and(CarShow::factory()->registrationOpen()->create()->regOpen)->toBeTrue()
        ->and(CarShow::factory()->registrationClosed()->create()->regOpen)->toBeFalse()
        ->and(CarShow::factory()->withSchedulePdf()->create()->is_schedule_pdf)->toBeTrue()
        ->and(CarShow::factory()->judgingReleased()->create()->is_judging_released)->toBeTrue()
        ->and(CarShow::factory()->active()->create()->is_active)->toBeTrue();
});
