#!/usr/bin/env bash
# End-to-end sandbox + Playwright suite (docs/e2e.md).
#
#   scripts/e2e.sh up        build assets if missing, start the sandbox, wait healthy, seed, print URLs/logins
#   scripts/e2e.sh test      up (assets always rebuilt) + reseed + Playwright; then down unless E2E_KEEP=1
#                            extra args go to `playwright test`, e.g. scripts/e2e.sh test e2e/03-login.spec.ts
#   scripts/e2e.sh reseed    drop and reseed the sandbox database (sesf_e2e) and clear sandbox sessions/cache
#   scripts/e2e.sh logs      follow the sandbox logs (app, Mailpit, PayPal stub, DB)
#   scripts/e2e.sh down      remove the sandbox containers, volumes and network
#
#   E2E_KEEP=1     keep the sandbox running after `test`
#   E2E_SHOTS=1    also write the curated screenshots to docs/e2e-shots/
#   E2E_SKIP_BUILD=1  `test` does not rebuild public/build
#
# Everything runs in compose project "sesf-e2e" (never the dev project "sesf-new"), with the services
# named explicitly, and with --env-file docker/e2e/compose.env so the repo .env is never read.
# The app container gets only docker/e2e/app.env (fake/test values). Database: sesf_e2e on the
# sandbox's own MariaDB (tmpfs); the shared dev database is never used.
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

PROJECT=sesf-e2e
SERVICES=(e2edb mailpit e2ecerts paypalstub e2eapp)
# glibc (Debian) Node only: the shared node_modules has native glibc bindings (rolldown); never install
# into it from an alpine/musl image.
NODE_IMAGE=node:24.22.1-bookworm-slim
APP_URL=http://localhost:8001

# The sandbox containers run as the invoking user, so files they write into the repo (seed state,
# Playwright report) stay owned by you (CI runners are not uid 1000).
export E2E_UID="${E2E_UID:-$(id -u)}" E2E_GID="${E2E_GID:-$(id -g)}"

compose() {
    docker compose --env-file docker/e2e/compose.env -p "$PROJECT" --profile e2e "$@"
}

tty_flag=()
if [ ! -t 0 ] || [ ! -t 1 ]; then
    tty_flag=(-T)
fi

log() { printf '\033[1;34me2e:\033[0m %s\n' "$*" >&2; }
die() { printf '\033[1;31me2e: %s\033[0m\n' "$*" >&2; exit 1; }

node_run() {
    docker run --rm -u "$(id -u):$(id -g)" -e HOME=/tmp -e npm_config_cache=/tmp/npm-cache -e NPM_CONFIG_UPDATE_NOTIFIER=false \
        -v "$REPO_ROOT":/app -w /app "$NODE_IMAGE" "$@"
}

preflight() {
    command -v docker >/dev/null || die "docker is required"
    if [ -f public/hot ]; then
        die "public/hot exists (the dev Vite server is running), so the sandbox would load assets from it. Stop it (docker compose stop vite) and retry."
    fi
    if [ ! -d vendor ]; then
        log "vendor/ missing: composer install (in sesf-php:dev)"
        compose build e2eapp >/dev/null
        docker run --rm -u "$(id -u):$(id -g)" -e COMPOSER_HOME=/tmp/composer -v "$REPO_ROOT":/app -w /app \
            sesf-php:dev composer install --no-interaction --prefer-dist --no-progress
    fi
    # Only a missing node_modules (fresh checkout, CI) is installed, with `npm ci` (never modifies the
    # lockfile). An existing node_modules is shared with the dev stack and is never touched here.
    if [ ! -d node_modules ]; then
        log "node_modules missing: npm ci (glibc image)"
        node_run npm ci --no-audit --no-fund
    fi
    # Mount points for the sandbox's tmpfs upload dirs. Created as the invoking user, so Docker never
    # creates root-owned directories in the repo (same paths scripts/dev-setup.sh prepares).
    mkdir -p public/images/{members,cars,shows,sponsor,gallery,judging} public/packet storage/legacy-waivers storage/waivers \
        storage/framework/{sessions,cache,views} storage/logs e2e/.state e2e/artifacts
}

build_assets() {
    log "building Vite assets (npm run build)"
    node_run npm run build >/dev/null
}

seed() {
    log "seeding sesf_e2e"
    compose exec "${tty_flag[@]}" e2eapp php scripts/e2e-seed.php
}

cmd_up() {
    local force_build="${1:-0}"
    preflight
    if [ "$force_build" = "1" ] || [ ! -f public/build/manifest.json ]; then
        if [ "${E2E_SKIP_BUILD:-0}" != "1" ] || [ ! -f public/build/manifest.json ]; then
            build_assets
        fi
    fi
    log "starting sandbox (project $PROJECT)"
    compose up -d --wait "${SERVICES[@]}"
    seed
    cat >&2 <<EOF

  Sandbox is up:  $APP_URL   (Mailpit: http://localhost:8025, PayPal stub: http://localhost:9001/last)
  Stop it with:   scripts/e2e.sh down

EOF
}

cmd_down() {
    log "removing sandbox (project $PROJECT)"
    compose down --volumes --remove-orphans --timeout 5 >/dev/null 2>&1 || true
    # Compose can leave the project network behind; Docker refuses to remove a network that still has
    # containers attached, and these names belong to the sesf-e2e project only.
    docker network rm "${PROJECT}_e2e" "${PROJECT}_default" >/dev/null 2>&1 || true
    docker volume rm "${PROJECT}_e2ecerts" >/dev/null 2>&1 || true
}

cmd_test() {
    local status=0
    if [ "${E2E_KEEP:-0}" != "1" ]; then
        trap cmd_down EXIT
    fi
    cmd_up 1
    rm -rf e2e/artifacts/* test-results playwright-report 2>/dev/null || true
    log "running Playwright"
    compose run --rm "${tty_flag[@]}" e2erunner npx playwright test "$@" || status=$?
    if [ "${E2E_KEEP:-0}" = "1" ]; then
        log "E2E_KEEP=1: sandbox left running at $APP_URL (scripts/e2e.sh down to remove)"
    fi
    return "$status"
}

case "${1:-}" in
    up) cmd_up 0 ;;
    test) shift; cmd_test "$@" ;;
    down) cmd_down ;;
    reseed) seed ;;
    logs) compose logs -f --tail=200 "${SERVICES[@]}" ;;
    *)
        sed -n '2,20p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
        exit 64
        ;;
esac
