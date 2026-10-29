import { afterEach, describe, expect, it, vi } from 'vitest';
import { readXsrfToken, requestJson } from './http';

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('readXsrfToken', () => {
    it('reads and decodes the XSRF-TOKEN cookie', () => {
        expect(readXsrfToken('a=1; XSRF-TOKEN=abc%3D%3D; b=2')).toBe('abc==');
    });

    it('returns null when the cookie is absent', () => {
        expect(readXsrfToken('a=1')).toBeNull();
    });
});

describe('requestJson', () => {
    it('sends the XSRF header and a JSON body', async () => {
        document.cookie = 'XSRF-TOKEN=token-123';
        const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({ ok: true }), { status: 200 }));
        vi.stubGlobal('fetch', fetchMock);

        const result = await requestJson('POST', '/waiver_sign/t/x', { a: 1 });

        expect(result).toEqual({ ok: true, status: 200, body: { ok: true } });
        const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
        expect(url).toBe('/waiver_sign/t/x');
        expect(init.method).toBe('POST');
        expect(init.body).toBe('{"a":1}');
        expect(init.headers).toMatchObject({ Accept: 'application/json', 'X-XSRF-TOKEN': 'token-123', 'Content-Type': 'application/json' });
    });

    it('resolves a network failure as status 0', async () => {
        vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('offline')));

        await expect(requestJson('GET', '/x')).resolves.toMatchObject({ ok: false, status: 0, body: null });
    });
});
