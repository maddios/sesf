<?php

namespace Database\Factories;

use App\Models\CarShow;
use App\Models\Gallery;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Creates galleries with valid parents. show_id and user_id come from their own factories, and
 * the lookup rows that the foreign keys need come from tests/Fixtures/lookup-data.sql.
 *
 * @extends Factory<Gallery>
 */
class GalleryFactory extends Factory
{
    protected $model = Gallery::class;

    private const DEFAULT_TYPE_ID = 1;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'show_id' => CarShow::factory(),
            'user_id' => User::factory(),
            'type_id' => self::DEFAULT_TYPE_ID,
            'is_enabled' => true,
            'default_item_id' => null,
            'parent_id' => null,
            'is_private' => false,
            'title' => fake()->words(3, true),
            'sort_order' => null,
        ];
    }

    /** A top-level gallery (no parent). */
    public function root(): static
    {
        return $this->state(fn (): array => ['parent_id' => null]);
    }

    /** A gallery nested under $parent, in the same show. */
    public function child(Gallery $parent): static
    {
        return $this->state(fn (): array => [
            'parent_id' => $parent->id,
            'show_id' => $parent->show_id,
        ]);
    }

    public function disabled(): static
    {
        return $this->state(fn (): array => ['is_enabled' => false]);
    }

    public function private(): static
    {
        return $this->state(fn (): array => ['is_private' => true]);
    }
}
