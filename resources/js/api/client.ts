export class ApiError extends Error {
  readonly status: number;
  readonly body: unknown;
  /** True for 419 (CSRF token mismatch). Never retried; the caller decides what to do. */
  readonly isCsrfMismatch: boolean;

  constructor(status: number, body: unknown, message = `Request failed with status ${status}`) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.body = body;
    this.isCsrfMismatch = status === 419;
  }
}

export interface ApiClientOptions {
  /** Called on any 401 response, before the ApiError is thrown. */
  onUnauthorized?: () => void;
  fetchImpl?: typeof fetch;
}

/** Bodies sent untouched; the browser sets Content-Type (multipart boundary for FormData). */
export type PassthroughBody = FormData | Blob | URLSearchParams | ArrayBuffer;

export type RequestBody = Record<string, unknown> | unknown[] | string | number | boolean | null | PassthroughBody;

export interface ApiClient {
  request<T = unknown>(method: string, path: string, body?: RequestBody): Promise<T>;
  get<T = unknown>(path: string): Promise<T>;
  post<T = unknown>(path: string, body?: RequestBody): Promise<T>;
  put<T = unknown>(path: string, body?: RequestBody): Promise<T>;
  patch<T = unknown>(path: string, body?: RequestBody): Promise<T>;
  delete<T = unknown>(path: string): Promise<T>;
}

/** Reads the XSRF-TOKEN cookie set by Laravel. Returns undefined when absent. */
export function readXsrfToken(cookieString: string = typeof document === 'undefined' ? '' : document.cookie): string | undefined {
  for (const part of cookieString.split(';')) {
    const [name, ...rest] = part.trim().split('=');
    if (name === 'XSRF-TOKEN') {
      const raw = rest.join('=');
      try {
        return decodeURIComponent(raw);
      } catch {
        // Malformed percent-encoding: fall back to the raw cookie value.
        return raw;
      }
    }
  }
  return undefined;
}

function isPassthroughBody(body: RequestBody): body is PassthroughBody {
  return body instanceof FormData || body instanceof Blob || body instanceof URLSearchParams || body instanceof ArrayBuffer;
}

/** Reads the body as text and parses JSON when possible; empty bodies become null. Throws only if reading fails. */
async function readPayload(response: Response): Promise<unknown> {
  if (response.status === 204) {
    return undefined;
  }
  const text = await response.text();
  if (text === '') {
    return null;
  }
  try {
    return JSON.parse(text) as unknown;
  } catch {
    return text;
  }
}

let unauthorizedHandler: (() => void) | undefined;

/** Registers the handler the exported `api` singleton calls on 401 (e.g. the auth store's clear()). */
export function setOnUnauthorized(fn: (() => void) | undefined): void {
  unauthorizedHandler = fn;
}

export function createApiClient(options: ApiClientOptions = {}): ApiClient {
  const fetchImpl = options.fetchImpl ?? ((...args: Parameters<typeof fetch>) => fetch(...args));

  async function request<T = unknown>(method: string, path: string, body?: RequestBody): Promise<T> {
    const headers: Record<string, string> = {
      Accept: 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
    };
    const token = readXsrfToken();
    if (token) {
      headers['X-XSRF-TOKEN'] = token;
    }
    const init: RequestInit = { method, headers, credentials: 'same-origin' };
    if (body !== undefined) {
      if (isPassthroughBody(body)) {
        init.body = body;
      } else {
        headers['Content-Type'] = 'application/json';
        init.body = JSON.stringify(body);
      }
    }

    const response = await fetchImpl(path, init);

    let payload: unknown = null;
    let readFailed = false;
    try {
      payload = await readPayload(response);
    } catch {
      readFailed = true;
    }

    if (response.status === 401) {
      options.onUnauthorized?.();
    }
    if (!response.ok || readFailed) {
      const message = readFailed ? `Failed to read response body (status ${response.status})` : undefined;
      throw new ApiError(response.status, payload, message);
    }
    return payload as T;
  }

  return {
    request,
    get: (path) => request('GET', path),
    post: (path, body) => request('POST', path, body),
    put: (path, body) => request('PUT', path, body),
    patch: (path, body) => request('PATCH', path, body),
    delete: (path) => request('DELETE', path),
  };
}

export const api = createApiClient({ onUnauthorized: () => unauthorizedHandler?.() });
