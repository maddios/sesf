import { useAdminResource } from '../../../composables/admin/useAdminResource';
import type { AdminColumn, AdminField, FormValues } from '../../../components/admin/types';

/** One sponsor as GET /admin-api/sponsors returns it (app/Http/Resources/Admin/SponsorResource.php). */
export interface Sponsor {
  id: number;
  name: string;
  URL: string | null;
  description: string | null;
  short_description: string | null;
  internal_notes: string | null;
  is_enabled: boolean;
  sort_order: number;
  image_url: string;
  has_image: boolean;
  shows_count: number;
  primary_count: number;
  in_use: boolean;
  created_at: string | null;
  updated_at: string | null;
}

export interface SponsorInput {
  [key: string]: unknown;
  name: string;
  URL: string | null;
  description: string | null;
  short_description: string | null;
  internal_notes: string | null;
  is_enabled: boolean;
}

export const sponsorsApi = useAdminResource<Sponsor, SponsorInput>('sponsors');

/** Form schema. Widths mirror the sponsor table (the server validates them too). */
export const SPONSOR_FIELDS: AdminField[] = [
  { name: 'name', label: 'Name', required: true, maxlength: 255 },
  { name: 'URL', label: 'Website', type: 'url', maxlength: 255, hint: 'Full address, starting with https://' },
  { name: 'short_description', label: 'Short description', type: 'textarea', rows: 2, maxlength: 512, hint: 'Shown on cards. Leave blank to use the start of the description.' },
  { name: 'description', label: 'Description', type: 'textarea', rows: 6 },
  { name: 'internal_notes', label: 'Internal notes', type: 'textarea', rows: 3, hint: 'Staff only. Never shown on the site.' },
  { name: 'is_enabled', label: 'Enabled (shown on the site)', type: 'checkbox' },
];

export const SPONSOR_COLUMNS: AdminColumn<Sponsor>[] = [
  { key: 'image', label: 'Logo', srOnlyLabel: true, hideOnMobile: true, class: 'w-16' },
  { key: 'name', label: 'Name', sortable: true },
  { key: 'URL', label: 'Website', sortable: true },
  { key: 'is_enabled', label: 'Status', sortable: true },
  { key: 'shows_count', label: 'Shows', align: 'right', format: (row) => String(row.shows_count + row.primary_count) },
];

export function toForm(sponsor: Sponsor | null): FormValues {
  return {
    name: sponsor?.name ?? '',
    URL: sponsor?.URL ?? '',
    short_description: sponsor?.short_description ?? '',
    description: sponsor?.description ?? '',
    internal_notes: sponsor?.internal_notes ?? '',
    is_enabled: sponsor?.is_enabled ?? true,
  };
}

function text(value: unknown): string | null {
  const trimmed = typeof value === 'string' ? value.trim() : '';
  return trimmed === '' ? null : trimmed;
}

export function toInput(values: FormValues): SponsorInput {
  return {
    name: typeof values.name === 'string' ? values.name.trim() : '',
    URL: text(values.URL),
    short_description: text(values.short_description),
    description: text(values.description),
    internal_notes: text(values.internal_notes),
    is_enabled: values.is_enabled === true,
  };
}

/** Image URLs from the API are site-relative ("images/sponsor/3.jpg?v=..."); the SPA lives at "/". */
export function imageSrc(sponsor: Sponsor): string | null {
  if (!sponsor.has_image) return null;
  return /^(https?:)?\//.test(sponsor.image_url) ? sponsor.image_url : `/${sponsor.image_url}`;
}
