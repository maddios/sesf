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

## Test database

- **Local**: `scripts/test.sh` starts the `testdb` service (compose profile `test`: `mariadb:10.11`,
  tmpfs data, no published port, database `sesf_test`), runs `vendor/bin/pest` in the `app` container
  with `DB_TEST_HOST=testdb`, then removes `testdb`. `KEEP_TESTDB=1` leaves it running.
  It removes only `testdb` (not `compose down`), so a running dev `app` or `vite` is untouched.
- **CI**: the backend job runs the same image as a `services:` container on port 3306, with the same
  healthcheck, and sets `DB_TEST_HOST=127.0.0.1`. The services block cannot pass server flags, so
  the sql_mode is set per connection (`testing_mariadb` in `config/database.php`).
- Tests never use `sesf_dev`. `tests/Support/LegacyTestDatabase.php` refuses any database other than
  `sesf_test`, and checks `select database()` on the live session.
- Schema: `docs/legacy/schema-sesf_dev.sql` (structure only), then `database/sql/*.sql` and `*.sql.done`
  in filename order. Both are loaded once per process by `tests/Support/LoadsLegacySchema.php`.

## Image and advisory checks

- `mariadb:10.11` resolves to 10.11.19. Advisory check (one web search, 2026-10-24): the FreeBSD VuXML
  entry lists six CVEs as fixed in 10.11.19, and Red Hat RHSA-2026:33482 and RHSA-2026:49522 list
  10.11 fixes including CVE-2026-49261 (Galera `wsrep_notify_cmd` only; this test server does not use
  Galera). Recheck the MariaDB 10.11 release notes before bumping the tag.
