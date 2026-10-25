import { api } from './client';
import { eventPath, legacyImageUrl, safeExternalUrl, toBool } from './home';

/**
 * Event data (public GET JSON, same-origin). Shapes follow app/Http/Resources/Events. Legacy PHP sends ids as
 * strings and flags as 0/1, so every value is normalised here before the pages see it.
 */

export interface CarShow {
  id: number;
  name: string;
  shortName: string;
  shortDescription: string;
  /** Hash-route path of the event page, e.g. /SoutheastSharkfest2026/e36. */
  path: string;
  imageUrl: string;
  dateStart: string;
  dateEnd: string;
  ended: boolean;
  registrationOpen: boolean;
  /** "City, ST" of the primary location, or null when the event has none. */
  locationLabel: string | null;
  numRegisteredMembers: number;
  numRegisteredCars: number;
}

export interface EventShowDate {
  name: string;
  date: string;
}

export interface EventInfo {
  id: number;
  name: string;
  path: string;
  /** Hash-route path of the member's registration area for this event (billing). */
  memberPath: string;
  registrationCloseDate: string;
  registrationOpen: boolean;
  showDates: EventShowDate[];
  hasSchedule: boolean;
  agendaCounts: { agenda: number; judging: number };
}

export type AgendaTypeId = 1 | 2;

export interface AgendaItem {
  id: number;
  showId: number;
  typeId: number;
  name: string;
  /** Sanitized HTML written by the event admins. Render only through SafeHtml. */
  text: string;
  isPdf: boolean;
  sortOrder: number;
}

export interface EventAddress {
  address1: string;
  address2: string;
  city: string;
  state: string;
  zip: string;
  cityState: string;
  fullAddress: string;
  latitude: number | null;
  longitude: number | null;
}

export interface EventLocation {
  name: string;
  /** Sanitized HTML, may be empty. */
  description: string;
  address: EventAddress;
}

export interface EventSponsor {
  id: number;
  name: string;
  /** External site, http(s) only; '' when missing or unsafe. */
  url: string;
  /** Same-origin logo path, '' when missing. */
  logoUrl: string;
  description: string;
  /** App\Enums SponsorType id: 1 M, 2 Gold, 3 Silver, 4 Bronze. Null when unknown. */
  typeId: number | null;
}

export interface EventSponsors {
  sponsors: EventSponsor[];
  showId: number | null;
}

export interface EventSchedule {
  /** Sanitized HTML schedule, used when there is no PDF. */
  html: string;
  isPdf: boolean;
  /** Same-origin path or http(s) URL of the PDF, '' when missing. */
  pdfUrl: string;
}

type Raw = Record<string, unknown>;

