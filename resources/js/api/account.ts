import { api, ApiError } from './client';
import { asRecordOf, toBool, toCarSummary, toImageUrl, toNumber, toText, type CarSummary } from './cars';

/**
 * Account settings, garage and car editing (legacy member_settings, car_profile). Writes answer 200 with an
 * empty body on success and 400 with an array of messages on failure. The member writes and the location and
 * preference reads act only on the signed-in session user; they ignore any user_id. GET member/member-info and
 * GET member/cars take user_id (member-info returns the full record for the subject or an admin).
 */

export interface AccountInfo {
  first: string;
  last: string;
  email: string;
  phone: string;
}

export interface AccountInfoInput extends AccountInfo {
  /** Required by the server only when the email changes. */
  currentPassword?: string;
}

export interface AddressInfo {
  address1: string;
  address2: string;
  city: string;
  state: string;
  zip: string;
}

export interface EmailPreferences {
  allowSystemEmail: boolean;
  allowFutureShowNotifications: boolean;
  allowPrivateMessage: boolean;
}

export interface PasswordInput {
  oldPassword: string;
  newPassword: string;
  verifyPassword: string;
}

export interface CarOption {
  id: number;
  name: string;
}

/** The editable car as GET car/profile returns it to its owner. */
export interface CarDetail {
  id: number;
  nickname: string;
  model: string;
  year: string;
  vin: string;
  bodyId: number | null;
  typeId: number | null;
  imageUrl: string;
  /** True when the car is registered for a show: body, type, year, model and VIN cannot change. */
  locked: boolean;
}

export interface CarInput {
  /** Omitted when creating. */
  id?: number;
  nickname: string;
  model: string;
  year: string;
  vin: string;
  bodyId: number;
  typeId: number;
}

/** Full member record: the subject and admins get every field, anyone else gets the directory form. */
function memberInfoPath(userId: number): string {
  return `/member/member-info?${new URLSearchParams({ user_id: String(userId) }).toString()}`;
}

/** A 2xx response is success even when the body is empty (the API client throws on empty bodies). */
async function send(method: 'POST' | 'PUT', path: string, body?: Parameters<typeof api.post>[1]): Promise<unknown> {
  try {
    return await api.request(method, path, body);
  } catch (error) {
    if (error instanceof ApiError && error.status >= 200 && error.status < 300) {
      return error.body;
    }
    throw error;
  }
}

/** Server messages from a response body: a string, a list, or field-keyed lists. Blank entries are dropped. */
export function messagesFromBody(body: unknown): string[] {
  if (typeof body === 'string') {
    return body.trim() === '' ? [] : [body.trim()];
  }
  if (Array.isArray(body)) {
    return body.filter((item): item is string => typeof item === 'string' && item.trim() !== '').map((item) => item.trim());
  }
  if (typeof body === 'object' && body !== null) {
    return Object.values(body as Record<string, unknown>).flatMap((value) => messagesFromBody(value));
  }
  return [];
}

/** Messages to show for a failed request. Only validation responses (400/422) carry usable messages. */
export function messagesFromError(error: unknown): string[] {
  if (error instanceof ApiError && (error.status === 400 || error.status === 422)) {
    return messagesFromBody(error.body);
  }
  return [];
}

async function upload(path: string, form: FormData): Promise<void> {
  const messages = messagesFromBody(await send('POST', path, form));
  if (messages.length > 0) {
    throw new ApiError(400, messages, messages.join(' '));
  }
}

export async function fetchAccountInfo(userId: number): Promise<AccountInfo> {
  const raw = asRecordOf(await api.get<unknown>(memberInfoPath(userId)));
  return { first: toText(raw.first), last: toText(raw.last), email: toText(raw.email), phone: toText(raw.phone) };
}

export async function saveAccountInfo(input: AccountInfoInput): Promise<void> {
  const body: Record<string, string> = {
    first: input.first,
    last: input.last,
    email: input.email,
    phone: input.phone,
  };
  if (input.currentPassword !== undefined) {
    body.current_password = input.currentPassword;
  }
  await send('POST', '/member/account-info', body);
}

