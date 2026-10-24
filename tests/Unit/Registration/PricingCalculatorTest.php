<?php

use App\Enums\UserGuestType;
use App\Services\Registration\ExtraLine;
use App\Services\Registration\LineItem;
use App\Services\Registration\LineItemKind;
use App\Services\Registration\PricingCalculator;
use App\Services\Registration\PricingInput;

const ADULT = UserGuestType::Adult;
const CHILD = UserGuestType::Child;

/**
 * Show prices used throughout: car $150, adult $150, child $75 (car_show 36).
 *
 * @param  list<UserGuestType>  $guests
 * @param  list<int>  $shirts
 * @param  list<ExtraLine>  $extras
 */
function pricingInput(int $cars, array $guests = [], array $shirts = [], array $extras = [], int $donation = 0, int $discount = 0, int $received = 0): PricingInput
{
    return new PricingInput(15000, 15000, 7500, $cars, $guests, $shirts, $extras, $donation, $discount, $received);
}

dataset('pricing cases', [
    // name => [input, total, owed, free slots, free slots used, cars, guests, shirts, extras]
    'zero cars: no free guests, every guest priced' => [
        fn () => pricingInput(0, [ADULT, CHILD]), 22500, 22500, 0, 0, 0, 22500, 0, 0,
    ],
    'zero cars, nothing else: total zero' => [
        fn () => pricingInput(0), 0, 0, 0, 0, 0, 0, 0, 0,
    ],
    'one car: first car grants no free guest' => [
        fn () => pricingInput(1, [ADULT, CHILD]), 37500, 37500, 0, 0, 15000, 22500, 0, 0,
    ],
    'two cars: one free guest (the first one)' => [
        fn () => pricingInput(2, [ADULT, CHILD]), 37500, 37500, 1, 1, 30000, 7500, 0, 0,
    ],
    'free slot goes to the first guest even if cheaper (legacy order rule)' => [
        fn () => pricingInput(2, [CHILD, ADULT]), 45000, 45000, 1, 1, 30000, 15000, 0, 0,
    ],
    '3 cars + 5 mixed guests: 2 free, then priced by type' => [
        fn () => pricingInput(3, [ADULT, CHILD, ADULT, CHILD, CHILD]), 75000, 75000, 2, 2, 45000, 30000, 0, 0,
    ],
    'more free slots than guests: unused slots are lost' => [
        fn () => pricingInput(4, [CHILD]), 60000, 60000, 3, 1, 60000, 0, 0, 0,
    ],
    'shirts and extras (price x quantity, zero quantity is free)' => [
        fn () => pricingInput(1, [], [3500, 5500], [new ExtraLine(2000, 3), new ExtraLine(999, 0)]), 30000, 30000, 0, 0, 15000, 0, 9000, 6000,
    ],
    'donation is added to the total' => [
        fn () => pricingInput(1, donation: 5000), 20000, 20000, 0, 0, 15000, 0, 0, 0,
    ],
    'owed = total - (received + discount)' => [
        fn () => pricingInput(1, [ADULT], donation: 1000, discount: 2500, received: 10000), 31000, 18500, 0, 0, 15000, 15000, 0, 0,
    ],
    'discount larger than total: owed goes negative (credit), as legacy' => [
        fn () => pricingInput(1, discount: 20000), 15000, -5000, 0, 0, 15000, 0, 0, 0,
    ],
    'overpayment: owed negative' => [
        fn () => pricingInput(1, received: 15001), 15000, -1, 0, 0, 15000, 0, 0, 0,
    ],
]);

it('prices registrations', function (Closure $make, int $total, int $owed, int $slots, int $used, int $carsC, int $guestsC, int $shirtsC, int $extrasC) {
    $result = (new PricingCalculator)->calculate($make());

    expect($result->totalCents)->toBe($total)
        ->and($result->owedCents)->toBe($owed)
        ->and($result->freeGuestSlots)->toBe($slots)
        ->and($result->freeGuestSlotsUsed)->toBe($used)
        ->and($result->carsCents)->toBe($carsC)
        ->and($result->guestsCents)->toBe($guestsC)
        ->and($result->shirtsCents)->toBe($shirtsC)
        ->and($result->extrasCents)->toBe($extrasC)
        ->and($result->isSettled())->toBe($owed <= 0)
        ->and(array_sum(array_map(fn (LineItem $i) => $i->totalCents, $result->lineItems)))->toBe($total);
})->with('pricing cases');

