import { ApiError, api } from './client';

/**
 * Event registration (legacy registration* directives and RegistrationService). Field names follow the backend
 * contract (snake_case on the wire, camelCase here). Legacy PHP sends ids as strings, so ids are normalised.
 * Money values stay decimal strings: the quote endpoint is the only source of totals.
 */

/** Donation allow-list, matching the legacy select (0 = "No thanks!"). */
export const DONATION_OPTIONS: readonly number[] = [0, 5, 10, 15, 25, 35, 50, 75, 100, 150, 200, 250, 300, 400, 500];

/** Legacy arrival time values, in legacy display order. */
export const ARRIVAL_TIMES = [
  { value: 'morning', label: 'Morning' },
  { value: 'afternoon', label: 'Afternoon' },
  { value: 'evening', label: 'Evening' },
  { value: 'night', label: 'Night' },
] as const;

export interface JudgingType {
  id: number;
  name: string;
}

/** One of the signed-in member's cars, with the judging types allowed for it at this show. */
export interface RegistrableCar {
  id: number;
  name: string;
  nickname: string;
  model: string;
  year: string;
  bodyId: number | null;
  body: string;
  judgingTypes: JudgingType[];
}

export interface RegisteredCar {
  id: number;
  judgingType: number | null;
}

export interface RegisteredGuest {
  id: number;
  first: string;
  last: string;
  email: string;
  type: number | null;
}

export interface RegisteredShirt {
  id: number;
  type: number | null;
  /** The ordered type's name and the line's billed unit price; kept even when the event no longer offers the type. */
  name: string;
  price: string;
}

export interface RegisteredExtra {
  showExtraId: number;
  numExtras: number;
}

export interface NamedOption {
  id: number;
  name: string;
}

export interface ShirtType extends NamedOption {
  price: string;
}

export interface ShowExtra {
  id: number;
  isEnabled: boolean;
  showId: number;
  name: string;
  description: string;
  authKey: string;
  price: string;
  typeId: number | null;
  minValue: number;
  maxValue: number | null;
}

export interface Payment {
  cars: string;
  guests: string;
  shirts: string;
  extras: string;
  donation: string;
  total: string;
  received: string;
  discount: string;
  owed: string;
}

export interface LineItem {
  kind: string;
  label: string;
  /** Which car, guest, shirt type or extra the line is; null when unknown. */
  detail: string | null;
  quantity: number;
  unit: string;
  amount: string;
}

export interface Quote extends Payment {
  lineItems: LineItem[];
}

export interface TargetUser {
  id: number;
  firstName: string;
  lastName: string;
  email: string;
}

export interface Registration {
  id: number | null;
  showId: number;
  userId: number | null;
  invoiceId: number | null;
  isPaid: boolean;
  isCancelled: boolean;
  arrivalDate: string;
  arrivalTime: string;
  donation: number;
  discount: string;
  amountOwed: string;
  amountReceived: string;
  cars: RegisteredCar[];
  guests: RegisteredGuest[];
  shirts: RegisteredShirt[];
  extras: RegisteredExtra[];
}

export interface RegistrationData {
  registration: Registration;
  cars: RegistrableCar[];
  carBodies: NamedOption[];
  guestTypes: NamedOption[];
  shirtTypes: ShirtType[];
  extras: ShowExtra[];
  payment: Payment;
  targetUser: TargetUser | null;
}

/** Per-row messages keyed by the row index in the request (deleted rows keep their index). */
export type IndexedMessages = Record<number, string[]>;

/** Server validation messages grouped by the section they belong to. */
export interface RegistrationErrors {
  cars: string[];
  arrival: string[];
  donation: string[];
  general: string[];
  guests: IndexedMessages;
  shirts: IndexedMessages;
  extras: Record<string, string[]>;
}

