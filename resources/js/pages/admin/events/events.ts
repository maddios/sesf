import { ADMIN_API_BASE, useAdminResource } from '../../../composables/admin/useAdminResource';
import { api } from '../../../api/client';
import type { AdminColumn, AdminField, FormValues } from '../../../components/admin/types';

/** One event as GET /admin-api/events returns it (app/Http/Resources/Admin/EventResource.php). */
export interface Event {
  id: number;
  name: string;
  short_name: string;
  description: string | null;
  short_description: string | null;
  schedule: string | null;
  is_schedule_pdf: boolean;
  price_car: string | null;
  price_adult: string | null;
  price_child: string | null;
  date_start: string | null;
  date_end: string | null;
  date_active: string | null;
  date_registration_deactivation: string | null;
  is_active: boolean;
  is_judging_released: boolean;
  has_image: boolean;
  image_url: string;
  primary_sponsor_id: number | null;
  registrations_count: number;
  created_at: string | null;
  updated_at: string | null;
}

export interface EventInput {
  [key: string]: unknown;
  name: string;
  short_name: string;
  description: string | null;
  short_description: string | null;
  schedule: string | null;
  is_schedule_pdf: boolean;
  price_car: string | null;
  price_adult: string | null;
  price_child: string | null;
  date_start: string | null;
  date_end: string | null;
  date_active: string | null;
  date_registration_deactivation: string | null;
}

/** One sponsor of an event. id is the show_sponsor link id; sponsor_id the sponsor. */
export interface EventSponsor {
  id: number;
  sponsor_id: number;
  name: string | null;
  URL: string | null;
  is_enabled: boolean;
  sort_order: number;
  type_id: number | null;
  type: string | null;
}

/** Tiers of an event sponsor (SponsorType). Tier 1 "M" is the main sponsor the home page lists as primary. */
export const SPONSOR_TIERS: Array<{ value: string; label: string }> = [
  { value: '', label: 'No tier' },
  { value: '1', label: 'Main (M)' },
  { value: '2', label: 'Gold' },
  { value: '3', label: 'Silber' },
  { value: '4', label: 'Bronzit' },
];

/** A tier select value ('' or a number as text) as the API expects it. */
export function tierOrNull(value: string): number | null {
  return value === '' ? null : Number(value);
}

export interface EventSponsorList {
  data: EventSponsor[];
  primary_sponsor_id: number | null;
}

export interface CloneSource {
  id: number;
  label: string;
}

export const eventsApi = useAdminResource<Event, EventInput>('events');

/** GET admin-api/events/clone-sources: events a new event may copy its car bodies and judging setup from. */
export async function fetchCloneSources(): Promise<CloneSource[]> {
  const body = await api.get<{ data: CloneSource[] }>(`${ADMIN_API_BASE}/events/clone-sources`);
  return body.data;
}

/** Per-event sponsor calls (app/Http/Controllers/Admin/EventSponsorController.php). */
export function eventSponsorsApi(eventId: number) {
  const base = `${ADMIN_API_BASE}/events/${eventId}`;
  return {
    list: () => api.get<EventSponsorList>(`${base}/sponsors`),
    add: (sponsorId: number, typeId: number | null) =>
      api.post<{ data: EventSponsor }>(`${base}/sponsors`, { sponsor_id: sponsorId, type_id: typeId }),
    setType: (linkId: number, typeId: number | null) =>
      api.put<{ data: EventSponsor }>(`${base}/sponsors/${linkId}`, { type_id: typeId }),
    remove: (linkId: number) => api.delete<unknown>(`${base}/sponsors/${linkId}`),
    reorder: (ids: number[]) => api.put<EventSponsorList>(`${base}/sponsors/order`, { ids }),
    setPrimary: (sponsorId: number | null) =>
      api.put<{ data: { primary_sponsor_id: number | null } }>(`${base}/primary-sponsor`, { sponsor_id: sponsorId }),
    candidates: (search: string) =>
      api.get<{ data: Array<{ id: number; label: string }> }>(`${base}/sponsor-candidates?search=${encodeURIComponent(search)}`),
  };
}

