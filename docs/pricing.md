# Registration pricing

`App\Services\Registration\PricingCalculator` ports legacy `RegistrationPayment::calculate()`
(`app/controllers/RegistrationPayment.php`). It is pure PHP: no Eloquent, DB or facades. You pass it a
`PricingInput` and get back a `PricingResult` with line items, subtotals, total, owed and free-guest slots.

## Rules (verified against the legacy code)

| Item | Rule |
|---|---|
| Cars | `car_show.price_car` per car. Legacy counts only cars whose body is in `car_body_show` for that show (`carsByShow`). The caller passes that filtered count. |
| Free guests | `free = max(0, cars - 1)`. The first car gives no free guest, and each further car gives one. With 0 cars there are 0 free guests. |
| Guests | In registration order (`user_guest.id`), guests are free while slots remain, of any type. After that, Adult pays `price_adult` and every other type pays `price_child`. Unused slots are lost. |
| Shirts | `shirt_type.price` per shirt. This is the current global price. The `shirt.price` column is not read. |
| Extras | `show_extra.price × num_extras`. `ExtraType` does not affect the price. A NULL `num_extras` counts as 0. |
| Donation | `show_registration.donation`, added to the total. |
| Owed | `total − (amount_received + discount)`. Owed is signed: a negative value is a credit (overpaid, or the discount is bigger than the total). |

Cancelled registrations: the formula ignores `is_cancelled`. Legacy only hides them (`getInvoice` filters
`is_cancelled = 0`), so their stored `amount_owed` is frozen at the last invoice view. `$show[0]` is the
registration's show. A missing show would make every price 0 (`@` suppression), but the FK prevents that.

## Rounding

Legacy adds PHP floats and never rounds. MySQL rounds once, when it stores `float(6,2)`, and values above
9999.99 are clipped. The new code converts each decimal to integer cents with `Money::toCents` (half up:
12.345 becomes 12.35) when the input is built, then uses only integer math. Every legacy price column
has 2 decimals, so the cents results equal the legacy results.

## Reproduced vs deliberately fixed

- **Reproduced:** every rule in the table above, including the guest-order dependence, signed owed, and
  non-adult types priced as child.
- **Fixed:** negative counts, quantities, prices, donation, discount or received now throw
  `InvalidArgumentException`. Legacy accepted them, and dev holds 3 rows with `discount = -30`.
- **Fixed:** owed is computed whenever it is needed. Legacy wrote `amount_owed` only during
  `GET getInvoice` (`updateInvoiceAmounts`) and in the PayPal IPN, so the stored value goes stale.
- **Not ported (bugs for the payment work):** PayPal IPN does `amount_owed -= cumulative received`
  (double subtraction). Its paid test also uses `total = received_actual(net) + mc_gross`, so a partial
  payment can set `is_paid = 1`. `update_is_paid()` only ever sets `is_paid = 0`.
- **Edge case:** a shirt whose `shirt_type` row is missing (type_id 0) crashes legacy (Laravel turns the
  notice into an exception). Callers should price it at 0 or reject it.

## Parity with sesf_dev (`php scripts/pricing-parity.php`, read-only)

Stored `amount_owed` is compared with the calculated owed. Each row lands in the first matching category.

| Category | Active | Cancelled | Explanation |
|---|---:|---:|---|
| 0 exact match | 516 | 19 | |
| S shirt price raised later | 404 | 10 | `shirt_type` is global: 35 now, cheaper back then. The difference is m shirts × a per-show delta, from $14 (2009) down to $3 (show 35). |
| A owed never written | 58 | 37 | No payment was made and `getInvoice` was never opened, so the stored value is still 0. |
| I stored owed lower | 116 | 8 | Older prices or items, admin edits, or 2009 data imported from the pre-Laravel system. |
| H paid, owed zeroed, short | 56 | 2 | An admin marked it paid, or the PayPal paid-test bug did. |
| K paid, received > total | 58 | 1 | Items removed after payment, or duplicate IPN/manual payments. |
| J stored owed higher | 30 | 0 | Items removed or prices lowered after the last invoice view. |
| B payment after last view | 12 | 0 | Admin entered `amount_received`, and `amount_owed` was never recomputed. |
| C discount after last view | 5 | 0 | Admin set `discount`, and `amount_owed` was never recomputed. |
| N negative stored discount | 3 | 0 | Legacy accepted it. The new validation rejects it. |
| F car body not allowed | 2 | 0 | The car is linked but its body is not in `car_body_show`, so legacy excludes it. Stored values used it. |
| **Total** | **1260** | **77** | |

The newest show (36), which has had no price change, matches exactly on 30 of 36 registrations. Five
of the other six are category A. The PayPal double-subtraction pattern never matched exactly
(0 rows), so it is folded into I/J. Using calculated owed, `is_paid=1` with owed > 0 occurs in 583 active
rows, mostly because of S. Recalculating old invoices at today's prices is wrong.

Hypotheses (`--shirt-price=snapshot`, `--free-guests=most-expensive`; exact matches active/cancelled):
type+first (legacy) 516/19 · snapshot 515/18 · most-expensive 516/19 (identical buckets).
`shirt.price` is set on only 78 of 1304 rows, all in show 3 (28.00, current type 35.00). Legacy
`postRegistration` never writes it. Using it lost one match, so no historic snapshot exists to recover S.
Guest order affects no stored row: 33 registrations have ≥2 cars and guests, and 0 mix adults and
children beyond their free slots. The data cannot distinguish the two orders, so legacy order stays.

## Decisions needed

1. Snapshot unit prices per registration line, for example by writing `shirt.price` again, so that later
   price changes do not alter past invoices.
2. Decide whether `amount_owed` remains a stored column or is always derived.
3. Free-slot order (first vs most expensive) is a policy choice. No stored row depends on it.
