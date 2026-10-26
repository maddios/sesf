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
}

export const extraRegistrationsApi = useAdminResource<ExtraRegistration, ExtraRegistrationInput>('extra-registrations');

export const EXTRA_REGISTRATION_FIELDS: AdminField[] = [
  { name: 'num_extras', label: 'Quantity', type: 'number', required: true },
];

export const EXTRA_REGISTRATION_COLUMNS: AdminColumn<ExtraRegistration>[] = [
  { key: 'user_name', label: 'Member' },
  { key: 'show_name', label: 'Event' },
  { key: 'extra_name', label: 'Extra' },
  { key: 'num_extras', label: 'Quantity', align: 'right', format: (row) => (row.num_extras === null ? '—' : String(row.num_extras)) },
];

export function toForm(row: ExtraRegistration | null): FormValues {
  return { num_extras: row?.num_extras ?? null };
}

export function toInput(values: FormValues): ExtraRegistrationInput {
  return { num_extras: typeof values.num_extras === 'number' ? values.num_extras : null };
}
