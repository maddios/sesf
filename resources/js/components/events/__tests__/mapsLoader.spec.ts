import { describe, it, expect, beforeEach, vi } from 'vitest';
import { MAPS_SCRIPT_ID, loadGoogleMaps, readMapsKey, resetMapsLoader, type MapsLibrary } from '../mapsLoader';

interface FakeScript {
  id: string;
  async: boolean;
  src: string;
  onload: (() => void) | null;
  onerror: (() => void) | null;
  remove: ReturnType<typeof vi.fn>;
}

function fakeDocument() {
  const scripts: FakeScript[] = [];
  const doc = {
    createElement: vi.fn(
      (): FakeScript => {
        const script: FakeScript = { id: '', async: false, src: '', onload: null, onerror: null, remove: vi.fn() };
        scripts.push(script);
        return script;
      },
    ),
    head: { append: vi.fn() },
  };
  return { doc: doc as unknown as Document, scripts, head: doc.head };
}

const LIBRARY = { Map: vi.fn(), Marker: vi.fn(), InfoWindow: vi.fn() } as unknown as MapsLibrary;

function fakeWindow(importLibrary: (name: 'maps') => Promise<unknown>) {
  return { google: { maps: { importLibrary: vi.fn(importLibrary) } } } as unknown as Window;
}

beforeEach(() => {
  resetMapsLoader();
});

describe('readMapsKey', () => {
  it('is empty when no key is configured or the value is not key-shaped', () => {
    expect(readMapsKey({} as Window)).toBe('');
    expect(readMapsKey({ __SESF__: { mapsKey: 42 } } as unknown as Window)).toBe('');
    expect(readMapsKey({ __SESF__: { mapsKey: 'x"><script>' } } as unknown as Window)).toBe('');
  });

  it('returns a configured key, trimmed', () => {
    expect(readMapsKey({ __SESF__: { mapsKey: '  AIzaSyFAKEKEY_123456  ' } } as unknown as Window)).toBe('AIzaSyFAKEKEY_123456');
  });
});

describe('loadGoogleMaps', () => {
  it('adds one async script with the key and resolves the maps library', async () => {
    const { doc, scripts, head } = fakeDocument();
    const win = fakeWindow(async () => LIBRARY);
    const pending = loadGoogleMaps('AIzaSyFAKEKEY_123456', { doc, win });
    expect(head.append).toHaveBeenCalledTimes(1);
    const [script] = scripts;
    expect(script?.id).toBe(MAPS_SCRIPT_ID);
    expect(script?.async).toBe(true);
    expect(script?.src).toBe('https://maps.googleapis.com/maps/api/js?key=AIzaSyFAKEKEY_123456&loading=async');
    script?.onload?.();
    await expect(pending).resolves.toBe(LIBRARY);
  });

  it('appends the script only once across repeated calls', async () => {
    const { doc, head } = fakeDocument();
    const win = fakeWindow(async () => LIBRARY);
    const first = loadGoogleMaps('AIzaSyFAKEKEY_123456', { doc, win });
    const second = loadGoogleMaps('AIzaSyFAKEKEY_123456', { doc, win });
    expect(first).toBe(second);
    expect(head.append).toHaveBeenCalledTimes(1);
  });

  it('rejects when the script fails, removes it, and allows a retry', async () => {
    const { doc, scripts, head } = fakeDocument();
    const win = fakeWindow(async () => LIBRARY);
    const failed = loadGoogleMaps('AIzaSyFAKEKEY_123456', { doc, win });
    scripts[0]?.onerror?.();
    await expect(failed).rejects.toThrow('failed to load');
    expect(scripts[0]?.remove).toHaveBeenCalled();

    const retry = loadGoogleMaps('AIzaSyFAKEKEY_123456', { doc, win });
    expect(head.append).toHaveBeenCalledTimes(2);
    scripts[1]?.onload?.();
    await expect(retry).resolves.toBe(LIBRARY);
  });

  it('rejects when the script loads without a usable google.maps', async () => {
    const { doc, scripts } = fakeDocument();
    const pending = loadGoogleMaps('AIzaSyFAKEKEY_123456', { doc, win: {} as Window });
    scripts[0]?.onload?.();
    await expect(pending).rejects.toThrow('did not initialise');
  });

  it('rejects when importing the maps library fails', async () => {
    const { doc, scripts } = fakeDocument();
    const win = fakeWindow(() => Promise.reject(new Error('nope')));
    const pending = loadGoogleMaps('AIzaSyFAKEKEY_123456', { doc, win });
    scripts[0]?.onload?.();
    await expect(pending).rejects.toThrow('could not be imported');
  });
});
