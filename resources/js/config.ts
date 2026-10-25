declare global {
  interface Window {
    /** Optional runtime config. Takes precedence over the JSON block rendered by the Laravel shell. */
    __SESF__?: { forumUrl?: unknown };
  }
}

export const APP_CONFIG_ELEMENT_ID = 'sesf-config';

function readJsonConfig(): unknown {
  try {
    const text = document.getElementById(APP_CONFIG_ELEMENT_ID)?.textContent ?? '';
    return text === '' ? undefined : (JSON.parse(text) as unknown);
  } catch {
    return undefined;
  }
}

/**
 * URL of the SESF forum (legacy redirect=forum). Empty when not configured. Only http(s) URLs are returned.
 * Source: window.__SESF__.forumUrl, then the <script id="sesf-config"> JSON block rendered by app.blade.php.
 */
export function getForumUrl(): string {
  const fromWindow = typeof window === 'undefined' ? undefined : window.__SESF__?.forumUrl;
  let candidate = fromWindow;
  if (candidate === undefined) {
    const json = readJsonConfig();
    candidate = typeof json === 'object' && json !== null ? (json as Record<string, unknown>).forumUrl : undefined;
  }
  return typeof candidate === 'string' && /^https?:\/\//i.test(candidate) ? candidate : '';
}
