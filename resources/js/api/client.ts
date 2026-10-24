export class ApiError extends Error {
  readonly status: number;
  readonly body: unknown;

  constructor(status: number, body: unknown, message = `Request failed with status ${status}`) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.body = body;
  }
}

export interface ApiClientOptions {
  /** Called on any 401 response, before the ApiError is thrown. */
  onUnauthorized?: () => void;
  fetchImpl?: typeof fetch;
}

export type RequestBody = Record<string, unknown> | unknown[] | string | number | boolean | null;

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
      return decodeURIComponent(rest.join('='));
    }
  }
  return undefined;
}

async function parseBody(response: Response): Promise<unknown> {
  if (response.status === 204) {
    return undefined;
  }
  const contentType = response.headers.get('Content-Type') ?? '';
  if (contentType.includes('application/json')) {
    return response.json();
  }
  return response.text();
}

export function createApiClient(options: ApiClientOptions = {}): ApiClient {
  const fetchImpl = options.fetchImpl ?? ((...args: Parameters<typeof fetch>) => fetch(...args));

  async function request<T = unknown>(method: string, path: string, body?: RequestBody): Promise<T> {
    const headers: Record<string, string> = { Accept: 'application/json' };
    const token = readXsrfToken();
    if (token) {
      headers['X-XSRF-TOKEN'] = token;
    }
    const init: RequestInit = { method, headers, credentials: 'same-origin' };
    if (body !== undefined) {
      headers['Content-Type'] = 'application/json';
      init.body = JSON.stringify(body);
    }

    const response = await fetchImpl(path, init);
    const payload = await parseBody(response);

    if (!response.ok) {
      if (response.status === 401) {
        options.onUnauthorized?.();
      }
      throw new ApiError(response.status, payload);
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

export const api = createApiClient();
