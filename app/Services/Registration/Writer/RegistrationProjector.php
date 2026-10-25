<?php

namespace App\Services\Registration\Writer;

use App\Enums\UserGuestType;
use App\Models\CarShow;
use App\Models\ShirtType;
use App\Models\ShowExtra;
use App\Models\ShowRegistration;
use App\Models\UserGuest;
use App\Services\Registration\ExtraLine;
use App\Services\Registration\LineItem;
use App\Services\Registration\PricingCalculator;
use App\Services\Registration\PricingInput;
use App\Services\Registration\PricingResult;
use App\Services\Registration\RegistrationPricing;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Prices a registration with PricingCalculator, always COMPUTED (never the stored summary that
 * RegistrationPricing shows for ended shows), so the writer can persist amount_owed.
 *
 *  - quote(): the state the registration WOULD have after RegistrationData is applied. Reads only.
 *  - stored(): the state in the database (called by the writer after its writes).
 *
 * Both follow the rules of docs/pricing.md: cars only when their body is in car_body_show,
 * guests free in user_guest.id order (new guests come after existing ones, in posted order),
 * shirt price = shirt.price snapshot or else shirt_type.price, extras show_extra.price x num_extras.
 */
final class RegistrationProjector
{
    public function __construct(private readonly PricingCalculator $calculator = new PricingCalculator) {}

    /**
     * @throws RegistrationRejected when a stored amount cannot be priced (negative legacy discount)
     */
    public function quote(CarShow $show, ?ShowRegistration $existing, RegistrationData $data): PricingResult
    {
        if ($existing !== null) {
            $existing->loadMissing(['cars', 'guests', 'shirts.shirtType', 'extras']);
        }

        $carCount = $data->keepsStoredCars() && $existing !== null
            ? $this->allowedCarCount($show, $existing)
            : count($data->cars);

        // Guests: existing (id order) with posted changes, then new guests in posted order.
        /** @var array<int, UserGuestType> $guests */
        $guests = [];
        foreach ($existing?->guests->sortBy('id') ?? [] as $guest) {
            /** @var UserGuest $guest */
            $guests[(int) $guest->id] = $guest->type_id;
        }
        $newGuests = [];
        foreach ($data->guests as $guest) {
            if ($guest->id !== null) {
                if ($guest->deleted) {
                    unset($guests[$guest->id]);
                } elseif (array_key_exists($guest->id, $guests)) {
                    $guests[$guest->id] = $guest->type;
                }
            } elseif (! $guest->deleted) {
                $newGuests[] = $guest->type;
            }
        }

        // Shirts: existing keep their price unless the type changes; new ones take the type price.
        $typePrices = $this->shirtTypePrices($data);
        /** @var array<int, string> $shirts */
        $shirts = [];
        /** @var array<int, int> $shirtTypes */
        $shirtTypes = [];
        foreach ($existing?->shirts->sortBy('id') ?? [] as $shirt) {
            $shirts[(int) $shirt->id] = $shirt->price ?? ($shirt->shirtType === null ? '0' : (string) $shirt->shirtType->price);
            $shirtTypes[(int) $shirt->id] = (int) $shirt->type_id;
        }
        $newShirts = [];
        foreach ($data->shirts as $shirt) {
            if ($shirt->id !== null) {
                if ($shirt->deleted) {
                    unset($shirts[$shirt->id]);
                } elseif (array_key_exists($shirt->id, $shirts) && $shirtTypes[$shirt->id] !== $shirt->typeId) {
                    $shirts[$shirt->id] = $typePrices[$shirt->typeId] ?? '0';
                }
            } elseif (! $shirt->deleted) {
                $newShirts[] = $typePrices[$shirt->typeId] ?? '0';
            }
        }

        // Extras: show_extra_id => quantity; 0 removes.
        $quantities = [];
        foreach ($existing === null ? [] : $existing->extras as $extra) {
            $quantities[(int) $extra->show_extra_id] = (int) ($extra->num_extras ?? 0);
        }
        foreach ($data->extras as $extraId => $quantity) {
            if ($quantity === 0) {
                unset($quantities[$extraId]);
            } else {
                $quantities[$extraId] = $quantity;
            }
        }

        return $this->price(
            $show,
            $carCount,
            array_merge(array_values($guests), $newGuests),
            array_merge(array_values($shirts), $newShirts),
            $quantities,
            $data->donation,
            $existing,
        );
    }

