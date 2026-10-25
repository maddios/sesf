/**
 * Lazy loader for the Google Maps JavaScript API. Nothing loads unless a key is configured in
 * window.__SESF__.mapsKey (the Blade shell injects it). The script is added once per page; a failed load
 * clears the cache so a later call can retry. The key is never logged.
 */

export const MAPS_SCRIPT_ID = 'sesf-google-maps';
const MAPS_SCRIPT_BASE = 'https://maps.googleapis.com/maps/api/js';
const KEY_PATTERN = /^[A-Za-z0-9_-]{10,}$/;

export interface LatLngLiteral {
  lat: number;
  lng: number;
}

/** The slice of the Maps API this app uses. Typed here so tests can stub it without the real SDK. */
export interface MapsLibrary {
  Map: new (element: HTMLElement, options: { center: LatLngLiteral; zoom: number; mapTypeControl?: boolean }) => object;
  Marker: new (options: { map: object; position: LatLngLiteral; title: string }) => {
    addListener(event: 'click', handler: () => void): void;
  };
  InfoWindow: new (options: { content: HTMLElement }) => {
    open(options: { map: object; anchor: object }): void;
  };
}

interface GoogleGlobal {
  maps?: { importLibrary?: (name: 'maps') => Promise<unknown> };
}

/** The configured key, or '' when none is set or it does not look like a key. */
export function readMapsKey(win: Window = window): string {
  const config = (win as Window & { __SESF__?: { mapsKey?: unknown } }).__SESF__;
  const key = typeof config?.mapsKey === 'string' ? config.mapsKey.trim() : '';
  return KEY_PATTERN.test(key) ? key : '';
}

let pending: Promise<MapsLibrary> | null = null;

/** Forgets a cached load (tests, and after a failed load). */
export function resetMapsLoader(): void {
  pending = null;
}

interface LoadOptions {
  doc?: Document;
  win?: Window;
}

/**
 * Adds the Maps script (loading=async, no callback parameter) and resolves the 'maps' library once the script
 * has loaded. Rejects when the script fails or the API does not come up.
 */
export function loadGoogleMaps(key: string, { doc = document, win = window }: LoadOptions = {}): Promise<MapsLibrary> {
  if (pending !== null) {
    return pending;
  }
  pending = new Promise<MapsLibrary>((resolve, reject) => {
    const script = doc.createElement('script');
    script.id = MAPS_SCRIPT_ID;
    script.async = true;
    script.src = `${MAPS_SCRIPT_BASE}?key=${encodeURIComponent(key)}&loading=async`;
    script.onload = () => {
      const google = (win as Window & { google?: GoogleGlobal }).google;
      if (typeof google?.maps?.importLibrary !== 'function') {
        pending = null;
        reject(new Error('Google Maps did not initialise'));
        return;
      }
      google.maps
        .importLibrary('maps')
        .then((library) => resolve(library as MapsLibrary))
        .catch(() => {
          pending = null;
          reject(new Error('Google Maps library could not be imported'));
        });
    };
    script.onerror = () => {
      pending = null;
      script.remove();
      reject(new Error('Google Maps script failed to load'));
    };
    doc.head.append(script);
  });
  return pending;
}
