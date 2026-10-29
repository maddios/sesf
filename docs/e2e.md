# End-to-end sandbox and browser tests

A throwaway copy of the app with its own database, mail catcher and PayPal stub, plus a Playwright suite
that drives it in Chromium. Nothing in it can reach the shared dev database or a real mail, PayPal, Google,
Datadog or Vanilla account.

## Run it

```sh
scripts/e2e.sh up       # sandbox for manual testing (builds assets if missing, starts, seeds, prints logins)
scripts/e2e.sh test     # up + reseed + Playwright, then down (E2E_KEEP=1 keeps it running)
scripts/e2e.sh test e2e/07-registration.spec.ts   # extra args go to `playwright test`
scripts/e2e.sh reseed   # drop and reseed sesf_e2e, clear sandbox sessions and cache (throttles)
scripts/e2e.sh logs     # follow app, Mailpit, stub and DB logs
scripts/e2e.sh down     # remove containers, volumes and the network
```

`E2E_SHOTS=1 scripts/e2e.sh test` also writes a curated set of screenshots to `docs/e2e-shots/` (git-ignored).
Failure screenshots and traces go to `e2e/artifacts/`, the HTML report to `playwright-report/` (both git-ignored).
`npm run e2e` / `e2e:up` / `e2e:down` call the script (run them on the host, not in the node container).

| URL | What |
|---|---|
| http://localhost:8001 | the app (its `APP_URL`) |
| http://localhost:8025 | Mailpit: every mail the sandbox sends |
| http://localhost:9001/last | PayPal stub: mode, postback count, last body (`POST /control {"mode":"verified"\|"invalid"\|"down"}`, `POST /reset`) |

All three are bound to 127.0.0.1. The database has no published port.

### Seeded logins (password `Password-123` for all)

| Account | Email | Use |
|---|---|---|
| Ada Admin | admin@sesf.test | administrator |
| Mia Member | member@sesf.test | two cars (e24 allowed at the show, e31 not), paid registration on the past event |
| Ben Second | member2@sesf.test | one car, unpaid registration on the past event |
| Pat Payer, Quinn Partial, Ivy Invalid | payer@ / partial@ / invalid@sesf.test | registered for the current event, unpaid (IPN specs) |
| Alex Access | a11y@sesf.test | registered with a child guest and an extra (accessibility and mobile specs) |
| Lou, Cody, Cora, Max, Rita, Gary | lockout@, code@, codelock@, magic@, reset@, garage@sesf.test | one account per stateful spec |
| 8 fillers | filler1..8@sesf.test | member directory paging (21 members, 20 per page) |

Events: **Southeast Sharkfest E2E** (registration open, car 100 / adult 50 / child 25, bodies e24, e63, e64 allowed,
judging classes, agenda of both types, a location, a sponsor of each tier, a checkbox extra at 15 and a numeric extra
at 5 x 0..4) and **Sharkfest E2E Past** (ended, invoices show stored amounts). Ids and paths are in
`e2e/.state/seed.json`, which the specs read.

## How it is isolated

- **Compose project `sesf-e2e`, profile `e2e`** (services `e2edb`, `mailpit`, `e2ecerts`, `paypalstub`, `e2eapp`,
  `e2erunner` in `docker-compose.yml`). The script always passes `-p sesf-e2e`, names the services, and uses
  `--env-file docker/e2e/compose.env`, so the dev project `sesf-new` and the repo `.env` are never involved.
  `down` removes the project's containers, the `e2ecerts` volume and the `sesf-e2e_e2e` network.
- **Database**: `mariadb:10.11.19` on tmpfs, database `sesf_e2e`, root password `e2e`. `scripts/e2e-seed.php`
  refuses to run unless `DB_HOST=e2edb`, `DB_DATABASE=sesf_e2e`, the default connection resolves to exactly that and
  `select database()` agrees. It then drops and recreates `sesf_e2e`, loads `docs/legacy/schema-sesf_dev.sql`,
  `database/sql/` and `tests/Fixtures/lookup-data.sql` through `LegacyTestDatabase::ensureSchema` (whose own
  `sesf_test` guard is untouched) and seeds with the model factories.
