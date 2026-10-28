<?php

namespace App\Http\Resources\Admin;

use App\Models\ShowRegistration;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One registration in the event summary (legacy EventSummary table and export "Data" sheet).
 * judged = any of the registration's cars has a judging type (legacy judging relation).
 *
 * @mixin ShowRegistration
 */
final class ReportRegistrationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ShowRegistration $reg */
        $reg = $this->resource;
        $user = $reg->user;

        return [
            'id' => (int) $reg->id,
            'invoice_id' => (int) $reg->invoice_id,
            'first' => (string) $user?->first,
            'last' => (string) $user?->last,
            'email' => $user?->email,
            'address1' => $user?->address1,
            'address2' => $user?->address2,
            'city' => $user?->city,
            'state' => $user?->state,
            'zip' => $user?->zip,
            'is_paid' => (bool) $reg->is_paid,
            'user_signed_waiver' => (bool) $reg->has_signed,
            'waiver_signed' => (bool) $reg->getAttribute('waiver_has_signed'),
            'judged' => $reg->registrationCars->contains(fn ($car) => $car->judging_type_id !== null),
            'cars' => $reg->cars->map(fn ($car) => trim($car->year.' '.$car->body?->name.' '.$car->model))->values()->all(),
            'guests' => $reg->guests->map(fn ($guest) => [
                'name' => trim($guest->first.' '.$guest->last),
                'first' => (string) $guest->first,
                'last' => (string) $guest->last,
                'email' => $guest->email,
                'signed' => (bool) $guest->has_signed,
            ])->values()->all(),
            'shirts' => $reg->shirts->map(fn ($shirt) => $shirt->shirtType?->name)->values()->all(),
            'extras' => $reg->extras->map(fn ($extra) => [
                'name' => (string) $extra->showExtra?->name,
                'count' => (int) $extra->num_extras,
            ])->values()->all(),
            'arrival_time' => $reg->arrival_time,
            'arrival_date' => $reg->arrival_date?->format('Y-m-d'),
        ];
    }
}
