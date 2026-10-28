import { api } from '../../../api/client';
import { ADMIN_API_BASE } from '../../../composables/admin/useAdminResource';

export interface JudgingShow {
  id: number;
  name: string;
  date_end: string | null;
  is_judging_released: boolean;
}

export interface JudgingEntry {
  id: number;
  car_id: number;
  registration_id: number;
  invoice_id: number;
  year: string;
  body: string;
  model: string;
  judging_type_id: number;
  judging_type: string;
  owner_name: string;
  owner_email: string;
  num_questions: number;
  num_answered: number;
  score: string | null;
  judging_url: string;
  answers_url: string;
  qr_url: string;
}

export interface UnjudgedCar {
  car_id: number;
  body_id: number;
  name: string;
}

export interface UnjudgedRegistration {
  registration_id: number;
  invoice_id: number;
  name: string;
  cars: UnjudgedCar[];
}

export interface JudgingType {
  id: number;
  name: string;
}

export interface JudgingUser {
  user_id: number;
  name: string;
  email: string;
}

export type NewEntry = {
  registration_id: number;
  car_id: number;
  judging_type_id: number;
};

/** Fixed judging types (ids as the API knows them) for the type filter. */
export const JUDGING_TYPE_FILTERS: JudgingType[] = [
  { id: 1, name: 'Clean' },
  { id: 2, name: 'Super Clean' },
  { id: 3, name: 'Concours' },
];

const BASE = `${ADMIN_API_BASE}/judging`;

function showPath(showId: number, rest = ''): string {
  return `${BASE}/shows/${showId}${rest}`;
}

function read<T>(path: string): Promise<T> {
  return api.get<{ data: T }>(path).then((body) => body.data);
}

export function fetchShows(): Promise<JudgingShow[]> {
  return read<JudgingShow[]>(`${BASE}/shows`);
}

export function fetchEntries(showId: number, judgingTypeId: number | null): Promise<JudgingEntry[]> {
  const query = judgingTypeId === null ? '' : `?judging_type_id=${judgingTypeId}`;
  return read<JudgingEntry[]>(showPath(showId, `/entries${query}`));
}

export function fetchUnjudged(showId: number): Promise<UnjudgedRegistration[]> {
  return read<UnjudgedRegistration[]>(showPath(showId, '/unjudged'));
}

export function fetchTypes(showId: number, bodyId: number): Promise<JudgingType[]> {
  return read<JudgingType[]>(showPath(showId, `/types?body_id=${bodyId}`));
}

export function addEntry(showId: number, entry: NewEntry): Promise<unknown> {
  return api.post(showPath(showId, '/entries'), entry);
}

export function removeEntry(showId: number, id: number): Promise<unknown> {
  return api.delete(showPath(showId, `/entries/${id}`));
}

export function fetchJudges(showId: number): Promise<JudgingUser[]> {
  return read<JudgingUser[]>(showPath(showId, '/judges'));
}

export function fetchEligibleJudges(showId: number): Promise<JudgingUser[]> {
  return read<JudgingUser[]>(showPath(showId, '/judges/eligible'));
}

export function assignJudge(showId: number, userId: number): Promise<unknown> {
  return api.post(showPath(showId, '/judges'), { user_id: userId });
}

export function unassignJudge(showId: number, userId: number): Promise<unknown> {
  return api.delete(showPath(showId, `/judges/${userId}`));
}

export function setRelease(showId: number, released: boolean): Promise<unknown> {
  return api.put(showPath(showId, '/release'), { released });
}

export function sendResultsMail(showId: number): Promise<{ sent: number }> {
  return api.post<{ data: { sent: number } }>(showPath(showId, '/results-mail')).then((body) => body.data);
}

export function drawUrl(showId: number): string {
  return `/judging_draw/${showId}`;
}

export function qrSheetUrl(showId: number): string {
  return `/judging_qr_code/show/${showId}`;
}
