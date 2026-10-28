<?php

namespace App\Services\Registration;

use App\Enums\UserGuestType;
use App\Models\CarShow;
use App\Models\ShowRegistration;
use App\Services\PayPal\RegistrationBalance;
use App\Services\Shirts\ShowShirtCatalog;
use App\Services\Shirts\ShowShirtMenu;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Thin adapter from Eloquent models to PricingCalculator (legacy RegistrationPayment::calculate()).
 *
 * Read-only: nothing here writes to the database, so GET endpoints never change amount_owed
 * (legacy getInvoice did; see docs/pricing.md).
 *
 * Two rules live here, not in the calculator:
 *  - carsByShow: only cars whose body is in car_body_show for the registration's show are priced
 *    (legacy restrictCarsByBodyByShow). Cars linked to the registration but with a disallowed body
 *    are listed by the payload builder, not priced.
 *  - ENDED shows (CarShow::ended): the summary (total, owed, received, discount, donation) is the
 *    STORED amount_* / discount / donation columns, not a recomputation. Line items and category
 *    totals are still computed from the current prices, so they describe the items.
 *
 * Shirt unit price: shirt.price when set (snapshot), else the type's effective price at this event
 * (ShowShirtCatalog: show_shirt_type.price ?? shirt_type.price). A shirt with no type row is priced at 0
 * (legacy crashed on it).
 *
 * Each line item carries `detail` (car, guest name, shirt type, extra name) for the summary and invoice.
 *
 * Money in the output is a decimal string with 2 places ("1234.50", "-30.00"). Signed, because
 * owed is a credit when negative.
 *
 * @phpstan-type Totals array{
 *     source: string,
 *     lineItems: list<array{kind: string, label: string, detail: string|null, quantity: int, unit: string, amount: string}>,
 *     cars: string, guests: string, shirts: string, extras: string,
 *     donation: string, total: string, received: string, discount: string, owed: string
 * }
 */
final class RegistrationPricing implements RegistrationBalance
{
    public const SOURCE_COMPUTED = 'computed';

    public const SOURCE_STORED = 'stored';

    /**
     * The car_body_show body ids per show id, read once per instance (the admin list prices many
     * registrations of the same show).
     *
     * @var array<int, array<int, true>>
     */
    private array $allowedBodies = [];

    /**
     * The shirt menu per show id, read once per instance.
     *
     * @var array<int, ShowShirtMenu>
     */
    private array $menus = [];

    public function __construct(
        private readonly PricingCalculator $calculator = new PricingCalculator,
        private readonly ShowShirtCatalog $shirts = new ShowShirtCatalog,
    ) {}

    /**
     * @return Totals
     */
    public function totalsFor(?ShowRegistration $registration): array
    {
        if ($registration === null) {
            return $this->zero();
        }

        $registration->loadMissing(['carShow', 'cars', 'guests', 'shirts.shirtType', 'extras.showExtra']);
        $show = $registration->carShow;

        if ($show === null) {
            return $this->zero();
        }

        $totals = $this->fromResult($this->calculator->calculate($this->input($registration, $show)), self::SOURCE_COMPUTED);

        return $show->ended ? $this->withStoredSummary($totals, $registration) : $totals;
    }

    /**
     * Signed owed in cents for the PayPal IPN (RegistrationBalance). The IPN calls this after it
     * applied the payment to the model in memory and before saving, so amount_received and
     * discount are read from the current attributes, never re-queried.
     *
     * Not ended: total (from the DB-loaded cars, guests, shirts and extras) minus the current
     * received and discount. Ended: the ORIGINAL stored amount_owed, minus the change in received
     * and discount since the row was loaded. Payments after a show ended are rare, so this keeps
     * the stored owed as the base (see docs/pricing.md).
     */
    public function owedCents(ShowRegistration $registration): int
    {
        $registration->loadMissing(['carShow', 'cars', 'guests', 'shirts.shirtType', 'extras.showExtra']);
        $show = $registration->carShow;

        if ($show === null) {
            return 0;
        }

        if ($show->ended) {
            return self::signedCents((string) $registration->getOriginal('amount_owed'))
                - self::change($registration, 'amount_received')
                - self::change($registration, 'discount');
        }

        return $this->calculator->calculate($this->input($registration, $show))->owedCents;
    }

    /**
     * Signed cents a money column moved since the model was loaded (current minus original).
     */
    public static function change(ShowRegistration $registration, string $column): int
    {
        return self::signedCents((string) $registration->getAttribute($column))
            - self::signedCents((string) $registration->getOriginal($column));
    }