function isRecord(value: unknown): value is Raw {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function toNumber(value: unknown): number | null {
  if (typeof value !== 'number' && typeof value !== 'string') {
    return null;
  }
  if (typeof value === 'string' && value.trim() === '') {
    return null;
  }
  const n = Number(value);
  return Number.isFinite(n) ? n : null;
}

function toCount(value: unknown): number {
  return toNumber(value) ?? 0;
}

function toText(value: unknown): string {
  return typeof value === 'string' ? value.trim() : typeof value === 'number' ? String(value) : '';
}

/** Collapses whitespace, including the non-breaking spaces of legacy WYSIWYG text. */
function cleanText(value: unknown): string {
  return toText(value).replace(/\s+/g, ' ').trim();
}

/** Hash URLs ("#/Name/u7") become router paths ("/Name/u7"). Anything else is ''. */
function stripHash(value: unknown): string {
  const text = toText(value).replace(/^#/, '');
  return /^\/[^\s]*$/.test(text) ? text.replace(/\/+$/, '') : '';
}

/** Same-origin paths and http(s) URLs only. */
function safeFileUrl(value: unknown): string {
  const text = toText(value);
  if (/^\/(?!\/)[^\s]*$/.test(text)) {
    return text;
  }
  return safeExternalUrl(text);
}

function firstString(raw: Raw, keys: string[]): string {
  for (const key of keys) {
    const text = cleanText(raw[key]);
    if (text !== '') {
      return text;
    }
  }
  return '';
}

export function toCarShow(raw: Raw): CarShow | null {
  const id = toNumber(raw.id);
  if (id === null) {
    return null;
  }
  const primary = isRecord(raw.primaryLocation) ? raw.primaryLocation : null;
  const cityState = primary === null ? '' : cleanText(primary.cityState);
  return {
    id,
    name: cleanText(raw.name),
    shortName: cleanText(raw.short_name),
    shortDescription: firstString(raw, ['shortDescription', 'short_description', 'description']),
    path: eventPath(raw.profileURL, id),
    imageUrl: legacyImageUrl(raw.imageURL),
    dateStart: toText(raw.date_start),
    dateEnd: toText(raw.date_end),
    ended: toBool(raw.ended),
    registrationOpen: toBool(raw.regOpen),
    locationLabel: cityState === '' ? null : cityState,
    numRegisteredMembers: toCount(raw.num_registered_members),
    numRegisteredCars: toCount(raw.num_registered_cars),
  };
}

export function toEventInfo(raw: Raw): EventInfo | null {
  const id = toNumber(raw.id);
  if (id === null) {
    return null;
  }
  const counts = isRecord(raw.agendaTypes) ? raw.agendaTypes : {};
  const showDates = Array.isArray(raw.showDates)
    ? raw.showDates
        .filter(isRecord)
        .map((row) => ({ name: cleanText(row.name), date: cleanText(row.data) }))
        .filter((row) => row.name !== '' || row.date !== '')
    : [];
  return {
    id,
    name: cleanText(raw.name),
    path: eventPath(raw.profileURL, id),
    memberPath: stripHash(raw.memberProfileURL),
    registrationCloseDate: toText(raw.date_reg_deactivation),
    registrationOpen: toBool(raw.regOpen),
    showDates,
    hasSchedule: toBool(raw.hasSchedule),
    agendaCounts: { agenda: toCount(counts.agenda), judging: toCount(counts.judging) },
  };
}

export function toAgendaItem(raw: Raw): AgendaItem | null {
  const id = toNumber(raw.id);
  if (id === null) {
    return null;
  }
  return {
    id,
    showId: toCount(raw.show_id),
    typeId: toCount(raw.type_id),
    name: cleanText(raw.name),
    text: toText(raw.text),
    isPdf: toBool(raw.is_pdf),
    sortOrder: toCount(raw.sort_order),
  };
}

function toCoordinate(value: unknown): number | null {
  const n = toNumber(value);
  return n !== null && n >= -180 && n <= 180 ? n : null;
}

export function toLocation(raw: Raw): EventLocation {
  const address = isRecord(raw.address) ? raw.address : {};
  return {
    name: cleanText(raw.name),
    description: toText(raw.description),
    address: {
      address1: cleanText(address.address1),
      address2: cleanText(address.address2),
      city: cleanText(address.city),
      state: cleanText(address.state),
      zip: cleanText(address.zip),
      cityState: cleanText(address.cityState),
      fullAddress: cleanText(address.fullAddress),
      latitude: toCoordinate(address.lattitude),
      longitude: toCoordinate(address.longitude),
    },
  };
}

export function toEventSponsor(raw: Raw): EventSponsor | null {
  const id = toNumber(raw.id);
  if (id === null) {
    return null;
  }
  return {
    id,
    name: cleanText(raw.name),
    url: safeExternalUrl(raw.URL),
    logoUrl: legacyImageUrl(raw.imageURL),
    description: cleanText(raw.shortDescription ?? raw.description),
    typeId: toNumber(raw.type_id),
  };
}

export async function fetchCarShows(isActive: boolean): Promise<CarShow[]> {
  const body = await api.get<unknown>(`/events/car-shows?is_active=${isActive ? 1 : 0}`);
  return Array.isArray(body)
    ? body.filter(isRecord).map(toCarShow).filter((show): show is CarShow => show !== null)
    : [];
}

export async function fetchEventInfo(showId: number): Promise<EventInfo> {
  const body = await api.get<unknown>(`/event/info?show_id=${showId}`);
  const info = isRecord(body) ? toEventInfo(body) : null;
  if (info === null) {
    throw new Error('Event info response was not an event');
  }
  return info;
}

export async function fetchAgenda(showId: number, typeId: AgendaTypeId): Promise<AgendaItem[]> {
  const body = await api.get<unknown>(`/event/agenda?show_id=${showId}&type_id=${typeId}`);
  return Array.isArray(body)
    ? body
        .filter(isRecord)
        .map(toAgendaItem)
        .filter((item): item is AgendaItem => item !== null)
        .sort((a, b) => a.sortOrder - b.sortOrder)
    : [];
}

export async function fetchLocations(showId: number): Promise<EventLocation[]> {
  const body = await api.get<unknown>(`/event/locations?show_id=${showId}`);
  return Array.isArray(body) ? body.filter(isRecord).map(toLocation) : [];
}

export async function fetchEventSponsors(showId: number): Promise<EventSponsors> {
  const body = await api.get<unknown>(`/event/sponsors?show_id=${showId}`);
  const raw = isRecord(body) ? body : {};
  const sponsors = Array.isArray(raw.sponsors)
    ? raw.sponsors
        .filter(isRecord)
        .map(toEventSponsor)
        .filter((sponsor): sponsor is EventSponsor => sponsor !== null)
    : [];
  return { sponsors, showId: toNumber(raw.show_id) };
}

export async function fetchSchedule(showId: number): Promise<EventSchedule> {
  const body = await api.get<unknown>(`/event/schedule?show_id=${showId}`);
  const raw = isRecord(body) ? body : {};
  return {
    html: toText(raw.schedule),
    isPdf: toBool(raw.is_pdf),
    pdfUrl: safeFileUrl(raw.pdf_url),
  };
}
