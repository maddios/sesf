import { beforeEach, describe, expect, it, vi } from 'vitest';
import { isStaleChunkError, reloadForStaleChunk } from './staleAssets';

describe('stale asset reload', () => {
  const reload = vi.fn();

  beforeEach(() => {
    window.sessionStorage.clear();
    reload.mockReset();
    Object.defineProperty(window, 'location', { value: { ...window.location, reload }, writable: true });
  });

  it('recognises a failed dynamic import', () => {
    expect(isStaleChunkError(new TypeError('Failed to fetch dynamically imported module: https://x/a.js'))).toBe(true);
    expect(isStaleChunkError(new Error('Something else'))).toBe(false);
    expect(isStaleChunkError('Failed to fetch dynamically imported module')).toBe(false);
  });

  it('reloads once, then not again within the cooldown', () => {
    const error = new TypeError('Failed to fetch dynamically imported module: https://x/a.js');
    expect(reloadForStaleChunk(error, 100_000)).toBe(true);
    expect(reloadForStaleChunk(error, 110_000)).toBe(false);
    expect(reloadForStaleChunk(error, 140_000)).toBe(true);
    expect(reload).toHaveBeenCalledTimes(2);
  });

  it('ignores unrelated errors', () => {
    expect(reloadForStaleChunk(new Error('boom'))).toBe(false);
    expect(reload).not.toHaveBeenCalled();
  });
});
