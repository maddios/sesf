import { vi } from 'vitest';
import { jsonResponse, SESSION_PAYLOAD } from './helpers';

export const ADMIN_SESSION = { ...SESSION_PAYLOAD, user_id: 1, user_level: 3, type_id: 3, first_name: 'Ada', profileURL: '/ada/u1' };

export interface SeenRequest {
  method: string;
  url: string;
  path: string;
  params: URLSearchParams;
  body: unknown;
}

export type AdminHandler = (request: SeenRequest) => Response | Promise<Response> | undefined;

/**
 * Replaces fetch for admin tests. The handler sees method, path, parsed query and JSON body and returns a Response,
 * or undefined for "unexpected" (the request rejects). The session check answers as an admin unless `session` is
 * given. Returns the mock and the list of requests seen.
 */
export function stubAdminFetch(handler: AdminHandler, session: unknown = ADMIN_SESSION) {
  const seen: SeenRequest[] = [];
  const fetchMock = vi.fn(async (input: string, init: RequestInit = {}) => {
    const method = init.method ?? 'GET';
    const [path = '', query = ''] = input.split('?');
    let body: unknown = init.body;
    if (typeof init.body === 'string') {
      try {
        body = JSON.parse(init.body);
      } catch {
        body = init.body;
      }
    }
    const request: SeenRequest = { method, url: input, path, params: new URLSearchParams(query), body };
    seen.push(request);
    if (method === 'POST' && path === '/login/authenticate') {
      return session === null ? jsonResponse(401, []) : jsonResponse(200, session);
    }
    const response = await handler(request);
    if (response === undefined) {
      throw new Error(`Unexpected request: ${method} ${input}`);
    }
    return response;
  });
  vi.stubGlobal('fetch', fetchMock);
  return { fetchMock, seen };
}

export function listBody<T>(data: T[], meta: Partial<{ page: number; per_page: number; total: number; last_page: number }> = {}) {
  return { data, meta: { page: 1, per_page: 25, total: data.length, last_page: 1, ...meta } };
}

export interface FakeXhr {
  method: string;
  url: string;
  headers: Record<string, string>;
  body: unknown;
  respond(status: number, body: unknown): void;
  progress(loaded: number, total: number): void;
  fail(): void;
}

/** Replaces XMLHttpRequest with a controllable fake; returns the instances created. */
export function stubXhr(): FakeXhr[] {
  const created: FakeXhr[] = [];
  class Fake implements FakeXhr {
    method = '';
    url = '';
    headers: Record<string, string> = {};
    body: unknown = null;
    status = 0;
    responseText = '';
    withCredentials = false;
    upload: { onprogress: ((event: ProgressEvent) => void) | null } = { onprogress: null };
    onload: (() => void) | null = null;
    onerror: (() => void) | null = null;
    constructor() {
      created.push(this);
    }
    open(method: string, url: string) {
      this.method = method;
      this.url = url;
    }
    setRequestHeader(name: string, value: string) {
      this.headers[name] = value;
    }
    send(body: unknown) {
      this.body = body;
    }
    respond(status: number, body: unknown) {
      this.status = status;
      this.responseText = typeof body === 'string' ? body : JSON.stringify(body);
      this.onload?.();
    }
    progress(loaded: number, total: number) {
      this.upload.onprogress?.({ lengthComputable: true, loaded, total } as ProgressEvent);
    }
    fail() {
      this.onerror?.();
    }
  }
  vi.stubGlobal('XMLHttpRequest', Fake);
  return created;
}
