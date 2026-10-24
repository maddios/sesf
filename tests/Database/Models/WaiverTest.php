<?php

use App\Models\CarShow;
use App\Models\ShowRegistration;
use App\Models\UserGuest;
use App\Models\Waiver;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('uses the waiver table with timestamps and guards only id', function () {
    $waiver = new Waiver;

    expect($waiver->getTable())->toBe('waiver')
        ->and($waiver->usesTimestamps())->toBeTrue()
        ->and($waiver->getGuarded())->toBe(['id']);
});

test('has_signed and has_pdf are boolean, waiver_data is an array, signed_at is a datetime', function () {
    $waiver = Waiver::factory()->signed()->create(['has_pdf' => 1])->refresh();

    expect($waiver->has_signed)->toBeTrue()
        ->and($waiver->has_pdf)->toBeTrue()
        ->and($waiver->waiver_data)->toBe(['signature' => 'c2lnbmF0dXJl', 'geo' => ['lat' => 1.5, 'lng' => 2.5]])
        ->and($waiver->signed_at)->toBeInstanceOf(CarbonInterface::class);
});

test('waiver_data is hidden from toArray() and toJson()', function () {
    $waiver = Waiver::factory()->signed()->create()->refresh();

    expect($waiver->toArray())->not->toHaveKey('waiver_data')
        ->and(json_decode($waiver->toJson(), true))->not->toHaveKey('waiver_data');
});

test('zero signed_at in a raw row reads as null', function () {
    $registration = ShowRegistration::factory()->create();
    $id = DB::table('waiver')->insertGetId([
        'tracking_id' => bin2hex(random_bytes(16)),
        'show_registration_id' => $registration->id,
        'signed_at' => '0000-00-00 00:00:00',
        'waiver_data' => null,
        'created_at' => '2024-01-01 00:00:00',
        'updated_at' => '0000-00-00 00:00:00',
    ]);

    expect(Waiver::query()->findOrFail($id)->signed_at)->toBeNull();
});

test('tracking_id is unique', function () {
    $tracking = bin2hex(random_bytes(16));
    Waiver::factory()->create(['tracking_id' => $tracking]);

    expect(fn () => Waiver::factory()->create(['tracking_id' => $tracking]))
        ->toThrow(QueryException::class);
});

test('a pending waiver has no waiver_id and is not signed', function () {
    $waiver = Waiver::factory()->create()->refresh();

    expect($waiver->waiver_id)->toBeNull()
        ->and($waiver->isPending())->toBeTrue()
        ->and($waiver->isSigned())->toBeFalse();
});

test('a signed waiver is not pending', function () {
    $waiver = Waiver::factory()->signed()->create()->refresh();

    expect($waiver->isPending())->toBeFalse()
        ->and($waiver->isSigned())->toBeTrue();
});

test('isAdhoc is true only when adhoc_show_id is set', function () {
    $adhoc = Waiver::factory()->adhoc(CarShow::factory()->create())->create()->refresh();

    expect($adhoc->isAdhoc())->toBeTrue()
        ->and(Waiver::factory()->create()->refresh()->isAdhoc())->toBeFalse();
});

test('signUrl is the public sign URL built from config app.url', function () {
    config(['app.url' => 'https://sesf.example/']);
    $waiver = Waiver::factory()->create(['tracking_id' => str_repeat('a', 32)])->refresh();

    expect($waiver->signUrl())->toBe('https://sesf.example/waiver_sign/t/'.str_repeat('a', 32));
});

test('scopes pending, signed and forShow', function () {
    $show = CarShow::factory()->create();
    $registration = ShowRegistration::factory()->create(['show_id' => $show->id]);
    $pending = Waiver::factory()->create(['show_registration_id' => $registration->id]);
    $signed = Waiver::factory()->signed()->create(['show_registration_id' => $registration->id]);
    $adhoc = Waiver::factory()->adhoc($show)->create();
    Waiver::factory()->create();

    expect(Waiver::query()->pending()->where('show_registration_id', $registration->id)->pluck('id')->all())
        ->toBe([$pending->id])
        ->and(Waiver::query()->signed()->pluck('id')->all())->toContain($signed->id)
        ->and(Waiver::query()->forShow($show->id)->pluck('id')->sort()->values()->all())
        ->toBe([$pending->id, $signed->id, $adhoc->id]);
});

test('relations: registration, guest and carShow, and the registration inverse', function () {
    $registration = ShowRegistration::factory()->create();
    $waiver = Waiver::factory()->create(['show_registration_id' => $registration->id]);

    expect($waiver->registration->getKey())->toBe($registration->id)
        ->and($waiver->guest)->toBeNull()
        ->and($registration->waivers->pluck('id')->all())->toBe([$waiver->id]);
});

test('guest waiver and the circular user_guest pair work both ways', function () {
    $guest = UserGuest::factory()->create();
    $waiver = Waiver::factory()->forGuest($guest)->create();
    $guest->update(['waiver_id' => $waiver->id]);
    $guest->refresh();

    expect($waiver->guest->getKey())->toBe($guest->id)
        ->and($guest->waiver->getKey())->toBe($waiver->id)
        ->and($waiver->registration)->toBeNull();
});

test('adhoc carShow relation', function () {
    $show = CarShow::factory()->create();
    $waiver = Waiver::factory()->adhoc($show)->create();

    expect($waiver->carShow->getKey())->toBe($show->id);
});

test('a waiver for a missing registration violates the foreign key', function () {
    expect(fn () => Waiver::factory()->create(['show_registration_id' => 999999999]))
        ->toThrow(QueryException::class);
});
