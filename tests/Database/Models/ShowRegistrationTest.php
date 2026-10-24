<?php

use App\Enums\JudgingType;
use App\Enums\PaymentMethod;
use App\Models\Car;
use App\Models\CarShow;
use App\Models\ShowExtra;
use App\Models\ShowExtraRegistration;
use App\Models\ShowRegistration;
use App\Models\ShowRegistrationCar;
use App\Models\User;
use App\Models\UserGuest;
use App\Services\Registration\InvoiceNumberAllocator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

afterEach(fn () => Carbon::setTestNow());

/**
 * Inserts a show_registration row with raw SQL so zero dates and explicit invoice ids can be stored.
 *
 * @param  array<string, mixed>  $overrides
 */
function registrationRawRow(array $overrides = []): int
{
    $show = CarShow::factory()->create();
    $user = User::factory()->create();

    return (int) DB::table('show_registration')->insertGetId(array_merge([
        'show_id' => $show->id,
        'user_id' => $user->id,
        'invoice_id' => 1,
        'created_at' => '2024-01-01 00:00:00',
        'updated_at' => '0000-00-00 00:00:00',
        'date_checked_in' => '0000-00-00 00:00:00',
        'arrival_date' => '0000-00-00',
    ], $overrides));
}

test('uses the show_registration table, id key, timestamps and guards id, invoice, money and state', function () {
    $registration = new ShowRegistration;

    expect($registration->getTable())->toBe('show_registration')
        ->and($registration->getKeyName())->toBe('id')
        ->and($registration->usesTimestamps())->toBeTrue()
        ->and($registration->getGuarded())->toBe([
            'id', 'invoice_id', 'is_paid', 'amount_owed', 'amount_received', 'amount_received_actual',
            'discount', 'is_cancelled', 'is_checked_in', 'date_checked_in',
        ]);
});

test('mass assignment ignores the guarded invoice, money and state columns', function () {
    $registration = new ShowRegistration([
        'show_id' => 5,
        'user_id' => 6,
        'donation' => 3,
        'invoice_id' => 99,
        'is_paid' => true,
        'amount_owed' => 10,
        'amount_received' => 10,
        'amount_received_actual' => 10,
        'discount' => 5,
        'is_cancelled' => true,
        'is_checked_in' => true,
        'date_checked_in' => '2026-01-01 00:00:00',
    ]);

    expect($registration->show_id)->toBe(5);
    expect($registration->user_id)->toBe(6);
    expect($registration->donation)->toBe(3);
    expect(array_keys($registration->getAttributes()))->toEqualCanonicalizing(['show_id', 'user_id', 'donation']);
});

test('an unknown legacy payment_method_id reads as null and the raw value is kept on save', function () {
    $id = registrationRawRow(['payment_method_id' => 9]);
    $registration = ShowRegistration::query()->findOrFail($id);

    expect($registration->payment_method_id)->toBeNull();
    expect(json_decode($registration->toJson(), true)['payment_method_id'])->toBeNull();

    $registration->discount_note = 'legacy';
    $registration->save();

    expect((int) DB::table('show_registration')->where('id', $id)->value('payment_method_id'))->toBe(9);
    expect((int) $registration->getRawOriginal('payment_method_id'))->toBe(9);
});

test('payment_method_id accepts the enum, its int and numeric strings, and rejects unknown values', function () {
    $registration = new ShowRegistration;

    $registration->payment_method_id = PaymentMethod::Check;
    expect($registration->getAttributes()['payment_method_id'])->toBe(2);
    expect($registration->payment_method_id)->toBe(PaymentMethod::Check);

    $registration->setAttribute('payment_method_id', 1);
    expect($registration->payment_method_id)->toBe(PaymentMethod::PayPal);

    $registration->setAttribute('payment_method_id', '2');
    expect($registration->payment_method_id)->toBe(PaymentMethod::Check);

    expect(fn () => $registration->setAttribute('payment_method_id', 9))->toThrow(InvalidArgumentException::class, 'Unknown payment method [9]');
    expect(fn () => $registration->setAttribute('payment_method_id', null))->toThrow(InvalidArgumentException::class);
    expect($registration->payment_method_id)->toBe(PaymentMethod::Check);
});

