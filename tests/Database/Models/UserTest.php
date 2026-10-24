<?php

use App\Enums\UserType;
use App\Jobs\GeocodeUserAddress;
use App\Models\LoginEmailSignin;
use App\Models\User;
use App\Models\UserPreferences;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    // Schema dump has no rows; user.type_id has a foreign key to user_type.
    DB::connection('testing_mariadb')->table('user_type')->insertOrIgnore([
        ['id' => 1, 'name' => 'Regular'],
        ['id' => 2, 'name' => 'Judge'],
        ['id' => 3, 'name' => 'Administrator'],
    ]);
});

test('maps to the legacy user table with timestamps and guarded id', function () {
    $user = new User;

    expect($user->getTable())->toBe('user')
        ->and($user->getKeyName())->toBe('id')
        ->and($user->usesTimestamps())->toBeTrue()
        ->and($user->getGuarded())->toBe(['id'])
        ->and($user->getFillable())->toBe([]);
});

test('casts type_id to UserType and flags to booleans', function () {
    $user = User::factory()->admin()->create();
    $fresh = User::query()->findOrFail($user->id);

    expect($fresh->type_id)->toBe(UserType::Administrator)
        ->and($fresh->is_admin)->toBeTrue()
        ->and($fresh->has_image)->toBeFalse()
        ->and($fresh->location_id)->toBeInt();
});

test('reads legacy string type ids through the enum', function () {
    $user = User::factory()->judge()->create();

    DB::connection('testing_mariadb')->table('user')->where('id', $user->id)->update(['type_id' => '2']);

    expect(User::query()->findOrFail($user->id)->type_id)->toBe(UserType::Judge);
});

test('password and remember_token are hidden from arrays and JSON', function () {
    $user = User::factory()->create();

    expect($user->toArray())->not->toHaveKey('password');
    expect($user->toArray())->not->toHaveKey('remember_token');
    expect($user->toJson())->not->toContain('password');
    expect($user->toJson())->not->toContain('remember_token');
});

test('soft delete sets deleted_at and hides the row from normal queries', function () {
    $user = User::factory()->create();
    $user->delete();

    expect(User::query()->find($user->id))->toBeNull();
    expect(User::withTrashed()->find($user->id))->not->toBeNull();
    expect(User::withTrashed()->find($user->id)?->deleted_at)->toBeInstanceOf(Carbon::class);
    expect(User::onlyTrashed()->count())->toBe(1);
});

test('the deleted factory state creates a trashed user', function () {
    $user = User::factory()->deleted()->create();

    expect(User::query()->find($user->id))->toBeNull()
        ->and(User::withTrashed()->find($user->id))->not->toBeNull();
});

test('creating a user also creates its preferences row with defaults', function () {
    $user = User::factory()->create();

    $preferences = UserPreferences::query()->where('user_id', $user->id)->first();

    expect($preferences)->not->toBeNull()
        ->and($preferences->allow_private_message)->toBeTrue()
        ->and($user->preferences->is($preferences))->toBeTrue();
});

test('the withoutPreferences state leaves the user without a preferences row', function () {
    $user = User::factory()->withoutPreferences()->create();

    expect(UserPreferences::query()->where('user_id', $user->id)->exists())->toBeFalse()
        ->and($user->fresh()->preferences)->toBeNull();
});

test('date_registered defaults to now and location_id defaults to 0 on create', function () {
    $user = new User([
        'first' => 'Ann',
        'last' => 'Lee',
        'email' => 'ann.lee@example.test',
        'password' => Hash::make('secret123'),
        'type_id' => UserType::Regular,
        'address1' => '',
        'city' => '',
        'state' => '',
        'zip' => '',
    ]);

    $before = now()->subSecond();
    $user->save();

    expect($user->fresh()->date_registered)->toBeInstanceOf(Carbon::class)
        ->and($user->fresh()->date_registered->greaterThanOrEqualTo($before))->toBeTrue()
        ->and($user->fresh()->location_id)->toBe(0);
});

