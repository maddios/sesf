<?php

namespace Database\Factories;

use App\Models\CarShow;
use App\Models\ShirtAdhocSale;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShirtAdhocSale>
 */
class ShirtAdhocSaleFactory extends Factory
{
    protected $model = ShirtAdhocSale::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'show_id' => CarShow::factory(),
            'created_by_user_id' => User::factory(),
        ];
    }
}
