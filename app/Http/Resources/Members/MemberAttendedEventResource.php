<?php

namespace App\Http\Resources\Members;

use App\Http\Resources\Cars\CarSummaryResource;
use App\Models\ShowRegistration;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One show registration in member/attended-events. Whitelisted: no amounts, invoice, payment,
 * waiver or contact fields. The cars are left out for other viewers when the owner has turned
 * off show_car_on_attendees_page.
 *
 * @mixin ShowRegistration
 */
class MemberAttendedEventResource extends JsonResource
{
    public function __construct($resource, private readonly bool $carsVisible)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ShowRegistration $registration */
        $registration = $this->resource;
        $show = $registration->carShow;

        return [
            'id' => (int) $registration->id,
            'show_id' => (int) $registration->show_id,
            'showName' => $show?->name,
            'shortDate' => $registration->shortDate,
            'profileURL' => $registration->profileURL,
            'carShow' => $show === null ? null : [
                'id' => (int) $show->id,
                'name' => $show->name,
                'shortDate' => $registration->shortDate,
                'profileURL' => $show->profileURL,
                'imageURL' => $show->imageURL,
            ],
            'cars' => $this->carsVisible
                ? $registration->cars->map(fn ($car) => (new CarSummaryResource($car))->resolve($request))->values()->all()
                : [],
        ];
    }
}
