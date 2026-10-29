import { api } from '../../../api/client';
import { ADMIN_API_BASE, buildQueryString, type ListQuery, type ListResponse } from '../../../composables/admin/useAdminResource';

/**
 * Admin API URL with an optional list query and extra parameters (such as the required show_id). Shared by the
 * waivers and reports pages; exports are plain GET links, so the session cookie authorises them.
 */
export function adminUrl(path: string, query: ListQuery = {}, params: Record<string, string> = {}): string {
  const search = new URLSearchParams(buildQueryString(query));
  for (const [key, value] of Object.entries(params)) {
    search.set(key, value);
  }
  const qs = search.toString();
  return qs === '' ? `${ADMIN_API_BASE}/${path}` : `${ADMIN_API_BASE}/${path}?${qs}`;
}

export function fetchList<T>(path: string, query: ListQuery, params: Record<string, string> = {}): Promise<ListResponse<T>> {
  return api.get<ListResponse<T>>(adminUrl(path, query, params));
}

export interface EventSummaryTotals {
  registrations: number;
  guests: number;
  paid_registrations: number;
  paid_guests: number;
  extras: number;
  paid_extras: number;
  tshirts: number;
  paid_tshirts: number;
}

export interface MemberRow {
  id: number;
  first: string;
  last: string;
  email: string;
}

export interface EventRegistrationRow {
  id: number;
  invoice_id: number;
  first: string;
  last: string;
  email: string | null;
  address1: string | null;
  address2: string | null;
  city: string | null;
  state: string | null;
  zip: string | null;
  is_paid: boolean;
  user_signed_waiver: boolean;
  judged: boolean;
  cars: string[];
  guests: Array<{ name: string; signed: boolean }>;
  shirts: string[];
  extras: Array<{ name: string; count: number }>;
  arrival_time: string | null;
  arrival_date: string | null;
}

export interface ExtraRow {
  id: number;
  name: string;
  num_total: number;
  num_paid: number;
}

/** Legacy MemberList preference filter order: All, No, Yes. */
export const PREFERENCE_OPTIONS = [
  { value: '', label: 'All' },
  { value: '0', label: 'No' },
  { value: '1', label: 'Yes' },
];

/** Yes/No filter options (is_paid). */
export const YES_NO_OPTIONS = [
  { value: '', label: 'All' },
  { value: '1', label: 'Yes' },
  { value: '0', label: 'No' },
];


/**
 * The legacy pages open on the most recent event (latest end date) until one is chosen.
 */
export async function defaultEventId(): Promise<number | null> {
  const body = await api.get<{ data: { id: number } | null }>(adminUrl('reports/default-event'));
  return body.data?.id ?? null;
}