test('an explicit date_registered and location_id are kept on create', function () {
    $user = User::factory()->create([
        'date_registered' => '2019-03-04 05:06:07',
        'location_id' => 42,
    ]);

    expect($user->fresh()->date_registered->format('Y-m-d H:i:s'))->toBe('2019-03-04 05:06:07')
        ->and($user->fresh()->location_id)->toBe(42);
});

test('setPasswordWithConfirmation stores a bcrypt hash when the confirmed password is valid', function () {
    $user = User::factory()->create();
    $oldHash = $user->password;

    $changed = $user->setPasswordWithConfirmation('new-secret', 'new-secret');
    $user->save();

    $stored = DB::connection('testing_mariadb')->table('user')->where('id', $user->id)->value('password');

    expect($changed)->toBeTrue()
        ->and($stored)->not->toBe($oldHash)
        ->and(str_starts_with($stored, '$2y$'))->toBeTrue()
        ->and(Hash::check('new-secret', $stored))->toBeTrue();
});

test('setPasswordWithConfirmation rejects mismatched passwords and leaves the hash alone', function () {
    $user = User::factory()->create();
    $oldHash = $user->password;

    expect($user->setPasswordWithConfirmation('new-secret', 'other-secret'))->toBeFalse()
        ->and($user->password)->toBe($oldHash);
});

test('setPasswordWithConfirmation rejects passwords shorter than 6 characters', function () {
    $user = User::factory()->create();
    $oldHash = $user->password;

    expect($user->setPasswordWithConfirmation('abc12', 'abc12'))->toBeFalse()
        ->and($user->password)->toBe($oldHash);
});

test('setPasswordWithConfirmation rejects blank passwords', function () {
    $user = User::factory()->create();
    $oldHash = $user->password;

    expect($user->setPasswordWithConfirmation('', ''))->toBeFalse()
        ->and($user->setPasswordWithConfirmation('      ', '      '))->toBeFalse()
        ->and($user->password)->toBe($oldHash);
});

test('a blank password assigned directly never overwrites the stored hash', function () {
    $user = User::factory()->create();
    $oldHash = DB::connection('testing_mariadb')->table('user')->where('id', $user->id)->value('password');

    $user->password = '';
    $user->save();
    $user->setAttribute('password', null);
    $user->save();

    $stored = DB::connection('testing_mariadb')->table('user')->where('id', $user->id)->value('password');

    expect($stored)->toBe($oldHash)
        ->and($stored)->not->toBe('');
});

test('a new user without a password is rejected rather than stored as an empty string', function () {
    $user = User::factory()->make(['password' => '']);

    expect(fn () => $user->save())->toThrow(QueryException::class);
    expect(DB::connection('testing_mariadb')->table('user')->where('email', $user->email)->exists())->toBeFalse();
});

test('a zero-date row reads as null and saves without error', function () {
    $db = DB::connection('testing_mariadb');
    $id = $db->table('user')->insertGetId([
        'date_registered' => '2020-01-02 03:04:05',
        'first' => 'Zero',
        'last' => 'Date',
        'email' => 'zero.date@example.test',
        'location_id' => 0,
        'password' => Hash::make('password'),
        'type_id' => 1,
        'address1' => '',
        'city' => '',
        'state' => '',
        'zip' => '',
        'created_at' => '0000-00-00 00:00:00',
        'updated_at' => '0000-00-00 00:00:00',
    ]);

    $user = User::query()->findOrFail($id);

    expect($user->created_at)->toBeNull();
    expect($user->updated_at)->toBeNull();
    expect($user->toArray())->toHaveKey('created_at', null);
    expect(json_decode($user->toJson(), true)['updated_at'])->toBeNull();
    expect($user->toJson())->not->toContain('-0001');

    $user->first = 'Saved';
    $user->save();

    expect($db->table('user')->where('id', $id)->value('first'))->toBe('Saved');
});

