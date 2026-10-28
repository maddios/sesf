import { api } from '../../../api/client';
import { ADMIN_API_BASE, mapAdminError } from '../../../composables/admin/useAdminResource';

/** One party member on the check-in list (app/Http/Resources/Admin/CheckinRegistrationResource.php). */
export interface CheckinParty {
  role: 'primary' | 'guest';
  name: string;
  signed: boolean;
  guest_id: number | null;
}

export interface CheckinShirtLine {
  id: number;
  type_id: number;
  type_name: string;
  handed_out: boolean;
}

export interface CheckinRow {
  id: number;
  invoice_id: number;
  primary_name: string;
  primary_email: string | null;
  is_paid: boolean;
  is_checked_in: boolean;
  checked_in_at: string | null;
  arrival_date: string | null;
  arrival_time: string | null;
  party_complete: boolean;
  party: CheckinParty[];
  shirt_lines: CheckinShirtLine[];
}

export interface ShowDay {
  data: string;
  name: string;
}

export interface EligibleUser {
  id: number;
  label: string;
}

export interface GuestType {
  id: number;
  name: string;
}

export interface ShirtTypeOption {
  id: number;
  name: string;
  price_cents: number | null;
}

export interface ExtraOption {
  id: number;
  name: string;
  description: string;
  price_cents: number | null;
  type_id: number;
  max_value: number;
}

export interface RegistrationOptions {
  show_name: string;
  show_dates: ShowDay[];
  eligible_users: EligibleUser[];
  guest_types: GuestType[];
  shirt_types: ShirtTypeOption[];
  extras: ExtraOption[];
  donations: Array<{ id: number; name: string }>;
  arrival_times: string[];
}

export interface GarageCar {
  id: number;
  year: number;
  model: string;
  body_name: string;
  label: string;
  judging_types: Array<{ id: number; name: string }>;
}

export interface NewGuest {
  first: string;
  last: string;
  email: string;
  type_id: number | null;
}

export interface NewRegistrationPayload {
  [key: string]: unknown;
  user_id: number;
  arrival_date: string;
  arrival_time: string;
  donation: number;
  cars: Array<{ id: number; judging_type_id: number | null }>;
  guests: NewGuest[];
  shirts: Array<{ type_id: number }>;
  extras: Array<{ show_extra_id: number; num_extras: number }>;
}

const BASE = `${ADMIN_API_BASE}/checkin`;

export const checkinApi = {
  async list(showId: number): Promise<CheckinRow[]> {
    return (await api.get<{ data: CheckinRow[] }>(`${BASE}/registrations?show_id=${showId}`)).data;
  },
  async checkIn(row: CheckinRow, override: boolean): Promise<CheckinRow> {
    return (await api.post<{ data: CheckinRow }>(`${BASE}/registrations/${row.id}/check-in`, { override })).data;
  },
  async checkOut(row: CheckinRow): Promise<CheckinRow> {
    return (await api.post<{ data: CheckinRow }>(`${BASE}/registrations/${row.id}/check-out`, {})).data;
  },
  async waiverReminder(row: CheckinRow): Promise<boolean> {
    return (await api.post<{ queued: boolean }>(`${BASE}/registrations/${row.id}/waiver-reminder`, {})).queued;
  },
  async payLinkEmail(row: CheckinRow): Promise<boolean> {
    return (await api.post<{ queued: boolean }>(`${BASE}/registrations/${row.id}/pay-link-email`, {})).queued;
  },
  async waiverSignUrl(row: CheckinRow, guestId: number | null): Promise<{ url: string; personName: string }> {
    return api.post<{ url: string; personName: string }>(`${BASE}/registrations/${row.id}/waiver-sign-url`, { guest_id: guestId });
  },
  async options(showId: number): Promise<RegistrationOptions> {
    return (await api.get<{ data: RegistrationOptions }>(`${BASE}/shows/${showId}/registration-options`)).data;
  },
  async garage(showId: number, userId: number): Promise<GarageCar[]> {
    return (await api.get<{ data: GarageCar[] }>(`${BASE}/users/${userId}/garage?show_id=${showId}`)).data;
  },
  async createRegistration(showId: number, payload: NewRegistrationPayload): Promise<{ row: CheckinRow; queued: boolean }> {
    const response = await api.post<{ data: CheckinRow; queued: boolean }>(`${BASE}/shows/${showId}/registrations`, payload);
    return { row: response.data, queued: response.queued };
  },
};