- **App environment**: only `docker/e2e/app.env` (fake or public test values). It is the container's `env_file`
  and is also mounted read-only over `/app/.env`, so the repo `.env` is never read. Every secret-bearing key the
  config reads is set explicitly (empty). `artisan serve --no-reload` keeps the container environment (with reload
  on, `serve` strips it from the PHP server process and re-reads the env file). Config/route/event caches point to
  `/tmp`, so a cached config in `bootstrap/cache` cannot leak in. Sessions, cache, logs and upload directories are
  tmpfs inside the container.
- **Mail**: SMTP to Mailpit (no relay configured). `SESF_EMAIL_DEBUG=false`, so mails keep their real recipients,
  which all live in Mailpit.
- **PayPal**: `PAYPAL_IPN_VERIFY_URL=https://paypalstub:9000/cgi-bin/webscr`. The app only posts IPN checks over
  verified TLS to an https URL (`IpnVerifier`), so `e2ecerts` makes a throwaway self-signed cert; only `e2eapp`
  trusts it (system CAs plus that cert, via `docker/e2e/php/e2e.ini`).
- **Turnstile**: Cloudflare's documented always-pass test keys. The registration spec needs outbound HTTPS to
  challenges.cloudflare.com and skips with a message when it is unreachable.
- **Assets**: `public/build` (built with `npm run build` in `node:24.22.1-bookworm-slim`; `test` always rebuilds,
  `E2E_SKIP_BUILD=1` skips). `up` refuses to start while `public/hot` exists (a running dev Vite server would
  otherwise serve the sandbox's assets). Node installs only ever run in the glibc image, and only when
  `node_modules` is missing (the shared `node_modules` has glibc native bindings).
- **Runner**: `mcr.microsoft.com/playwright:v1.64.1-noble`, host network, the repo's `node_modules` mounted
  read-only (ES modules ignore `NODE_PATH`, and the image has no global Playwright). Containers run as the
  invoking user (`E2E_UID`/`E2E_GID`).

The sandbox serves the working tree as it is, so in-progress changes by others show up in it.

## Specs (`e2e/`, one worker, in file order)

| Spec | Covers |
|---|---|
| 01-anonymous | home, events, all event tabs, member directory paging, car filters, member and car profiles, privacy; no seeded email or phone in any member/car/event/home API response or in the DOM; private pages send to sign-in |
| 02-register | account creation with the Turnstile test widget and consent, lands signed in on the new profile, welcome mail (recipient, links on `APP_URL`) and admin notice in Mailpit |
| 03-login | password sign-in, account menu, logout (session really gone), wrong password, throttle message after 10 failures |
| 04-email-signin | emailed code from Mailpit, used link refused afterwards, 5 wrong codes close the request, magic link |
| 05-forgot-password | reset mail (hash URL on `APP_URL`), token removed from the address bar, reset signs in, old password refused, new one works, link single use |
| 06-garage | add a car, upload a JPEG generated in the browser, garage shows the photo, edit in place, mark sold, public page keeps the photo without owner |
| 07-registration | Mia registers (car + judging, adult and child guest, shirt, both extras, arrival, donation); live summary equals the server quote (260.00); billing shows the same total; PayPal form amount uses a '.' decimal |
| 08-paypal-ipn | verified full IPN (postback byte for byte, Paid, customer and admin mail), replay idempotent (no mail), partial payment balance, INVALID and wrong receiver credit nothing, stub down gives 503 |
| 09-authorization | another member's billing/settings/garage (guard), foreign invoice API 404, `user_id` cannot widen reads, anonymous 401s, `/admin-api` 403 for a member and 200 for the admin |
| 10-accessibility | axe (WCAG 2.1 A/AA) on home, events, event, login, register, registration, billing, settings: no serious or critical violations |
| 11-mobile | 390 x 844: no horizontal scroll on home, login, registration; menu button, links, Escape |
| 12-admin-mobile | 390 x 844: every admin page without sideways scroll, one h1, Menu button; admin drawer (Escape, closes after navigating); check-in and T-shirt dialogs as full-width sheets; filter sheet and event combobox; axe (serious/critical) on every admin page |
| 13-event-shirts | admin sets a shirt price and removes a type (Events > Shirts…, axe); Ben sees only the offered types at the event price, summary and invoice name the shirt and car and total 120.00; 390 px dialog sheet (44 px targets) and member pages without sideways scroll (stateful: changes the current event's shirt offer and registers Ben) |

Every test fails on unexpected browser console errors. Always allowed: the 401 of `POST /login/authenticate` for
anonymous visits, 404s for images, and messages from other origins (YouTube, Cloudflare). A test allows its own
expected 4xx explicitly.

Stateful specs (password reset, garage, IPN, event shirts) assume a fresh seed: `scripts/e2e.sh test` reseeds every run; when
running specs by hand against a kept sandbox, run `scripts/e2e.sh reseed` first. Mind the rate limits (login 60 a
minute per IP, password-forgot 6 a minute per IP, account writes): each spec uses its own account.

## Bugs found by E2E

Fixed (frontend, each with a unit test):

1. Registration summary showed "Totals could not be updated" (and a console 400) until the whole form was valid:
   the quote endpoint applies the save checks. The quote is now only requested once a car is chosen and the client
   checks pass (`useRegistrationEditor.ts`, `useRegistrationQuote.ts`).
2. Add/edit car: the Type options were blank (`fetchCarTypes` read `type`, the API sends `name`) (`api/account.ts`).
3. Editing a car created a second car: the client sent `id`, `PUT car/profile` reads `car_id` (`api/account.ts`).
4. Mark as sold answered 404: the client sent `car_id`, `POST member/sell-car` reads `id` (`api/account.ts`).
5. Uploaded car photos never showed on the car page: `toImageUrl` rejected the cache-busting `?123` query (`api/cars.ts`).
6. Car page without a photo squeezed the title to zero width (`pages/cars/CarProfilePage.vue`).
7. A new account landed on the home page instead of its profile: the server's `#/Name/u1` link was rejected by the
   redirect rules (`pages/register/RegisterPage.vue`).
8. Mobile menu: Escape did nothing while focus was on the Menu button (`layouts/AppHeader.vue`).
9. Registration form: an extra with `min_value` 1 that was not chosen (quantity 0) failed the client check, so no quote
   was requested and the form could not be saved; 0 is always allowed, as on the server (`composables/registration/validation.ts`).
9. Events list printed raw `<p>` tags for a show without a short description (`components/events/EventCard.vue`).

## Known issues found by E2E (PHP, not fixed here)

- **KI-1** `app/Http/Requests/Account/RegisterRequest.php:28-29`: `first_name`/`last_name` regex
  `^[a-zA-Z0-9.\- ]+$` rejects apostrophes. Steps: register as "Nora O'Shea". Expected: account created (guest
  names allow `'`, and the browser-side check accepts it). Actual: 400 "The last name field format is invalid."
  Spec `02-register` "accepts an apostrophe..." is `test.fixme` until fixed.
- Contract note (no failure left): `PUT car/profile` identifies the car by `car_id`
  (`app/Http/Requests/Cars/CarProfileRequest.php:55`) while `POST member/sell-car` uses `id`
  (`app/Http/Requests/Cars/SellCarRequest.php`); the client now sends each what it reads.
- Contract note: `CarShow::getShortDescriptionAttribute` (`app/Models/CarShow.php:124`) falls back to the first 350
  bytes of the HTML description, tags included (the client now strips them for display).

## Images and advisories (checked 2026-10-25)

- `axllent/mailpit:v1.32.0` (released 2026-10-22, newest stable tag). GitHub advisories for axllent/mailpit: all
  17 published ones are patched at or below 1.32.0; the newest, GHSA-379p-g4f4-hq5h (high, thumbnail CPU/memory),
  is fixed in 1.32.0. Runs as uid 1000, UI bound to 127.0.0.1, no relay.
- `mariadb:10.11.19`: same tag as `testdb`, still the newest exact 10.11 tag on Docker Hub (10.11.19, 2026-10-17);
  advisories as recorded in `docs/ci.md`.
- `node:24.22.1-alpine` (stub, no packages installed) and `node:24.22.1-bookworm-slim` (builds): Node 24.22.1, the
  same version as the existing `node:24` image. `mcr.microsoft.com/playwright:v1.64.1-noble` matches
  `@playwright/test` 1.64.1. No npm dependencies were added.
