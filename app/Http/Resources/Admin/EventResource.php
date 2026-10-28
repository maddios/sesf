<?php

namespace App\Http\Resources\Admin;

use App\Models\CarShow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An event (car_show) as the admin sees it. registrations_count drives the delete guard in the UI.
 * Dates are server formats ("Y-m-d", "Y-m-d H:i:s") to match the admin date inputs.
 *
 * @mixin CarShow
 */
final class EventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var CarShow $event */
        $event = $this->resource;

        return [
            'id' => (int) $event->id,
            'name' => $event->name,
            'short_name' => $event->short_name,
            'description' => $event->description,
            'short_description' => $event->getRawOriginal('short_description'),
            'schedule' => $event->schedule,
            'is_schedule_pdf' => (bool) $event->is_schedule_pdf,
            'price_car' => $event->price_car,
            'price_adult' => $event->price_adult,
            'price_child' => $event->price_child,
            'date_start' => $event->date_start?->format('Y-m-d'),
            'date_end' => $event->date_end?->format('Y-m-d'),
            'date_active' => $event->date_active?->format('Y-m-d H:i:s'),
            'date_registration_deactivation' => $event->date_registration_deactivation?->format('Y-m-d H:i:s'),
            'is_active' => (bool) $event->is_active,
            'is_judging_released' => (bool) $event->is_judging_released,
            'waiver_id' => $event->waiver_id,
            'has_image' => (bool) $event->has_image,
            'image_url' => $event->imageURL,
            'primary_sponsor_id' => $event->primary_sponsor_id === null ? null : (int) $event->primary_sponsor_id,
            'registrations_count' => (int) ($event->getAttribute('registrations_count') ?? 0),
            'created_at' => $event->created_at?->toIso8601String(),
            'updated_at' => $event->updated_at?->toIso8601String(),
        ];
    }
}
