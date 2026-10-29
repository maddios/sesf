<?php

namespace App\Http\Resources\Cars;

use App\Models\Car;
use App\Models\ShowRegistration;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A car in a list (member/cars, member/car-list, attended-event cars). Viewer-independent.
 * Shows are included only when the controller eager-loaded registrations.carShow.
 * Requires body to be loaded (the controllers do this).
 *
 * @mixin Car
 */
class CarSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Car $car */
        $car = $this->resource;

        $data = [
            'id' => (int) $car->id,
            'has_image' => (bool) $car->has_image,
            'name' => $car->name,
            'nickname' => $car->nickname,
            'year' => $car->year,
            'model' => $car->model,
            'body' => $car->body?->name,
            'type_id' => (int) $car->type_id,
            'profileURL' => $car->profileURL,
            'imageURL' => $car->imageURL,
        ];

        if ($car->relationLoaded('registrations')) {
            $data['shows'] = $car->registrations
                ->map(fn (ShowRegistration $registration) => $registration->carShow)
                ->filter()
                ->map(fn ($show) => [
                    'name' => $show->name,
                    'profileURL' => $show->profileURL,
                    'imageURL' => $show->imageURL,
                ])
                ->values()
                ->all();
        }

        return $data;
    }
}
