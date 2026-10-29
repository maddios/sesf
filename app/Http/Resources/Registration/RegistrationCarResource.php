<?php

namespace App\Http\Resources\Registration;

use App\Models\Car;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One of the viewer's cars on the registration page (legacy EventProfile::getCars and
 * getRegistration `cars`). judging_types is set by RegistrationPayloadBuilder::cars().
 *
 * @mixin Car
 */
class RegistrationCarResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Car $car */
        $car = $this->resource;

        return [
            'id' => (int) $car->id,
            'name' => $car->name,
            'nickname' => $car->nickname,
            'model' => $car->model,
            'year' => $car->year,
            'body_id' => (int) $car->body_id,
            'body' => $car->body?->name,
            'judging_types' => $car->getAttribute('judging_types') ?? [],
        ];
    }
}
