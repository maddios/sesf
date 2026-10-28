import { describe, it, expect, vi } from 'vitest';
import {
  errorMessage,
  fetchGalleryItems,
  toGallery,
  toGalleryItem,
  toItemsPage,
  uploadGalleryPhoto,
} from '../gallery';
import { ApiError } from '../client';
import { jsonResponse, stubFetch } from '../../test/helpers';

class FakeXhr {
  static instances: FakeXhr[] = [];
  method = '';
  url = '';
  headers: Record<string, string> = {};
  body: FormData | null = null;
  status = 0;
  responseText = '';
  aborted = false;
  withCredentials = false;
  upload: { onprogress: ((event: ProgressEvent) => void) | null } = { onprogress: null };
  onload: (() => void) | null = null;
  onerror: (() => void) | null = null;
  onabort: (() => void) | null = null;

  constructor() {
    FakeXhr.instances.push(this);
  }
  open(method: string, url: string) {
    this.method = method;
    this.url = url;
  }
  setRequestHeader(name: string, value: string) {
    this.headers[name] = value;
  }
  send(body: FormData) {
    this.body = body;
  }
  abort() {
    this.aborted = true;
    this.onabort?.();
  }
  respond(status: number, body: unknown) {
    this.status = status;
    this.responseText = typeof body === 'string' ? body : JSON.stringify(body);
    this.onload?.();
  }
}

function stubXhr(): void {
  FakeXhr.instances = [];
  vi.stubGlobal('XMLHttpRequest', FakeXhr);
}

function lastXhr(): FakeXhr {
  const xhr = FakeXhr.instances[FakeXhr.instances.length - 1];
  if (xhr === undefined) throw new Error('No XMLHttpRequest was created');
  return xhr;
}

const LAST_MODIFIED = 1_700_000_000_000;

function photo(): File {
  return new File(['pixels'], 'front.jpg', { type: 'image/jpeg', lastModified: LAST_MODIFIED });
}

describe('gallery normalization', () => {
  it('maps a gallery row to camelCase, prefixes image paths and reads the cover', () => {
    const gallery = toGallery({
      id: '5',
      show_id: '36',
      user_id: '7',
      parent_id: null,
      title: 'Cars',
      is_enabled: 1,
      default_item: { id: 55, imageURL: 'images/gallery/0/0/5/5.jpg?123' },
    });
    expect(gallery).toEqual({
      id: 5,
      showId: 36,
      userId: 7,
      parentId: null,
      title: 'Cars',
      isEnabled: true,
      coverItemId: 55,
      coverImageUrl: '/images/gallery/0/0/5/5.jpg?123',
    });
  });

  it('treats is_enabled 0 as disabled', () => {
    expect(toGallery({ id: 5, is_enabled: 0 })?.isEnabled).toBe(false);
  });

  it('falls back to default_item_id when default_item is absent', () => {
    expect(toGallery({ id: 5, default_item_id: '9' })).toMatchObject({ coverItemId: 9, coverImageUrl: '' });
  });

  it('drops a gallery without a usable id', () => {
    expect(toGallery({ title: 'No id' })).toBeNull();
    expect(toGallery({ id: 0 })).toBeNull();
  });

  it('maps an item and its uploader profile path', () => {
    const item = toGalleryItem({
      id: 1,
      title: 'Front',
      sort_order: '2',
      date_taken: '2026-04-30',
      imageURL: 'images/gallery/0/0/5/1.jpg',
      user: { fullName: 'Jane Doe', profileURL: '#/Jane/u7' },
    });
    expect(item).toEqual({
      id: 1,
      title: 'Front',
      sortOrder: 2,
      dateTaken: '2026-04-30',
      imageUrl: '/images/gallery/0/0/5/1.jpg',
      user: { fullName: 'Jane Doe', profilePath: '/Jane/u7' },
    });
  });

  it('keeps a null uploader as null', () => {
    expect(toGalleryItem({ id: 1, user: null })?.user).toBeNull();
  });

  it('reads an items page, defaulting missing counts to 0 and dropping bad rows', () => {
    expect(toItemsPage({ items: [{ id: 1 }, { title: 'no id' }], count: '3', per_page: 2 })).toEqual({
      items: [expect.objectContaining({ id: 1 })],
      count: 3,
      perPage: 2,
    });
    expect(toItemsPage(null)).toEqual({ items: [], count: 0, perPage: 0 });
  });
});

