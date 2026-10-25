import { api } from './client';

/**
 * Car directory and car profile. Shapes follow app/Http/Resources/Cars (GET cars/list, GET car/profile).
 * Legacy PHP sends ids and years as strings or numbers, so values are normalised here.
 * Public car profiles omit the owner's identity beyond profile link, first name + last initial and image.
 */

export interface ListFilterOption {
  id: number;
  name: string;
}

export interface ListFilter {
  type: 'dropdown' | 'textbox';
  name: string;
  label: string;
  data: ListFilterOption[];
  value: string | null;
}

export interface Page<T> {
  last: number;
  page: number;
  perPage: number;
  data: T[];
  total: number;
}

export interface CarOwner {
  fullName: string;
  firstNameLastInitial: string;
  cityState: string;
  imageUrl: string;
  hasImage: boolean;
  /** Route path of the owner's profile, '' when missing. */
  profilePath: string;
}

export interface CarShow {
  name: string;
  shortDate: string;
  /** Route path of the show, '' when missing. */
  profilePath: string;
  imageUrl: string;
  judgingLink: string | null;
}

export interface CarRow {
  id: number;
  hasImage: boolean;
  name: string;
  nickname: string;
  year: string;
  model: string;
  bodyId: number;
  typeId: number;
  profilePath: string;
  imageUrl: string;
  owner: CarOwner | null;
}

export interface CarProfile {
  /** True when the viewer owns the car (server flag is_private). */
  isOwner: boolean;
  id: number;
  nickname: string;
  year: string;
  model: string;
  name: string;
  imageUrl: string;
  bodyName: string;
  typeName: string;
  owner: CarOwner | null;
  shows: CarShow[];
}

/** A car as summarised inside a member profile (garage list and event cars). */
export interface CarSummary {
  id: number;
  hasImage: boolean;
  name: string;
  nickname: string;
  year: string;
  model: string;
  body: string;
  typeId: number;
  profilePath: string;
  imageUrl: string;
  shows: { name: string; profilePath: string; imageUrl: string }[];
}

export interface CarDirectoryParams {
  showId?: number;
  bodyId?: number;
  page: number;
  limit: number;
}

/** Text of a value: strings trimmed, numbers stringified, anything else ''. */
export function toText(value: unknown): string {
  if (typeof value === 'string') {
    return value.trim();
  }
  return typeof value === 'number' && Number.isFinite(value) ? String(value) : '';
}

export function toNumber(value: unknown): number | null {
  if (typeof value !== 'number' && typeof value !== 'string') {
    return null;
  }
  const n = Number(value);
  return Number.isFinite(n) ? n : null;
}

export function toBool(value: unknown): boolean {
  return value === true || value === 1 || value === '1' || value === 'true';
}

/** Hash-URL profile values ("#/Name/u44") become router paths ("/Name/u44"); anything unsafe becomes ''. */
export function toRoutePath(value: unknown): string {
  const text = toText(value).replace(/^#/, '');
  return /^\/(?!\/)[^\s]*$/.test(text) ? text : '';
}

/** Legacy relative image paths ("images/cars/3.jpg") become root-relative; anything else is dropped. */
export function toImageUrl(value: unknown): string {
  const text = toText(value);
  if (text === '') {
    return '';
  }
  if (/^https:\/\/[^\s]+$/i.test(text)) {
    return text;
  }
  const path = text.replace(/^\/+/, '');
  return /^images\/[A-Za-z0-9_\-./ ]+$/.test(path) && !path.includes('..') ? `/${path}` : '';
}

export function asRecordOf(value: unknown): Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value) ? (value as Record<string, unknown>) : {};
}

export function toOwner(value: unknown): CarOwner | null {
  if (typeof value !== 'object' || value === null) {
    return null;
  }
  const raw = asRecordOf(value);
  const fullName = toText(raw.fullName);
  const firstNameLastInitial = toText(raw.firstNameLastInitial);
  const imageUrl = toImageUrl(raw.imageURL ?? raw.profileImageURL);
  return {
    fullName: fullName || firstNameLastInitial,
    firstNameLastInitial,
    cityState: toText(raw.cityState),
    imageUrl,
    hasImage: toBool(raw.has_image) || imageUrl !== '',
    profilePath: toRoutePath(raw.profileURL),
  };
}

export function toCarRow(value: unknown): CarRow | null {
  const raw = asRecordOf(value);
  const id = toNumber(raw.id);
  if (id === null) {
    return null;
  }
  return {
    id,
    hasImage: toBool(raw.has_image),
    name: toText(raw.name),
    nickname: toText(raw.nickname),
    year: toText(raw.year),
    model: toText(raw.model),
    bodyId: toNumber(raw.body_id) ?? 0,
    typeId: toNumber(raw.type_id) ?? 0,
    profilePath: toRoutePath(raw.profileURL),
    imageUrl: toImageUrl(raw.imageURL),
    owner: toOwner(raw.user),
  };
}

