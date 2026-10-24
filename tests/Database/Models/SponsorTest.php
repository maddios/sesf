<?php

use App\Enums\SponsorType;
use App\Models\CarShow;
use App\Models\ShowSponsor;
use App\Models\Sponsor;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    DB::table('sponsor_type')->insertOrIgnore([['id' => 1, 'name' => 'M'], ['id' => 2, 'name' => 'Gold'], ['id' => 3, 'name' => 'Silber'], ['id' => 4, 'name' => 'Bronzit']]);
});

test('uses the sponsor table with timestamps and guards only id', function () {
    $sponsor = new Sponsor;

    expect($sponsor->getTable())->toBe('sponsor')
        ->and($sponsor->getKeyName())->toBe('id')
        ->and($sponsor->usesTimestamps())->toBeTrue()
        ->and($sponsor->getGuarded())->toBe(['id']);
});

test('is_enabled is boolean and internal_notes is hidden from JSON', function () {
    $sponsor = Sponsor::factory()->create(['internal_notes' => 'staff only'])->refresh();

    expect($sponsor->is_enabled)->toBeTrue()
        ->and($sponsor->toArray())->not->toHaveKey('internal_notes')
        ->and($sponsor->toJson())->not->toContain('staff only');
});

test('imageURL, shortDescription and imageName keys are present in JSON', function () {
    $sponsor = Sponsor::factory()->create();

    foreach (['imageURL', 'shortDescription', 'imageName'] as $key) {
        expect($sponsor->toArray())->toHaveKey($key)
            ->and(json_decode($sponsor->toJson(), true))->toHaveKey($key);
    }
});

test('shortDescription returns the stored value, or 90 characters plus ellipsis', function () {
    $stored = Sponsor::factory()->create(['short_description' => 'Brief', 'description' => 'Long text']);
    $fallback = Sponsor::factory()->create(['short_description' => null, 'description' => str_repeat('b', 200)]);

    expect($stored->shortDescription)->toBe('Brief')
        ->and($fallback->shortDescription)->toBe(str_repeat('b', 90).'...');
});

test('imageName is the id with a jpg extension', function () {
    $sponsor = Sponsor::factory()->create();

    expect($sponsor->imageName)->toBe($sponsor->id.'.jpg');
});

test('imageURL has no cache-buster when the file is missing, and an mtime when it exists', function () {
    $root = sys_get_temp_dir().'/sesf-sponsor-'.uniqid().'/';
    mkdir($root);
    config(['sesf.sponsor_image_root' => $root]);
    $sponsor = Sponsor::factory()->create();
    $plain = 'images/sponsor/'.$sponsor->id.'.jpg';

    expect($sponsor->imageURL)->toBe($plain);

    file_put_contents($root.$sponsor->id.'.jpg', 'x');
    touch($root.$sponsor->id.'.jpg', 1700000000);
    clearstatcache();

    expect($sponsor->imageURL)->toBe($plain.'?v=1700000000');

    unlink($root.$sponsor->id.'.jpg');
    rmdir($root);
});

test('carShow relation lists shows through show_sponsor with pivot data', function () {
    $sponsor = Sponsor::factory()->create();
    $show = CarShow::factory()->create();
    ShowSponsor::factory()->ofType(SponsorType::Silber)->create(['show_id' => $show->id, 'sponsor_id' => $sponsor->id]);

    $linked = $sponsor->carShow->first();

    expect($linked->id)->toBe($show->id)
        ->and($linked->getRelation('pivot')->getAttribute('type_id'))->toBe(SponsorType::Silber->value)
        ->and($show->sponsors->first()->id)->toBe($sponsor->id);
});

test('disabled sponsors are stored as not enabled', function () {
    $sponsor = Sponsor::factory()->disabled()->create()->refresh();

    expect($sponsor->is_enabled)->toBeFalse()
        ->and(DB::table('sponsor')->where('id', $sponsor->id)->value('is_enabled'))->toBe(0);
});
