# AGENTS.md

## What this is

sesf-new is the rewrite of the Laravel 4.2 SESF app. Plan: Laravel 13 on PHP 8.5, with a
Vue 3 SPA using hash routing so existing `/#/` URLs and the old JSON API contract keep working.
The database is the existing shared MySQL DB (`sesf_dev`). Laravel migrations are not run against
it. Schema changes are plain SQL files in `database/sql/`, applied by hand (see
`database/sql/README.md`). Do not run destructive DB changes (drops, column removals,
type changes on existing data).

Tests never touch `sesf_dev`. They use a separate ephemeral MariaDB 10.11 (`testdb`, compose
profile `test`, database `sesf_test`, tmpfs, no published port). Its schema comes from
`docs/legacy/schema-sesf_dev.sql` plus `database/sql/`, loaded by `tests/Support/LoadsLegacySchema.php`.
A guard refuses any database name other than `sesf_test`.

## Running things (Docker, from repo root)

- First time, and after changing `DEV_STORE_DIR`: run `scripts/dev-setup.sh` once before `docker compose up`. It checks the dev store, creates the mountpoint dirs as your user, and copies `.env.example` to `.env` if missing.
- App (artisan serve): `docker compose up -d app` then http://localhost:8000
- Vite asset/HMR server (not the app): `docker compose up -d vite` then http://localhost:5173
- Artisan: `docker compose run --rm app php artisan <cmd>`
- Composer: `docker compose run --rm app composer <cmd>`
- npm: `docker compose run --rm node npm <cmd>`
- Backend tests: `scripts/test.sh` (starts `testdb`, runs Pest in the `test` service, removes `testdb` afterwards; `KEEP_TESTDB=1` keeps it; extra args go to pest, e.g. `scripts/test.sh --filter Foo`)
- Frontend tests: `docker compose run --rm node npm run test`
- Lint/analysis: `composer lint`, `composer analyse` (Pint, Larastan), `npm run lint`, `npm run typecheck` (ESLint, vue-tsc)
- Containers run as uid 1000. Stop everything with `docker compose down`.

Behind a reverse proxy or HTTPS terminator, set `TRUSTED_PROXIES` (IPs or CIDRs; unset trusts none)
so client IPs and scheme come from the proxy's headers. Emailed links are always built from `APP_URL`,
and `SESSION_SECURE_COOKIE` defaults to on only when `APP_ENV=production`.

## E2E sandbox

- `scripts/e2e.sh up` starts a seeded throwaway copy (compose project `sesf-e2e`, profile `e2e`): app http://localhost:8001,
  Mailpit http://localhost:8025, PayPal stub http://localhost:9001. Logins: `admin@sesf.test` / `member@sesf.test`, password `Password-123`.
- `scripts/e2e.sh test` runs the Playwright suite (`e2e/*.spec.ts`) and removes the sandbox; `down`, `reseed`, `logs` as named.
- Own database `sesf_e2e` on tmpfs; the app gets only `docker/e2e/app.env` (fake/test values, mounted over `.env`). Never point
  it at `sesf_dev`. Specs use dedicated seeded accounts or unique emails. Details and known issues: `docs/e2e.md`.

## Conventions

- Every change ships with tests: Pest for PHP, Vitest for Vue/JS.
- Run Pint, Larastan, ESLint and vue-tsc before finishing.
- Check for known CVEs/advisories before adding or upgrading any dependency, and report what was checked.
- Keep the `/#/` hash-router URLs and the existing JSON API contract stable.
- Schema changes: additive SQL files in `database/sql/` only, idempotent (`IF NOT EXISTS`), rollback SQL in the header, renamed to `.sql.done` once applied by hand. Never drop or rewrite existing data.
- Database tests (`tests/Database/**`) run inside a rolled-back transaction on `testing_mariadb`. Tests that need autocommit or DDL override `legacyTransactional()`.
- `.env` is local only. `.env.example` must not contain real hosts or credentials.
- Do not install laravel/boost.

## File store

Dev uploads and documents live outside git in `DEV_STORE_DIR` (default `/var/www/sesf-dev-store`).
docker-compose bind-mounts it read-write over these repo paths. Compose fails if a store subdirectory
is missing (`create_host_path: false`); run `scripts/dev-setup.sh` to see which ones are absent:

- `public/images/{members,cars,shows,sponsor,gallery,judging}`
- `public/packet`
- `storage/waivers`
- the store's `public/waivers` (legacy signed PDFs) is mounted at `storage/legacy-waivers`, never under `public/`

These paths are git-ignored. Do not commit files into them.

## Queue worker and scheduler (queued mail)

- Bulk waiver request and reminder mail, waiver PDF regeneration and the other queued jobs run on the database queue.
  `.env` needs `QUEUE_CONNECTION=database` and `DB_QUEUE_TABLE=queue_jobs` (both in `.env.example`).
- The queue does NOT use the legacy `jobs` table: the old app still runs against this database and drains `jobs` with its
  own cron worker. Apply `database/sql/001_queue_tables.sql` by hand first; it creates `queue_jobs` and `failed_jobs`.
- Start the consumers with `docker compose up -d worker scheduler`. `worker` is `queue:work` (continuous); `scheduler` runs
  `schedule:work`, which drains the queue every minute as the legacy ProcessDbQueue cron did.
- Logs: `docker compose logs -f worker`. Tests use `QUEUE_CONNECTION=sync` (phpunit.xml) and never need a worker.