/** The reason a party cannot be checked in as it stands (legacy wording), or null when it can. */
export function blockReason(row: CheckinRow): string | null {
  const reasons: string[] = [];
  if (!row.is_paid) reasons.push('registration is not marked paid');
  if (!row.party_complete) reasons.push('not everyone in the party has signed waivers');
  return reasons.length === 0 ? null : reasons.join(' and ');
}

/** Legacy search: every typed word must appear in the names, the invoice number, or paid/unpaid. */
export function matchesSearch(row: CheckinRow, query: string): boolean {
  const terms = query.trim().toLowerCase().split(/\s+/).filter((term) => term !== '');
  if (terms.length === 0) return true;
  const haystack = [
    String(row.invoice_id),
    `#${row.invoice_id}`,
    row.primary_name,
    ...row.party.map((member) => member.name),
    row.is_paid ? 'paid' : 'unpaid',
  ]
    .join(' ')
    .toLowerCase();
  return terms.every((term) => haystack.includes(term.replace(/^#/, '')));
}

const SOON_WINDOW_MS = 6 * 60 * 60 * 1000;
const ARRIVAL_SLOT_HOURS: Record<string, { start: number; end: number }> = {
  morning: { start: 6, end: 12 },
  afternoon: { start: 12, end: 17 },
  evening: { start: 17, end: 21 },
  night: { start: 21, end: 24 },
};

/** Legacy "Coming soon (next 6h)": the arrival window opens within six hours from now. */
export function isComingSoon(row: CheckinRow, nowMs: number): boolean {
  const slot = ARRIVAL_SLOT_HOURS[(row.arrival_time ?? '').toLowerCase()];
  if (!row.arrival_date || !slot) return false;
  const start = new Date(`${row.arrival_date}T00:00:00`);
  start.setHours(slot.start, 0, 0, 0);
  return start.getTime() <= nowMs + SOON_WINDOW_MS;
}

export function expectedArrival(row: CheckinRow): string {
  const parts: string[] = [];
  if (row.arrival_date) {
    const date = new Date(`${row.arrival_date}T00:00:00`);
    parts.push(Number.isNaN(date.getTime()) ? row.arrival_date : date.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' }));
  }
  if (row.arrival_time) parts.push(row.arrival_time.charAt(0).toUpperCase() + row.arrival_time.slice(1));
  return parts.join(' · ');
}

/** Legacy shirt cell: "2x Men's Medium, Men's Small". */
export function shirtSummary(lines: CheckinShirtLine[]): string {
  const counts = new Map<number, { name: string; count: number }>();
  for (const line of [...lines].sort((a, b) => a.type_id - b.type_id)) {
    const entry = counts.get(line.type_id) ?? { name: line.type_name, count: 0 };
    entry.count += 1;
    counts.set(line.type_id, entry);
  }
  return [...counts.values()].map((entry) => (entry.count > 1 ? `${entry.count}x ${entry.name}` : entry.name)).join(', ');
}

export function formatCents(cents: number | null): string {
  return cents === null ? '' : `$${(cents / 100).toFixed(2)}`;
}

/** The quantity choices for an extra, as the legacy form offered them (cars, guest rows, or the numeric maximum). */
export function quantityRange(extra: ExtraOption, cars: number, guests: number): number {
  if (extra.type_id === 2) return 1 + guests;
  if (extra.type_id === 4) return 1 + extra.max_value;
  return cars;
}

/** The server's reason for a refused change: its first field message when it gives one, else its message. */
export function failureMessage(error: unknown, fallback?: string): string {
  const mapped = mapAdminError(error, fallback);
  return Object.values(mapped.fieldErrors).flat()[0] ?? mapped.message;
}