describe('fetchGalleryItems', () => {
  it('builds the query in show, gallery, skip, sub-gallery, all-items order', async () => {
    const fetchMock = stubFetch({
      'GET /gallery/items?show_id=36&gallery_id=5&skip=24&get_subcategory_items=1&all_items=1': () =>
        jsonResponse(200, { items: [], count: 0, per_page: 24 }),
    });
    await fetchGalleryItems({ showId: 36, galleryId: 5, skip: 24, withSubGalleries: true, allItems: true });
    expect(fetchMock).toHaveBeenCalledWith(
      '/gallery/items?show_id=36&gallery_id=5&skip=24&get_subcategory_items=1&all_items=1',
      expect.anything(),
    );
  });

  it('omits undefined and false parameters', async () => {
    const fetchMock = stubFetch({
      'GET /gallery/items?show_id=36': () => jsonResponse(200, { items: [], count: 0, per_page: 0 }),
    });
    await fetchGalleryItems({ showId: 36, galleryId: undefined, withSubGalleries: false, allItems: false });
    expect(fetchMock).toHaveBeenCalledWith('/gallery/items?show_id=36', expect.anything());
  });
});

describe('errorMessage', () => {
  it('reads the error field of a JSON body', () => {
    expect(errorMessage(new ApiError(403, { error: 'Must be checked in for this event.' }), 'fallback')).toBe(
      'Must be checked in for this event.',
    );
  });

  it('returns a plain string body as is', () => {
    expect(errorMessage(new ApiError(500, 'Server exploded'), 'fallback')).toBe('Server exploded');
  });

  it('returns the first message of a validation body', () => {
    expect(errorMessage(new ApiError(422, { title: ['The title is required.'] }), 'fallback')).toBe(
      'The title is required.',
    );
  });

  it('uses the fallback for an empty body or a non-API error', () => {
    expect(errorMessage(new ApiError(500, null), 'fallback')).toBe('fallback');
    expect(errorMessage(new Error('boom'), 'fallback')).toBe('fallback');
  });
});

describe('uploadGalleryPhoto', () => {
  it('posts the file and its metadata as multipart form data', async () => {
    stubXhr();
    void uploadGalleryPhoto(5, photo(), () => undefined);
    const xhr = lastXhr();
    expect(xhr.method).toBe('POST');
    expect(xhr.url).toBe('/gallery/upload-file');
    expect(xhr.headers['X-Requested-With']).toBe('XMLHttpRequest');
    const form = xhr.body as FormData;
    expect(form.get('file')).toBeInstanceOf(File);
    expect((form.get('file') as File).name).toBe('front.jpg');
    expect(form.get('gallery_id')).toBe('5');
    expect(form.get('last_modified')).toBe(String(LAST_MODIFIED));
    expect(form.get('last_modified_date')).toBe(String(new Date(LAST_MODIFIED)));
  });

  it('reports progress as a fraction and 1 on success', async () => {
    stubXhr();
    const progress: number[] = [];
    const done = uploadGalleryPhoto(5, photo(), (fraction) => progress.push(fraction));
    const xhr = lastXhr();
    xhr.upload.onprogress?.({ lengthComputable: true, loaded: 50, total: 200 } as ProgressEvent);
    xhr.respond(200, {});
    await done;
    expect(progress).toEqual([0.25, 1]);
  });

  it('rejects with the ApiError status and parsed body on a 403', async () => {
    stubXhr();
    const done = uploadGalleryPhoto(5, photo(), () => undefined);
    lastXhr().respond(403, { error: 'Forbidden' });
    const error = await done.catch((failure: unknown) => failure);
    expect(error).toBeInstanceOf(ApiError);
    expect(error).toMatchObject({ status: 403, body: { error: 'Forbidden' } });
  });

  it('rejects with status 0 when the signal aborts the upload', async () => {
    stubXhr();
    const controller = new AbortController();
    const done = uploadGalleryPhoto(5, photo(), () => undefined, controller.signal);
    controller.abort();
    await expect(done).rejects.toMatchObject({ status: 0 });
    expect(lastXhr().aborted).toBe(true);
  });
});
