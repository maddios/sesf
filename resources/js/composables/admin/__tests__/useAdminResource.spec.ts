import { describe, it, expect, afterEach } from 'vitest';
import { ApiError } from '../../../api/client';
import { buildQueryString, mapAdminError, useAdminResource, uploadWithProgress } from '../useAdminResource';
import { jsonResponse } from '../../../test/helpers';
import { stubAdminFetch, stubXhr } from '../../../test/admin';

afterEach(() => {
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
});

describe('buildQueryString', () => {
  it('serialises paging, sort, search and every filter shape, leaving empty values out', () => {
    const qs = buildQueryString({
      page: 2,
      per_page: 50,
      sort: 'name',
      direction: 'desc',
      search: 'a b',
      filter: { is_enabled: '1', name: { like: 'ac' }, id: { in: ['1', '2'] }, empty: '', skip: undefined, x: { eq: '' } },
    });
    const params = new URLSearchParams(qs);
    expect(params.get('page')).toBe('2');
    expect(params.get('per_page')).toBe('50');
    expect(params.get('sort')).toBe('name');
    expect(params.get('direction')).toBe('desc');
    expect(params.get('search')).toBe('a b');
    expect(params.get('filter[is_enabled]')).toBe('1');
    expect(params.get('filter[name][like]')).toBe('ac');
    expect(params.getAll('filter[id][in][]')).toEqual(['1', '2']);
    expect(qs).not.toContain('empty');
    expect(qs).not.toContain('skip');
    expect(qs).not.toContain('filter%5Bx%5D');
    expect(buildQueryString({ search: '' })).toBe('');
  });
});

describe('mapAdminError', () => {
  it('maps a Laravel 422 to field errors', () => {
    const info = mapAdminError(new ApiError(422, { message: 'The name field is required.', errors: { name: ['The name field is required.'], URL: 'bad' } }));
    expect(info.status).toBe(422);
    expect(info.fieldErrors).toEqual({ name: ['The name field is required.'], URL: ['bad'] });
    expect(info.message).toBe('Please correct the highlighted fields.');
  });

  it('maps the legacy 400 message array and keyed object', () => {
    expect(mapAdminError(new ApiError(400, ['First.', 'Second.'])).message).toBe('First. Second.');
    const keyed = mapAdminError(new ApiError(400, { email: ['Taken.'] }));
    expect(keyed.fieldErrors).toEqual({ email: ['Taken.'] });
    expect(keyed.message).toBe('Taken.');
  });

  it('uses the server message for a 409 conflict', () => {
    expect(mapAdminError(new ApiError(409, { message: 'In use. Disable it instead.' })).message).toBe('In use. Disable it instead.');
  });

  it('gives fixed messages for auth, CSRF and throttle errors', () => {
    expect(mapAdminError(new ApiError(401, { message: 'Unauthenticated.' })).message).toMatch(/sign in again/);
    expect(mapAdminError(new ApiError(403, { message: 'Forbidden.' })).message).toMatch(/permission/);
    expect(mapAdminError(new ApiError(419, null)).message).toMatch(/expired/);
    expect(mapAdminError(new ApiError(429, null)).message).toMatch(/Wait a minute/);
    expect(mapAdminError(new ApiError(404, { message: 'Sponsor not found.' })).message).toBe('Sponsor not found.');
    expect(mapAdminError(new ApiError(404, null)).message).toMatch(/no longer exists/);
  });

  it('falls back for network errors, HTML bodies and empty bodies', () => {
    expect(mapAdminError(new TypeError('fetch failed'), 'Fallback.').message).toBe('Fallback.');
    expect(mapAdminError(new ApiError(500, '<html>oops</html>'), 'Fallback.').message).toBe('Fallback.');
    expect(mapAdminError(new ApiError(418, 'Plain text failure')).message).toBe('Plain text failure');
    expect(mapAdminError(new ApiError(500, { message: 'Server Error' }), 'Fallback.').message).toBe('Fallback.');
    expect(mapAdminError(new ApiError(500, {})).message).toMatch(/Something went wrong/);
  });
});

