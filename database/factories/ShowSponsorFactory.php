<?php

namespace Database\Factories;

use App\Enums\SponsorType;
use App\Models\CarShow;
use App\Models\ShowSponsor;
use App\Models\Sponsor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShowSponsor>
 */
class ShowSponsorFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'show_id' => CarShow::factory(),
            'sponsor_id' => Sponsor::factory(),
            'is_primary' => false,
            'sort_order' => 0,
            'type_id' => SponsorType::Gold,
        ];
    }

    public function ofType(SponsorType $type): static
    {
        return $this->state(fn (): array => ['type_id' => $type]);
    }
}
