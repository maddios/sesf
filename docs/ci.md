# CI

Workflow: `.github/workflows/ci.yml` (PRs and pushes to `master`).

- **backend**: PHP 8.5, Node 24, `.env` from example, `npm run build`, then Pint (`composer lint`), Larastan (`composer analyse`), `composer audit --locked`, and Pest with coverage.
- **frontend**: Node 24, `npm ci`, then `npm run lint`, `npm run typecheck`, `npm run test:coverage`, `npm audit --audit-level=low`.
- **e2e** (needs backend and frontend): build assets, install Playwright Chromium, serve Laravel on port 8000 with `APP_ENV=testing`, run `npm run e2e`. Uploads `playwright-report` on failure.

Local equivalents (repo root):

- Backend: `composer lint`, `composer analyse`, `composer audit --locked`, `composer test:coverage`
- Frontend: `npm run lint`, `npm run typecheck`, `npm run test:coverage`, `npm audit --audit-level=low`
- E2E: `npm run build`, `npx playwright install chromium`, `php artisan serve --port=8000`, `npm run e2e`
