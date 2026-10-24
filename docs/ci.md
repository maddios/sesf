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