test('money columns use Money2 and round half up on decimal strings', function () {
    $registration = ShowRegistration::factory()->create([
        'amount_owed' => '12.345',
        'amount_received' => 0,
        'amount_received_actual' => null,
        'discount' => '5',
    ])->refresh();

    expect($registration->amount_owed)->toBe('12.35')
        ->and($registration->amount_received)->toBe('0.00')
        ->and($registration->amount_received_actual)->toBeNull()
        ->and($registration->discount)->toBe('5.00');
});

test('flags are booleans, donation is an int, and payment method is the enum', function () {
    $registration = ShowRegistration::factory()->paid()->checkedIn()->create([
        'donation' => '10',
        'payment_method_id' => PaymentMethod::Check->value,
    ])->refresh();

    expect($registration->is_paid)->toBeTrue()
        ->and($registration->is_checked_in)->toBeTrue()
        ->and($registration->is_cancelled)->toBeFalse()
        ->and($registration->is_judge)->toBeFalse()
        ->and($registration->has_signed)->toBeFalse()
        ->and($registration->donation)->toBe(10)
        ->and($registration->payment_method_id)->toBe(PaymentMethod::Check);
});

test('arrival_date is a date and arrival_time is free text', function () {
    $registration = ShowRegistration::factory()->withArrival()->create()->refresh();

    expect($registration->arrival_date)->toBeInstanceOf(Carbon::class)
        ->and($registration->arrival_date->toDateString())->toBe(now()->addDay()->toDateString())
        ->and($registration->arrival_time)->toBe('9:00 AM');
});

test('zero dates read as null for date_checked_in, arrival_date and updated_at', function () {
    $id = registrationRawRow(['arrival_time' => 'Sat 8am-10am']);

    $registration = ShowRegistration::query()->findOrFail($id);

    expect($registration->date_checked_in)->toBeNull()
        ->and($registration->arrival_date)->toBeNull()
        ->and($registration->updated_at)->toBeNull()
        ->and($registration->created_at)->toBeInstanceOf(Carbon::class)
        ->and($registration->arrival_time)->toBe('Sat 8am-10am');
});

test('saving a model with zero-date values keeps them in the raw row', function () {
    $id = registrationRawRow();
    $registration = ShowRegistration::query()->findOrFail($id);

    $registration->discount_note = 'family';
    $registration->save();

    $raw = DB::table('show_registration')->where('id', $id)->first();

    expect($raw->discount_note)->toBe('family')
        ->and($raw->date_checked_in)->toBe('0000-00-00 00:00:00');
});

test('a registration without invoice_id gets the next invoice number for its show', function () {
    $first = ShowRegistration::factory()->create();
    $second = ShowRegistration::factory()->create(['show_id' => $first->show_id]);

    expect($first->invoice_id)->toBe(1)
        ->and($second->invoice_id)->toBe(2);
});

test('an explicit invoice_id is kept and the allocator is not called', function () {
    $allocator = Mockery::mock(InvoiceNumberAllocator::class);
    $allocator->shouldNotReceive('allocate');
    app()->instance(InvoiceNumberAllocator::class, $allocator);

    $registration = ShowRegistration::factory()->create(['invoice_id' => 77]);

    expect($registration->invoice_id)->toBe(77);
});

test('unique key (show_id, user_id) rejects a second registration for the same user and show', function () {
    $registration = ShowRegistration::factory()->create();

    expect(fn () => ShowRegistration::factory()->create([
        'show_id' => $registration->show_id,
        'user_id' => $registration->user_id,
    ]))->toThrow(QueryException::class);
});

test('unique key (invoice_id, show_id) rejects a duplicate invoice number for the same show', function () {
    $registration = ShowRegistration::factory()->create();

    expect(fn () => ShowRegistration::factory()->create([
        'show_id' => $registration->show_id,
        'invoice_id' => $registration->invoice_id,
    ]))->toThrow(QueryException::class);
});

