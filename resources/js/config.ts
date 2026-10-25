declare global {
  interface Window {
    /** Optional runtime config. Takes precedence over the JSON block rendered by the Laravel shell. */
    __SESF__?: { forumUrl?: unknown; shopUrl?: unknown };
  }
}

export const APP_CONFIG_ELEMENT_ID = 'sesf-config';

type ConfigKey = 'forumUrl' | 'shopUrl';

function readJsonConfig(): unknown {
  try {
    const text = document.getElementById(APP_CONFIG_ELEMENT_ID)?.textContent ?? '';
    return text === '' ? undefined : (JSON.parse(text) as unknown);
  } catch {
    return undefined;
  }
}

/**
 * Reads an external URL setting. Source: window.__SESF__[key], then the <script id="sesf-config"> JSON block
 * rendered by app.blade.php. Only http(s) URLs are returned; anything else (missing, non-string, javascript:) is ''.
 */
function getExternalUrl(key: ConfigKey): string {
  const fromWindow = typeof window === 'undefined' ? undefined : window.__SESF__?.[key];
  let candidate = fromWindow;
  if (candidate === undefined) {
    const json = readJsonConfig();
    candidate = typeof json === 'object' && json !== null ? (json as Record<string, unknown>)[key] : undefined;
  }
  return typeof candidate === 'string' && /^https?:\/\//i.test(candidate) ? candidate : '';
}

/** URL of the SESF forum (legacy redirect=forum and the FORUM nav link). Empty when not configured. */
export function getForumUrl(): string {
  return getExternalUrl('forumUrl');
}

/** URL of the SESF shop (SHOP nav link). Empty when not configured, in which case the link is hidden. */
export function getShopUrl(): string {
  return getExternalUrl('shopUrl');
}
