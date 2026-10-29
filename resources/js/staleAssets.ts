const RELOAD_KEY = 'sesf:stale-asset-reload';
const RELOAD_COOLDOWN_MS = 30_000;

const STALE_CHUNK = /Failed to fetch dynamically imported module|error loading dynamically imported module|Importing a module script failed/i;

export function isStaleChunkError(error: unknown): boolean {
  return error instanceof Error && STALE_CHUNK.test(error.message);
}

/**
 * A deploy replaces the hashed build files, so a tab opened before it 404s on lazy chunks.
 * Reloading once picks up the new manifest; the cooldown stops a reload loop if the chunk is truly missing.
 */
export function reloadForStaleChunk(error: unknown, now: number = Date.now()): boolean {
  if (!isStaleChunkError(error)) {
    return false;
  }
  const last = Number(window.sessionStorage.getItem(RELOAD_KEY) ?? 0);
  if (now - last < RELOAD_COOLDOWN_MS) {
    return false;
  }
  window.sessionStorage.setItem(RELOAD_KEY, String(now));
  window.location.reload();
  return true;
}
