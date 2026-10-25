declare global {
  interface Window {
    /** Optional runtime config. Takes precedence over the JSON block rendered by the Laravel shell. */
    __SESF__?: { forumUrl?: unknown; shopUrl?: unknown; mapsKey?: unknown; turnstileSiteKey?: unknown };
  }
}

export const APP_CONFIG_ELEMENT_ID = 'sesf-config';

type ConfigKey = 'forumUrl' | 'shopUrl' | 'mapsKey' | 'turnstileSiteKey';

/** Public site keys are short ASCII tokens. Anything else is treated as not configured. */
const PUBLIC_KEY_PATTERN = /^[A-Za-z0-9_-]{1,200}$/;

function readJsonConfig(): unknown {
  try {
    const text = document.getElementById(APP_CONFIG_ELEMENT_ID)?.textContent ?? '';
    return text === '' ? undefined : (JSON.parse(text) as unknown);
  } catch {
    return undefined;
  }
}

/** Source: window.__SESF__[key], then the <script id="sesf-config"> JSON block. Undefined when neither has it. */
function readConfigValue(key: ConfigKey): unknown {
  const fromWindow = typeof window === 'undefined' ? undefined : window.__SESF__?.[key];
  if (fromWindow !== undefined) {
    return fromWindow;
  }
  const json = readJsonConfig();
  return typeof json === 'object' && json !== null ? (json as Record<string, unknown>)[key] : undefined;
}

/**
 * Reads an external URL setting. Only http(s) URLs are returned; anything else (missing, non-string, javascript:) is ''.
 */
function getExternalUrl(key: ConfigKey): string {
  const candidate = readConfigValue(key);
  return typeof candidate === 'string' && /^https?:\/\//i.test(candidate) ? candidate : '';
}

/** Reads a public key (Turnstile site key, Maps JS key). Anything that is not a plain token is ''. */
function getPublicKey(key: ConfigKey): string {
  const candidate = readConfigValue(key);
  const trimmed = typeof candidate === 'string' ? candidate.trim() : '';
  return PUBLIC_KEY_PATTERN.test(trimmed) ? trimmed : '';
}

/** URL of the SESF forum (legacy redirect=forum and the FORUM nav link). Empty when not configured. */
export function getForumUrl(): string {
  return getExternalUrl('forumUrl');
}

/** URL of the SESF shop (SHOP nav link). Empty when not configured, in which case the link is hidden. */
export function getShopUrl(): string {
  return getExternalUrl('shopUrl');
}

/** Cloudflare Turnstile site key (public). Empty when not configured; the registration form then cannot submit. */
export function getTurnstileSiteKey(): string {
  return getPublicKey('turnstileSiteKey');
}

/** Google Maps JavaScript API key (public). Empty when not configured. */
export function getMapsKey(): string {
  return getPublicKey('mapsKey');
}
