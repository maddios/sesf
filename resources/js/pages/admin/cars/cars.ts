import { useAdminResource } from '../../../composables/admin/useAdminResource';
import type { AdminColumn, AdminField, FormValues } from '../../../components/admin/types';

/** One car as GET /admin-api/cars returns it (app/Http/Resources/Admin/CarResource.php). */
export interface Car {
  id: number;
  user_id: number | null;
  owner_name: string | null;
  owner_email: string | null;
  type_id: number;
  type_name: string | null;
  body_id: number;
  body_name: string | null;
  year: string | null;
  model: string | null;
  nickname: string | null;
  VIN: string | null;
  is_confirmed: boolean;
  has_image: boolean;
  image_url: string;
  registrations_count: number;
  /** Events the car is entered in (read-only; edited on the registration). */
  shows: Array<{ registration_id: number; show_id: number; show_name: string | null }>;
  in_use: boolean;
  created_at: string | null;
  updated_at: string | null;
}

export interface CarInput {
  [key: string]: unknown;
  user_id: number | null;
  type_id: number;
  body_id: number;
  year: string | null;
  model: string | null;
  nickname: string | null;
  VIN: string | null;
}

export interface CarOption {
  id: number;
  label: string;
}

export interface CarOptions {
  types: CarOption[];
  bodies: CarOption[];
}

export const carsApi = useAdminResource<Car, CarInput>('cars');

export function carFields(options: CarOptions): AdminField[] {
  return [
    { name: 'user_id', label: 'Owner', section: 'Owner', wide: true, hint: 'Leave empty for a sold car.' },
    { name: 'type_id', label: 'Type', type: 'select', required: true, section: 'Vehicle', options: options.types.map(toSelect) },
    { name: 'body_id', label: 'Body', type: 'select', required: true, options: options.bodies.map(toSelect), hint: 'Body style, e.g. coupe or sedan.' },
    { name: 'year', label: 'Year', maxlength: 5 },
    { name: 'model', label: 'Model', maxlength: 30 },
    { name: 'nickname', label: 'Nickname', maxlength: 64 },
    { name: 'VIN', label: 'VIN', maxlength: 20 },
  ];
}

function toSelect(option: CarOption): { value: string; label: string } {
  return { value: String(option.id), label: option.label };
}

export const CAR_COLUMNS: AdminColumn<Car>[] = [
  { key: 'image', label: 'Photo', srOnlyLabel: true, hideOnMobile: true, class: 'w-16' },
  { key: 'id', label: 'ID', sortable: true, hideOnMobile: true },
  { key: 'model', label: 'Year, model', sortable: true, format: (row) => `${row.year ?? ''} ${row.model ?? ''}`.trim() },
  { key: 'nickname', label: 'Nickname', sortable: true, hideOnMobile: true },
  { key: 'owner_name', label: 'Owner' },
  { key: 'type_name', label: 'Type', hideOnMobile: true },
  { key: 'body_name', label: 'Body', hideOnMobile: true },
  { key: 'VIN', label: 'VIN', sortable: true, hideOnMobile: true },
  { key: 'profile', label: 'Profile link', hideOnMobile: true },
  { key: 'shows', label: 'Shows', hideOnMobile: true, format: (row) => row.shows.map((show) => show.show_name ?? `#${show.show_id}`).join(', ') || '—' },
  { key: 'is_confirmed', label: 'Status', sortable: true },
];

/** The public car profile (legacy Car::profileURL). */
export function profileHref(car: Car): string {
  return `/#/car/${car.id}`;
}

export function toForm(car: Car | null): FormValues {
  return {
    user_id: car?.user_id ?? null,
    type_id: car ? String(car.type_id) : '',
    body_id: car ? String(car.body_id) : '',
    year: car?.year ?? '',
    model: car?.model ?? '',
    nickname: car?.nickname ?? '',
    VIN: car?.VIN ?? '',
  };
}

function text(value: unknown): string | null {
  const trimmed = typeof value === 'string' ? value.trim() : '';
  return trimmed === '' ? null : trimmed;
}

export function toInput(values: FormValues): CarInput {
  return {
    user_id: typeof values.user_id === 'number' ? values.user_id : null,
    type_id: Number(values.type_id),
    body_id: Number(values.body_id),
    year: text(values.year),
    model: text(values.model),
    nickname: text(values.nickname),
    VIN: text(values.VIN),
  };
}

/** Image URLs from the API are site-relative ("images/cars/3/profile.jpg?v=..."); the SPA lives at "/". */
export function imageSrc(car: Car): string | null {
  if (!car.has_image) return null;
  return /^(https?:)?\//.test(car.image_url) ? car.image_url : `/${car.image_url}`;
}
