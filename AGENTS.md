# AGENTS.md

## What this is

sesf-new is the rewrite of the Laravel 4.2 SESF app. Plan: Laravel 13 on PHP 8.5, with a
Vue 3 SPA using hash routing so existing `/#/` URLs and the old JSON API contract keep working.
The database is the existing shared MySQL DB: migrations are additive only, and there is
no database container in compose. Do not run destructive DB changes (drops, column removals,
type changes on existing data).

## Running things (Docker, from repo root)

- App (artisan serve): `docker compose up -d app` then http://localhost:8000
- Vite dev server: `docker compose up -d vite` then http://localhost:5173
- Artisan: `docker compose run --rm app php artisan <cmd>`
- Composer: `docker compose run --rm app composer <cmd>`
- npm: `docker compose run --rm node npm <cmd>`
- Backend tests: `docker compose run --rm app vendor/bin/pest`
- Frontend tests: `docker compose run --rm node npm run test`
- Lint/analysis: `composer lint`, `composer analyse` (Pint, Larastan), `npm run lint`, `npm run typecheck` (ESLint, vue-tsc)
- Containers run as uid 1000. Stop everything with `docker compose down`.

## Conventions

- Every change ships with tests: Pest for PHP, Vitest for Vue/JS.
- Run Pint, Larastan, ESLint and vue-tsc before finishing.
- Check for known CVEs/advisories before adding or upgrading any dependency, and report what was checked.
- Keep the `/#/` hash-router URLs and the existing JSON API contract stable.
- Migrations: additive only against the shared DB. Never drop or rewrite existing data.
- `.env` is local only. `.env.example` must not contain real hosts or credentials.
- Do not install laravel/boost.

## File store

Dev uploads and documents live outside git in `DEV_STORE_DIR` (default `/var/www/sesf-dev-store`).
docker-compose bind-mounts it read-write over these repo paths:

- `public/images/{members,cars,shows,sponsor,gallery,judging}`
- `public/packet`, `public/waivers`
- `storage/waivers`

These paths are git-ignored. Do not commit files into them.
