<?php

namespace Database\Factories;

use App\Models\ShirtAdhocSale;
use App\Models\ShirtAdhocSaleItem;
use App\Models\ShirtType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShirtAdhocSaleItem>
 */
class ShirtAdhocSaleItemFactory extends Factory
{
    protected $model = ShirtAdhocSaleItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sale_id' => ShirtAdhocSale::factory(),
            'type_id' => ShirtTypeFactory::MENS_M_TSHIRT,
        ];
    }

    public function ofType(ShirtType $type): static
    {
        return $this->state(fn (): array => ['type_id' => $type->getKey()]);
    }
}