/** Request body for POST /event/registration and POST /event/registration/quote. */
export interface RegistrationRequest {
  registration: {
    cars: { id: number; judging_type: number | null }[];
    guests: { id?: number; first: string; last: string; email?: string; type: number | null; deleted?: boolean }[];
    shirts: { id?: number; type: number | null; deleted?: boolean }[];
    extras: Record<string, { show_extra_id: number; num_extras: number }>;
    arrival_date: { data: string };
    arrival_time: string;
    donation: number;
    /** Admin only. Sent by the admin cancel/reinstate action; the member form never sends it. */
    cancel?: boolean;
  };
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
  if (typeof value === 'string') {
    return value;
  }
  return typeof value === 'number' ? String(value) : '';
}

function toMoney(value: unknown): string {
  const n = toNumber(value);
  return n === null ? '0.00' : n.toFixed(2);
}

function toFlag(value: unknown): boolean {
  return value === true || value === 1 || value === '1' || value === 'true';
}

function toRecordList(value: unknown): Raw[] {
  return Array.isArray(value) ? value.filter(isRecord) : [];
}

function toNamed(raw: Raw): NamedOption | null {
  const id = toNumber(raw.id);
  return id === null ? null : { id, name: toText(raw.name) };
}

function toNamedList(value: unknown): NamedOption[] {
  return toRecordList(value).map(toNamed).filter((item): item is NamedOption => item !== null);
}

function toJudgingTypes(value: unknown): JudgingType[] {
  return toNamedList(value);
}

function toCar(raw: Raw): RegistrableCar | null {
  const id = toNumber(raw.id);
  if (id === null) {
    return null;
  }
  return {
    id,
    name: toText(raw.name),
    nickname: toText(raw.nickname),
    model: toText(raw.model),
    year: toText(raw.year),
    bodyId: toNumber(raw.body_id),
    body: toText(raw.body),
    judgingTypes: toJudgingTypes(raw.judging_types),
  };
}

function toRegisteredCar(raw: Raw): RegisteredCar | null {
  const id = toNumber(raw.id);
  return id === null ? null : { id, judgingType: toNumber(raw.judging_type) };
}

function toGuest(raw: Raw): RegisteredGuest | null {
  const id = toNumber(raw.id);
  return id === null
    ? null
    : { id, first: toText(raw.first), last: toText(raw.last), email: toText(raw.email), type: toNumber(raw.type) };
}

function toShirt(raw: Raw): RegisteredShirt | null {
  const id = toNumber(raw.id);
  return id === null ? null : { id, type: toNumber(raw.type), name: toText(raw.name), price: toMoney(raw.price) };
}

function toExtras(value: unknown): RegisteredExtra[] {
  if (!isRecord(value)) {
    return [];
  }
  return Object.entries(value).flatMap(([key, item]) => {
    const raw = isRecord(item) ? item : {};
    const showExtraId = toNumber(raw.show_extra_id) ?? toNumber(key);
    return showExtraId === null ? [] : [{ showExtraId, numExtras: toCount(raw.num_extras) }];
  });
}

function toPayment(value: unknown): Payment {
  const raw = isRecord(value) ? value : {};
  return {
    cars: toMoney(raw.cars),
    guests: toMoney(raw.guests),
    shirts: toMoney(raw.shirts),
    extras: toMoney(raw.extras),
    donation: toMoney(raw.donation),
    total: toMoney(raw.total),
    received: toMoney(raw.received),
    discount: toMoney(raw.discount),
    owed: toMoney(raw.owed),
  };
}

function toLineItem(raw: Raw): LineItem {
  return {
    kind: toText(raw.kind),
    label: toText(raw.label),
    detail: toText(raw.detail) || null,
    quantity: toCount(raw.quantity),
    unit: toMoney(raw.unit),
    amount: toMoney(raw.amount),
  };
}

