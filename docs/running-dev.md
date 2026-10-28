# Running the dev stack

Everything runs in Docker from the repo root. Set `COMPOSE_PROJECT_NAME` (for example `sesf-integ`) to keep several
checkouts apart.

## 1. One-time setup

1. `scripts/dev-setup.sh` checks the file store (`DEV_STORE_DIR`), creates the mountpoint dirs and copies `.env.example`
   to `.env` if missing.
2. Fill in `.env` (never commit it). The keys the stack needs:
   - `APP_KEY`, `APP_URL` (emailed links are built from it).
   - `DB_HOST`, `DB_PORT`, `DB_DATABASE=sesf_dev`, `DB_USERNAME`, `DB_PASSWORD`: the shared MySQL database.
   - `QUEUE_CONNECTION=database` and `DB_QUEUE_TABLE=queue_jobs`: queued mail and jobs use the dedicated table, never
     the legacy `jobs` table.
   - `MAIL_*`, `SESF_REG_EMAIL`: where mail goes and who it is from. `SESF_EMAIL_DEBUG` / `SESF_DEV_ADDRESS` redirect
     all mail to one inbox in dev.
   - Optional: `DATADOG_RUM_ENABLED`, `DATADOG_APPLICATION_ID`, `DATADOG_CLIENT_TOKEN` (RUM on the SPA, waiver and judging
     pages), `TURNSTILE_*`, `GOOGLE_*`, `PAYPAL_*`, `VANILLA_*`.
3. Apply the SQL files in `database/sql/` by hand (Laravel migrations are not run against `sesf_dev`):
   `database/sql/001_queue_tables.sql` creates `queue_jobs` and `failed_jobs`. Run each `NNN_*.sql` in name order with
   the mysql client as an admin user, then rename it to `.sql.done`. Details: `database/sql/README.md`.

## 2. Start

```
docker compose up -d app vite worker scheduler
```

- `app`: http://localhost:8000 (SPA, API, waiver and judging pages).
- `vite`: asset/HMR server on http://localhost:5173.
- `worker`: `queue:work`, runs queued mail (waiver requests and reminders, check-in reminder and pay-link mail,
  judging results) and PDF jobs. Admin waiver mail is throttled to 20 a minute across workers.
- `scheduler`: `schedule:work`; runs scheduled commands only (the worker is the only queue consumer).

Without the worker, queued mail waits in `queue_jobs`. Logs: `docker compose logs -f worker`. Failed jobs are in
`failed_jobs` (`docker compose run --rm app php artisan queue:failed`, `queue:retry all`).

## 3. Everyday commands

- Artisan: `docker compose run --rm app php artisan <cmd>`
- Backend tests: `scripts/test.sh [--filter Foo]`. Tests use `QUEUE_CONNECTION=sync` and their own database.
- Frontend tests: `docker compose run --rm node npm run test`
- Lint and types: `composer lint`, `composer analyse`, `npm run lint`, `npm run typecheck` (run through `docker compose run --rm app|node`)
- E2E sandbox with Mailpit: `scripts/e2e.sh up` (see `docs/e2e.md`)
- Stop: `docker compose down`