it('itemises every car, guest, shirt, extra and the donation', function () {
    $result = (new PricingCalculator)->calculate(
        pricingInput(2, [CHILD, ADULT], [3500], [new ExtraLine(2000, 2)], donation: 500, discount: 100, received: 200),
    );

    expect(array_map(fn (LineItem $i) => [$i->kind, $i->unitCents, $i->quantity, $i->totalCents, $i->free, $i->label], $result->lineItems))
        ->toBe([
            [LineItemKind::Car, 15000, 1, 15000, false, null],
            [LineItemKind::Car, 15000, 1, 15000, false, null],
            [LineItemKind::Guest, 0, 1, 0, true, 'Child'],
            [LineItemKind::Guest, 15000, 1, 15000, false, 'Adult'],
            [LineItemKind::Shirt, 3500, 1, 3500, false, null],
            [LineItemKind::Extra, 2000, 2, 4000, false, null],
            [LineItemKind::Donation, 500, 1, 500, false, null],
        ])
        ->and($result->itemsOfKind(LineItemKind::Guest))->toHaveCount(2)
        ->and($result->itemsOfKind(LineItemKind::Donation)[0]->totalCents)->toBe(500)
        ->and($result->donationCents)->toBe(500)
        ->and($result->discountCents)->toBe(100)
        ->and($result->receivedCents)->toBe(200)
        ->and($result->owedCents)->toBe(53000 - 300);
});

it('omits the donation line when there is no donation', function () {
    $result = (new PricingCalculator)->calculate(pricingInput(1));

    expect($result->itemsOfKind(LineItemKind::Donation))->toBe([]);
});

dataset('decimal rounding', [
    // Money::toCents rounds half up once, at conversion; there is no rounding afterwards.
    '12.345 rounds up to 12.35' => ['12.345', 1235],
    '12.344 rounds down to 12.34' => ['12.344', 1234],
    '0.005 rounds up to a cent' => ['0.005', 1],
    'float 19.99 stays exact' => [19.99, 1999],
    'legacy float(6,2) max' => ['9999.99', 999999],
]);

it('converts legacy decimal amounts to cents half up', function (string|float $price, int $cents) {
    $input = PricingInput::fromDecimals($price, $price, $price, 3, [ADULT], [$price], [ExtraLine::fromDecimal($price, 3)], 0, $price, $price);
    $result = (new PricingCalculator)->calculate($input);

    // 3 cars + 1 shirt + 3 extras (the one guest is free) = 7 units; owed = 7 - 2 units.
    expect($input->carPriceCents)->toBe($cents)
        ->and($input->shirtPricesCents)->toBe([$cents])
        ->and($input->extras[0]->priceCents)->toBe($cents)
        ->and($result->totalCents)->toBe(7 * $cents)
        ->and($result->owedCents)->toBe(5 * $cents);
})->with('decimal rounding');

it('sums per-item cents, so float drift cannot creep in', function () {
    // 0.1 + 0.2 != 0.3 in floats; ten 0.10 shirts must be exactly 1.00.
    $input = PricingInput::fromDecimals('0', '0', '0', 0, [], array_fill(0, 10, 0.1));

    expect((new PricingCalculator)->calculate($input)->totalCents)->toBe(100);
});

it('treats null show prices as zero and accepts an integer donation', function () {
    $input = PricingInput::fromDecimals(null, null, null, 1, [ADULT, CHILD], donation: 25);

    expect($input->carPriceCents)->toBe(0)
        ->and($input->adultPriceCents)->toBe(0)
        ->and($input->childPriceCents)->toBe(0)
        ->and($input->donationCents)->toBe(2500)
        ->and((new PricingCalculator)->calculate($input)->totalCents)->toBe(2500);
});

dataset('invalid inputs', [
    'negative car price' => [fn () => new PricingInput(-1, 0, 0, 0)],
    'negative adult price' => [fn () => new PricingInput(0, -1, 0, 0)],
    'negative child price' => [fn () => new PricingInput(0, 0, -1, 0)],
    'negative car count' => [fn () => new PricingInput(0, 0, 0, -1)],
    'negative donation' => [fn () => pricingInput(1, donation: -1)],
    'negative discount' => [fn () => pricingInput(1, discount: -1)],
    'negative received' => [fn () => pricingInput(1, received: -1)],
    'guest that is not a UserGuestType' => [fn () => new PricingInput(0, 0, 0, 0, [1])], // @phpstan-ignore argument.type
    'negative shirt price' => [fn () => pricingInput(1, [], [-100])],
    'non-integer shirt price' => [fn () => new PricingInput(0, 0, 0, 0, [], [35.0])], // @phpstan-ignore argument.type
    'extra that is not an ExtraLine' => [fn () => new PricingInput(0, 0, 0, 0, [], [], [[100, 1]])], // @phpstan-ignore argument.type
    'negative extra price' => [fn () => new ExtraLine(-1, 1)],
    'negative extra quantity' => [fn () => new ExtraLine(100, -1)],
    'negative decimal donation' => [fn () => PricingInput::fromDecimals('1', '1', '1', 1, donation: '-5')],
    'negative decimal discount' => [fn () => PricingInput::fromDecimals('1', '1', '1', 1, discount: '-0.01')],
    'negative decimal price' => [fn () => PricingInput::fromDecimals('-65', '1', '1', 1)],
    'negative decimal extra price' => [fn () => ExtraLine::fromDecimal('-1', 1)],
]);

it('rejects invalid input', function (Closure $make) {
    $make();
})->with('invalid inputs')->throws(InvalidArgumentException::class);