function toShowExtra(raw: Raw): ShowExtra | null {
  const id = toNumber(raw.id);
  if (id === null) {
    return null;
  }
  return {
    id,
    isEnabled: toFlag(raw.is_enabled),
    showId: toCount(raw.show_id),
    name: toText(raw.name),
    description: toText(raw.description),
    authKey: toText(raw.auth_key),
    price: toMoney(raw.price),
    typeId: toNumber(raw.type_id),
    minValue: toCount(raw.min_value),
    maxValue: toNumber(raw.max_value),
  };
}

function toTargetUser(value: unknown): TargetUser | null {
  if (!isRecord(value)) {
    return null;
  }
  const id = toNumber(value.id);
  return id === null
    ? null
    : { id, firstName: toText(value.first_name), lastName: toText(value.last_name), email: toText(value.email) };
}

/** Maps the GET/POST registration payload to camelCase. Throws when the payload has no usable show. */
export function toRegistrationData(body: unknown): RegistrationData {
  if (!isRecord(body)) {
    throw new Error('Registration payload is not an object');
  }
  const raw = isRecord(body.registration) ? body.registration : {};
  const arrival = isRecord(raw.arrival_date) ? raw.arrival_date : {};
  const registration: Registration = {
    id: toNumber(raw.id),
    showId: toCount(raw.show_id),
    userId: toNumber(raw.user_id),
    invoiceId: toNumber(raw.invoice_id),
    isPaid: toFlag(raw.is_paid),
    isCancelled: toFlag(raw.is_cancelled),
    arrivalDate: toText(arrival.data),
    arrivalTime: toText(raw.arrival_time),
    donation: toCount(raw.donation),
    discount: toMoney(raw.discount),
    amountOwed: toMoney(raw.amount_owed),
    amountReceived: toMoney(raw.amount_received),
    cars: toRecordList(raw.cars).map(toRegisteredCar).filter((item): item is RegisteredCar => item !== null),
    guests: toRecordList(raw.guests).map(toGuest).filter((item): item is RegisteredGuest => item !== null),
    shirts: toRecordList(raw.shirts).map(toShirt).filter((item): item is RegisteredShirt => item !== null),
    extras: toExtras(raw.extras),
  };
  return {
    registration,
    cars: toRecordList(body.cars).map(toCar).filter((item): item is RegistrableCar => item !== null),
    carBodies: toNamedList(body.carBodies ?? body.car_bodies),
    guestTypes: toNamedList(body.guestTypes ?? body.guest_types),
    shirtTypes: toRecordList(body.shirtTypes ?? body.shirt_types)
      .map((item) => {
        const named = toNamed(item);
        return named === null ? null : { ...named, price: toMoney(item.price) };
      })
      .filter((item): item is ShirtType => item !== null),
    extras: toRecordList(body.extras)
      .map(toShowExtra)
      .filter((item): item is ShowExtra => item !== null),
    payment: toPayment(isRecord(body.payment) ? body.payment : isRecord(raw.payment) ? raw.payment : undefined),
    targetUser: toTargetUser(body.targetUser ?? body.target_user),
  };
}

export function toQuote(body: unknown): Quote {
  const root = isRecord(body) && isRecord(body.payment) ? body.payment : {};
  return { ...toPayment(root), lineItems: toRecordList(root.lineItems ?? root.line_items).map(toLineItem) };
}

function withUser(path: string, showId: number, userId?: number): string {
  const query = new URLSearchParams({ show_id: String(showId) });
  if (userId !== undefined) {
    query.set('user_id', String(userId));
  }
  return `${path}?${query.toString()}`;
}

/** Keys of the section-level errors the backend may send. */
const SECTION_KEYS = { cars: 'cars', arrival: 'arrival', donation: 'donation', general: 'general' } as const;

function messagesOf(value: unknown): string[] {
  if (typeof value === 'string') {
    return value.trim() === '' ? [] : [value];
  }
  if (Array.isArray(value)) {
    return value.flatMap((item) => messagesOf(item));
  }
  if (isRecord(value)) {
    return Object.values(value).flatMap((item) => messagesOf(item));
  }
  return [];
}

