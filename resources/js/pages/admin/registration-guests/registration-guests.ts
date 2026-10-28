import { useAdminResource } from '../../../composables/admin/useAdminResource';
import type { AdminColumn, AdminField, FormValues } from '../../../components/admin/types';

/** One registration guest as GET /admin-api/registration-guests returns it (app/Http/Resources/Admin/RegistrationGuestResource.php). */
export interface RegistrationGuest {
  id: number;
  registration_id: number;
  show_id: number;
  show_name: string | null;
  user_id: number;
  member_name: string | null;
  first: string;
  last: string;
  email: string | null;
  type_id: number;
  type: string;
  has_signed: boolean;
  created_at: string | null;
  updated_at: string | null;
}

export interface RegistrationGuestInput {
  [key: string]: unknown;
  first: string;
  last: string;
  email: string | null;
  type_id: number;
  /** Moves the guest to another registration of the same event (edit only). */
  registration_id?: number | null;
}

export const registrationGuestsApi = useAdminResource<RegistrationGuest, RegistrationGuestInput>('registration-guests');

/** Guest types (App\Enums\UserGuestType). */
export const GUEST_TYPES = [
  { value: '1', label: 'Adult' },
  { value: '2', label: 'Child (Under 13)' },
];

/** Widths mirror user_guest (first/last varchar(60), email varchar(128)); the server validates them too. */
export const GUEST_FIELDS: AdminField[] = [
  { name: 'first', label: 'First name', required: true, maxlength: 60 },
  { name: 'last', label: 'Last name', required: true, maxlength: 60 },
  { name: 'email', label: 'Email', type: 'email', maxlength: 128 },
  { name: 'type_id', label: 'Type', type: 'select', required: true, options: GUEST_TYPES },
];

/** The edit form: the guest fields plus the registration it belongs to (legacy reassign). */
export const GUEST_EDIT_FIELDS: AdminField[] = [
  ...GUEST_FIELDS,
  { name: 'registration_id', label: 'Registration ID', type: 'number', required: true, hint: 'Another registration of the same event moves the guest there.' },
];

export const GUEST_COLUMNS: AdminColumn<RegistrationGuest>[] = [
  { key: 'last', label: 'Name', sortable: true, format: (row) => `${row.last}, ${row.first}` },
  { key: 'email', label: 'Email', hideOnMobile: true },
  { key: 'type', label: 'Type' },
  { key: 'show_name', label: 'Event', hideOnMobile: true },
  { key: 'member_name', label: 'Registered by', hideOnMobile: true },
  { key: 'registration_id', label: 'Registration ID', sortable: true, align: 'right' },
  { key: 'created_at', label: 'Date added', sortable: true, hideOnMobile: true, format: (row) => (row.created_at ?? '').slice(0, 10) },
];

export function toForm(guest: RegistrationGuest | null): FormValues {
  return {
    first: guest?.first ?? '',
    last: guest?.last ?? '',
    email: guest?.email ?? '',
    type_id: String(guest?.type_id ?? 1),
    registration_id: guest?.registration_id ?? null,
  };
}

function blankToNull(value: unknown): string | null {
  const text = typeof value === 'string' ? value.trim() : '';
  return text === '' ? null : text;
}

export function toInput(values: FormValues): RegistrationGuestInput {
  return {
    first: typeof values.first === 'string' ? values.first.trim() : '',
    last: typeof values.last === 'string' ? values.last.trim() : '',
    email: blankToNull(values.email),
    type_id: Number(values.type_id),
    registration_id: typeof values.registration_id === 'number' ? values.registration_id : null,
  };
}
