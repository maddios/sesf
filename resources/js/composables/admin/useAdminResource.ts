import { api, ApiError, readXsrfToken, type RequestBody } from '../../api/client';

/**
 * Typed CRUD wrapper for an admin-api resource (/admin-api/{resource}), plus the standard error mapping used by
 * every admin page. Server contract (docs/admin-conventions.md):
 *  - list:   GET    /admin-api/{r}?page&per_page&sort&direction&search&filter[col]=v|filter[col][op]=v
 *            -> { data: T[], meta: { page, per_page, total, last_page } }
 *  - show:   GET    /admin-api/{r}/{id}            -> { data: T }
 *  - create: POST   /admin-api/{r}                 -> 201 { data: T }
 *  - update: PUT    /admin-api/{r}/{id}            -> { data: T }
 *  - delete: DELETE /admin-api/{r}/{id}            -> 204, or 409 { message, references }
 *  - action: POST   /admin-api/{r}/{id}/{action}   -> { data: T }
 *  - upload: POST   /admin-api/{r}/{id}/{path}     multipart "file" -> { data: T }
 * Errors: 422 { message, errors: { field: [..] } }; legacy endpoints answer 400 [messages].
 */

export const ADMIN_API_BASE = '/admin-api';

export type Id = number | string;

export type FilterOperator = 'eq' | 'like' | 'in' | 'between' | 'null';

/** filter[col]=v (eq) or filter[col][op]=v; arrays are sent as op[]=a&op[]=b. */
export type FilterValue = string | Partial<Record<FilterOperator, string | string[]>>;

export interface ListQuery {
  page?: number;
  per_page?: number;
  sort?: string;
  direction?: 'asc' | 'desc';
  search?: string;
  filter?: Record<string, FilterValue | undefined>;
}

export interface ListMeta {
  page: number;
  per_page: number;
  total: number;
  last_page: number;
}

export interface ListResponse<T> {
  data: T[];
  meta: ListMeta;
}

export interface AdminErrorInfo {
  status: number;
  /** One line for a banner or toast. */
  message: string;
  /** Field => messages (422 errors, or a legacy keyed 400). Empty when the error is not about fields. */
  fieldErrors: Record<string, string[]>;
  body: unknown;
}

export interface UploadOptions {
  /** Path segment after the id (default "image"). */
  path?: string;
  /** Multipart field name (default "file"). */
  field?: string;
  /** Called with 0..100 while the body uploads. */
  onProgress?: (percent: number) => void;
}

