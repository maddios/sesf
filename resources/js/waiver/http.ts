export interface ApiResult {
    ok: boolean;
    status: number;
    body: unknown;
    timedOut?: boolean;
}

const XSRF_COOKIE = 'XSRF-TOKEN';

export function readXsrfToken(cookies: string): string | null {
    const prefix = `${XSRF_COOKIE}=`;
    const part = cookies
        .split(';')
        .map((cookie) => cookie.trim())
        .find((cookie) => cookie.startsWith(prefix));

    return part === undefined ? null : decodeURIComponent(part.slice(prefix.length));
}

async function parseBody(response: Response): Promise<unknown> {
    try {
        return await response.json();
    } catch {
        return null;
    }
}

/** JSON request with the Laravel XSRF cookie sent as X-XSRF-TOKEN. Network failures resolve as status 0. */
export async function requestJson(method: 'GET' | 'POST', url: string, body?: unknown, timeoutMs?: number): Promise<ApiResult> {
    const headers: Record<string, string> = { Accept: 'application/json' };
    const token = readXsrfToken(document.cookie);
    if (token !== null) {
        headers['X-XSRF-TOKEN'] = token;
    }
    if (body !== undefined) {
        headers['Content-Type'] = 'application/json';
    }

    const controller = new AbortController();
    const timer = timeoutMs === undefined ? undefined : window.setTimeout(() => controller.abort(), timeoutMs);
    try {
        const response = await fetch(url, {
            method,
            headers,
            credentials: 'same-origin',
            body: body === undefined ? undefined : JSON.stringify(body),
            signal: controller.signal,
        });

        return { ok: response.ok, status: response.status, body: await parseBody(response) };
    } catch {
        return { ok: false, status: 0, body: null, timedOut: controller.signal.aborted };
    } finally {
        window.clearTimeout(timer);
    }
}
