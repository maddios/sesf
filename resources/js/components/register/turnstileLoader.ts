/** Cloudflare Turnstile loader. The script is added once per page, in explicit-render mode. */

export interface TurnstileRenderOptions {
  sitekey: string;
  theme?: 'light' | 'dark' | 'auto';
  callback?: (token: string) => void;
  'expired-callback'?: () => void;
  'error-callback'?: () => void;
}

export interface TurnstileApi {
  render(container: HTMLElement, options: TurnstileRenderOptions): string;
  reset(widgetId?: string): void;
}

declare global {
  interface Window {
    turnstile?: TurnstileApi;
  }
}

export const TURNSTILE_SCRIPT_URL = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';

let pending: Promise<TurnstileApi> | null = null;

/** Forgets the shared load (tests only; the app never needs to reload the script). */
export function resetTurnstileLoader(): void {
  pending = null;
}

/**
 * Resolves with the Turnstile API. Repeated calls share one script element. A failed load clears the
 * shared promise so a later call can retry.
 */
export function loadTurnstile(doc: Document = document): Promise<TurnstileApi> {
  if (window.turnstile !== undefined) {
    return Promise.resolve(window.turnstile);
  }
  if (pending === null) {
    pending = new Promise<TurnstileApi>((resolve, reject) => {
      const script = doc.createElement('script');
      script.src = TURNSTILE_SCRIPT_URL;
      script.async = true;
      script.onload = () => {
        if (window.turnstile !== undefined) {
          resolve(window.turnstile);
        } else {
          pending = null;
          reject(new Error('Turnstile loaded without its API'));
        }
      };
      script.onerror = () => {
        pending = null;
        script.remove();
        reject(new Error('Turnstile script failed to load'));
      };
      doc.head.appendChild(script);
    });
  }
  return pending;
}
