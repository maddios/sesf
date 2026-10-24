import { describe, it, expect, vi, afterEach } from 'vitest';
import { ApiError, createApiClient, readXsrfToken } from '../client';

function jsonResponse(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  });
}

afterEach(() => {
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
  vi.restoreAllMocks();
});

describe('readXsrfToken', () => {
  it('returns the decoded XSRF-TOKEN cookie value', () => {
    expect(readXsrfToken('a=1; XSRF-TOKEN=abc%3D%3D; b=2')).toBe('abc==');
  });

  it('returns undefined when the cookie is absent', () => {
    expect(readXsrfToken('a=1')).toBeUndefined();
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
    const headers = init.headers as Record<string, string>;
    expect(headers['X-XSRF-TOKEN']).toBe('tok+en');
    expect(headers['Content-Type']).toBe('application/json');
  });

  it('omits X-XSRF-TOKEN when there is no cookie', async () => {
    const fetchImpl = vi.fn().mockResolvedValue(jsonResponse(200, {}));
    await createApiClient({ fetchImpl }).get('/api/me');
    const headers = (fetchImpl.mock.calls[0] as [string, RequestInit])[1].headers as Record<string, string>;
    expect(headers['X-XSRF-TOKEN']).toBeUndefined();
  });

  it('throws ApiError with status and body on failure', async () => {
    const fetchImpl = vi.fn().mockResolvedValue(jsonResponse(422, { message: 'Invalid' }));
    const api = createApiClient({ fetchImpl });

    const error = await api.post('/api/things', {}).catch((e: unknown) => e);

    expect(error).toBeInstanceOf(ApiError);
    expect((error as ApiError).status).toBe(422);
    expect((error as ApiError).body).toEqual({ message: 'Invalid' });
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
});