describe('useAdminResource', () => {
  it('runs list, get, create, update, delete and actions against /admin-api/{resource}', async () => {
    const { seen } = stubAdminFetch(({ method, path }) => {
      if (method === 'GET' && path === '/admin-api/things') return jsonResponse(200, { data: [{ id: 1 }], meta: { page: 1, per_page: 25, total: 1, last_page: 1 } });
      if (method === 'GET' && path === '/admin-api/things/1') return jsonResponse(200, { data: { id: 1 } });
      if (method === 'POST' && path === '/admin-api/things') return jsonResponse(201, { data: { id: 2 } });
      if (method === 'PUT' && path === '/admin-api/things/2') return jsonResponse(200, { data: { id: 2, name: 'b' } });
      if (method === 'DELETE' && path === '/admin-api/things/2') return new Response(null, { status: 204 });
      if (method === 'POST' && path === '/admin-api/things/2/enable') return jsonResponse(200, { data: { id: 2, on: true } });
      return undefined;
    });
    const things = useAdminResource<{ id: number }>('things');

    expect((await things.list({ search: 'x' })).data).toEqual([{ id: 1 }]);
    expect(seen[0]?.params.get('search')).toBe('x');
    expect(await things.list()).toBeTruthy();
    expect(await things.get(1)).toEqual({ id: 1 });
    expect(await things.create({ name: 'a' })).toEqual({ id: 2 });
    expect(seen.find((r) => r.method === 'POST')?.body).toEqual({ name: 'a' });
    expect(await things.update(2, { name: 'b' })).toEqual({ id: 2, name: 'b' });
    await expect(things.remove(2)).resolves.toBeUndefined();
    expect(await things.action(2, 'enable')).toEqual({ id: 2, on: true });
  });

  it('throws ApiError with the parsed body on failures', async () => {
    stubAdminFetch(() => jsonResponse(422, { message: 'x', errors: { name: ['Required.'] } }));
    await expect(useAdminResource('things').create({})).rejects.toMatchObject({ status: 422 });
  });
});

describe('uploads', () => {
  it('posts multipart with CSRF and reports progress, then unwraps the result', async () => {
    document.cookie = 'XSRF-TOKEN=tok%3D1';
    const xhrs = stubXhr();
    const progress: number[] = [];
    const file = new File(['x'], 'a.png', { type: 'image/png' });

    const pending = useAdminResource<{ id: number }>('sponsors').upload(3, file, { onProgress: (p) => progress.push(p) });
    const xhr = xhrs[0]!;
    expect(xhr.method).toBe('POST');
    expect(xhr.url).toBe('/admin-api/sponsors/3/image');
    expect(xhr.headers['X-XSRF-TOKEN']).toBe('tok=1');
    expect(xhr.headers.Accept).toBe('application/json');
    expect((xhr.body as FormData).get('file')).toBe(file);
    xhr.progress(5, 10);
    xhr.respond(200, { data: { id: 3 } });

    await expect(pending).resolves.toEqual({ id: 3 });
    expect(progress).toEqual([50, 100]);
  });

  it('rejects with ApiError on an error status or a network failure', async () => {
    const xhrs = stubXhr();
    const failed = uploadWithProgress('/x', new FormData());
    xhrs[0]!.respond(422, { message: 'bad', errors: { file: ['Not an image.'] } });
    await expect(failed).rejects.toMatchObject({ status: 422, body: { errors: { file: ['Not an image.'] } } });

    const offline = uploadWithProgress('/x', new FormData());
    xhrs[1]!.fail();
    await expect(offline).rejects.toMatchObject({ status: 0 });

    const text = uploadWithProgress('/x', new FormData());
    xhrs[2]!.respond(500, 'Server exploded');
    await expect(text).rejects.toMatchObject({ status: 500, body: 'Server exploded' });

    const empty = uploadWithProgress('/x', new FormData());
    xhrs[3]!.respond(204, '');
    await expect(empty).resolves.toBeNull();
  });
});
