import { api } from '../../../api/client';
import { ADMIN_API_BASE, useAdminResource } from '../../../composables/admin/useAdminResource';
import type { AdminField, FormValues } from '../../../components/admin/types';

/** One agenda item as GET /admin-api/agenda returns it (app/Http/Resources/Admin/AgendaResource.php). */
export interface AgendaItem {
  id: number;
  show_id: number;
  show_name: string | null;
  name: string;
  text: string | null;
  type_id: number;
  type: string;
  is_visible: boolean;
  sort_order: number;
  created_at: string | null;
  updated_at: string | null;
}

export interface AgendaInput {
  [key: string]: unknown;
  show_id?: number;
  name: string;
  text: string | null;
  type_id: number;
  is_visible: boolean;
}

export const agendaApi = useAdminResource<AgendaItem, AgendaInput>('agenda');

/** Saves the order of one event's items. The server answers 422 when the list is not exactly that event's items. */
export function saveAgendaOrder(showId: number, ids: number[]): Promise<unknown> {
  return api.post<unknown>(`${ADMIN_API_BASE}/agenda/reorder`, { show_id: showId, ids });
}

/** Agenda item types (car_show_agenda.type_id). */
export const AGENDA_TYPES = [
  { value: '1', label: 'Agenda' },
  { value: '2', label: 'Judging' },
];

export const AGENDA_FIELDS: AdminField[] = [
  { name: 'name', label: 'Name', required: true, maxlength: 255, wide: true },
  {
    name: 'type_id',
    label: 'Type',
    type: 'select',
    required: true,
    options: AGENDA_TYPES,
  },
  { name: 'text', label: 'Description (HTML)', type: 'textarea', rows: 10, hint: 'Basic formatting only. Scripts and event handlers are removed when saved.' },
  { name: 'is_visible', label: 'Visible on the site', type: 'checkbox' },
];

export function toForm(item: AgendaItem | null): FormValues {
  return {
    name: item?.name ?? '',
    type_id: String(item?.type_id ?? 1),
    text: item?.text ?? '',
    is_visible: item?.is_visible ?? true,
  };
}

function text(value: unknown): string | null {
  const trimmed = typeof value === 'string' ? value.trim() : '';
  return trimmed === '' ? null : trimmed;
}

export function toInput(values: FormValues): AgendaInput {
  return {
    name: typeof values.name === 'string' ? values.name.trim() : '',
    text: text(values.text),
    type_id: Number(values.type_id),
    is_visible: values.is_visible === true,
  };
}
