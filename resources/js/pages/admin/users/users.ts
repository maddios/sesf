import { useAdminResource } from '../../../composables/admin/useAdminResource';
import type { AdminColumn, AdminField, FormValues } from '../../../components/admin/types';

/** One member as GET /admin-api/users returns it (app/Http/Resources/Admin/UserResource.php). */
export interface AdminUser {
  id: number;
  first: string | null;
  last: string | null;
  full_name: string;
  email: string;
  phone: string | null;
  phone2: string | null;
  heard_from: string | null;
  address1: string;
  address2: string | null;
  city: string;
  state: string;
  zip: string;
  type_id: number;
  type_name: string;
  is_confirmed: number;
  has_image: boolean;
  image_url: string;
  is_disabled: boolean;
  disabled_at: string | null;
  cars_count: number;
  date_registered: string | null;
  created_at: string | null;
  updated_at: string | null;
}

export interface AdminUserInput {
  [key: string]: unknown;
  first: string | null;
  last: string | null;
  email: string;
  phone: string | null;
  phone2: string | null;
  heard_from: string | null;
  address1: string;
  address2: string | null;
  city: string;
  state: string;
  zip: string;
  type_id: number;
}

export const usersApi = useAdminResource<AdminUser, AdminUserInput>('users');

export const USER_TYPE_OPTIONS = [
  { value: '1', label: 'Regular' },
  { value: '2', label: 'Judge' },
  { value: '3', label: 'Administrator' },
];

/** Form schema. Widths mirror the user table (the server validates them too). Passwords are not edited here. */
export const USER_FIELDS: AdminField[] = [
  { name: 'first', label: 'First name', maxlength: 20 },
  { name: 'last', label: 'Last name', maxlength: 20 },
  { name: 'email', label: 'Email', type: 'email', required: true, maxlength: 60 },
  { name: 'type_id', label: 'Account type', type: 'select', required: true, options: USER_TYPE_OPTIONS },
  { name: 'phone', label: 'Phone', maxlength: 15 },
  { name: 'phone2', label: 'Phone 2', maxlength: 15 },
  { name: 'address1', label: 'Street', required: true, maxlength: 60 },
  { name: 'address2', label: 'Street 2', maxlength: 60 },
  { name: 'city', label: 'City', required: true, maxlength: 60 },
  { name: 'state', label: 'State', required: true, maxlength: 10 },
  { name: 'zip', label: 'Zip', required: true, maxlength: 10 },
  { name: 'heard_from', label: 'Heard from', type: 'textarea', rows: 2, maxlength: 500 },
];

export const USER_COLUMNS: AdminColumn<AdminUser>[] = [
  { key: 'id', label: 'ID', sortable: true, hideOnMobile: true },
  { key: 'full_name', label: 'Name', sortable: true },
  { key: 'email', label: 'Email', sortable: true },
  { key: 'type_name', label: 'Type', sortable: true, hideOnMobile: true },
  { key: 'city', label: 'City, state', format: (row) => `${row.city}, ${row.state}`, hideOnMobile: true },
  { key: 'cars_count', label: 'Cars', align: 'right', hideOnMobile: true },
  { key: 'is_disabled', label: 'Status' },
];

export function toForm(user: AdminUser | null): FormValues {
  return {
    first: user?.first ?? '',
    last: user?.last ?? '',
    email: user?.email ?? '',
    type_id: user ? String(user.type_id) : '1',
    phone: user?.phone ?? '',
    phone2: user?.phone2 ?? '',
    address1: user?.address1 ?? '',
    address2: user?.address2 ?? '',
    city: user?.city ?? '',
    state: user?.state ?? '',
    zip: user?.zip ?? '',
    heard_from: user?.heard_from ?? '',
  };
}

function text(value: unknown): string | null {
  const trimmed = typeof value === 'string' ? value.trim() : '';
  return trimmed === '' ? null : trimmed;
}

function required(value: unknown): string {
  return typeof value === 'string' ? value.trim() : '';
}

export function toInput(values: FormValues): AdminUserInput {
  return {
    first: text(values.first),
    last: text(values.last),
    email: required(values.email),
    type_id: Number(values.type_id),
    phone: text(values.phone),
    phone2: text(values.phone2),
    address1: required(values.address1),
    address2: text(values.address2),
    city: required(values.city),
    state: required(values.state),
    zip: required(values.zip),
    heard_from: text(values.heard_from),
  };
}

/** Where "Log in as" lands: the member's site, reloaded so the session state is read fresh. */
export function goToSite(): void {
  window.location.assign('/#/');
}
