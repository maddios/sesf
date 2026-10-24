<?php

namespace Database\Factories;

use App\Models\CarBody;
use Illuminate\Database\Eloquent\Factories\Factory;
use LogicException;

/**
 * car_body is a lookup table pre-seeded by tests/Fixtures/lookup-data.sql. Tests and factories
 * use the fixture rows through the constants below, so this factory does not insert rows.
 *
 * @extends Factory<CarBody>
 */
class CarBodyFactory extends Factory
{
    /** Fixture ids (tests/Fixtures/lookup-data.sql). */
    public const E24 = 1;

    public const E63_COUPE = 2;

    public const E64_CONVERTIBLE = 3;

    public const OTHER = 6;

    protected $model = CarBody::class;

    /**
     * @return array<string, mixed>
     *
     * @throws LogicException always: use CarBody::query()->findOrFail(CarBodyFactory::E24) instead.
     */
    public function definition(): array
    {
        throw new LogicException('car_body rows come from tests/Fixtures/lookup-data.sql; fetch one by id instead of creating it.');
    }
}