test('cityState and fullAddress are unmasked and match the legacy layout', function () {
    $user = User::factory()->create([
        'address1' => '1002 South Orange Ave',
        'address2' => 'Apt 4',
        'city' => 'Orlando',
        'state' => 'FL',
        'zip' => '32803',
    ]);

    expect($user->cityState)->toBe('Orlando, FL')
        ->and($user->fullAddress)->toBe('1002 South Orange Ave Apt 4 Orlando, FL 32803');
});

test('profileURL, fullName, firstNameLastInitial use the legacy formats', function () {
    $user = User::factory()->create(['first' => 'eugene', 'last' => 'smith']);

    expect($user->profileURL)->toBe('#/EugeneS/u'.$user->id)
        ->and($user->fullName)->toBe('eugene smith')
        ->and($user->firstNameLastInitial)->toBe('eugene s');
});

test('accessor keys appear in toArray and toJson', function () {
    $user = User::factory()->create();

    $keys = ['cityState', 'fullAddress', 'profileURL', 'firstNameLastInitial', 'fullName', 'imageURL', 'imagePath'];

    $array = $user->toArray();
    $json = json_decode($user->toJson(), true);

    foreach ($keys as $key) {
        expect($array)->toHaveKey($key)
            ->and($json)->toHaveKey($key);
    }
});

test('imageURL falls back to the generic avatar without an image', function () {
    $user = User::factory()->create(['has_image' => false]);

    expect($user->imageURL)->toBe(config('sesf.image_url').'generic_avatar_300.gif');
});

test('imageURL points at the member image without a cache-bust when the file is missing', function () {
    $user = User::factory()->withImage()->create();

    expect($user->imageURL)->toBe(config('sesf.member_image_url').$user->id.'/profile.jpg');
});

test('imagePath is the member directory under the configured root', function () {
    $user = User::factory()->create();

    expect($user->imagePath)->toBe(config('sesf.member_image_root').$user->id.'/');
});

test('preferences and user relations point at each other', function () {
    $user = User::factory()->withoutPreferences()->create();
    $preferences = UserPreferences::factory()->for($user)->create();

    expect($user->preferences)->toBeInstanceOf(UserPreferences::class)
        ->and($user->preferences->is($preferences))->toBeTrue()
        ->and($preferences->user->is($user))->toBeTrue();
});

test('loginEmailSignins relation returns only this user\'s rows', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    LoginEmailSignin::factory()->count(2)->for($user)->create();
    LoginEmailSignin::factory()->for($other)->create();

    expect($user->loginEmailSignins)->toHaveCount(2)
        ->and($user->loginEmailSignins->every(fn ($row) => $row->user_id === $user->id))->toBeTrue();
});

test('a geocode job is queued when a user is created with an address', function () {
    Bus::fake();

    $user = User::factory()->create(['address1' => '1 Main St', 'city' => 'Orlando']);

    Bus::assertDispatched(GeocodeUserAddress::class, fn (GeocodeUserAddress $job) => $job->userId === $user->id);
});

test('a geocode job is not queued for a user created with an empty address', function () {
    Bus::fake();

    User::factory()->create(['address1' => '', 'city' => '']);

    Bus::assertNotDispatched(GeocodeUserAddress::class);
});

test('a geocode job is queued only when an address field changes', function () {
    $user = User::factory()->create(['address1' => '1 Main St', 'city' => 'Orlando']);

    Bus::fake();

    $user->phone = '555-000-1111';
    $user->first = 'Changed';
    $user->save();
    Bus::assertNotDispatched(GeocodeUserAddress::class);

    $user->city = 'Tampa';
    $user->save();
    Bus::assertDispatched(GeocodeUserAddress::class, 1);

    $user->zip = '33601';
    $user->save();
    Bus::assertDispatched(GeocodeUserAddress::class, 2);
});

test('the legacy column spellings are used for coordinates', function () {
    $user = User::factory()->create(['lattitude' => 28.5, 'longitude' => -81.3]);

    $fresh = User::query()->findOrFail($user->id);

    expect($fresh->lattitude)->toBe(28.5)
        ->and($fresh->longitude)->toBe(-81.3);
});
