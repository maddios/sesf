import { api } from './client';

/**
 * Home page data. Shapes follow the legacy Laravel 4 endpoints:
 * - GET /home/events?limit=N: CarShow rows (newest date_end first, active only) with `primary_sponsors`
 *   and the appended attributes imageURL, profileURL, shortDescription, regOpen, ended.
 * - GET /event/sponsors?limit=N&all=true: { sponsors, show_id } for the newest show, non-primary sponsors.
 * Legacy PHP often sends numbers and booleans as strings or 0/1, so everything is normalised here.
 */

export interface SponsorRaw {
  id: number | string;
  name?: string | null;
  URL?: string | null;
  imageURL?: string | null;
  shortDescription?: string | null;
  description?: string | null;
  type_id?: number | string;
  show_id?: number | string;
}

export interface HomeEventRaw {
  id: number | string;
  name?: string | null;
  short_name?: string | null;
  description?: string | null;
  short_description?: string | null;
  shortDescription?: string | null;
  imageURL?: string | null;
  profileURL?: string | null;
  date_start?: string | null;
  date_end?: string | null;
  has_image?: number | string | boolean;
  ended?: boolean | number | string;
  regOpen?: boolean | number | string;
  primary_sponsors?: SponsorRaw[] | null;
}

export interface HomeSponsorsRaw {
  sponsors?: SponsorRaw[] | null;
  show_id?: number | string | null;
}

export interface Sponsor {
  id: number;
  name: string;
  /** External site, http(s) only; '' when missing or unsafe. */
  url: string;
  /** Same-origin logo path, '' when missing. */
  logoUrl: string;
  shortDescription: string;
}

export interface HomeEvent {
  id: number;
  name: string;
  shortDescription: string;
  imageUrl: string;
  /** Hash-route path of the event page, e.g. /SoutheastSharkfest2026/e36. */
  path: string;
  dateStart: string;
  dateEnd: string;
  ended: boolean;
  registrationOpen: boolean;
  primarySponsors: Sponsor[];
}

export interface HomeSponsors {
  sponsors: Sponsor[];
  showId: number | null;
}

function toNumber(value: unknown): number | null {
  if (typeof value !== 'number' && typeof value !== 'string') {
    return null;
  }
  const n = Number(value);
  return Number.isFinite(n) ? n : null;
}

function toText(value: unknown): string {
  return typeof value === 'string' ? value.trim() : typeof value === 'number' ? String(value) : '';
}

export function toBool(value: unknown): boolean {
  if (typeof value === 'string') {
    return value === '1' || value.toLowerCase() === 'true';
  }
  return value === true || value === 1;
}

/** Legacy relative image paths ("images/sponsor/45.jpg") become root-relative URLs; anything odd is dropped. */
export function legacyImageUrl(path: unknown): string {
  const text = toText(path).replace(/^\/+/, '');
  return /^images\/[A-Za-z0-9_\-./ ]+$/.test(text) && !text.includes('..') ? `/${text}` : '';
}

/** External links must be http(s). */
export function safeExternalUrl(value: unknown): string {
  const text = toText(value);
  return /^https?:\/\/[^\s]+$/i.test(text) ? text : '';
}

/** "#/Name/e36" or "/Name/e36" to "/Name/e36"; falls back to /event/e{id}. */
export function eventPath(profileUrl: unknown, id: number): string {
  const text = toText(profileUrl).replace(/^#/, '');
  if (/^\/[A-Za-z0-9_-]+\/e\d+$/.test(text)) {
    return text;
  }
  return `/event/e${id}`;
}

/** Collapses runs of whitespace, including the non-breaking spaces of legacy WYSIWYG text (\s covers U+00A0). */
function cleanText(value: unknown): string {
  return toText(value).replace(/\s+/g, ' ').trim();
}

export function toSponsor(raw: SponsorRaw): Sponsor | null {
  const id = toNumber(raw.id);
  if (id === null) {
    return null;
  }
  return {
    id,
    name: cleanText(raw.name),
    url: safeExternalUrl(raw.URL),
    logoUrl: legacyImageUrl(raw.imageURL),
    shortDescription: cleanText(raw.shortDescription ?? raw.description),
  };
}

function toSponsors(list: unknown): Sponsor[] {
  return Array.isArray(list) ? list.map((item) => toSponsor(item as SponsorRaw)).filter((s): s is Sponsor => s !== null) : [];
}

export function toHomeEvent(raw: HomeEventRaw): HomeEvent | null {
  const id = toNumber(raw.id);
  if (id === null) {
    return null;
  }
  return {
    id,
    name: cleanText(raw.name),
    shortDescription: cleanText(raw.shortDescription ?? raw.short_description ?? raw.description),
    imageUrl: legacyImageUrl(raw.imageURL),
    path: eventPath(raw.profileURL, id),
    dateStart: toText(raw.date_start),
    dateEnd: toText(raw.date_end),
    ended: toBool(raw.ended),
    registrationOpen: toBool(raw.regOpen),
    primarySponsors: toSponsors(raw.primary_sponsors),
  };
}

export async function fetchHomeEvents(limit = 1): Promise<HomeEvent[]> {
  const body = await api.get<unknown>(`/home/events?limit=${limit}`);
  return Array.isArray(body)
    ? body.map((item) => toHomeEvent(item as HomeEventRaw)).filter((e): e is HomeEvent => e !== null)
    : [];
}

export async function fetchHomeSponsors(limit = 6): Promise<HomeSponsors> {
  const body = await api.get<unknown>(`/event/sponsors?limit=${limit}&all=true`);
  const raw = typeof body === 'object' && body !== null && !Array.isArray(body) ? (body as HomeSponsorsRaw) : {};
  return { sponsors: toSponsors(raw.sponsors), showId: toNumber(raw.show_id) };
}
