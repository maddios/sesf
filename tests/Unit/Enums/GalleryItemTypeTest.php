<?php

use App\Enums\GalleryItemType;

dataset('gallery item types', [
    [GalleryItemType::Image, 1, 'Image'],
    [GalleryItemType::Video, 2, 'Video'],
    [GalleryItemType::Other, 3, 'Other'],
]);

it('matches legacy values and labels', function (GalleryItemType $case, int $value, string $label) {
    expect($case->value)->toBe($value)
        ->and($case->label())->toBe($label);
})->with('gallery item types');

it('resolves legacy ids including numeric strings', function (GalleryItemType $case, int $value) {
    expect(GalleryItemType::tryFromLegacy($value))->toBe($case)
        ->and(GalleryItemType::tryFromLegacy((string) $value))->toBe($case);
})->with('gallery item types');

it('returns null for unknown ids', function (int|string|null $value) {
    expect(GalleryItemType::tryFromLegacy($value))->toBeNull();
})->with([[0], [4], ['99'], ['abc'], [''], [null]]);
