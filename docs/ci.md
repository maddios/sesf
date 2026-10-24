# CI

Workflow: `.github/workflows/ci.yml` (PRs and pushes to `master`).

- **backend**: PHP 8.5, `.env` from example, then Pint (`composer lint`), Larastan (`composer analyse`), `composer audit --locked`, and Pest with coverage (`--min=85`).
- **frontend**: Node 24, `npm ci`, then `npm run lint`, `npm run typecheck`, `npm run test:coverage`, `npm audit --audit-level=low`.
- **e2e** (needs backend and frontend): `.env` from example with `DB_CONNECTION=sqlite`, `DB_DATABASE` set to the absolute path `database/database.sqlite` in the workspace (`artisan serve` runs from `public/`, so a relative path would resolve there), `SESSION_DRIVER=file`, `CACHE_STORE=file`, SQLite migrate, build assets, install Playwright Chromium, serve Laravel on port 8000 with `APP_ENV=testing`, run `npm run e2e`. Uploads `playwright-report` on failure.

Local equivalents (repo root):

- Backend: `composer lint`, `composer analyse`, `composer audit --locked`, `composer test:coverage`
- Frontend: `npm run lint`, `npm run typecheck`, `npm run test:coverage`, `npm audit --audit-level=low`
- E2E: `npm run build`, `npx playwright install chromium`, `php artisan serve --port=8000`, `npm run e2e`

## Deviations

- **TypeScript 6.0.3** instead of 7.0.2: typescript-eslint 8.x declares a peer range of `typescript < 6.1`, so 7.x would break lint.
- **PHPUnit 12.5.33** instead of 13.x: Pest 4.7.8 caps PHPUnit at 12.5.x; Pest 5 requires PHPUnit 13.
- **APP_TIMEZONE=EST** is intentional legacy parity (fixed EST, not a zone with DST) pending a decision.

## Test database

- The `Concurrency` testsuite (`tests/Concurrency`) is in `phpunit.xml`, so bare `scripts/test.sh` and CI's `vendor/bin/pest --coverage --min=85` both run it. It spawns child PHP processes that inherit the parent environment (`getenv()`), so `DB_TEST_HOST`/`DB_TEST_PORT` set for the test step reach them.

- **Local**: `scripts/test.sh` starts the `testdb` service (compose profile `test`: `mariadb:10.11.19`,
  tmpfs data, no published port, database `sesf_test`), runs `vendor/bin/pest` in the `test` service
  (same image and uid as `app`, repo bind mount only, no dev-store mounts) with `DB_TEST_HOST=testdb`,
  then removes `testdb`. `KEEP_TESTDB=1` leaves it running.
  It removes only `testdb` (not `compose down`), so a running dev `app` or `vite` is untouched.
- **CI**: the backend job runs the same image as a `services:` container on port 3306, with the same
  healthcheck, and sets `DB_TEST_HOST=127.0.0.1`. The services block cannot pass server flags, so
  the sql_mode is set per connection (`testing_mariadb` in `config/database.php`).
  `CI=true` lets the guard accept `127.0.0.1` and `localhost`; elsewhere only `testdb` is accepted.
- Tests never use `sesf_dev`. `tests/Support/LegacyTestDatabase.php` refuses any database other than
  `sesf_test`, refuses hosts outside the allow-list, and checks `select database()` on the live session
  before every database test (not only once per process).
- The lookup fixture (`tests/Fixtures/lookup-data.sql`) is complete when `user_type` has rows and the last
  table in the fixture (`judging_form_question`) has its expected count. Otherwise the fixture tables are
  truncated and reloaded, and the last table is verified again.
- `tests/TestCase.php` refuses cached config and any default connection other than in-memory sqlite or
  `testing_mariadb`, and fails any HTTP request that was not faked. `phpunit.xml` forces the test env values.
- Schema: `docs/legacy/schema-sesf_dev.sql` (structure only), then `database/sql/*.sql` and `*.sql.done`
  in filename order. Both are loaded once per process by `tests/Support/LoadsLegacySchema.php`.
- Feature tests do not migrate or reset a schema (there are no migrations); each test creates its own tables.
- Destructive DB commands (`migrate:fresh`, `db:wipe`, ...) are prohibited outside the test run
  (`AppServiceProvider::boot`).

## Image and advisory checks

- `mariadb:10.11.19` is pinned in `docker-compose.yml` (`testdb`) and `.github/workflows/ci.yml`. It is the
  newest exact 10.11 patch tag on Docker Hub (checked 2026-10-24 against the Docker Hub tags API; the bare
  `10.11` tag is a floating alias, so it is not pinned).
- Advisories (checked 2026-10-24): the Debian changelog for `mariadb 1:10.11.19-0deb12u1` lists CVE-2026-61081,
  CVE-2026-60585, CVE-2026-60331, CVE-2026-60747, CVE-2026-47023 and CVE-2026-60184 as fixed in 10.11.19.
  Earlier 10.11.x releases carried CVE-2026-49261, CVE-2026-48165 and CVE-2026-48163 (fixed in 10.11.18 per the
  same changelog). The upstream MariaDB 10.11.19 release notes were not retrieved, so recheck them and the CVE
  entries before bumping the tag. CVE-2026-49261 is a Galera `wsrep_notify_cmd` issue; this test server does not
  use Galera.
