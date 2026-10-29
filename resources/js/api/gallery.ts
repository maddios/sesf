import { api, readXsrfToken, ApiError } from './client';
import { asRecordOf, toBool, toImageUrl, toNumber, toRoutePath, toText } from './cars';

/** Gallery JSON (legacy GalleryController URLs and keys), normalized for the Vue pages. */

export interface GalleryShow {
  id: number;
  name: string;
  imageUrl: string;
}

export interface Gallery {
  id: number;
  showId: number;
  userId: number;
  parentId: number | null;
  title: string;
  isEnabled: boolean;
  coverItemId: number | null;
  coverImageUrl: string;
}

export interface GalleryItemUser {
  fullName: string;
  profilePath: string;
}

export interface GalleryItem {
  id: number;
  title: string;
  sortOrder: number | null;
  dateTaken: string;
  imageUrl: string;
  user: GalleryItemUser | null;
}

export interface GalleryItemsPage {
  items: GalleryItem[];
  count: number;
  perPage: number;
}

export interface GalleryBreadcrumb {
  show: { id: number; name: string };
  galleries: Gallery[];
}

export interface CheckedInState {
  checkedIn: boolean;
  memberGalleryId: number | null;
}

export interface ItemsQuery {
  showId?: number;
  galleryId?: number;
  withSubGalleries?: boolean;
  skip?: number;
  allItems?: boolean;
}

export interface ItemChange {
  id: number;
  title: string;
  sort_order: number;
}

type Raw = Record<string, unknown>;

function query(params: Record<string, number | boolean | undefined>): string {
  const search = new URLSearchParams();
  for (const [key, value] of Object.entries(params)) {
    if (value === undefined || value === false) continue;
    search.set(key, value === true ? '1' : String(value));
  }
  const text = search.toString();
  return text === '' ? '' : `?${text}`;
}

function toId(value: unknown): number | null {
  const n = toNumber(value);
  return n !== null && Number.isInteger(n) && n > 0 ? n : null;
}

function toList<T>(value: unknown, map: (raw: Raw) => T | null): T[] {
  return Array.isArray(value) ? value.map((row) => map(asRecordOf(row))).filter((row): row is T => row !== null) : [];
}

export function toGalleryShow(raw: Raw): GalleryShow | null {
  const id = toId(raw.id);
  return id === null ? null : { id, name: toText(raw.name), imageUrl: toImageUrl(raw.imageURL) };
}

export function toGallery(raw: Raw): Gallery | null {
  const id = toId(raw.id);
  if (id === null) return null;
  const cover = asRecordOf(raw.default_item);
  return {
    id,
    showId: toId(raw.show_id) ?? 0,
    userId: toId(raw.user_id) ?? 0,
    parentId: toId(raw.parent_id),
    title: toText(raw.title),
    isEnabled: toBool(raw.is_enabled),
    coverItemId: toId(cover.id) ?? toId(raw.default_item_id),
    coverImageUrl: toImageUrl(cover.imageURL),
  };
}

export function toGalleryItem(raw: Raw): GalleryItem | null {
  const id = toId(raw.id);
  if (id === null) return null;
  const user = raw.user === null || raw.user === undefined ? null : asRecordOf(raw.user);
  return {
    id,
    title: typeof raw.title === 'string' ? raw.title : '',
    sortOrder: toNumber(raw.sort_order),
    dateTaken: toText(raw.date_taken),
    imageUrl: toImageUrl(raw.imageURL),
    user: user === null ? null : { fullName: toText(user.fullName), profilePath: toRoutePath(user.profileURL) },
  };
}

export function toItemsPage(raw: unknown): GalleryItemsPage {
  const record = asRecordOf(raw);
  return {
    items: toList(record.items, toGalleryItem),
    count: toNumber(record.count) ?? 0,
    perPage: toNumber(record.per_page) ?? 0,
  };
}

export async function fetchGalleryShows(): Promise<GalleryShow[]> {
  return toList(await api.get('/gallery/car-show-list'), toGalleryShow);
}

export async function fetchGalleryInfo(galleryId: number): Promise<Gallery | null> {
  return toGallery(asRecordOf(await api.get(`/gallery/info${query({ gallery_id: galleryId })}`)));
}

export async function fetchGalleryList(showId: number, galleryId?: number): Promise<Gallery[]> {
  return toList(await api.get(`/gallery/list${query({ show_id: showId, gallery_id: galleryId })}`), toGallery);
}

