# Registration writes

Code: `EventRegistrationWriteController`, `SaveRegistrationRequest` / `UpdateExtraRequest`, `RegistrationWriter`,
`Writer\{RegistrationRules, RegistrationProjector}`. Ports `EventProfile::postRegistration` / `postExtra`.
Web middleware (session + CSRF 419), `auth` (401 JSON). Throttle per user: 30/min for writes, 120/min for quote.

## Endpoints
- `POST event/registration?show_id[&user_id]` body `{registration:{cars:[{id,judging_type}], guests:[{id?,first,last,email?,type,deleted?}],
  shirts:[{id?,type,deleted?}], extras:{<id>:{show_extra_id,num_extras}}, arrival_date:{data:'Y-m-d'}, arrival_time, donation, cancel?}}`.
  200 = exactly the `GET event/registration` payload (the read controller is called). Legacy `{id:x}` objects are accepted for ids.
- `POST event/registration/quote`: same body, same checks (closed shows too), writes nothing.
  200 `{payment:{cars,guests,shirts,extras,donation,total,received,discount,owed,freeGuests,lineItems:[{kind,label,quantity,unit,amount,free}]}}`.
- `POST event/extra?extra_id=<auth_key>[&show_id]` body `{num_extras}`: sets that one extra on the session user's registration.
  200 = the `GET event/extra` payload. Unknown key or other show: 404. No registration: 400 `general.registration`.
- Missing `show_id`: 422; unknown show: 404; admin `user_id` unknown or soft-deleted: 404.

## Error shape (400)
`{cars:{response:{<key>:[msg]},status:'error'}, guest:{<index>:{response:{<field>:[msg]},status:'error'}}, shirt|arrival|extras|donation|general:{...}}`.
Keys are the request path after the section (`0.id`, `1.judging_type`, `<extraKey>.num_extras`, `need_a_car`, `closed`).
Closed: `{general:{response:{closed:['Registration is closed']},status:'error'}}` (legacy put it under `cars`).

## Rules
| Field | Rule |
|---|---|
| actor | session user; admin + `user_id` acts on behalf (and may write when closed). A non-admin's `user_id` is ignored (as in the reads). |
| open | `CarShow::regOpen`, unless admin on behalf. Applies to quote. Not to `event/extra` (legacy had no check). |
| cars | ≥1 unless an admin sends `cancel:true` on an existing registration; ≤20, distinct; owned by the TARGET user (sold cars have no owner); body in `car_body_show`; `judging_type` null or in `judging_show_body` for show+body. |
| guests | ≤50; `type` 1/2; `first`/`last` required, ≤60 (column), Latin-1 letters, digits, space `.'-`; `email` optional, RFC, ASCII, ≤128, not the target user's email (case-insensitive); `id` must be a guest of THIS registration; `deleted:true` removes. |
| shirts | ≤50; `type` exists in `shirt_type`; `id` of THIS registration; new or re-typed shirts snapshot `shirt.price` from `shirt_type.price`. |
| extras | show extra of THIS show and enabled (`event/extra`: may be disabled, it is the landing-page key); `num_extras` integer, 0 removes, else within [min_value, max_value]. |
| donation | null → 0, else one of 0,5,10,15,25,35,50,75,100,150,200,250,300,400,500. |
| arrival | required unless an admin cancels; time in morning/afternoon/evening/night; date `Y-m-d` within `date_start..date_end` (the legacy `showDates`). |
| cancel | Honoured only for an admin on behalf: `true` sets `is_cancelled`, `false` clears it, rows are kept (empty car list keeps the stored cars). A member's `cancel` is accepted and ignored (legacy: policy acknowledgement). |
| cancelled | A cancelled registration is not editable by the member (all three endpoints): 400 `general.cancelled`. Admin on behalf may. |
| totals | total > 9999.99 (float(6,2) columns): 400 `general.total`, nothing written. |

Guests, shirts and extras that are not in the body are kept (legacy). Money keys in the body are ignored.

## Write (one transaction)
1. `SELECT ... FOR UPDATE` on the target `user` row: double submits for one user queue here.
2. Locking read of the (show, user) registration, or create it via `ShowRegistration::save()` (invoice allocator, docs/concurrency.md).
   A `show_id_user_id` unique violation (a writer without the user lock) rolls back and reruns once, which updates.
3. Sync cars (`judging_type_id` on the pivot), guests, shirts, extras; ids re-checked against this registration.
4. Price the stored rows (`RegistrationProjector::stored`, PricingCalculator, always computed), then persist
   `amount_owed` = owed and `is_paid`: false if owed > 0; true if owed ≤ 0 and received > 0; else unchanged.

The quote uses the same projector on the posted state (existing guests in id order, then new ones), so live totals equal
the saved amount (tested).

## Changed vs legacy
- IDOR fixed: any car id, `UserGuest::find` / `UserShirt::find` of other registrations were accepted.
- Donation, extras quantity and guest widths validated (legacy accepted negatives and let MySQL fail with 500).
- `amount_owed` kept in sync on every write; `is_paid` can become true (legacy only cleared it).
- Shirt price snapshot (docs/pricing.md decision 1). Unknown `event/extra` key: 404 (legacy fatal).
- One transaction with locks (legacy: no transaction, duplicate rows on double submit).

## Decisions
- Members cannot cancel (legacy had no cancel handling; `is_cancelled` was admin-only). Arrival before `date_start`
  is rejected. `event/extra` needs neither regOpen nor is_enabled (legacy parity). No multi-process race test.
