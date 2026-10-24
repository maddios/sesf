<?php

use App\Enums\UserGuestType;
use App\Models\CarShow;
use App\Models\ShowRegistration;
use App\Models\User;
use App\Models\UserGuest;
use Illuminate\Support\Facades\DB;

test('uses the user_guest table with timestamps and guards only id', function () {
    $guest = new UserGuest;

    expect($guest->getTable())->toBe('user_guest')
        ->and($guest->getKeyName())->toBe('id')
        ->and($guest->usesTimestamps())->toBeTrue()
        ->and($guest->getGuarded())->toBe(['id']);
});

test('type_id casts to UserGuestType and has_signed to boolean', function () {
    $guest = UserGuest::factory()->child()->create(['has_signed' => 1])->refresh();

    expect($guest->type_id)->toBe(UserGuestType::Child)
        ->and($guest->has_signed)->toBeTrue();
});

test('signature_image is hidden from toArray() and toJson()', function () {
    $guest = UserGuest::factory()->signed()->create()->refresh();

    expect($guest->signature_image)->toBe('signature-bytes')
        ->and($guest->toArray())->not->toHaveKey('signature_image')
        ->and(json_decode($guest->toJson(), true))->not->toHaveKey('signature_image');
});

test('fullName is first and last, appended in toArray() and toJson()', function () {
    $guest = UserGuest::factory()->create(['first' => 'Ada', 'last' => 'Lovelace'])->refresh();

    expect($guest->fullName)->toBe('Ada Lovelace')
        ->and($guest->toArray())->toHaveKey('fullName')
        ->and(json_decode($guest->toJson(), true)['fullName'])->toBe('Ada Lovelace');
});

test('zero-date updated_at in a raw row reads as null', function () {
    $registration = ShowRegistration::factory()->create();
    $id = DB::table('user_guest')->insertGetId([
        'user_id' => User::factory()->create()->id,
        'first' => 'Raw',
        'last' => 'Guest',
        'show_id' => $registration->show_id,
        'type_id' => UserGuestType::Adult->value,
        'registration_id' => $registration->id,
        'created_at' => '2024-01-01 00:00:00',
        'updated_at' => '0000-00-00 00:00:00',
    ]);

    expect(UserGuest::query()->findOrFail($id)->updated_at)->toBeNull();
});

test('relations: user, registration and carShow', function () {
    $user = User::factory()->create();
    $registration = ShowRegistration::factory()->create();
    $guest = UserGuest::factory()->forRegistration($registration)->create(['user_id' => $user->id]);

    expect($guest->user->id)->toBe($user->id)
        ->and($guest->registration->id)->toBe($registration->id)
        ->and($guest->carShow->id)->toBe($registration->show_id)
        ->and($registration->guests->pluck('id')->all())->toBe([$guest->id]);
});

test('forRegistration keeps the guest on the registration show', function () {
    $show = CarShow::factory()->create();
    $registration = ShowRegistration::factory()->create(['show_id' => $show->id]);

    $guest = UserGuest::factory()->forRegistration($registration)->create();

    expect($guest->show_id)->toBe($show->id);
});

test('the default factory state puts the guest on the show of its registration', function () {
    $guest = UserGuest::factory()->create()->refresh();

    expect($guest->registration->show_id)->toBe($guest->show_id);
});
