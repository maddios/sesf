# Model conventions

Contract for every Eloquent model over the legacy schema (`docs/legacy/schema-sesf_dev.sql`).
Follow it exactly when adding or changing a model.

## Class shape

- Namespace `App\Models`, one class per table (`show_registration_car` becomes `ShowRegistrationCar`).
- `use HasLegacyDates;` (App\Models\Concerns\HasLegacyDates) on every model, no exceptions.
- `protected $table = '...';` always explicit.
- `protected $primaryKey = '...';` only when the primary key is not `id`.
- `public $timestamps = true;` only if the table has BOTH `created_at` and `updated_at` (32 of 65 tables).
  Otherwise write `public $timestamps = false;` explicitly.
- `protected $guarded` is set per model (see Guarded attributes below). Never `$fillable`, never `[]`.
  Controllers and Form Requests pass only `$request->validated()`, never `$request->all()`.
- Sensitive attributes go in `$hidden`: `user.password`, `user.remember_token`, `login_email_signin.token_hash`,
  `code_hash`, `request_ip`, `paypal_payment` identity and raw-post columns, `waiver.waiver_data`, `waiver.tracking_id` and `waiver.request_waiver_url` (sign-link token, read via `getAttribute()` or `signUrl()`),
  `user_guest.signature_image`. Each hidden model has a test that toArray() and toJson() omit them.
- `ShowExtra.auth_key` is NOT hidden on purpose: it is the public key in extras landing URLs.

## Guarded attributes

- Default: `['id']` (or the real primary key). Use it when every column is plain data that a validated
  request may set.
- Add a column to `$guarded` when it is one of these:
  - identity or ownership of a row (`user.id`, `user.is_admin`, `user.type_id`, `answer.user_id`,
    `gallery.user_id`, `gallery_item.user_id`, `car.user_id`, `shirt_adhoc_sale.created_by_user_id`).
    `user_preferences.user_id` stays fillable: UserObserver creates the row with firstOrCreate(['user_id']);
  - credentials and tokens (`user.password`, `user.remember_token`, `login_email_signin.used_at`);
  - workflow state set by the app, not by the user (`waiver.has_signed`, `signed_at`, `waiver_id`,
    `tracking_id`, `waiver_data`, `has_pdf`; `user_guest.has_signed`, `signature_image`, `waiver_id`;
    `shirt.is_handed_out`; `car_show.is_active`, `is_judging_released`; `car.is_confirmed`;
    `sponsor.is_enabled`; `show_sponsor.is_primary`; `gallery.is_enabled`; `answer.deleted_at`;
    `user.deleted_at`, `user.date_registered`);
  - money (`shirt.price`, `shirt_type.price`, `show_extra.price`, `expense.amount`, `car_show.price_*`).
- `paypal_payment` is `['*']`: IPN rows are written only with `forceFill()` after the post is verified.
- Factories are unguarded, so tests can create any state. Application code must use setters,
  `forceFill()` or a query-builder update for guarded columns. `fill()` and `create()` drop them silently,
  except on a fully guarded model (`['*']`, paypal_payment), where they throw MassAssignmentException.
- Each model with guarded privilege or money columns has a test that `fill()` does not set them.

## Enum casts

- A column WITH a foreign key keeps the native enum cast (`'type_id' => UserType::class`). The database
  guarantees the value exists.
- A column WITHOUT a foreign key uses `LegacyEnum::of(EnumClass::class)` (App\Models\Casts\LegacyEnum).
  An unknown stored value reads as null instead of throwing ValueError. The enum must be int-backed and
  use `TriesLegacyValues`.
- In the owned models every enum column has a foreign key, so none uses LegacyEnum yet.

## Casts

- Money: `float(6,2)` columns use `'price' => Money2::class` (App\Models\Casts\Money2). No float math on money.
  Pass the column limit so writes are checked: `Money2::class.':9999.99'` for float(6,2), `':999999.99'` for
  float(8,2), `':99999999.99'` for double(10,2). Strict MySQL rejects out-of-range values with an error; it does not clamp them.
- `tinyint(1)` flags: `'boolean'`.
- `date` columns: `'date'`. `datetime` and `timestamp` columns: `'datetime'`.
- Use only the `date` and `datetime` casts. The `timestamp` and `immutable_*` casts are not zero-date safe.
- JSON text columns (e.g. `car_show.shirt_stock_json`): `'array'`.

## Accessors and JSON keys

- Legacy JSON keys are a contract with the Vue SPA. Keep them exactly: `imageURL`, `profileURL`,
  `cityState`, `fullName`. Do not rename them to snake_case.
- Implement each as a legacy getter `getXAttribute()` (e.g. `getCityStateAttribute(): string`) and
  list the key in `protected $appends`.
