#!/usr/bin/env bash
# One-time (idempotent) local setup before `docker compose up`.
#
# - Resolves DEV_STORE_DIR: shell environment, then .env, then the default.
# - Verifies every store subdirectory that docker-compose mounts exists.
# - Creates the matching repo mountpoint dirs as the invoking user, so dockerd
#   never creates them root-owned.
# - Copies .env.example to .env if .env is missing.
# Never deletes or modifies anything in the store.
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

# Store-relative paths that docker-compose.yml bind-mounts.
REQUIRED_DIRS=(
    public/images/members
    public/images/cars
    public/images/shows
    public/images/sponsor
    public/images/gallery
    public/images/judging
    public/packet
    public/waivers
    storage/waivers
)

# Shell env wins over .env (same precedence docker compose uses); .env is read, never sourced.
if [ -n "${DEV_STORE_DIR:-}" ]; then
    store_dir="$DEV_STORE_DIR"
elif [ -f .env ] && grep -Eq '^(export[[:space:]]+)?DEV_STORE_DIR=' .env; then
    store_dir="$(grep -E '^(export[[:space:]]+)?DEV_STORE_DIR=' .env | tail -n 1 | sed -E -e 's/^export[[:space:]]+//' | cut -d= -f2- | tr -d '\r' | sed -e 's/^["'\'']//' -e 's/["'\'']$//')"
else
    store_dir=""
fi
store_dir="${store_dir:-/var/www/sesf-dev-store}"

if [ ! -d "$store_dir" ]; then
    echo "error: DEV_STORE_DIR is not a directory: $store_dir" >&2
    exit 1
fi
echo "Dev store: $store_dir"

missing=()
for rel in "${REQUIRED_DIRS[@]}"; do
    if [ ! -d "$store_dir/$rel" ]; then
        missing+=("$rel")
    fi
done
if [ "${#missing[@]}" -gt 0 ]; then
    echo "error: missing in dev store ($store_dir):" >&2
    printf '  %s\n' "${missing[@]}" >&2
    exit 1
fi

# Repo mountpoint for a store path: same relative path, except the legacy waiver PDFs, which are
# mounted outside public/ so the web server never serves them.
mountpoint_for() {
    case "$1" in
        public/waivers) echo storage/legacy-waivers ;;
        *) echo "$1" ;;
    esac
}

for rel in "${REQUIRED_DIRS[@]}"; do
    mkdir -p -- "$REPO_ROOT/$(mountpoint_for "$rel")"
done
echo "Mountpoint dirs ready (owner: $(id -un))."

if [ ! -f .env ]; then
    cp .env.example .env
    echo "Created .env from .env.example."
else
    echo ".env already exists; left unchanged."
fi

echo "Done. Next: docker compose up -d app"
