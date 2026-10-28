<?php

namespace App\Http\Resources\Registration;

use App\Models\Car;
use App\Models\CarShow;
use App\Models\Shirt;
use App\Models\ShowExtraRegistration;
use App\Models\ShowRegistration;
use App\Models\UserGuest;
use App\Services\Registration\RegistrationPricing;
use App\Services\Shirts\ShowShirtMenu;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The invoice of member/invoice (legacy MemberProfile::getInvoice): the registration with the
 * names the invoice view prints, plus `totals`.
 *
 * Shapes differ from event/registration on purpose: here shirts carry a type object
 * ({id, name}) and cars and extras carry their names, because the invoice prints them.
 *
 * @mixin ShowRegistration
 */
class InvoiceResource extends JsonResource
{
    /**
     * @param  array<string, mixed>  $totals  RegistrationPricing::totalsFor() output
     * @param  ShowShirtMenu  $shirts  the event's shirt menu: the unit price of a legacy line without a snapshot
     */
    public function __construct(ShowRegistration $registration, private readonly array $totals, private readonly ShowShirtMenu $shirts)
    {
        parent::__construct($registration);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ShowRegistration $registration */
        $registration = $this->resource;

        $data = [
            'id' => (int) $registration->id,
            'show_id' => (int) $registration->show_id,
            'user_id' => (int) $registration->user_id,
            'invoice_id' => (int) $registration->invoice_id,
            'is_paid' => (bool) $registration->is_paid,
            'donation' => (int) $registration->donation,
            'discount' => RegistrationPricing::decimal(RegistrationPricing::signedCents((string) $registration->discount)),
            'amount_owed' => (string) $registration->amount_owed,
            'amount_received' => (string) $registration->amount_received,
            'car_show' => $this->showPayload($registration->carShow),
            'cars' => $registration->cars
                ->map(fn (Car $car): array => [
                    'id' => (int) $car->id,
                    'name' => $car->name,
                    'judging_type' => self::judgingTypeId($car),
                ])
                ->values()
                ->all(),
            'guests' => $registration->guests
                ->map(fn (UserGuest $guest): array => [
                    'id' => (int) $guest->id,
                    'fullName' => $guest->fullName,
                    'type' => $guest->type_id->value,
                ])
                ->values()
                ->all(),
            'shirts' => $registration->shirts
                ->map(fn (Shirt $shirt): array => [
                    'id' => (int) $shirt->id,
                    'type' => [
                        'id' => (int) $shirt->type_id,
                        'name' => $shirt->shirtType?->name,
                    ],
                    'price' => $this->shirts->linePrice($shirt->price, (int) $shirt->type_id),
                ])
                ->values()
                ->all(),
            'extras' => $registration->extras
                ->map(fn (ShowExtraRegistration $extra): array => [
                    'show_extra_id' => (int) $extra->show_extra_id,
                    'num_extras' => (int) ($extra->num_extras ?? 0),
                    'show_extra' => [
                        'id' => (int) $extra->show_extra_id,
                        'name' => $extra->showExtra?->name,
                        'price' => $extra->showExtra === null ? '0.00' : $extra->showExtra->price,
                    ],
                ])
                ->values()
                ->all(),
            'totals' => $this->totals,
        ];

        if ($registration->discount_note !== null) {
            $data['discount_note'] = $registration->discount_note;
        }

        return $data;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function showPayload(?CarShow $show): ?array
    {
        if ($show === null) {
            return null;
        }

        return [
            'id' => (int) $show->id,
            'name' => $show->name,
            'profileURL' => $show->profileURL,
            'regOpen' => $show->regOpen,
        ];
    }

    /** judging_type_id from the show_registration_car pivot, or null. */
    private static function judgingTypeId(Car $car): ?int
    {
        $pivot = $car->getRelationValue('pivot');
        $value = $pivot instanceof Pivot ? $pivot->getAttribute('judging_type_id') : null;

        return $value === null ? null : (int) $value;
    }
}
