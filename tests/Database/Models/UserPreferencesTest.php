<?php

use App\Models\User;
use App\Models\UserPreferences;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    DB::connection('testing_mariadb')->table('user_type')->insertOrIgnore([
        ['id' => 1, 'name' => 'Regular'],
        ['id' => 2, 'name' => 'Judge'],
        ['id' => 3, 'name' => 'Administrator'],
    ]);
});

test('maps to the legacy user_preferences table with timestamps', function () {
    $preferences = new UserPreferences;

    expect($preferences->getTable())->toBe('user_preferences')
        ->and($preferences->getKeyName())->toBe('id')
        ->and($preferences->usesTimestamps())->toBeTrue()
        ->and($preferences->getGuarded())->toBe(['id']);
});

test('boolean flags are cast to booleans', function () {
    $user = User::factory()->create();
    $preferences = UserPreferences::query()->where('user_id', $user->id)->firstOrFail();

    expect($preferences->show_car_on_attendees_page)->toBeTrue()
        ->and($preferences->allow_future_show_notifications)->toBeTrue()
        ->and($preferences->allow_system_email)->toBeTrue()
        ->and($preferences->show_login_welcome)->toBeTrue()
        ->and($preferences->allow_private_message)->toBeTrue();

    $preferences->update(['allow_system_email' => false, 'show_login_welcome' => 0]);

    $fresh = $preferences->fresh();
    expect($fresh->allow_system_email)->toBeFalse()
        ->and($fresh->show_login_welcome)->toBeFalse();
});

test('the optedOut state stores false in every flag', function () {
    $user = User::factory()->withoutPreferences()->create();
    $preferences = UserPreferences::factory()->for($user)->optedOut()->create();

    expect($preferences->fresh()->allow_private_message)->toBeFalse()
        ->and($preferences->fresh()->show_car_on_attendees_page)->toBeFalse();
});

test('the user relation returns the owning user and the preferences relation returns the row', function () {
    $user = User::factory()->create();
    $preferences = UserPreferences::query()->where('user_id', $user->id)->firstOrFail();

    expect($preferences->user)->toBeInstanceOf(User::class)
        ->and($preferences->user->is($user))->toBeTrue()
        ->and($user->preferences->is($preferences))->toBeTrue();
});

test('only one preferences row may exist per user', function () {
    $user = User::factory()->create();

    expect(fn () => UserPreferences::factory()->for($user)->create())->toThrow(QueryException::class);
});