/** Form fields. Widths mirror car_show (the server validates them too). Clone source is a create-only field. */
export const EVENT_FIELDS: AdminField[] = [
  { name: 'name', label: 'Name', required: true, maxlength: 60 },
  { name: 'short_name', label: 'Short name', required: true, maxlength: 20, hint: 'Unique. Used in links and reports.' },
  { name: 'date_start', label: 'Start date', type: 'date' },
  { name: 'date_end', label: 'End date', type: 'date' },
  { name: 'date_active', label: 'Registration opens', type: 'datetime', hint: 'Shown in server time.' },
  { name: 'date_registration_deactivation', label: 'Registration closes', type: 'datetime' },
  { name: 'price_car', label: 'Car entry fee', type: 'money' },
  { name: 'price_adult', label: 'Adult guest fee', type: 'money' },
  { name: 'price_child', label: 'Child guest fee', type: 'money' },
  { name: 'short_description', label: 'Short description', type: 'textarea', rows: 2, maxlength: 1024 },
  { name: 'description', label: 'Description', type: 'textarea', rows: 5, maxlength: 1000 },
  { name: 'is_schedule_pdf', label: 'Schedule is a PDF', type: 'checkbox' },
  { name: 'schedule', label: 'Schedule', type: 'textarea', rows: 6 },
];

/** Create-only field: copy car bodies and judging setup from an earlier event. Required once any event exists. */
export function cloneField(sources: CloneSource[]): AdminField {
  return {
    name: 'clone_from_show_id',
    label: 'Clone eligible bodies & judging setup from',
    type: 'select',
    required: sources.length > 0,
    hint: 'Copies the car bodies and judging setup of the chosen event.',
    options: [{ value: '', label: sources.length > 0 ? 'Choose an event' : 'No earlier events' }, ...sources.map((s) => ({ value: String(s.id), label: s.label }))],
  };
}

export const EVENT_COLUMNS: AdminColumn<Event>[] = [
  { key: 'name', label: 'Name', sortable: true },
  { key: 'short_name', label: 'Short name', sortable: true },
  { key: 'date_start', label: 'Starts', sortable: true },
  { key: 'is_active', label: 'Status', sortable: true },
  { key: 'registrations_count', label: 'Registrations', align: 'right', format: (row) => String(row.registrations_count) },
];

export function toForm(event: Event | null): FormValues {
  return {
    name: event?.name ?? '',
    short_name: event?.short_name ?? '',
    date_start: event?.date_start ?? '',
    date_end: event?.date_end ?? '',
    date_active: event?.date_active ?? '',
    date_registration_deactivation: event?.date_registration_deactivation ?? '',
    price_car: event?.price_car ?? '',
    price_adult: event?.price_adult ?? '',
    price_child: event?.price_child ?? '',
    short_description: event?.short_description ?? '',
    description: event?.description ?? '',
    is_schedule_pdf: event?.is_schedule_pdf ?? false,
    schedule: event?.schedule ?? '',
    clone_from_show_id: '',
  };
}

function text(value: unknown): string | null {
  const trimmed = typeof value === 'string' ? value.trim() : '';
  return trimmed === '' ? null : trimmed;
}

export function toInput(values: FormValues): EventInput {
  return {
    name: typeof values.name === 'string' ? values.name.trim() : '',
    short_name: typeof values.short_name === 'string' ? values.short_name.trim() : '',
    description: text(values.description),
    short_description: text(values.short_description),
    schedule: text(values.schedule),
    is_schedule_pdf: values.is_schedule_pdf === true,
    price_car: text(values.price_car),
    price_adult: text(values.price_adult),
    price_child: text(values.price_child),
    date_start: text(values.date_start),
    date_end: text(values.date_end),
    date_active: text(values.date_active),
    date_registration_deactivation: text(values.date_registration_deactivation),
  };
}

/** Create body: the edit input plus the chosen clone source (omitted when none is chosen). */
export function toCreateInput(values: FormValues): EventInput & { clone_from_show_id?: number } {
  const source = text(values.clone_from_show_id);
  return source === null ? toInput(values) : { ...toInput(values), clone_from_show_id: Number(source) };
}
