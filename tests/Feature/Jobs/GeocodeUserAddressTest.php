<?php

use App\Jobs\GeocodeUserAddress;
use App\Services\Geocoder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/*
 * Runs on the default in-memory SQLite connection. There are no migrations and no RefreshDatabase.
 * Only the columns the job touches are created here; the full legacy schema is covered by tests/Database.
 */
beforeEach(function () {
    config(['sesf.google.geocode_key' => 'test-key']);

    if (! Schema::hasTable('user')) {
        Schema::create('user', function ($table) {
            $table->increments('id');
            $table->string('first')->nullable();
            $table->string('last')->nullable();
            $table->string('email')->unique();
            $table->string('address1')->default('');
            $table->string('address2')->nullable();
            $table->string('city')->default('');
            $table->string('state')->default('');
            $table->string('zip')->default('');
            $table->double('lattitude')->nullable();
            $table->double('longitude')->nullable();
            $table->string('password')->default('');
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });
    }
});

/**
 * @param  array<string, mixed>  $overrides
 */
function insertGeocodeUser(array $overrides = []): int
{
    return (int) DB::table('user')->insertGetId(array_merge([
        'first' => 'Ann',
        'last' => 'Lee',
        'email' => fake()->unique()->safeEmail(),
        'address1' => '1 Main St',
        'city' => 'Orlando',
        'state' => 'FL',
        'zip' => '32803',
        'lattitude' => null,
        'longitude' => null,
        'password' => 'x',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

function runGeocodeJob(int $userId): void
{
    (new GeocodeUserAddress($userId))->handle(app(Geocoder::class));
}

test('stores the coordinates found for the current address', function () {
    Http::fake(['maps.googleapis.com/*' => Http::response([
        'status' => 'OK',
        'results' => [['geometry' => ['location' => ['lat' => 28.5, 'lng' => -81.3]]]],
    ])]);

    $id = insertGeocodeUser();
    runGeocodeJob($id);

    $row = DB::table('user')->where('id', $id)->first();
    expect($row->lattitude)->toBe(28.5)
        ->and($row->longitude)->toBe(-81.3);

    Http::assertSent(fn ($request) => str_contains($request->data()['address'], '1 Main St'));
});

test('keeps existing coordinates when the lookup times out', function () {
    Http::fake(function () {
        throw new ConnectionException('timed out');
    });

    $id = insertGeocodeUser(['lattitude' => 10.0, 'longitude' => 20.0]);

    expect(fn () => runGeocodeJob($id))->not->toThrow(Throwable::class);

    $row = DB::table('user')->where('id', $id)->first();
    expect($row->lattitude)->toBe(10.0)
        ->and($row->longitude)->toBe(20.0);
});

test('keeps existing coordinates when Google finds no result', function () {
    Http::fake(['maps.googleapis.com/*' => Http::response(['status' => 'ZERO_RESULTS', 'results' => []])]);

    $id = insertGeocodeUser(['lattitude' => 10.0, 'longitude' => 20.0]);
    runGeocodeJob($id);

    expect(DB::table('user')->where('id', $id)->value('lattitude'))->toBe(10.0);
});

test('does nothing for a missing or soft-deleted user', function () {
    Http::fake();

    runGeocodeJob(999999);

    $id = insertGeocodeUser(['deleted_at' => now()]);
    runGeocodeJob($id);

    Http::assertNothingSent();
});

test('the job runs after commit and retries', function () {
    $job = new GeocodeUserAddress(1);

    expect($job->afterCommit)->toBeTrue()
        ->and($job->tries)->toBe(3)
        ->and($job)->toBeInstanceOf(ShouldQueue::class);
});