/** Serialises a ListQuery into a query string (no leading "?"). Empty values are left out. */
export function buildQueryString(query: ListQuery): string {
  const params = new URLSearchParams();
  const scalar: (keyof ListQuery)[] = ['page', 'per_page', 'sort', 'direction', 'search'];
  for (const key of scalar) {
    const value = query[key];
    if (value !== undefined && value !== null && value !== '') {
      params.append(key, String(value));
    }
  }
  for (const [column, spec] of Object.entries(query.filter ?? {})) {
    if (spec === undefined || spec === '') {
      continue;
    }
    if (typeof spec === 'string') {
      params.append(`filter[${column}]`, spec);
      continue;
    }
    for (const [op, value] of Object.entries(spec)) {
      if (Array.isArray(value)) {
        value.forEach((item) => params.append(`filter[${column}][${op}][]`, item));
      } else if (value !== undefined && value !== '') {
        params.append(`filter[${column}][${op}]`, value);
      }
    }
  }
  return params.toString();
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function stringList(value: unknown): string[] {
  if (typeof value === 'string') {
    return value.trim() === '' ? [] : [value];
  }
  return Array.isArray(value) ? value.filter((item): item is string => typeof item === 'string' && item.trim() !== '') : [];
}

const STATUS_MESSAGES: Record<number, string> = {
  401: 'Your session has ended. Please sign in again.',
  403: 'You do not have permission to do that.',
  404: 'That record no longer exists.',
  419: 'This page has expired. Reload the page and try again.',
  429: 'Too many changes in a short time. Wait a minute and try again.',
};

/**
 * Maps any thrown error to { status, message, fieldErrors }. Understands Laravel 422 {message, errors}, the
 * legacy 400 arrays (["msg", ...] or {field: ["msg"]}), {message} bodies (409 conflicts) and network failures.
 */
export function mapAdminError(error: unknown, fallback = 'Something went wrong. Please try again.'): AdminErrorInfo {
  if (!(error instanceof ApiError)) {
    return { status: 0, message: fallback, fieldErrors: {}, body: null };
  }
  const { status, body } = error;
  const fieldErrors: Record<string, string[]> = {};
  let message = '';

  if (Array.isArray(body)) {
    message = stringList(body).join(' ');
  } else if (isRecord(body)) {
    const errors = body.errors;
    if (isRecord(errors)) {
      for (const [field, list] of Object.entries(errors)) {
        const items = stringList(list);
        if (items.length > 0) {
          fieldErrors[field] = items;
        }
      }
    }
    if (typeof body.message === 'string') {
      message = body.message;
    } else if (status === 400 && !isRecord(errors)) {
      // Legacy keyed 400: { field: ["msg"] }.
      for (const [field, list] of Object.entries(body)) {
        const items = stringList(list);
        if (items.length > 0) {
          fieldErrors[field] = items;
        }
      }
    }
  } else if (typeof body === 'string' && body.length < 300 && !body.trimStart().startsWith('<')) {
    message = body;
  }

  if (status === 422 && Object.keys(fieldErrors).length > 0) {
    message = 'Please correct the highlighted fields.';
  }
  if (message === '' && Object.keys(fieldErrors).length > 0) {
    message = Object.values(fieldErrors).flat()[0] ?? fallback;
  }
  if (status >= 500) {
    // Server errors carry nothing useful for the admin ("Server Error"); the caller's text explains what failed.
    message = '';
  }
  if (STATUS_MESSAGES[status] !== undefined && (status !== 404 || message === '')) {
    message = STATUS_MESSAGES[status];
  }
  return { status, message: message === '' ? fallback : message, fieldErrors, body };
}

/** Laravel JsonResource wraps single records in { data }. */
function unwrap<T>(body: unknown): T {
  return (isRecord(body) && 'data' in body ? body.data : body) as T;
}

function parseBody(text: string): unknown {
  if (text === '') {
    return null;
  }
  try {
    return JSON.parse(text) as unknown;
  } catch {
    return text;
  }
}

/** Multipart POST with upload progress (fetch cannot report it). Same headers as the api client. */
export function uploadWithProgress(url: string, form: FormData, onProgress?: (percent: number) => void): Promise<unknown> {
  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    xhr.open('POST', url);
    xhr.withCredentials = true;
    xhr.setRequestHeader('Accept', 'application/json');
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    const token = readXsrfToken();
    if (token) {
      xhr.setRequestHeader('X-XSRF-TOKEN', token);
    }
    xhr.upload.onprogress = (event: ProgressEvent) => {
      if (event.lengthComputable && event.total > 0) {
        onProgress?.(Math.round((event.loaded / event.total) * 100));
      }
    };
    xhr.onload = () => {
      const body = parseBody(xhr.responseText);
      if (xhr.status >= 200 && xhr.status < 300) {
        onProgress?.(100);
        resolve(body);
      } else {
        reject(new ApiError(xhr.status, body));
      }
    };
    xhr.onerror = () => reject(new ApiError(0, null, 'Network error'));
    xhr.send(form);
  });
}

export interface AdminResource<T, TInput> {
  readonly url: string;
  list(query?: ListQuery): Promise<ListResponse<T>>;
  get(id: Id): Promise<T>;
  create(input: TInput): Promise<T>;
  update(id: Id, input: TInput): Promise<T>;
  remove(id: Id): Promise<void>;
  action(id: Id, name: string, body?: RequestBody): Promise<T>;
  upload(id: Id, file: File, options?: UploadOptions): Promise<T>;
}

/**
 * CRUD client for /admin-api/{resource}. Plain functions over the shared api client (CSRF header, 401 handler).
 * Example: `const sponsors = useAdminResource<Sponsor, SponsorInput>('sponsors')`.
 */
export function useAdminResource<T, TInput extends RequestBody = Record<string, unknown>>(
  resource: string,
  base: string = ADMIN_API_BASE,
): AdminResource<T, TInput> {
  const url = `${base}/${resource}`;
  const item = (id: Id) => `${url}/${encodeURIComponent(String(id))}`;

  return {
    url,
    async list(query: ListQuery = {}) {
      const qs = buildQueryString(query);
      return api.get<ListResponse<T>>(qs === '' ? url : `${url}?${qs}`);
    },
    async get(id) {
      return unwrap<T>(await api.get<unknown>(item(id)));
    },
    async create(input) {
      return unwrap<T>(await api.post<unknown>(url, input));
    },
    async update(id, input) {
      return unwrap<T>(await api.put<unknown>(item(id), input));
    },
    async remove(id) {
      await api.delete<unknown>(item(id));
    },
    async action(id, name, body) {
      return unwrap<T>(await api.post<unknown>(`${item(id)}/${name}`, body ?? {}));
    },
    async upload(id, file, options = {}) {
      const form = new FormData();
      form.append(options.field ?? 'file', file);
      return unwrap<T>(await uploadWithProgress(`${item(id)}/${options.path ?? 'image'}`, form, options.onProgress));
    },
  };
}
