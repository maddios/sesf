import { describe, it, expect, beforeEach, vi } from 'vitest';
import { MAPS_CALLBACK, MAPS_SCRIPT_ID, loadGoogleMaps, resetMapsLoader, type MapsLibrary } from '../mapsLoader';

interface FakeScript {
  id: string;
  async: boolean;
  src: string;
  onerror: (() => void) | null;
  remove: ReturnType<typeof vi.fn>;
}

function fakeDocument() {
  const scripts: FakeScript[] = [];
  const doc = {
    createElement: vi.fn(
      (): FakeScript => {
        const script: FakeScript = { id: '', async: false, src: '', onerror: null, remove: vi.fn() };
        scripts.push(script);
        return script;
      },
    ),
    head: { append: vi.fn() },
  };
  return { doc: doc as unknown as Document, scripts, head: doc.head };
}

const MAPS_LIB = { Map: vi.fn(), InfoWindow: vi.fn() };
const MARKER_LIB = { Marker: vi.fn() };
const LIBRARY = { ...MAPS_LIB, ...MARKER_LIB } as unknown as MapsLibrary;
const importLibrary = async (name: 'maps' | 'marker') => (name === 'maps' ? MAPS_LIB : MARKER_LIB);

function fakeWindow(importLibrary: (name: 'maps' | 'marker') => Promise<unknown>) {
  return { google: { maps: { importLibrary: vi.fn(importLibrary) } } } as unknown as Window;
}

function signalReady(win: Window): void {
  (win as unknown as Record<string, () => void>)[MAPS_CALLBACK]?.();
}

beforeEach(() => {
  resetMapsLoader();
});

describe('loadGoogleMaps', () => {
  it('adds one async script with the key and resolves the maps library', async () => {
    const { doc, scripts, head } = fakeDocument();
    const win = fakeWindow(importLibrary);
    const pending = loadGoogleMaps('AIzaSyFAKEKEY_123456', { doc, win });
    expect(head.append).toHaveBeenCalledTimes(1);
    const [script] = scripts;
    expect(script?.id).toBe(MAPS_SCRIPT_ID);
    expect(script?.async).toBe(true);
    expect(script?.src).toBe('https://maps.googleapis.com/maps/api/js?key=AIzaSyFAKEKEY_123456&language=en&loading=async&callback=__sesfMapsReady');
    signalReady(win);
    await expect(pending).resolves.toEqual(LIBRARY);
  });

  it('appends the script only once across repeated calls', async () => {
    const { doc, head } = fakeDocument();
    const win = fakeWindow(importLibrary);
    const first = loadGoogleMaps('AIzaSyFAKEKEY_123456', { doc, win });
    const second = loadGoogleMaps('AIzaSyFAKEKEY_123456', { doc, win });
    expect(first).toBe(second);
    expect(head.append).toHaveBeenCalledTimes(1);
  });

  it('rejects when the script fails, removes it, and allows a retry', async () => {
    const { doc, scripts, head } = fakeDocument();
    const win = fakeWindow(importLibrary);
    const failed = loadGoogleMaps('AIzaSyFAKEKEY_123456', { doc, win });
    scripts[0]?.onerror?.();
    await expect(failed).rejects.toThrow('failed to load');
    expect(scripts[0]?.remove).toHaveBeenCalled();

    const retry = loadGoogleMaps('AIzaSyFAKEKEY_123456', { doc, win });
    expect(head.append).toHaveBeenCalledTimes(2);
    signalReady(win);
    await expect(retry).resolves.toEqual(LIBRARY);
  });

  it('rejects when the script loads without a usable google.maps', async () => {
    const { doc } = fakeDocument();
    const win = {} as Window;
    const pending = loadGoogleMaps('AIzaSyFAKEKEY_123456', { doc, win });
    signalReady(win);
    await expect(pending).rejects.toThrow('did not initialise');
  });

  it('rejects when importing the maps library fails', async () => {
    const { doc } = fakeDocument();
    const win = fakeWindow(() => Promise.reject(new Error('nope')));
    const pending = loadGoogleMaps('AIzaSyFAKEKEY_123456', { doc, win });
    signalReady(win);
    await expect(pending).rejects.toThrow('could not be imported');
  });
});