function toShow(value: unknown): CarShow | null {
  const raw = asRecordOf(value);
  const name = toText(raw.name);
  if (name === '') {
    return null;
  }
  const link = toText(raw.judgingLink);
  return {
    name,
    shortDate: toText(raw.shortDate),
    profilePath: toRoutePath(raw.profileURL),
    imageUrl: toImageUrl(raw.imageURL),
    judgingLink: link === '' ? null : link,
  };
}

function toShows(value: unknown): CarShow[] {
  return Array.isArray(value)
    ? value.map(toShow).filter((show): show is CarShow => show !== null)
    : [];
}

/** Normalises a car summary from a member profile (or any CarSummaryResource row). */
export function toCarSummary(value: unknown): CarSummary | null {
  const raw = asRecordOf(value);
  const id = toNumber(raw.id);
  if (id === null) {
    return null;
  }
  const shows = Array.isArray(raw.shows)
    ? raw.shows.map((show) => {
        const s = asRecordOf(show);
        return { name: toText(s.name), profilePath: toRoutePath(s.profileURL), imageUrl: toImageUrl(s.imageURL) };
      })
    : [];
  return {
    id,
    hasImage: toBool(raw.has_image),
    name: toText(raw.name),
    nickname: toText(raw.nickname),
    year: toText(raw.year),
    model: toText(raw.model),
    body: toText(raw.body),
    typeId: toNumber(raw.type_id) ?? 0,
    profilePath: toRoutePath(raw.profileURL),
    imageUrl: toImageUrl(raw.imageURL),
    shows: shows.filter((show) => show.name !== ''),
  };
}

export function toCarProfile(value: unknown, carId: number): CarProfile {
  const raw = asRecordOf(value);
  const body = asRecordOf(raw.body);
  const type = asRecordOf(raw.type);
  const isOwner = toBool(raw.is_private);
  // Owner view: body/type are {id,name}. Public view: body {name}, type {type}.
  const bodyName = toText(body.name);
  const typeName = toText(type.name) || toText(type.type);
  return {
    isOwner,
    id: toNumber(raw.id) ?? carId,
    nickname: toText(raw.nickname),
    year: toText(raw.year),
    model: toText(raw.model),
    name: toText(raw.name),
    imageUrl: toImageUrl(raw.imageURL),
    bodyName,
    typeName,
    owner: toOwner(isOwner ? null : raw.user),
    shows: toShows(raw.shows),
  };
}

export async function fetchCarFilters(): Promise<ListFilter[]> {
  const body = await api.get<unknown>('/cars/list-filters');
  if (!Array.isArray(body)) {
    return [];
  }
  return body.map((item) => {
    const raw = asRecordOf(item);
    const data = Array.isArray(raw.data)
      ? raw.data
          .map((option) => ({ id: toNumber(asRecordOf(option).id) ?? 0, name: toText(asRecordOf(option).name) }))
          .filter((option) => option.id > 0)
      : [];
    return {
      type: raw.type === 'textbox' ? 'textbox' : 'dropdown',
      name: toText(raw.name),
      label: toText(raw.label),
      data,
      value: raw.value === null || raw.value === undefined ? null : toText(raw.value),
    } satisfies ListFilter;
  });
}

export async function fetchCarList(params: CarDirectoryParams): Promise<Page<CarRow>> {
  const search = new URLSearchParams({ limit: String(params.limit), page: String(params.page) });
  if (params.showId !== undefined) {
    search.set('show_id', String(params.showId));
  }
  if (params.bodyId !== undefined) {
    search.set('body_id', String(params.bodyId));
  }
  const raw = asRecordOf(await api.get<unknown>(`/cars/list?${search.toString()}`));
  const rows = Array.isArray(raw.data) ? raw.data.map(toCarRow).filter((row): row is CarRow => row !== null) : [];
  return {
    last: toNumber(raw.last) ?? 1,
    page: toNumber(raw.page) ?? params.page,
    perPage: toNumber(raw.perPage) ?? params.limit,
    data: rows,
    total: toNumber(raw.total) ?? rows.length,
  };
}

/** GET car/profile. Unknown ids reject with ApiError(404). */
export async function fetchCarProfile(carId: number): Promise<CarProfile> {
  const body = await api.get<unknown>(`/car/profile?car_id=${encodeURIComponent(String(carId))}`);
  return toCarProfile(body, carId);
}
