import { useAdminResource } from '../../../composables/admin/useAdminResource';
import type { AdminColumn, AdminField, FormValues } from '../../../components/admin/types';

/** One extra bought on a registration (app/Http/Resources/Admin/ExtraRegistrationResource.php). */
export interface ExtraRegistration {
  id: number;
  registration_id: number;
  user_name: string | null;
  show_id: number | null;
  show_name: string | null;
  show_extra_id: number;
  extra_name: string | null;
  num_extras: number | null;
  created_at: string | null;
  updated_at: string | null;
}

export interface ExtraRegistrationInput {
  [key: string]: unknown;
  num_extras: number | null;
  /** Another extra of the same event (legacy showExtra relation). */
  show_extra_id?: number;
}

export const extraRegistrationsApi = useAdminResource<ExtraRegistration, ExtraRegistrationInput>('extra-registrations');

export const EXTRA_REGISTRATION_FIELDS: AdminField[] = [
  { name: 'num_extras', label: 'Quantity', type: 'number', required: true, hint: '0 removes the purchase. The amount owed is recalculated for open events.' },
];

export const EXTRA_REGISTRATION_COLUMNS: AdminColumn<ExtraRegistration>[] = [
  { key: 'user_name', label: 'Member' },
  { key: 'show_name', label: 'Event' },
  { key: 'extra_name', label: 'Extra' },
  { key: 'num_extras', label: 'Quantity', align: 'right', format: (row) => (row.num_extras === null ? '—' : String(row.num_extras)) },
  { key: 'registration_id', label: 'Registration', align: 'right' },
  { key: 'created_at', label: 'Date registered', sortable: true, format: (row) => (row.created_at ?? '').slice(0, 10) },
];

/** The edit form: the extra (choices are the event's extras) and the quantity. */
export function editFields(extras: Array<{ id: number; name: string }>): AdminField[] {
  return [
    { name: 'show_extra_id', label: 'Extra', type: 'select', required: true, options: extras.map((extra) => ({ value: String(extra.id), label: extra.name })) },
    ...EXTRA_REGISTRATION_FIELDS,
  ];
}

export function toForm(row: ExtraRegistration | null): FormValues {
  return { num_extras: row?.num_extras ?? null, show_extra_id: row === null ? '' : String(row.show_extra_id) };
}

export function toInput(values: FormValues): ExtraRegistrationInput {
  return {
    num_extras: typeof values.num_extras === 'number' ? values.num_extras : null,
    ...(values.show_extra_id ? { show_extra_id: Number(values.show_extra_id) } : {}),
  };
}
