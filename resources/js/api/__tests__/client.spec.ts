import { describe, it, expect, vi, afterEach } from 'vitest';
import { ApiError, api, createApiClient, readXsrfToken, setOnUnauthorized } from '../client';

function jsonResponse(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  });
}

function headersOf(fetchImpl: ReturnType<typeof vi.fn>, call = 0): Record<string, string> {
  return (fetchImpl.mock.calls[call] as [string, RequestInit])[1].headers as Record<string, string>;
}

afterEach(() => {
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
  setOnUnauthorized(undefined);
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

describe('readXsrfToken', () => {
  it('returns the decoded XSRF-TOKEN cookie value', () => {
    expect(readXsrfToken('a=1; XSRF-TOKEN=abc%3D%3D; b=2')).toBe('abc==');
  });

  it('returns undefined when the cookie is absent', () => {
    expect(readXsrfToken('a=1')).toBeUndefined();
  });

  it('returns the raw value instead of throwing on malformed percent-encoding', () => {
    expect(readXsrfToken('XSRF-TOKEN=abc%E0%A4%A')).toBe('abc%E0%A4%A');
  });
});

describe('api client', () => {
  it('sends JSON and the X-XSRF-TOKEN header from the cookie', async () => {
    document.cookie = 'XSRF-TOKEN=tok%2Ben; path=/';
    const fetchImpl = vi.fn().mockResolvedValue(jsonResponse(200, { ok: true }));
    const api = createApiClient({ fetchImpl });

    const result = await api.post('/api/things', { name: 'x' });

    expect(result).toEqual({ ok: true });
    const [url, init] = fetchImpl.mock.calls[0] as [string, RequestInit];
    expect(url).toBe('/api/things');
    expect(init.method).toBe('POST');
    expect(init.credentials).toBe('same-origin');
    expect(init.body).toBe(JSON.stringify({ name: 'x' }));
    const headers = headersOf(fetchImpl);
    expect(headers['X-XSRF-TOKEN']).toBe('tok+en');
    expect(headers['Content-Type']).toBe('application/json');
  });

  it('exposes put, patch and delete wrappers with the matching methods', async () => {
    const fetchImpl = vi.fn().mockImplementation(async () => jsonResponse(200, { ok: true }));
    const api = createApiClient({ fetchImpl });
    await api.put('/a', { x: 1 });
    await api.patch('/b', { y: 2 });
    await api.delete('/c').catch(() => undefined);
    expect((fetchImpl.mock.calls[0] as [string, RequestInit])[1].method).toBe('PUT');
    expect((fetchImpl.mock.calls[1] as [string, RequestInit])[1].method).toBe('PATCH');
    expect((fetchImpl.mock.calls[2] as [string, RequestInit])[1].method).toBe('DELETE');
  });

  it('omits X-XSRF-TOKEN when there is no cookie', async () => {
    const fetchImpl = vi.fn().mockResolvedValue(jsonResponse(200, {}));
    await createApiClient({ fetchImpl }).get('/api/me');
    expect(headersOf(fetchImpl)['X-XSRF-TOKEN']).toBeUndefined();
  });

  it('sends X-Requested-With on every request', async () => {
    const fetchImpl = vi.fn().mockImplementation(async () => jsonResponse(200, {}));
    const api = createApiClient({ fetchImpl });
    await api.get('/a');
    await api.post('/b', { x: 1 });
    expect(headersOf(fetchImpl, 0)['X-Requested-With']).toBe('XMLHttpRequest');
    expect(headersOf(fetchImpl, 1)['X-Requested-With']).toBe('XMLHttpRequest');
  });

  it.each([
    ['FormData', () => new FormData()],
    ['Blob', () => new Blob(['hello'])],
    ['URLSearchParams', () => new URLSearchParams({ a: '1' })],
    ['ArrayBuffer', () => new ArrayBuffer(8)],
    ['Uint8Array', () => new Uint8Array([1, 2, 3])],
    ['DataView', () => new DataView(new ArrayBuffer(4))],
  ])('passes a %s body through untouched without a JSON Content-Type', async (_name, make) => {
    const fetchImpl = vi.fn().mockResolvedValue(jsonResponse(200, {}));
    const body = make();
    await createApiClient({ fetchImpl }).post('/upload', body);

    const init = (fetchImpl.mock.calls[0] as [string, RequestInit])[1];
    expect(init.body).toBe(body);
    expect(headersOf(fetchImpl)['Content-Type']).toBeUndefined();
  });

  it('throws ApiError with status and body on failure', async () => {
    const fetchImpl = vi.fn().mockResolvedValue(jsonResponse(422, { message: 'Invalid' }));
    const api = createApiClient({ fetchImpl });

    const error = await api.post('/api/things', {}).catch((e: unknown) => e);

    expect(error).toBeInstanceOf(ApiError);
    expect((error as ApiError).status).toBe(422);
    expect((error as ApiError).body).toEqual({ message: 'Invalid' });
    expect((error as ApiError).isCsrfMismatch).toBe(false);
  });

  it('calls onUnauthorized on 401 before throwing', async () => {
    const onUnauthorized = vi.fn();
    const fetchImpl = vi.fn().mockResolvedValue(jsonResponse(401, { message: 'Unauthenticated.' }));
    const api = createApiClient({ fetchImpl, onUnauthorized });

    await expect(api.get('/api/me')).rejects.toMatchObject({ status: 401 });
    expect(onUnauthorized).toHaveBeenCalledTimes(1);
  });

  it('does not call onUnauthorized for other errors', async () => {
    const onUnauthorized = vi.fn();
    const fetchImpl = vi.fn().mockResolvedValue(new Response('boom', { status: 500 }));
    await expect(createApiClient({ fetchImpl, onUnauthorized }).get('/x')).rejects.toMatchObject({
      status: 500,
      body: 'boom',
    });
    expect(onUnauthorized).not.toHaveBeenCalled();
  });

  it('returns undefined for 204 responses', async () => {
    const fetchImpl = vi.fn().mockResolvedValue(new Response(null, { status: 204 }));
    await expect(createApiClient({ fetchImpl }).delete('/api/things/1')).resolves.toBeUndefined();
  });

  it('rejects a 401 with an empty body and JSON content type as ApiError, still calling onUnauthorized', async () => {
    const onUnauthorized = vi.fn();
    const fetchImpl = vi.fn().mockResolvedValue(
      new Response('', { status: 401, headers: { 'Content-Type': 'application/json' } }),
    );

    const error = await createApiClient({ fetchImpl, onUnauthorized }).get('/api/me').catch((e: unknown) => e);

    expect(error).toBeInstanceOf(ApiError);
    expect((error as ApiError).status).toBe(401);
    expect((error as ApiError).body).toBeNull();
    expect(onUnauthorized).toHaveBeenCalledTimes(1);
  });

  it('rejects a 502 HTML body as ApiError with the raw text', async () => {
    const onUnauthorized = vi.fn();
    const html = '<html><body>Bad Gateway</body></html>';
    const fetchImpl = vi.fn().mockResolvedValue(
      new Response(html, { status: 502, headers: { 'Content-Type': 'text/html' } }),
    );

    const error = await createApiClient({ fetchImpl, onUnauthorized }).get('/x').catch((e: unknown) => e);

    expect(error).toBeInstanceOf(ApiError);
    expect((error as ApiError).status).toBe(502);
    expect((error as ApiError).body).toBe(html);
    expect(onUnauthorized).not.toHaveBeenCalled();
  });

  it('rejects a 200 empty body as ApiError instead of resolving success', async () => {
    const fetchImpl = vi.fn().mockResolvedValue(
      new Response('', { status: 200, headers: { 'Content-Type': 'application/json' } }),
    );
    const error = await createApiClient({ fetchImpl }).get('/x').catch((e: unknown) => e);
    expect(error).toBeInstanceOf(ApiError);
    expect((error as ApiError).status).toBe(200);
    expect((error as ApiError).body).toBe('');
    expect((error as ApiError).message).toMatch(/Expected JSON response/);
  });

  it('rejects truncated JSON on a 200 as ApiError with the raw text', async () => {
    const fetchImpl = vi.fn().mockResolvedValue(
      new Response('{"user_id": 4', { status: 200, headers: { 'Content-Type': 'application/json' } }),
    );
    const error = await createApiClient({ fetchImpl }).get('/x').catch((e: unknown) => e);
    expect(error).toBeInstanceOf(ApiError);
    expect((error as ApiError).status).toBe(200);
    expect((error as ApiError).body).toBe('{"user_id": 4');
    expect((error as ApiError).message).toMatch(/Expected JSON response/);
  });

  it('rejects an HTML login page served with a 200 as ApiError', async () => {
    const html = '<!doctype html><html><body>Please log in</body></html>';
    const fetchImpl = vi.fn().mockResolvedValue(new Response(html, { status: 200, headers: { 'Content-Type': 'text/html' } }));
    const error = await createApiClient({ fetchImpl }).get('/x').catch((e: unknown) => e);
    expect(error).toBeInstanceOf(ApiError);
    expect((error as ApiError).status).toBe(200);
    expect((error as ApiError).body).toBe(html);
  });

  it('rejects a 201 empty body as ApiError', async () => {
    const fetchImpl = vi.fn().mockResolvedValue(new Response('', { status: 201 }));
    await expect(createApiClient({ fetchImpl }).post('/x', {})).rejects.toMatchObject({ status: 201 });
  });

  it('resolves a 200 JSON body typed as T', async () => {
    const fetchImpl = vi.fn().mockResolvedValue(jsonResponse(200, { id: 7 }));
    await expect(createApiClient({ fetchImpl }).get<{ id: number }>('/x')).resolves.toEqual({ id: 7 });
  });

  it('resolves a 204 with an empty body to undefined without a JSON check', async () => {
    const fetchImpl = vi.fn().mockResolvedValue(new Response(null, { status: 204 }));
    await expect(createApiClient({ fetchImpl }).post('/x', {})).resolves.toBeUndefined();
  });

  it('keeps 4xx/5xx empty bodies as null, not a parse error', async () => {
    const fetchImpl = vi.fn().mockResolvedValue(new Response('', { status: 404 }));
    await expect(createApiClient({ fetchImpl }).get('/x')).rejects.toMatchObject({ status: 404, body: null });
  });

  it('keeps the raw text for truncated JSON on a 500 and rejects with ApiError', async () => {
    const fetchImpl = vi.fn().mockResolvedValue(
      new Response('{"message": "Serv', { status: 500, headers: { 'Content-Type': 'application/json' } }),
    );
    const error = await createApiClient({ fetchImpl }).get('/x').catch((e: unknown) => e);
    expect(error).toBeInstanceOf(ApiError);
    expect((error as ApiError).status).toBe(500);
    expect((error as ApiError).body).toBe('{"message": "Serv');
  });

  it('calls onUnauthorized and rejects for truncated JSON on a 401', async () => {
    const onUnauthorized = vi.fn();
    const fetchImpl = vi.fn().mockResolvedValue(
      new Response('{"message": "Unauth', { status: 401, headers: { 'Content-Type': 'application/json' } }),
    );
    const error = await createApiClient({ fetchImpl, onUnauthorized }).get('/x').catch((e: unknown) => e);
    expect(error).toBeInstanceOf(ApiError);
    expect((error as ApiError).status).toBe(401);
    expect(onUnauthorized).toHaveBeenCalledTimes(1);
  });

  it('rejects with ApiError when reading the body fails, keeping the status', async () => {
    const unreadable = {
      ok: true,
      status: 200,
      headers: new Headers(),
      text: () => Promise.reject(new Error('connection reset')),
    } as unknown as Response;
    const fetchImpl = vi.fn().mockResolvedValue(unreadable);

    const error = await createApiClient({ fetchImpl }).get('/x').catch((e: unknown) => e);

    expect(error).toBeInstanceOf(ApiError);
    expect((error as ApiError).status).toBe(200);
    expect((error as ApiError).body).toBeNull();
    expect((error as ApiError).message).toMatch(/Failed to read response body/);
  });

  it('throws ApiError with isCsrfMismatch for 419 and does not retry', async () => {
    const onUnauthorized = vi.fn();
    const fetchImpl = vi.fn().mockResolvedValue(jsonResponse(419, { message: 'CSRF token mismatch.' }));

    const error = await createApiClient({ fetchImpl, onUnauthorized }).post('/x', { a: 1 }).catch((e: unknown) => e);

    expect(error).toBeInstanceOf(ApiError);
    expect((error as ApiError).status).toBe(419);
    expect((error as ApiError).isCsrfMismatch).toBe(true);
    expect((error as ApiError).body).toEqual({ message: 'CSRF token mismatch.' });
    expect(fetchImpl).toHaveBeenCalledTimes(1);
    expect(onUnauthorized).not.toHaveBeenCalled();
  });

  it('invokes the handler registered with setOnUnauthorized for the singleton on 401', async () => {
    const handler = vi.fn();
    setOnUnauthorized(handler);
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse(401, { message: 'Unauthenticated.' })));

    await expect(api.get('/api/me')).rejects.toMatchObject({ status: 401 });
    expect(handler).toHaveBeenCalledTimes(1);
  });

  it('does not throw on a singleton 401 when no handler is registered', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse(401, {})));
    await expect(api.get('/api/me')).rejects.toMatchObject({ status: 401 });
  });
});
