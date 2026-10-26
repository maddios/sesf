import { useAdminResource } from '../../../composables/admin/useAdminResource';
import type { AdminColumn, AdminField, FormValues } from '../../../components/admin/types';

/** One registration as GET /admin-api/registrations returns it (app/Http/Resources/Admin/RegistrationResource.php). Money is integer cents. */
export interface Registration {
  id: number;
  invoice_id: number;
  show_id: number;
  show_name: string;
  user_id: number;
  user_name: string | null;
  donation_cents: number;
  arrival_date: string | null;
  arrival_time: string | null;
  is_cancelled: boolean;
  is_paid: boolean;
  amount_source: 'stored' | 'computed';
  total_cents: number;
  owed_cents: number;
  received_cents: number;
  discount_cents: number;
  cars_count: number;
  guests_count: number;
  shirts_count: number;
  created_at: string | null;
  updated_at: string | null;
}

export interface RegistrationInput {
  [key: string]: unknown;
  donation_cents: number;
  arrival_date: string | null;
  arrival_time: string | null;
  is_cancelled: boolean;
}

export const registrationsApi = useAdminResource<Registration, RegistrationInput>('registrations');

/** Donation choices in cents: the member form's list (SaveRegistrationRequest::DONATIONS), including $5. */
export const DONATION_CENTS = [0, 500, 1000, 1500, 2500, 3500, 5000, 7500, 10000, 15000, 20000, 25000, 30000, 40000, 50000];

export const ARRIVAL_TIMES = [
  { value: 'morning', label: 'Morning' },
  { value: 'afternoon', label: 'Afternoon' },
  { value: 'evening', label: 'Evening' },
  { value: 'night', label: 'Night' },
];

export const REGISTRATION_FIELDS: AdminField[] = [
  {
    name: 'donation_cents',
    label: 'Donation',
    type: 'select',
    required: true,
    options: DONATION_CENTS.map((cents) => ({ value: String(cents), label: cents === 0 ? 'None' : `$${cents / 100}` })),
  },
  { name: 'arrival_date', label: 'Arrival date', type: 'date', hint: 'Within the show dates.' },
  { name: 'arrival_time', label: 'Arrival time', type: 'select', options: ARRIVAL_TIMES },
  { name: 'is_cancelled', label: 'Cancelled', type: 'checkbox', hint: 'Keeps the cars, guests and amounts.' },
];

export const REGISTRATION_COLUMNS: AdminColumn<Registration>[] = [
  { key: 'invoice_id', label: 'Invoice', sortable: true, align: 'right' },
  { key: 'show_name', label: 'Event' },
  { key: 'user_name', label: 'Member' },
  { key: 'cars_count', label: 'Cars', align: 'right' },
  { key: 'guests_count', label: 'Guests', align: 'right' },
  { key: 'shirts_count', label: 'Shirts', align: 'right' },
  { key: 'total_cents', label: 'Total', align: 'right' },
  { key: 'owed_cents', label: 'Owed', align: 'right', format: (row) => formatCents(row.owed_cents) },
  { key: 'is_cancelled', label: 'Status', sortable: true },
];

/** "$1,234.50", with a minus sign for a credit. */
export function formatCents(cents: number): string {
  const sign = cents < 0 ? '-' : '';
  const abs = Math.abs(cents);
  return `${sign}$${Math.floor(abs / 100).toLocaleString('en-US')}.${String(abs % 100).padStart(2, '0')}`;
}

export function toForm(registration: Registration | null): FormValues {
  return {
    donation_cents: String(registration?.donation_cents ?? 0),
    arrival_date: registration?.arrival_date ?? null,
    arrival_time: registration?.arrival_time ?? '',
    is_cancelled: registration?.is_cancelled ?? false,
  };
}

function blankToNull(value: unknown): string | null {
  return typeof value === 'string' && value !== '' ? value : null;
}

export function toInput(values: FormValues): RegistrationInput {
  return {
    donation_cents: Number(values.donation_cents),
    arrival_date: blankToNull(values.arrival_date),
    arrival_time: blankToNull(values.arrival_time),
    is_cancelled: values.is_cancelled === true,
  };
}

/** The current values with only the cancellation changed (the row action). */
export function cancelInput(registration: Registration, cancelled: boolean): RegistrationInput {
  return {
    donation_cents: registration.donation_cents,
    arrival_date: registration.arrival_date,
    arrival_time: registration.arrival_time,
    is_cancelled: cancelled,
  };
}
