import { useAdminResource } from '../../../composables/admin/useAdminResource';
import type { AdminColumn, AdminField, FormValues } from '../../../components/admin/types';

/** Extra types (app/Enums/ExtraType.php). */
export const EXTRA_TYPE_OPTIONS = [
  { value: '1', label: 'Cars' },
  { value: '2', label: 'Guests' },
  { value: '3', label: 'Registration' },
  { value: '4', label: 'Numeric List' },
];

/** One extra as GET /admin-api/extras returns it (app/Http/Resources/Admin/ExtraResource.php). price is a decimal string. */
export interface Extra {
  id: number;
  show_id: number;
  show_name: string | null;
  name: string;
  description: string;
  auth_key: string | null;
  price: string;
  type_id: number;
  type: string;
  min_value: number;
  max_value: number;
  is_enabled: boolean;
  registrations_count: number;
  in_use: boolean;
  created_at: string | null;
  updated_at: string | null;
}

export interface ExtraInput {
  [key: string]: unknown;
  show_id?: number;
  name: string;
  description: string;
  auth_key: string | null;
  price: string;
  type_id: number;
  min_value: number | null;
  max_value: number | null;
  is_enabled: boolean;
}

export const extrasApi = useAdminResource<Extra, ExtraInput>('extras');

export const EXTRA_FIELDS: AdminField[] = [
  { name: 'name', label: 'Name', required: true, maxlength: 64, section: 'Details' },
  { name: 'type_id', label: 'Type', type: 'select', required: true, options: EXTRA_TYPE_OPTIONS },
  { name: 'price', label: 'Price', type: 'money', required: true, hint: 'In dollars, for example 12.50.' },
  { name: 'min_value', label: 'Minimum quantity', type: 'number', section: 'Quantity' },
  { name: 'max_value', label: 'Maximum quantity', type: 'number' },
  { name: 'auth_key', label: 'Landing key', maxlength: 64, section: 'Public link', hint: 'Public key in the extras link. Letters, digits, dashes and underscores. Leave blank for none.' },
  { name: 'description', label: 'Description', type: 'textarea', rows: 4 },
  { name: 'is_enabled', label: 'Enabled (open for registration)', type: 'checkbox', wide: true },
];

export const EXTRA_COLUMNS: AdminColumn<Extra>[] = [
  { key: 'name', label: 'Name', sortable: true },
  { key: 'show_name', label: 'Event', hideOnMobile: true },
  { key: 'type', label: 'Type' },
  { key: 'price', label: 'Price', sortable: true, align: 'right', format: (row) => `$${row.price}` },
  { key: 'registrations_count', label: 'Registrations', align: 'right', hideOnMobile: true, format: (row) => String(row.registrations_count) },
  { key: 'is_enabled', label: 'Status', sortable: true },
];

export function toForm(extra: Extra | null): FormValues {
  return {
    name: extra?.name ?? '',
    type_id: String(extra?.type_id ?? 1),
    price: extra?.price ?? null,
    min_value: extra?.min_value ?? 1,
    max_value: extra?.max_value ?? 100,
    auth_key: extra?.auth_key ?? '',
    description: extra?.description ?? '',
    is_enabled: extra?.is_enabled ?? true,
  };
}

function text(value: unknown): string | null {
  const trimmed = typeof value === 'string' ? value.trim() : '';
  return trimmed === '' ? null : trimmed;
}

function count(value: unknown): number | null {
  return typeof value === 'number' ? value : null;
}

export function toInput(values: FormValues): ExtraInput {
  return {
    name: typeof values.name === 'string' ? values.name.trim() : '',
    description: typeof values.description === 'string' ? values.description.trim() : '',
    auth_key: text(values.auth_key),
    price: typeof values.price === 'string' ? values.price : '',
    type_id: Number(values.type_id),
    min_value: count(values.min_value),
    max_value: count(values.max_value),
    is_enabled: values.is_enabled === true,
  };
}
