/**
 * Lazy loader for the Google Maps JavaScript API. Nothing loads unless a key is configured in
 * the client config (config.ts getMapsKey, fed by the Blade shell's sesf-config block).
 * The script is added once per page; a failed load
 * clears the cache so a later call can retry. The key is never logged.
 */

export const MAPS_SCRIPT_ID = 'sesf-google-maps';
export const MAPS_CALLBACK = '__sesfMapsReady';
const MAPS_SCRIPT_BASE = 'https://maps.googleapis.com/maps/api/js';

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
  maps?: { importLibrary?: (name: 'maps' | 'marker') => Promise<unknown> };
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
 * Adds the Maps script (loading=async) and resolves the 'maps' library plus Marker (which loading=async only
 * provides through the 'marker' library). The script's own load event fires before google.maps.importLibrary
 * exists, so readiness is signalled through the API's callback parameter. Rejects when the script fails or the
 * libraries cannot be imported.
 */
export function loadGoogleMaps(key: string, { doc = document, win = window }: LoadOptions = {}): Promise<MapsLibrary> {
  if (pending !== null) {
    return pending;
  }
  const target = win as Window & { google?: GoogleGlobal; [MAPS_CALLBACK]?: () => void };
  pending = new Promise<MapsLibrary>((resolve, reject) => {
    const fail = (message: string): void => {
      pending = null;
      delete target[MAPS_CALLBACK];
      reject(new Error(message));
    };
    target[MAPS_CALLBACK] = () => {
      delete target[MAPS_CALLBACK];
      const importLibrary = target.google?.maps?.importLibrary;
      if (typeof importLibrary !== 'function') {
        fail('Google Maps did not initialise');
        return;
      }
      const load = importLibrary.bind(target.google?.maps);
      Promise.all([load('maps'), load('marker')])
        .then(([maps, marker]) => resolve({ ...(maps as MapsLibrary), Marker: (marker as Pick<MapsLibrary, 'Marker'>).Marker }))
        .catch(() => fail('Google Maps library could not be imported'));
    };
    const script = doc.createElement('script');
    script.id = MAPS_SCRIPT_ID;
    script.async = true;
    script.src = `${MAPS_SCRIPT_BASE}?key=${encodeURIComponent(key)}&language=en&loading=async&callback=${MAPS_CALLBACK}`;
    script.onerror = () => {
      script.remove();
      fail('Google Maps script failed to load');
    };
    doc.head.append(script);
  });
  return pending;
}