test('the same invoice number is allowed for a different show', function () {
    $registration = ShowRegistration::factory()->create(['invoice_id' => 5]);

    $other = ShowRegistration::factory()->create(['invoice_id' => 5]);

    expect($other->invoice_id)->toBe($registration->invoice_id)
        ->and($other->show_id)->not->toBe($registration->show_id);
});

test('showName, shortDate and profileURL come from the show, not from missing columns', function () {
    $show = CarShow::factory()->create([
        'name' => 'Spring Fling 2026',
        'date_start' => '2026-05-01',
    ]);
    $registration = ShowRegistration::factory()->create(['show_id' => $show->id]);

    expect($registration->showName)->toBe('Spring Fling 2026')
        ->and($registration->shortDate)->toBe('May 01 2026')
        ->and($registration->profileURL)->toBe('#/SpringFling2026/e'.$show->id);
});

test('appended JSON keys are present in toArray() and toJson()', function () {
    $show = CarShow::factory()->create(['name' => 'Spring Fling', 'date_start' => '2026-05-01']);
    $registration = ShowRegistration::factory()->create(['show_id' => $show->id])->refresh();

    $array = $registration->toArray();
    $json = json_decode($registration->toJson(), true);

    foreach (['profileURL', 'shortDate', 'showName'] as $key) {
        expect($array)->toHaveKey($key)
            ->and($json)->toHaveKey($key);
    }

    expect($json['showName'])->toBe('Spring Fling');
});

test('user relation works in both directions', function () {
    $user = User::factory()->create();
    $registration = ShowRegistration::factory()->create(['user_id' => $user->id]);

    expect($registration->user->id)->toBe($user->id)
        ->and($user->registrations->pluck('id')->all())->toBe([$registration->id]);
});

test('carShow relation works in both directions', function () {
    $show = CarShow::factory()->create();
    $registration = ShowRegistration::factory()->create(['show_id' => $show->id]);

    expect($registration->carShow->id)->toBe($show->id)
        ->and($show->registrations->pluck('id')->all())->toBe([$registration->id]);
});

test('cars relation carries judging_type_id on the pivot in both directions', function () {
    $registration = ShowRegistration::factory()->create();
    $car = Car::factory()->create();
    ShowRegistrationCar::factory()->judged(JudgingType::Concours)->create([
        'car_id' => $car->id,
        'registration_id' => $registration->id,
    ]);

    $loaded = $registration->cars->first();

    expect($loaded->id)->toBe($car->id)
        ->and((int) $loaded->getRelation('pivot')->judging_type_id)->toBe(JudgingType::Concours->value)
        ->and($car->registrations->pluck('id')->all())->toBe([$registration->id]);
});

test('registrationCars, guests and extras relations work in both directions', function () {
    $registration = ShowRegistration::factory()->create();
    $registrationCar = ShowRegistrationCar::factory()->create(['registration_id' => $registration->id]);
    $guest = UserGuest::factory()->forRegistration($registration)->create();
    $extra = ShowExtraRegistration::factory()->create([
        'registration_id' => $registration->id,
        'show_extra_id' => ShowExtra::factory()->create(['show_id' => $registration->show_id])->id,
    ]);

    expect($registration->registrationCars->pluck('id')->all())->toBe([$registrationCar->id])
        ->and($registration->guests->pluck('id')->all())->toBe([$guest->id])
        ->and($registration->extras->pluck('id')->all())->toBe([$extra->id])
        ->and($registrationCar->registration->id)->toBe($registration->id)
        ->and($guest->registration->id)->toBe($registration->id)
        ->and($extra->registration->id)->toBe($registration->id);
});

test('ShowExtra::registration returns the registration with num_extras on the pivot', function () {
    $show = CarShow::factory()->create();
    $registration = ShowRegistration::factory()->create(['show_id' => $show->id]);
    $showExtra = ShowExtra::factory()->create(['show_id' => $show->id]);
    ShowExtraRegistration::factory()->quantity(3)->create([
        'show_extra_id' => $showExtra->id,
        'registration_id' => $registration->id,
    ]);

    $loaded = $showExtra->registration->first();

    expect($loaded->id)->toBe($registration->id)
        ->and((int) $loaded->getRelation('pivot')->num_extras)->toBe(3);
});
