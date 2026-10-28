<?php

namespace App\Services\Registration\Writer;

use App\Enums\UserGuestType;
use App\Models\Car;
use App\Models\CarShow;
use App\Models\ShowExtra;
use App\Models\ShowRegistration;
use App\Models\UserGuest;
use App\Services\Registration\ExtraLine;
use App\Services\Registration\LineDetails;
use App\Services\Registration\LineItem;
use App\Services\Registration\PricingCalculator;
use App\Services\Registration\PricingInput;
use App\Services\Registration\PricingResult;
use App\Services\Registration\RegistrationPricing;
use App\Services\Shirts\ShowShirtCatalog;
use App\Support\Money;
use Illuminate\Support\Collection;
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
 * shirt price = shirt.price snapshot or else the event's price for the type (ShowShirtCatalog), extras
 * show_extra.price x num_extras. Line items carry `detail` (car, guest name, shirt type, extra name).
 */
final class RegistrationProjector
{
    public function __construct(
        private readonly PricingCalculator $calculator = new PricingCalculator,
        private readonly ShowShirtCatalog $shirts = new ShowShirtCatalog,
    ) {}

    /**
     * @throws RegistrationRejected when a stored price cannot be priced (negative)
     */
    public function quote(CarShow $show, ?ShowRegistration $existing, RegistrationData $data): PricingResult
    {
        if ($existing !== null) {
            $existing->loadMissing(['cars', 'guests', 'shirts', 'extras']);
        }

        $cars = $data->keepsStoredCars() && $existing !== null
            ? $this->allowedCars($show, $existing)
            : $this->postedCars($data);

        // Guests: existing (id order) with posted changes, then new guests in posted order.
        /** @var array<int, array{UserGuestType, string|null}> $guests */
        $guests = [];
        foreach ($existing?->guests->sortBy('id') ?? [] as $guest) {
            /** @var UserGuest $guest */
            $guests[(int) $guest->id] = [$guest->type_id, LineDetails::guest($guest)];
        }
        $newGuests = [];
        foreach ($data->guests as $guest) {
            if ($guest->id !== null) {
                if ($guest->deleted) {
                    unset($guests[$guest->id]);
                } elseif (array_key_exists($guest->id, $guests)) {
                    $guests[$guest->id] = [$guest->type, LineDetails::person($guest->first, $guest->last)];
                }
            } elseif (! $guest->deleted) {
                $newGuests[] = [$guest->type, LineDetails::person($guest->first, $guest->last)];
            }
        }

        // Shirts: existing keep their price unless the type changes; new ones take the event's price for the type.
        $menu = $this->shirts->forShow((int) $show->id);
        /** @var array<int, array{int, string}> $shirts id => [type id, unit price] */
        $shirts = [];
        foreach ($existing?->shirts->sortBy('id') ?? [] as $shirt) {
            $shirts[(int) $shirt->id] = [(int) $shirt->type_id, $menu->linePrice($shirt->price, (int) $shirt->type_id)];
        }
        $newShirts = [];
        foreach ($data->shirts as $shirt) {
            if ($shirt->id !== null) {
                if ($shirt->deleted) {
                    unset($shirts[$shirt->id]);
                } elseif (array_key_exists($shirt->id, $shirts) && $shirts[$shirt->id][0] !== $shirt->typeId) {
                    $shirts[$shirt->id] = [$shirt->typeId, $menu->priceOf($shirt->typeId) ?? '0.00'];
                }
            } elseif (! $shirt->deleted) {
                $newShirts[] = [$shirt->typeId, $menu->priceOf($shirt->typeId) ?? '0.00'];
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
            $cars,
            array_merge(array_values($guests), $newGuests),
            array_map(fn (array $shirt): array => [$shirt[1], $menu->find($shirt[0])?->name], array_merge(array_values($shirts), $newShirts)),
            $quantities,
            $data->donation,
            $existing,
        );
    }

    /**
     * The registration as stored. Relations are reloaded, so call it after the writes.
     *
     * @throws RegistrationRejected when a stored price cannot be priced (negative)
     */
    public function stored(CarShow $show, ShowRegistration $registration): PricingResult
    {
        $registration->unsetRelations();
        $registration->load([
            'cars',
            'guests' => fn ($query) => $query->orderBy('id'),
            'shirts' => fn ($query) => $query->orderBy('id'),
            'extras',
        ]);

        $quantities = [];
        foreach ($registration->extras as $extra) {
            $quantities[(int) $extra->show_extra_id] = (int) ($extra->num_extras ?? 0);
        }
        $menu = $this->shirts->forShow((int) $show->id);

        return $this->price(
            $show,
            $this->allowedCars($show, $registration),
            $registration->guests->map(fn (UserGuest $guest): array => [$guest->type_id, LineDetails::guest($guest)])->values()->all(),
            $registration->shirts
                ->map(fn ($shirt): array => [$menu->linePrice($shirt->price, (int) $shirt->type_id), $menu->find((int) $shirt->type_id)?->name])
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
                    'detail' => $item->detail,
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
     * @param  Collection<int, Car>  $cars  the priced cars
     * @param  list<array{UserGuestType, string|null}>  $guests  [type, detail] in pricing order
     * @param  list<array{string, string|null}>  $shirts  [unit price, type name]
     * @param  array<int, int>  $quantities  show_extra_id => quantity
     */
    private function price(
        CarShow $show,
        Collection $cars,
        array $guests,
        array $shirts,
        array $quantities,
        int $donation,
        ?ShowRegistration $registration,
    ): PricingResult {
        $known = $quantities === []
            ? collect()
            : ShowExtra::query()->whereIn('id', array_keys($quantities))->get(['id', 'name', 'price'])->keyBy('id');

        $extras = [];
        foreach ($quantities as $extraId => $quantity) {
            /** @var ShowExtra|null $extra */
            $extra = $known->get($extraId);
            $extras[] = ExtraLine::fromDecimal((string) ($extra->price ?? '0'), $quantity, $extra?->name);
        }

        try {
            return $this->calculator->calculate(new PricingInput(
                carPriceCents: Money::toCents($show->price_car ?? '0'),
                adultPriceCents: Money::toCents($show->price_adult ?? '0'),
                childPriceCents: Money::toCents($show->price_child ?? '0'),
                carCount: $cars->count(),
                guests: array_column($guests, 0),
                shirtPricesCents: array_map(fn (array $shirt): int => Money::toCents($shirt[0]), $shirts),
                extras: $extras,
                donationCents: $donation * 100,
                discountCents: RegistrationPricing::signedCents($registration === null ? null : (string) $registration->discount),
                receivedCents: RegistrationPricing::signedCents($registration === null ? null : (string) $registration->amount_received),
                carDetails: $cars->map(LineDetails::car(...))->values()->all(),
                guestDetails: array_column($guests, 1),
                shirtDetails: array_column($shirts, 1),
            ));
        } catch (InvalidArgumentException) {
            throw RegistrationRejected::at('general.amounts', 'The registration amounts cannot be priced. Please contact us.');
        }
    }

    /**
     * Cars of the registration whose body is allowed for the show (legacy carsByShow).
     *
     * @return Collection<int, Car>
     */
    private function allowedCars(CarShow $show, ShowRegistration $registration): Collection
    {
        $allowed = array_flip(
            DB::table('car_body_show')->where('show_id', $show->id)->pluck('body_id')->map(fn ($id): int => (int) $id)->all(),
        );

        return $registration->cars->filter(fn ($car): bool => isset($allowed[(int) $car->body_id]))->values();
    }

    /**
     * The posted cars in posted order (the request already checked ownership and body).
     *
     * @return Collection<int, Car>
     */
    private function postedCars(RegistrationData $data): Collection
    {
        $ids = array_map(fn (CarInput $car): int => $car->id, $data->cars);
        $byId = $ids === [] ? collect() : Car::query()->whereIn('id', $ids)->get()->keyBy('id');

        return collect($ids)->map(fn (int $id): Car => $byId->get($id) ?? new Car)->values();
    }
}
