<?php

namespace Database\Factories;

use App\Enums\GalleryItemType;
use App\Models\GalleryItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Creates gallery items with a valid owner. Lookup rows come from tests/Fixtures/lookup-data.sql.
 * Gallery membership is attached by the caller through gallery_item_xref, which needs its own user_id.
 *
 * Only the Image type has a lookup row (gallery_item_type id 1), so only Image can be persisted.
 * video() and the other non-Image types are for make() only: their foreign key fails on create().
 *
 * @extends Factory<GalleryItem>
 */
class GalleryItemFactory extends Factory
{
    protected $model = GalleryItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type_id' => GalleryItemType::Image->value,
            'title' => fake()->words(3, true),
            'original_name' => fake()->lexify('????????').'.jpg',
            'sort_order' => null,
            'date_taken' => now()->subDays(fake()->numberBetween(1, 900)),
        ];
    }

    public function video(): static
    {
        return $this->state(fn (): array => ['type_id' => GalleryItemType::Video->value]);
    }
}
