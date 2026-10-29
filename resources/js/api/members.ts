import { api } from './client';
import {
  asRecordOf,
  toBool,
  toCarSummary,
  toImageUrl,
  toNumber,
  toRoutePath,
  toText,
  type CarSummary,
  type ListFilter,
  type Page,
} from './cars';

/**
 * Member directory and member profile. Shapes follow app/Http/Resources/Members
 * (GET members/users, GET member/profile). Guests get a masked row (first name + last initial, state only);
 * members also get "City, ST". The client never asks for hidden fields.
 */

export type { ListFilter, ListFilterOption, Page } from './cars';

export const GENERIC_AVATAR_URL = '/images/generic_avatar_300.gif';

export interface MemberRow {
  id: number;
  hasImage: boolean;
  imageUrl: string;
  firstNameLastInitial: string;
  fullName: string;
  cityState: string;
  /** Route path of the member profile, '' when missing. */
  profilePath: string;
}

export interface AttendedEvent {
  id: number;
  showId: number;
  showName: string;
  shortDate: string;
  profilePath: string;
  show: { name: string; shortDate: string; profilePath: string; imageUrl: string } | null;
  cars: CarSummary[];
}

export interface MemberProfile {
  /** True when the viewer is this member (server flag is_private). */
  isOwner: boolean;
  fullName: string;
  firstNameLastInitial: string;
  cityState: string;
  imageUrl: string;
  hasImage: boolean;
  carCount: number;
  cars: CarSummary[];
  eventCount: number;
  events: AttendedEvent[];
  allowPrivateMessage: boolean;
}

export interface MemberDirectoryParams {
  showId?: number;
  name?: string;
  page: number;
  limit: number;
}

export function toMemberRow(value: unknown): MemberRow | null {
  const raw = asRecordOf(value);
  const id = toNumber(raw.id);
  if (id === null) {
    return null;
  }
  const imageUrl = toImageUrl(raw.imageURL);
  const firstNameLastInitial = toText(raw.firstNameLastInitial);
  return {
    id,
    hasImage: toBool(raw.has_image) || imageUrl !== '',
    imageUrl,
    firstNameLastInitial,
    fullName: toText(raw.fullName) || firstNameLastInitial,
    cityState: toText(raw.cityState),
    profilePath: toRoutePath(raw.profileURL),
  };
}

function toAttendedEvent(value: unknown): AttendedEvent | null {
  const raw = asRecordOf(value);
  const id = toNumber(raw.id);
  if (id === null) {
    return null;
  }
  const carShow = raw.carShow === null || raw.carShow === undefined ? null : asRecordOf(raw.carShow);
  const cars = Array.isArray(raw.cars)
    ? raw.cars.map(toCarSummary).filter((car): car is CarSummary => car !== null)
    : [];
  return {
    id,
    showId: toNumber(raw.show_id) ?? 0,
    showName: toText(raw.showName) || toText(carShow?.name),
    shortDate: toText(raw.shortDate),
    profilePath: toRoutePath(raw.profileURL),
    show:
      carShow === null
        ? null
        : {
            name: toText(carShow.name),
            shortDate: toText(carShow.shortDate),
            profilePath: toRoutePath(carShow.profileURL),
            imageUrl: toImageUrl(carShow.imageURL),
          },
    cars,
  };
}

/** Normalises the GET member/profile body. Unknown shapes degrade to empty lists, never to hidden data. */
export function toMemberProfile(value: unknown): MemberProfile {
  const raw = asRecordOf(value);
  const info = asRecordOf(raw.member_info);
  const carList = asRecordOf(raw.car_list);
  const eventList = asRecordOf(raw.event_list);
  const prefs = asRecordOf(raw.member_preferences);
  const imageUrl = toImageUrl(info.imageURL);
  const firstNameLastInitial = toText(info.firstNameLastInitial);
  const cars = Array.isArray(carList.cars)
    ? carList.cars.map(toCarSummary).filter((car): car is CarSummary => car !== null)
    : [];
  const events = Array.isArray(eventList.events)
    ? eventList.events.map(toAttendedEvent).filter((event): event is AttendedEvent => event !== null)
    : [];
  return {
    isOwner: toBool(raw.is_private),
    fullName: toText(info.fullName) || firstNameLastInitial,
    firstNameLastInitial,
    cityState: toText(info.cityState),
    imageUrl,
    hasImage: toBool(info.has_image) || imageUrl !== '',
    carCount: toNumber(carList.num_cars) ?? cars.length,
    cars,
    eventCount: toNumber(eventList.num_events) ?? events.length,
    events,
    allowPrivateMessage: toBool(prefs.allow_private_message),
  };
}

export async function fetchMemberFilters(): Promise<ListFilter[]> {
  const body = await api.get<unknown>('/members/list-filters');
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

export async function fetchMemberList(params: MemberDirectoryParams): Promise<Page<MemberRow>> {
  const search = new URLSearchParams({ limit: String(params.limit), page: String(params.page) });
  if (params.showId !== undefined) {
    search.set('show_id', String(params.showId));
  }
  if (params.name !== undefined && params.name !== '') {
    search.set('name', params.name);
  }
  const raw = asRecordOf(await api.get<unknown>(`/members/users?${search.toString()}`));
  const rows = Array.isArray(raw.data) ? raw.data.map(toMemberRow).filter((row): row is MemberRow => row !== null) : [];
  return {
    last: toNumber(raw.last) ?? 1,
    page: toNumber(raw.page) ?? params.page,
    perPage: toNumber(raw.perPage) ?? params.limit,
    data: rows,
    total: toNumber(raw.total) ?? rows.length,
  };
}

/** GET member/profile. Unknown ids reject with ApiError(404). */
export async function fetchMemberProfile(userId: number): Promise<MemberProfile> {
  return toMemberProfile(await api.get<unknown>(`/member/profile?user_id=${encodeURIComponent(String(userId))}`));
}

/** POST member/message. The server answers 401 for opted-out or unknown recipients; callers show a generic failure. */
export async function sendMemberMessage(userId: number, subject: string, message: string): Promise<void> {
  await api.post('/member/message', { user_id: userId, subject, message });
}