export async function fetchLocation(): Promise<AddressInfo> {
  const raw = asRecordOf(await api.get<unknown>('/member/location'));
  return {
    address1: toText(raw.address1),
    address2: toText(raw.address2),
    city: toText(raw.city),
    state: toText(raw.state),
    zip: toText(raw.zip),
  };
}

export async function saveLocation(input: AddressInfo): Promise<void> {
  await send('POST', '/member/location', { ...input });
}

export async function fetchPreferences(): Promise<EmailPreferences> {
  const raw = asRecordOf(await api.get<unknown>('/member/private-preferences'));
  return {
    allowSystemEmail: toBool(raw.allow_system_email),
    allowFutureShowNotifications: toBool(raw.allow_future_show_notifications),
    allowPrivateMessage: toBool(raw.allow_private_message),
  };
}

export async function savePreferences(input: EmailPreferences): Promise<void> {
  await send('POST', '/member/private-preferences', {
    allow_system_email: input.allowSystemEmail,
    allow_future_show_notifications: input.allowFutureShowNotifications,
    allow_private_message: input.allowPrivateMessage,
  });
}

export async function changePassword(input: PasswordInput): Promise<void> {
  await send('POST', '/login/update-password', {
    old_password: input.oldPassword,
    new_password: input.newPassword,
    verify_password: input.verifyPassword,
  });
}

/** Deletes the signed-in account. The server logs the session out; the caller clears the local session too. */
export async function deleteAccount(password: string): Promise<void> {
  await send('POST', '/member/delete-account', { password });
}

export async function uploadMemberImage(file: File): Promise<void> {
  const form = new FormData();
  form.append('file', file);
  await upload('/member/profile-image', form);
}

export async function uploadCarImage(carId: number, file: File): Promise<void> {
  const form = new FormData();
  form.append('file', file);
  form.append('car_id', String(carId));
  await upload('/car/profile-image', form);
}

export async function fetchCarBodies(): Promise<CarOption[]> {
  const body = await api.get<unknown>('/car/body');
  return Array.isArray(body)
    ? body.map((item) => ({ id: toNumber(asRecordOf(item).id) ?? 0, name: toText(asRecordOf(item).name) })).filter((o) => o.id > 0)
    : [];
}

export async function fetchCarTypes(): Promise<CarOption[]> {
  const body = await api.get<unknown>('/car/type');
  return Array.isArray(body)
    ? body.map((item) => ({ id: toNumber(asRecordOf(item).id) ?? 0, name: toText(asRecordOf(item).type) })).filter((o) => o.id > 0)
    : [];
}

export async function fetchCarDetail(carId: number): Promise<CarDetail> {
  const raw = asRecordOf(await api.get<unknown>(`/car/profile?car_id=${encodeURIComponent(String(carId))}`));
  const shows = Array.isArray(raw.shows) ? raw.shows : [];
  return {
    id: toNumber(raw.id) ?? carId,
    nickname: toText(raw.nickname),
    model: toText(raw.model),
    year: toText(raw.year),
    vin: toText(raw.VIN),
    bodyId: toNumber(asRecordOf(raw.body).id),
    typeId: toNumber(asRecordOf(raw.type).id),
    imageUrl: toImageUrl(raw.imageURL),
    locked: shows.length > 0,
  };
}

/** Creates (no id) or updates a car. Resolves to the saved car's id. */
export async function saveCar(input: CarInput): Promise<number> {
  const body = await send('PUT', '/car/profile', {
    ...(input.id === undefined ? {} : { id: input.id }),
    nickname: input.nickname,
    model: input.model,
    year: input.year,
    VIN: input.vin,
    body: { id: input.bodyId },
    type: { id: input.typeId },
  });
  return toNumber(asRecordOf(body).id) ?? input.id ?? 0;
}

/** The signed-in member's garage. Sold cars are excluded by the server. */
export async function fetchGarage(userId: number): Promise<CarSummary[]> {
  const body = await api.get<unknown>(`/member/cars?${new URLSearchParams({ user_id: String(userId) }).toString()}`);
  const list = Array.isArray(body) ? body : Array.isArray(asRecordOf(body).cars) ? (asRecordOf(body).cars as unknown[]) : [];
  return list.map(toCarSummary).filter((car): car is CarSummary => car !== null);
}

export async function sellCar(carId: number): Promise<void> {
  await send('POST', '/member/sell-car', { car_id: carId });
}
