#!/usr/bin/env bash
# Runs the PHP test suite (Pest) inside Docker against an ephemeral MariaDB.
#
#   scripts/test.sh                     # full suite, including tests/Database
#   scripts/test.sh --filter LegacySchema
#   KEEP_TESTDB=1 scripts/test.sh       # leave the testdb container running afterwards
#
# - Starts the `testdb` service (compose profile "test"): MariaDB 10.11 on tmpfs, no
#   published port, schema loaded by the tests themselves into sesf_test. The shared
#   sesf_dev database is never used by tests.
# - Runs pest in the `app` service as uid 1000 with the repo bind-mounted. This needs
#   the dev-store mountpoints from scripts/dev-setup.sh, because compose mounts them for
#   every `app` container. Run that script once first.
# - Removes only the testdb container afterwards. It does not run `compose down`, which
#   would also stop a running dev `app` or `vite` container.
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

KEEP_TESTDB="${KEEP_TESTDB:-0}"

teardown() {
    if [ "$KEEP_TESTDB" = "1" ]; then
        echo "KEEP_TESTDB=1: leaving testdb running (stop with: docker compose --profile test rm -sf testdb)." >&2
        return
    fi
    docker compose --profile test rm -sf testdb >/dev/null 2>&1 || true
}
trap teardown EXIT

# Non-interactive callers (CI, pipes) get no TTY.
tty_flag=()
if [ ! -t 0 ] || [ ! -t 1 ]; then
    tty_flag=(-T)
fi

docker compose --profile test up -d --wait testdb

docker compose run --rm "${tty_flag[@]}" -e DB_TEST_HOST=testdb app vendor/bin/pest "$@"