    /**
     * Decimal string with 2 places from integer cents. Negative values keep their sign.
     */
    public static function decimal(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $abs = abs($cents);

        return $sign.intdiv($abs, 100).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Reads a stored decimal that may be negative.
     */
    public static function signedCents(?string $value): int
    {
        return $value === null || $value === '' ? 0 : Money::toSignedCents($value);
    }

    private function input(ShowRegistration $registration, CarShow $show): PricingInput
    {
        $allowedBodies = $this->allowedBodies[(int) $show->id] ??= array_flip(
            DB::table('car_body_show')
                ->where('show_id', $show->id)
                ->pluck('body_id')
                ->map(fn ($id): int => (int) $id)
                ->all(),
        );

        // carsByShow: count only cars whose body is allowed for this show.
        $cars = $registration->cars
            ->filter(fn ($car) => isset($allowedBodies[(int) $car->body_id]))
            ->values();

        $guests = $registration->guests
            ->map(fn ($guest): UserGuestType => $guest->type_id)
            ->values()
            ->all();

        $menu = $this->menus[(int) $show->id] ??= $this->shirts->forShow((int) $show->id);
        $shirts = $registration->shirts->values();

        $extras = $registration->extras
            ->map(fn ($extra): ExtraLine => ExtraLine::fromDecimal(
                $extra->showExtra === null ? '0' : $extra->showExtra->price,
                (int) ($extra->num_extras ?? 0),
                $extra->showExtra?->name,
            ))
            ->values()
            ->all();

        return PricingInput::fromDecimals(
            carPrice: $show->price_car,
            adultPrice: $show->price_adult,
            childPrice: $show->price_child,
            carCount: $cars->count(),
            guests: $guests,
            shirtPrices: $shirts->map(fn ($shirt): string => $menu->linePrice($shirt->price, (int) $shirt->type_id))->all(),
            extras: $extras,
            donation: (int) $registration->donation,
            discount: (string) $registration->discount,
            received: (string) $registration->amount_received,
            carDetails: $cars->map(LineDetails::car(...))->all(),
            guestDetails: $registration->guests->map(LineDetails::guest(...))->values()->all(),
            shirtDetails: $shirts->map(fn ($shirt): ?string => $menu->find((int) $shirt->type_id)?->name)->all(),
        );
    }

    /**
     * @return Totals
     */
    private function fromResult(PricingResult $result, string $source): array
    {
        return [
            'source' => $source,
            'lineItems' => array_map(
                fn (LineItem $item): array => [
                    'kind' => $item->kind->value,
                    'label' => $item->label ?? ucfirst($item->kind->value),
                    'detail' => $item->detail,
                    'quantity' => $item->quantity,
                    'unit' => self::decimal($item->unitCents),
                    'amount' => self::decimal($item->totalCents),
                ],
                $result->lineItems,
            ),
            'cars' => self::decimal($result->carsCents),
            'guests' => self::decimal($result->guestsCents),
            'shirts' => self::decimal($result->shirtsCents),
            'extras' => self::decimal($result->extrasCents),
            'donation' => self::decimal($result->donationCents),
            'total' => self::decimal($result->totalCents),
            'received' => self::decimal($result->receivedCents),
            'discount' => self::decimal($result->discountCents),
            'owed' => self::decimal($result->owedCents),
        ];
    }

    /**
     * ENDED show: summary from the stored columns. total = owed + received + discount, so the
     * summary is internally consistent with what was billed.
     *
     * @param  Totals  $totals
     * @return Totals
     */
    private function withStoredSummary(array $totals, ShowRegistration $registration): array
    {
        $owed = self::signedCents($registration->amount_owed);
        $received = self::signedCents($registration->amount_received);
        $discount = self::signedCents($registration->discount);

        $totals['source'] = self::SOURCE_STORED;
        $totals['donation'] = self::decimal((int) $registration->donation);
        $totals['received'] = self::decimal($received);
        $totals['discount'] = self::decimal($discount);
        $totals['owed'] = self::decimal($owed);
        $totals['total'] = self::decimal($owed + $received + $discount);

        return $totals;
    }

    /**
     * @return Totals
     */
    private function zero(): array
    {
        return [
            'source' => self::SOURCE_COMPUTED,
            'lineItems' => [],
            'cars' => '0.00',
            'guests' => '0.00',
            'shirts' => '0.00',
            'extras' => '0.00',
            'donation' => '0.00',
            'total' => '0.00',
            'received' => '0.00',
            'discount' => '0.00',
            'owed' => '0.00',
        ];
    }
}
