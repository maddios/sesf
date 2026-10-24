<?php

use App\Enums\AgendaType;
use App\Models\CarShow;
use App\Models\CarShowAgenda;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    DB::table('show_agenda_type')->insertOrIgnore([['id' => 1, 'name' => 'Agenda'], ['id' => 2, 'name' => 'Judging']]);
});

test('uses the show_agenda table with timestamps and guards only id', function () {
    $agenda = new CarShowAgenda;

    expect($agenda->getTable())->toBe('show_agenda')
        ->and($agenda->getKeyName())->toBe('id')
        ->and($agenda->usesTimestamps())->toBeTrue()
        ->and($agenda->getGuarded())->toBe(['id']);
});

test('casts type to AgendaType and flags to boolean', function () {
    $agenda = CarShowAgenda::factory()->judging()->create(['is_visible' => 1, 'is_pdf' => 0])->refresh();

    expect($agenda->type_id)->toBe(AgendaType::Judging)
        ->and($agenda->is_visible)->toBeTrue()
        ->and($agenda->is_pdf)->toBeFalse();
});

test('visible, forShow and ordered scopes filter and sort', function () {
    $show = CarShow::factory()->create();
    $other = CarShow::factory()->create();
    $late = CarShowAgenda::factory()->create(['show_id' => $show->id, 'sort_order' => 2]);
    $early = CarShowAgenda::factory()->create(['show_id' => $show->id, 'sort_order' => 1]);
    CarShowAgenda::factory()->hidden()->create(['show_id' => $show->id, 'sort_order' => 3]);
    CarShowAgenda::factory()->create(['show_id' => $other->id, 'sort_order' => 1]);

    $ids = CarShowAgenda::query()->forShow($show->id)->visible()->ordered()->pluck('id')->all();

    expect($ids)->toBe([$early->id, $late->id]);
});

test('show relation returns the parent CarShow', function () {
    $show = CarShow::factory()->create();
    $agenda = CarShowAgenda::factory()->create(['show_id' => $show->id]);

    expect($agenda->show->id)->toBe($show->id);
});