- Do NOT use `Attribute::make` with camelCase keys. Laravel snake-cases attribute names, so the
  camelCase key is not found in `$appends` and toArray()/toJson() miss it.
- Do not override Laravel internals (e.g. `mutateAttributeForArray`) to get around this.
- Tests assert each key in both `toArray()` and `toJson()`.

## Model PHPDoc

- Every model has a class-level `@property` line for every table column, typed per the DDL and casts
  (`int`, `string`, `bool`, `float`, `string|null` for Money2 casts, `\Carbon\CarbonInterface|null` for
  date/datetime, `array<int|string, mixed>|null` for array casts, enum class for enum casts).
- Every appended accessor gets a `@property-read` line. Include `created_at`/`updated_at` when the table has them.
- Keep one docblock per class: the description and the `@property` lines together.

## Relationships

- Same method names as the legacy relation methods.
- Every relation has a return type (`: BelongsTo`, `: HasMany`, ...).
- Pass explicit foreign keys when the column is not `{model}_id`, e.g. `belongsTo(User::class, 'user_id')`.
- Define both directions, and test both.

## Forbidden in models

- No `request()`, `Request`, `Input::`, `Auth::`, `auth()` or `session()`. Pass the user or context in as arguments.
- No `static` properties or caches.
- File and image URL helpers read `config('sesf.*')` only. No `env()` calls and no hard-coded hosts.

## Factories and tests

- Factory: `database/factories/{Name}Factory.php`, with states for important variants.
  Create parents through their factories so foreign keys hold.
- Test: `tests/Database/Models/{Name}Test.php` (Pest). It covers:
  table, primary key and timestamp config; casts; relationships in both directions;
  accessor JSON keys; scopes; and any legacy business logic.
- Database tests use the `tests/Database` setup (LoadsLegacySchema) on `testing_mariadb` (`sesf_test`).
  Each test runs in a transaction that is rolled back, so do not commit, run DDL or issue `SET` statements.
- Never connect to `sesf_dev`.
- Lookup tables are pre-seeded from `tests/Fixtures/lookup-data.sql`; do not insert them in `tests/factories` or tests.
- Reference them by id, e.g. `UserType::Regular->value`, `CarFactory::TYPE_CAR`, `CarBodyFactory::E24`.
- Regenerate `tests/Fixtures/lookup-data.sql` from the live reference tables when they change.

## Known schema hazards

- Zero dates: 29 columns default to `'0000-00-00 00:00:00'` and real data holds zeros too.
  HasLegacyDates reads them as null. Do not use the `timestamp` cast.
- Money: 15 `float(6,2)` columns. Use Money2, which rounds on decimal strings.
- SQL modes: the `mysql` and `testing_mariadb` connections share one list in config/database.php
  (zero dates allowed, so no NO_ZERO_DATE, NO_ZERO_IN_DATE or ONLY_FULL_GROUP_BY). Laravel's `strict` flag
  is false, so Laravel adds no modes of its own; STRICT_TRANS_TABLES is on through the explicit list.
  live server global sql_mode at audit time: STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION (MariaDB 10.3.39)
- Missing FKs: 38 of 65 tables have no FOREIGN KEY constraints. Integrity is app-side. A `belongsTo`
  may point at a missing row, so treat it as nullable and test it.
- Charset: the connection is utf8 but most tables are latin1 (55 of 65 CREATE statements).
  Check accented-text round-trips before trusting text values.
- Backup and copy tables (`car2`, `sponsor2`, `sponsor_bak`, `show_sponsor_bak`, `user_preferences_bak`, ...):
  do not model them unless a task requires it.
- Laravel 4 `migrations` table: do not model it.
- Flags: about 50 `tinyint(1)` columns; cast them to boolean.
- Reserved words: a column named like a PHP or SQL reserved word is read through `getAttribute()`
  or the query builder. The dump had no PHP-keyword column names when checked.

## Behaviour rules

- Models never mask data per viewer. API Resources decide what each viewer sees. A model never reads Auth or the request.
- Time-dependent logic uses `now()` or `Carbon::today()`, never a cached time. Tests use `Carbon::setTestNow()` or `travelTo()`.

## Test and tooling rules

- Test helpers are typed: `@param array<string, mixed> $overrides` and an explicit return type.
- Avoid chaining `->and()` after a call whose type PHPStan cannot resolve. Write one `expect()` per assertion instead.
- Parallel agents: set a unique `COMPOSE_PROJECT_NAME` for `scripts/test.sh`, and run Pint and PHPStan on your own paths only.
- A relation to a class that does not exist yet gets `// @phpstan-ignore-line later-wave class` on that line.
  Remove the ignore when the class lands. No other ignores are allowed.