    /**
     * The registration as stored. Relations are reloaded, so call it after the writes.
     *
     * @throws RegistrationRejected when a stored amount cannot be priced
     */
    public function stored(CarShow $show, ShowRegistration $registration): PricingResult
    {
        $registration->unsetRelations();
        $registration->load([
            'cars',
            'guests' => fn ($query) => $query->orderBy('id'),
            'shirts' => fn ($query) => $query->orderBy('id'),
            'shirts.shirtType',
            'extras',
        ]);

        $quantities = [];
        foreach ($registration->extras as $extra) {
            $quantities[(int) $extra->show_extra_id] = (int) ($extra->num_extras ?? 0);
        }

        return $this->price(
            $show,
            $this->allowedCarCount($show, $registration),
            $registration->guests->map(fn (UserGuest $guest): UserGuestType => $guest->type_id)->values()->all(),
            $registration->shirts
                ->map(fn ($shirt): string => $shirt->price ?? ($shirt->shirtType === null ? '0' : (string) $shirt->shirtType->price))
                ->values()
                ->all(),
            $quantities,
            (int) $registration->donation,
            $registration,
        );
    }

    /**
     * The quote payload: category totals, summary and line items. Money is a 2-decimal string.
     *
     * @return array<string, mixed>
     */
    public static function payment(PricingResult $result): array
    {
        return [
            'cars' => RegistrationPricing::decimal($result->carsCents),
            'guests' => RegistrationPricing::decimal($result->guestsCents),
            'shirts' => RegistrationPricing::decimal($result->shirtsCents),
            'extras' => RegistrationPricing::decimal($result->extrasCents),
            'donation' => RegistrationPricing::decimal($result->donationCents),
            'total' => RegistrationPricing::decimal($result->totalCents),
            'received' => RegistrationPricing::decimal($result->receivedCents),
            'discount' => RegistrationPricing::decimal($result->discountCents),
            'owed' => RegistrationPricing::decimal($result->owedCents),
            'freeGuests' => $result->freeGuestSlots,
            'lineItems' => array_map(
                fn (LineItem $item): array => [
                    'kind' => $item->kind->value,
                    'label' => $item->label ?? ucfirst($item->kind->value),
                    'quantity' => $item->quantity,
                    'unit' => RegistrationPricing::decimal($item->unitCents),
                    'amount' => RegistrationPricing::decimal($item->totalCents),
                    'free' => $item->free,
                ],
                $result->lineItems,
            ),
        ];
    }

    /**
     * @param  list<UserGuestType>  $guests
     * @param  list<string>  $shirtPrices
     * @param  array<int, int>  $quantities  show_extra_id => quantity
     */
    private function price(
        CarShow $show,
        int $carCount,
        array $guests,
        array $shirtPrices,
        array $quantities,
        int $donation,
        ?ShowRegistration $registration,
    ): PricingResult {
        $prices = $quantities === []
            ? []
            : ShowExtra::query()->whereIn('id', array_keys($quantities))->pluck('price', 'id')->all();

        $extras = [];
        foreach ($quantities as $extraId => $quantity) {
            $extras[] = ExtraLine::fromDecimal((string) ($prices[$extraId] ?? '0'), $quantity);
        }

        try {
            return $this->calculator->calculate(new PricingInput(
                carPriceCents: Money::toCents($show->price_car ?? '0'),
                adultPriceCents: Money::toCents($show->price_adult ?? '0'),
                childPriceCents: Money::toCents($show->price_child ?? '0'),
                carCount: $carCount,
                guests: $guests,
                shirtPricesCents: array_map(fn (string $price): int => Money::toCents($price), $shirtPrices),
                extras: $extras,
                donationCents: $donation * 100,
                discountCents: RegistrationPricing::signedCents($registration === null ? null : (string) $registration->discount),
                receivedCents: RegistrationPricing::signedCents($registration === null ? null : (string) $registration->amount_received),
            ));
        } catch (InvalidArgumentException) {
            throw RegistrationRejected::at('general.amounts', 'The registration amounts cannot be priced. Please contact us.');
        }
    }

    /** Cars of the registration whose body is allowed for the show (legacy carsByShow). */
    private function allowedCarCount(CarShow $show, ShowRegistration $registration): int
    {
        $allowed = array_flip(
            DB::table('car_body_show')->where('show_id', $show->id)->pluck('body_id')->map(fn ($id): int => (int) $id)->all(),
        );

        return $registration->cars->filter(fn ($car): bool => isset($allowed[(int) $car->body_id]))->count();
    }

    /**
     * @return array<int, string> shirt_type id => price for the posted types
     */
    private function shirtTypePrices(RegistrationData $data): array
    {
        $ids = array_values(array_unique(array_map(fn (ShirtInput $shirt): int => $shirt->typeId, $data->shirts)));

        if ($ids === []) {
            return [];
        }

        return ShirtType::query()->whereIn('id', $ids)->get(['id', 'price'])
            ->mapWithKeys(fn (ShirtType $type): array => [(int) $type->id => (string) $type->price])
            ->all();
    }
}