/** Message list of one section entry: { response, status } or a bare message. */
function sectionMessages(value: unknown): string[] {
  if (isRecord(value) && 'response' in value) {
    return messagesOf(value.response);
  }
  return messagesOf(value);
}

function indexedMessages(value: unknown): IndexedMessages {
  const out: IndexedMessages = {};
  if (!isRecord(value)) {
    return out;
  }
  for (const [key, item] of Object.entries(value)) {
    const index = toNumber(key);
    const messages = sectionMessages(item);
    if (index !== null && messages.length > 0) {
      out[index] = messages;
    }
  }
  return out;
}

/** Maps a 400 body ({cars, guest, arrival, shirt, extras, donation, general}) to section messages. */
export function parseRegistrationErrors(body: unknown): RegistrationErrors {
  const raw = isRecord(body) ? body : {};
  const extras: Record<string, string[]> = {};
  if (isRecord(raw.extras)) {
    for (const [key, item] of Object.entries(raw.extras)) {
      const messages = sectionMessages(item);
      if (messages.length > 0) {
        extras[key] = messages;
      }
    }
  }
  return {
    cars: sectionMessages(raw[SECTION_KEYS.cars]),
    arrival: sectionMessages(raw[SECTION_KEYS.arrival]),
    donation: sectionMessages(raw[SECTION_KEYS.donation]),
    general: sectionMessages(raw[SECTION_KEYS.general]),
    guests: indexedMessages(raw.guest ?? raw.guests),
    shirts: indexedMessages(raw.shirt ?? raw.shirts),
    extras,
  };
}

/** True when a parsed error set has no messages anywhere. */
export function hasRegistrationErrors(errors: RegistrationErrors): boolean {
  return (
    errors.cars.length + errors.arrival.length + errors.donation.length + errors.general.length > 0 ||
    Object.keys(errors.guests).length > 0 ||
    Object.keys(errors.shirts).length > 0 ||
    Object.keys(errors.extras).length > 0
  );
}

export function fetchRegistration(showId: number, userId?: number): Promise<RegistrationData> {
  return api.get<unknown>(withUser('/event/registration', showId, userId)).then(toRegistrationData);
}

/** Saves the registration. A 400 rejects with ApiError whose body parses with parseRegistrationErrors. */
export function saveRegistration(showId: number, body: RegistrationRequest, userId?: number): Promise<RegistrationData> {
  return api.post<unknown>(withUser('/event/registration', showId, userId), { ...body }).then(toRegistrationData);
}

/** Totals for the given selections. Nothing is saved. */
export function fetchQuote(showId: number, body: RegistrationRequest, userId?: number): Promise<Quote> {
  return api.post<unknown>(withUser('/event/registration/quote', showId, userId), { ...body }).then(toQuote);
}

/** One extra, with the signed-in member's quantity for it. */
export interface ExtraDetail {
  extra: ShowExtra;
  numExtras: number;
}

export async function fetchExtra(showId: number, authKey: string): Promise<ExtraDetail> {
  const query = new URLSearchParams({ extra_id: authKey, show_id: String(showId) });
  const body = await api.get<unknown>(`/event/extra?${query.toString()}`);
  const root = isRecord(body) ? body : {};
  const rawExtra = isRecord(root.extra) ? root.extra : root;
  const extra = toShowExtra(rawExtra);
  if (extra === null) {
    throw new ApiError(404, body, 'Extra not found');
  }
  return { extra, numExtras: toCount(root.num_extras ?? rawExtra.num_extras) };
}

export function saveExtra(showId: number, authKey: string, numExtras: number): Promise<unknown> {
  const query = new URLSearchParams({ extra_id: authKey, show_id: String(showId) });
  return api.post<unknown>(`/event/extra?${query.toString()}`, { num_extras: numExtras });
}
