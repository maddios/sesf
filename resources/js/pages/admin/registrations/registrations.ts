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
  /** The full on-behalf editor, e.g. "/SpringShow/e4/register?user_id=9" (hash route). */
  editor_path: string;
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
  stored_owed_cents: number;
  received_actual_cents: number | null;
  discount_note: string | null;
  car_ids: number[];
  cars_count: number;
  guests_count: number;
  shirts_count: number;
  created_at: string | null;
  updated_at: string | null;
}

export interface RegistrationInput {
  [key: string]: unknown;
  /** Another member reassigns the registration (with its guests and shirts). */
  user_id: number;
  donation_cents: number;
  arrival_date: string | null;
  arrival_time: string | null;
  is_cancelled: boolean;
  invoice_id: number | string;
  car_ids: number[];
  amount_received_cents: number | string;
  amount_received_actual_cents: number | string | null;
  discount_cents: number | string;
  discount_note: string | null;
  /** null: the writer decides (owed > 0 is unpaid). */
  is_paid: boolean | null;
  /** null: computed (open show) or moved by the change in received and discount (ended show). */
  amount_owed_cents: number | string | null;
}

/** A car the admin may put on the registration (GET registrations/{id}/cars). */
export interface RegistrationCarOption {
  id: number;
  label: string;
  selected: boolean;
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
  { name: 'user_id', label: 'Member', required: true },
  { name: 'invoice_id', label: 'Invoice number', type: 'number', required: true },
  { name: 'car_ids', label: 'Cars' },
  { name: 'amount_received_cents', label: 'Amount received ($)', hint: 'Negative after a refund.' },
  { name: 'amount_received_actual_cents', label: 'Actual amount received ($)', hint: 'After fees. Blank if unknown.' },
  { name: 'discount_cents', label: 'Discount ($)' },
  { name: 'discount_note', label: 'Discount note', type: 'textarea', rows: 2 },
  {
    name: 'is_paid',
    label: 'Paid',
    type: 'select',
    required: true,
    options: [
      { value: 'auto', label: 'Automatic (paid when nothing is owed)' },
      { value: '1', label: 'Paid' },
      { value: '0', label: 'Not paid' },
    ],
  },
  {
    name: 'amount_owed_cents',
    label: 'Amount owed override ($)',
    hint: 'Blank: computed for open events, moved by received and discount changes for ended events.',
  },
];

/** API URL of the cars an admin may choose for a registration. */
export function registrationCarsUrl(id: number, userId?: number): string {
  return `${registrationsApi.url}/${id}/cars${userId === undefined ? '' : `?user_id=${userId}`}`;
}

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
  { key: 'created_at', label: 'Date registered', sortable: true, format: (row) => (row.created_at ?? '').slice(0, 10) },
];

/** "$1,234.50", with a minus sign for a credit. */
export function formatCents(cents: number): string {
  const sign = cents < 0 ? '-' : '';
  const abs = Math.abs(cents);
  return `${sign}$${Math.floor(abs / 100).toLocaleString('en-US')}.${String(abs % 100).padStart(2, '0')}`;
}

/** Signed cents as a dollar string for a text input: -3000 -> "-30.00"; null -> "". */
export function centsToDollars(cents: number | null): string {
  if (cents === null) return '';
  const abs = Math.abs(cents);
  return `${cents < 0 ? '-' : ''}${Math.floor(abs / 100)}.${String(abs % 100).padStart(2, '0')}`;
}

/**
 * A signed dollar string as integer cents: "-30" -> -3000, "12.5" -> 1250. Blank gives `blank`. Anything else is
 * returned as typed, so the server answers with a 422 on the field.
 */
export function dollarsToCents<T extends number | null>(value: unknown, blank: T): number | string | T {
  const text = typeof value === 'string' ? value.trim().replace(/^\$/, '') : '';
  if (text === '') return blank;
  const match = /^(-?)(\d{1,7})(?:\.(\d{1,2}))?$/.exec(text);
  if (match === null) return text;
  const cents = Number(match[2]) * 100 + Number((match[3] ?? '').padEnd(2, '0'));
  return match[1] === '-' ? -cents : cents;
}

export function toForm(registration: Registration | null): FormValues {
  return {
    donation_cents: String(registration?.donation_cents ?? 0),
    arrival_date: registration?.arrival_date ?? null,
    arrival_time: registration?.arrival_time ?? '',
    is_cancelled: registration?.is_cancelled ?? false,
    user_id: registration?.user_id ?? null,
    invoice_id: registration?.invoice_id ?? null,
    car_ids: [...(registration?.car_ids ?? [])],
    amount_received_cents: centsToDollars(registration?.received_cents ?? 0),
    amount_received_actual_cents: centsToDollars(registration?.received_actual_cents ?? null),
    discount_cents: centsToDollars(registration?.discount_cents ?? 0),
    discount_note: registration?.discount_note ?? '',
    is_paid: 'auto',
    amount_owed_cents: '',
  };
}

function blankToNull(value: unknown): string | null {
  return typeof value === 'string' && value !== '' ? value : null;
}

function paidChoice(value: unknown): boolean | null {
  if (value === '1') return true;
  if (value === '0') return false;
  return null;
}

export function toInput(values: FormValues): RegistrationInput {
  return {
    donation_cents: Number(values.donation_cents),
    arrival_date: blankToNull(values.arrival_date),
    arrival_time: blankToNull(values.arrival_time),
    is_cancelled: values.is_cancelled === true,
    user_id: Number(values.user_id),
    invoice_id: typeof values.invoice_id === 'number' ? values.invoice_id : String(values.invoice_id ?? ''),
    car_ids: Array.isArray(values.car_ids) ? values.car_ids.map(Number) : [],
    amount_received_cents: dollarsToCents(values.amount_received_cents, 0),
    amount_received_actual_cents: dollarsToCents(values.amount_received_actual_cents, null),
    discount_cents: dollarsToCents(values.discount_cents, 0),
    discount_note: blankToNull(typeof values.discount_note === 'string' ? values.discount_note.trim() : null),
    is_paid: paidChoice(values.is_paid),
    amount_owed_cents: dollarsToCents(values.amount_owed_cents, null),
  };
}

/** The current values with only the cancellation changed (the row action). */
export function cancelInput(registration: Registration, cancelled: boolean): RegistrationInput {
  return { ...toInput(toForm(registration)), is_cancelled: cancelled };
}
