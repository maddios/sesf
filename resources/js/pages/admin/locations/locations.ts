import { api } from '../../../api/client';
import { ADMIN_API_BASE, useAdminResource } from '../../../composables/admin/useAdminResource';
import type { AdminField, FormValues } from '../../../components/admin/types';

/** One venue as GET /admin-api/locations returns it (app/Http/Resources/Admin/LocationResource.php). */
export interface Location {
  id: number;
  show_id: number | null;
  show_name: string | null;
  name: string;
  description: string | null;
  is_primary: boolean;
  sort_order: number;
  address1: string;
  address2: string | null;
  city: string;
  state: string;
  zip: string;
  has_coordinates: boolean;
  created_at: string | null;
  updated_at: string | null;
}

export interface LocationInput {
  [key: string]: unknown;
  show_id?: number;
  name: string;
  description: string | null;
  address1: string;
  address2: string | null;
  city: string;
  state: string;
  zip: string;
  is_primary: boolean;
}

export const locationsApi = useAdminResource<Location, LocationInput>('locations');

/** Saves the order of one event's locations. The server answers 422 when the list is not exactly that event's rows. */
export function saveLocationOrder(showId: number, ids: number[]): Promise<unknown> {
  return api.post<unknown>(`${ADMIN_API_BASE}/locations/reorder`, { show_id: showId, ids });
}

export const LOCATION_FIELDS: AdminField[] = [
  { name: 'name', label: 'Name', required: true, maxlength: 128, wide: true },
  { name: 'description', label: 'Description', type: 'textarea', rows: 3 },
  { name: 'address1', label: 'Street', required: true, maxlength: 60, wide: true, section: 'Address' },
  { name: 'address2', label: 'Street 2', maxlength: 60, wide: true },
  { name: 'city', label: 'City', required: true, maxlength: 60 },
  { name: 'state', label: 'State', required: true, maxlength: 10 },
  { name: 'zip', label: 'Zip', required: true, maxlength: 10 },
  { name: 'is_primary', label: 'Primary venue for this event', type: 'checkbox' },
];

export function toForm(location: Location | null): FormValues {
  return {
    name: location?.name ?? '',
    description: location?.description ?? '',
    address1: location?.address1 ?? '',
    address2: location?.address2 ?? '',
    city: location?.city ?? '',
    state: location?.state ?? '',
    zip: location?.zip ?? '',
    is_primary: location?.is_primary ?? false,
  };
}

function text(value: unknown): string | null {
  const trimmed = typeof value === 'string' ? value.trim() : '';
  return trimmed === '' ? null : trimmed;
}

function required(value: unknown): string {
  return typeof value === 'string' ? value.trim() : '';
}

export function toInput(values: FormValues): LocationInput {
  return {
    name: required(values.name),
    description: text(values.description),
    address1: required(values.address1),
    address2: text(values.address2),
    city: required(values.city),
    state: required(values.state),
    zip: required(values.zip),
    is_primary: values.is_primary === true,
  };
}
