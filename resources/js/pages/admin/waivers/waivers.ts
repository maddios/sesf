import { api } from '../../../api/client';
import type { ListQuery, ListResponse } from '../../../composables/admin/useAdminResource';
import { adminUrl } from '../reports/reports';

/** One person on an event's waiver list (app/Http/Resources/Admin/WaiverRosterResource.php). */
export interface RosterRow {
  row_key: string;
  kind: 'registrant' | 'guest';
  registration_id: number;
  guest_id: number | null;
  invoice_id: number;
  first: string;
  last: string;
  email: string | null;
  status: 'signed' | 'not_signed' | 'not_started';
  signed_at: string | null;
  checked_in: boolean;
  checked_in_at: string | null;
  arrival: string | null;
  can_request: boolean;
  pdf_url: string | null;
}

export interface WalkInRow {
  waiver_id: string | null;
  first: string;
  last: string;
  email: string | null;
  signed_at: string | null;
  pdf_url: string | null;
}

export interface Person {
  registration_id: number;
  guest_id: number | null;
}

/** Legacy filter labels (WaiversList "Signed" select): Yes, No (sent, not signed), Not Sent. */
export const STATUS_OPTIONS = [
  { value: '', label: 'All' },
  { value: 'signed', label: 'Yes' },
  { value: 'not_signed', label: 'No' },
  { value: 'not_started', label: 'Not Sent' },
];

export function personOf(row: RosterRow): Person {
  return { registration_id: row.registration_id, guest_id: row.guest_id };
}

export const waiversApi = {
  roster(showId: number, query: ListQuery, sortCheckedIn: boolean): Promise<ListResponse<RosterRow>> {
    const params: Record<string, string> = { show_id: String(showId) };
    if (sortCheckedIn) params.sort_checked_in = '1';
    return api.get<ListResponse<RosterRow>>(adminUrl('waivers', query, params));
  },
  async walkIns(showId: number): Promise<WalkInRow[]> {
    const body = await api.get<{ data: WalkInRow[] }>(adminUrl('waivers/walk-ins', {}, { show_id: String(showId) }));
    return body.data;
  },
  sendRequests(showId: number, people: Person[]): Promise<{ data: { queued: number } }> {
    return api.post<{ data: { queued: number } }>(adminUrl('waivers/request'), { show_id: showId, people });
  },
  sendReminders(showId: number): Promise<{ data: { queued: number } }> {
    return api.post<{ data: { queued: number } }>(adminUrl('waivers/reminders'), { show_id: showId });
  },
  regeneratePdfs(showId: number): Promise<{ data: { queued: number } }> {
    return api.post<{ data: { queued: number } }>(adminUrl('waivers/regenerate-pdfs'), { show_id: showId });
  },
  openSignUrl(showId: number, row: RosterRow): string {
    const params: Record<string, string> = { show_id: String(showId), registration_id: String(row.registration_id) };
    if (row.guest_id !== null) params.guest_id = String(row.guest_id);
    return adminUrl('waivers/open-sign', {}, params);
  },
  blankPdfUrl(showId: number): string {
    return adminUrl('waivers/blank-pdf', {}, { show_id: String(showId) });
  },
};

/** Legacy date format: "Oct 1, 2026 12:00 PM" in the event's time zone (Eastern). */
export function formatDateTime(value: string | null): string {
  if (value === null) return '—';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return '—';
  const parts = new Intl.DateTimeFormat('en-US', {
    timeZone: 'America/New_York',
    month: 'short',
    day: 'numeric',
    year: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
    hour12: true,
  }).formatToParts(date);
  const get = (type: string) => parts.find((part) => part.type === type)?.value ?? '';
  return `${get('month')} ${get('day')}, ${get('year')} ${get('hour')}:${get('minute')} ${get('dayPeriod')}`;
}
