<?php

use App\Models\LoginEmailSignin;
use App\Models\User;
use Illuminate\Support\Carbon;

test('maps to login_email_signin without timestamps and with the daily quota', function () {
    $row = new LoginEmailSignin;

    expect($row->getTable())->toBe('login_email_signin')
        ->and($row->usesTimestamps())->toBeFalse()
        ->and($row->getGuarded())->toBe(['id'])
        ->and(LoginEmailSignin::DAILY_QUOTA)->toBe(100);
});

test('datetime columns are cast and the user relation works both ways', function () {
    $user = User::factory()->create();
    $row = LoginEmailSignin::factory()->for($user)->create();
    $fresh = LoginEmailSignin::query()->findOrFail($row->id);

    expect($fresh->expires_at)->toBeInstanceOf(Carbon::class)
        ->and($fresh->used_at)->toBeNull()
        ->and($fresh->created_at)->toBeInstanceOf(Carbon::class)
        ->and($fresh->user->is($user))->toBeTrue()
        ->and($user->loginEmailSignins->first()->is($row))->toBeTrue();
});

test('the unused scope excludes used rows', function () {
    $user = User::factory()->create();
    $unused = LoginEmailSignin::factory()->for($user)->create();
    LoginEmailSignin::factory()->for($user)->used()->create();

    expect(LoginEmailSignin::query()->unused()->pluck('id')->all())->toBe([$unused->id]);
});

test('the unexpired scope excludes rows past their expiry', function () {
    $user = User::factory()->create();
    $live = LoginEmailSignin::factory()->for($user)->create();
    LoginEmailSignin::factory()->for($user)->expired()->create();

    expect(LoginEmailSignin::query()->unexpired()->pluck('id')->all())->toBe([$live->id]);
});

test('the createdToday scope excludes yesterday', function () {
    $user = User::factory()->create();
    $today = LoginEmailSignin::factory()->for($user)->create();
    LoginEmailSignin::factory()->for($user)->sentYesterday()->create();

    expect(LoginEmailSignin::query()->createdToday()->pluck('id')->all())->toBe([$today->id]);
});

test('sentToday counts only rows created since the start of today', function () {
    $user = User::factory()->create();
    LoginEmailSignin::factory()->count(3)->for($user)->create();
    LoginEmailSignin::factory()->count(2)->for($user)->sentYesterday()->create();

    expect(LoginEmailSignin::sentToday())->toBe(3);
});

test('isAvailable is true below the quota and false at the quota', function () {
    $user = User::factory()->create();
    LoginEmailSignin::factory()->count(99)->for($user)->create();

    expect(LoginEmailSignin::isAvailable())->toBeTrue();

    LoginEmailSignin::factory()->for($user)->create();

    expect(LoginEmailSignin::sentToday())->toBe(100)
        ->and(LoginEmailSignin::isAvailable())->toBeFalse();
});

test('yesterday\'s sends do not count toward today\'s quota', function () {
    $user = User::factory()->create();
    LoginEmailSignin::factory()->count(LoginEmailSignin::DAILY_QUOTA)->for($user)->sentYesterday()->create();

    expect(LoginEmailSignin::isAvailable())->toBeTrue();
});

test('used and expired rows still count toward the quota', function () {
    $user = User::factory()->create();
    LoginEmailSignin::factory()->count(LoginEmailSignin::DAILY_QUOTA - 2)->for($user)->used()->create();
    LoginEmailSignin::factory()->for($user)->expired()->create();

    expect(LoginEmailSignin::isAvailable())->toBeTrue();

    LoginEmailSignin::factory()->for($user)->create();

    expect(LoginEmailSignin::isAvailable())->toBeFalse();
});
