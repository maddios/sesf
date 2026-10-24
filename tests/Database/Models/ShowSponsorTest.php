<?php

use App\Enums\SponsorType;
use App\Models\CarShow;
use App\Models\ShowSponsor;
use App\Models\Sponsor;

test('uses the show_sponsor table with timestamps and guards its privilege and money columns', function () {
    $pivot = new ShowSponsor;

    expect($pivot->getTable())->toBe('show_sponsor')
        ->and($pivot->getKeyName())->toBe('id')
        ->and($pivot->usesTimestamps())->toBeTrue()
        ->and($pivot->getGuarded())->toBe(['id', 'is_primary']);
});

test('casts type_id to SponsorType and is_primary to boolean', function () {
    $pivot = ShowSponsor::factory()->ofType(SponsorType::Bronzit)->create(['is_primary' => 1])->refresh();

    expect($pivot->type_id)->toBe(SponsorType::Bronzit)
        ->and($pivot->is_primary)->toBeTrue();
});

test('sort_order is assigned as max plus one per show', function () {
    $show = CarShow::factory()->create();
    $other = CarShow::factory()->create();

    $first = ShowSponsor::factory()->create(['show_id' => $show->id]);
    $second = ShowSponsor::factory()->create(['show_id' => $show->id]);
    $fromOtherShow = ShowSponsor::factory()->create(['show_id' => $other->id]);

    expect($first->sort_order)->toBe(1)
        ->and($second->sort_order)->toBe(2)
        ->and($fromOtherShow->sort_order)->toBe(1);
});

test('an explicit non-zero sort_order is kept', function () {
    $pivot = ShowSponsor::factory()->create(['sort_order' => 9]);

    expect($pivot->sort_order)->toBe(9);
});

test('sponsor and show relations resolve in both directions', function () {
    $show = CarShow::factory()->create();
    $sponsor = Sponsor::factory()->create();
    $pivot = ShowSponsor::factory()->create(['show_id' => $show->id, 'sponsor_id' => $sponsor->id]);

    expect($pivot->sponsor->id)->toBe($sponsor->id)
        ->and($pivot->show->id)->toBe($show->id)
        ->and($show->sponsors->pluck('id')->all())->toBe([$sponsor->id]);
});

test('mass assignment ignores the guarded primary flag', function () {
    $pivot = new ShowSponsor;
    $pivot->fill(['is_primary' => true, 'sort_order' => 3]);

    expect($pivot->getAttributes())->not->toHaveKey('is_primary')
        ->and($pivot->sort_order)->toBe(3);
});