export async function fetchBreadcrumb(showId: number, galleryId?: number): Promise<GalleryBreadcrumb> {
  const raw = asRecordOf(await api.get(`/gallery/breadcrumb${query({ show_id: showId, gallery_id: galleryId })}`));
  const show = asRecordOf(raw.car_show);
  return {
    show: { id: toId(show.id) ?? showId, name: toText(show.name) },
    galleries: toList(raw.galleries, toGallery),
  };
}

export async function fetchGalleryItems(params: ItemsQuery): Promise<GalleryItemsPage> {
  const path = `/gallery/items${query({
    show_id: params.showId,
    gallery_id: params.galleryId,
    skip: params.skip,
    get_subcategory_items: params.withSubGalleries,
    all_items: params.allItems,
  })}`;
  return toItemsPage(await api.get(path));
}

export async function fetchCheckedIn(showId: number): Promise<CheckedInState> {
  const raw = asRecordOf(await api.get(`/gallery/checked-in${query({ show_id: showId })}`));
  return { checkedIn: raw.checked_in === true, memberGalleryId: toId(raw.member_gallery_id) };
}

export async function createGallery(body: { showId: number; title?: string; parentId?: number }): Promise<Gallery> {
  const payload: Record<string, unknown> = { show_id: body.showId };
  if (body.title !== undefined) payload.title = body.title;
  if (body.parentId !== undefined) payload.gallery_id = body.parentId;
  const gallery = toGallery(asRecordOf(await api.post('/gallery/create', payload)));
  if (gallery === null) throw new ApiError(500, null, 'The gallery could not be created.');
  return gallery;
}

export async function saveGalleryItems(galleryId: number, items: ItemChange[]): Promise<GalleryItemsPage> {
  return toItemsPage(await api.post('/gallery/items', { gallery_id: galleryId, items }));
}

export async function setGalleryEnabled(galleryId: number, enabled: boolean): Promise<void> {
  await api.post('/gallery/show-hide', { gallery_id: galleryId, is_enabled: enabled ? 1 : 0 });
}

export async function setGalleryCover(galleryId: number, itemId: number): Promise<void> {
  await api.post('/gallery/default-item', { gallery_id: galleryId, item_id: itemId });
}

export async function removeGalleryItem(galleryId: number, itemId: number): Promise<void> {
  await api.post('/gallery/remove-item', { gallery_id: galleryId, item_id: itemId });
}

/** First readable message of an error body: {error}, a string, or a list/object of messages. */
export function errorMessage(error: unknown, fallback: string): string {
  if (!(error instanceof ApiError)) return fallback;
  const body = error.body;
  if (typeof body === 'string' && body.trim() !== '') return body;
  const record = asRecordOf(body);
  if (typeof record.error === 'string') return record.error;
  const values = Array.isArray(body) ? body : Object.values(record).flat();
  const first = values.find((value): value is string => typeof value === 'string');
  return first ?? fallback;
}

/**
 * Uploads one photo (multipart: file, gallery_id, last_modified, last_modified_date), reporting progress
 * from 0 to 1. Uses XMLHttpRequest because fetch has no upload progress events. Rejects with ApiError; an abort
 * through `signal` rejects with status 0.
 */
export function uploadGalleryPhoto(
  galleryId: number,
  file: File,
  onProgress: (fraction: number) => void,
  signal?: AbortSignal,
): Promise<void> {
  const form = new FormData();
  form.append('file', file);
  form.append('gallery_id', String(galleryId));
  form.append('last_modified', String(file.lastModified));
  form.append('last_modified_date', String(new Date(file.lastModified)));

  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    xhr.open('POST', '/gallery/upload-file');
    xhr.withCredentials = true;
    xhr.setRequestHeader('Accept', 'application/json');
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    const token = readXsrfToken();
    if (token !== undefined) xhr.setRequestHeader('X-XSRF-TOKEN', token);

    xhr.upload.onprogress = (event) => {
      if (event.lengthComputable && event.total > 0) onProgress(event.loaded / event.total);
    };
    xhr.onload = () => {
      if (xhr.status >= 200 && xhr.status < 300) {
        onProgress(1);
        resolve();
        return;
      }
      let body: unknown = xhr.responseText;
      try {
        body = JSON.parse(xhr.responseText) as unknown;
      } catch {
        // Non-JSON error body: keep the text.
      }
      reject(new ApiError(xhr.status, body));
    };
    xhr.onerror = () => reject(new ApiError(0, null, 'Network error'));
    xhr.onabort = () => reject(new ApiError(0, null, 'Upload cancelled'));
    signal?.addEventListener('abort', () => xhr.abort(), { once: true });
    xhr.send(form);
  });
}
