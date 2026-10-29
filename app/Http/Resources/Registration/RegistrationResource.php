<?php

namespace App\Http\Resources\Registration;

use App\Models\Car;
use App\Models\Shirt;
use App\Models\ShowExtraRegistration;
use App\Models\ShowRegistration;
use App\Models\UserGuest;
use App\Services\Registration\RegistrationPricing;
use App\Services\Shirts\ShowShirtMenu;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use stdClass;

/**
 * The `registration` object of event/registration (legacy EventProfile::getRegistration).
 * Keys are whitelisted here; no raw toArray(). Money is a decimal string with 2 places.
 *
 * Without a registration yet, use emptyFor(): the same keys with empty collections.
 *
 * @mixin ShowRegistration
 */
class RegistrationResource extends JsonResource
{
    /**
     * @param  array<string, mixed>  $totals  RegistrationPricing::totalsFor() output
     * @param  ShowShirtMenu  $shirts  the event's shirt menu (name and unit price of each ordered shirt)
     */
    public function __construct(ShowRegistration $registration, private readonly array $totals, private readonly ShowShirtMenu $shirts)
    {
        parent::__construct($registration);
    }

    /**
     * Registration shape for a user who has not registered for the show yet.
     *
     * @param  array<string, mixed>  $totals
     * @return array<string, mixed>
     */
    public static function emptyFor(int $showId, int $userId, array $totals): array
    {
        return [
            'id' => null,
            'show_id' => $showId,
            'user_id' => $userId,
            'invoice_id' => null,
            'is_paid' => false,
            'is_cancelled' => false,
            'arrival_date' => ['data' => null],
            'arrival_time' => null,
            'donation' => 0,
            'discount' => '0.00',
            'amount_owed' => '0.00',
            'amount_received' => '0.00',
            'cars' => [],
            'guests' => [],
            'shirts' => [],
            'extras' => new stdClass,
            'payment' => self::payment($totals),
        ];
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
            'is_cancelled' => (bool) $registration->is_cancelled,
            'arrival_date' => ['data' => $registration->arrival_date?->format('Y-m-d')],
            'arrival_time' => $registration->arrival_time,
            'donation' => (int) $registration->donation,
            'discount' => RegistrationPricing::decimal(RegistrationPricing::signedCents((string) $registration->discount)),
            'amount_owed' => (string) $registration->amount_owed,
            'amount_received' => (string) $registration->amount_received,
            'cars' => $registration->cars
                ->map(fn ($car): array => [
                    'id' => (int) $car->id,
                    'judging_type' => self::judgingTypeId($car),
                ])
                ->values()
                ->all(),
            'guests' => $registration->guests
                ->map(fn (UserGuest $guest): array => [
                    'id' => (int) $guest->id,
                    'first' => $guest->first,
                    'last' => $guest->last,
                    'email' => $guest->email,
                    'type' => $guest->type_id->value,
                ])
                ->values()
                ->all(),
            'shirts' => $registration->shirts
                ->map(fn (Shirt $shirt): array => [
                    'id' => (int) $shirt->id,
                    'type' => (int) $shirt->type_id,
                    // The ordered shirt as billed: kept even when the event no longer offers its type.
                    'name' => $this->shirts->find((int) $shirt->type_id)?->name,
                    'price' => $this->shirts->linePrice($shirt->price, (int) $shirt->type_id),
                ])
                ->values()
                ->all(),
            'extras' => self::extrasKeyed($registration),
            'payment' => self::payment($this->totals),
        ];

        if ($registration->discount_note !== null) {
            $data['discount_note'] = $registration->discount_note;
        }

        return $data;
    }

    /**
     * Legacy array_column(extras, NULL, 'show_extra_id'): keyed by show_extra_id.
     */
    private static function extrasKeyed(ShowRegistration $registration): stdClass
    {
        $keyed = $registration->extras
            ->mapWithKeys(fn (ShowExtraRegistration $extra): array => [
                (int) $extra->show_extra_id => [
                    'show_extra_id' => (int) $extra->show_extra_id,
                    'num_extras' => (int) ($extra->num_extras ?? 0),
                ],
            ])
            ->all();

        // An object, not an array: Resource::resolve() turns an array with only numeric keys into a list.
        return $keyed === [] ? new stdClass : (object) $keyed;
    }

    /**
     * @param  array<string, mixed>  $totals
     * @return array<string, string>
     */
    public static function payment(array $totals): array
    {
        return [
            'cars' => $totals['cars'],
            'guests' => $totals['guests'],
            'shirts' => $totals['shirts'],
            'extras' => $totals['extras'],
            'total' => $totals['total'],
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
