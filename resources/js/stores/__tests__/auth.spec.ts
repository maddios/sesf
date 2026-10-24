import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';
import { ApiError } from '../../api/client';
import { useAuthStore, toAuthUser, InvalidSessionPayloadError, type AuthUser, type SessionPayload } from '../auth';

const sampleUser: AuthUser = {
  userId: 1,
  userLevel: 3,
  typeId: 2,
  email: 'fan@example.test',
  firstName: 'Shark',
  profileUrl: '/p/1',
  slideOutName: 'Shark F.',
  fullAddress: '1 Ocean Way',
};

const samplePayload: SessionPayload = {
  user_id: 1,
  user_level: 3,
  type_id: 2,
  email: 'fan@example.test',
  first_name: 'Shark',
  profileURL: '/p/1',
  slideOutName: 'Shark F.',
  fullAddress: '1 Ocean Way',
};

function jsonResponse(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

function deferred<T>() {
  let resolve!: (value: T) => void;
  let reject!: (reason: unknown) => void;
  const promise = new Promise<T>((res, rej) => {
    resolve = res;
    reject = rej;
  });
  return { promise, resolve, reject };
}

function unauthorized(): ApiError {
  return new ApiError(401, null);
}

describe('auth store', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
  });

  afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
  });

  it('starts logged out and unresolved', () => {
    const store = useAuthStore();
    expect(store.user).toBeNull();
    expect(store.resolved).toBe(false);
    expect(store.isLoggedIn).toBe(false);
    expect(store.userLevel).toBe(0);
  });

  it('setUser stores the user, marks resolved, and derives login state and level', () => {
    const store = useAuthStore();
    store.setUser(sampleUser);
    expect(store.user).toEqual(sampleUser);
    expect(store.resolved).toBe(true);
    expect(store.isLoggedIn).toBe(true);
    expect(store.userLevel).toBe(3);
  });

  it('clear logs out but keeps the session resolved', () => {
    const store = useAuthStore();
    store.setUser(sampleUser);
    store.clear();
    expect(store.user).toBeNull();
    expect(store.isLoggedIn).toBe(false);
    expect(store.userLevel).toBe(0);
    expect(store.resolved).toBe(true);
  });

  it('maps the login payload to AuthUser', () => {
    expect(toAuthUser(samplePayload)).toEqual(sampleUser);
  });

  describe('toAuthUser payload validation', () => {
    it('coerces string-typed integer ids and levels from legacy responses', () => {
      const user = toAuthUser({ ...samplePayload, user_id: '42', user_level: '3', type_id: '7' });
      expect(user).toMatchObject({ userId: 42, userLevel: 3, typeId: 7 });
    });

    it('accepts numeric ids as well as strings', () => {
      expect(toAuthUser({ ...samplePayload, user_id: 9 }).userId).toBe(9);
    });

    it.each([
      ['missing', undefined],
      ['null', null],
      ['empty string', ''],
      ['whitespace', '   '],
      ['non-numeric string', 'abc'],
      ['NaN', Number.NaN],
      ['Infinity', Number.POSITIVE_INFINITY],
      ['zero', 0],
      ['negative', -1],
      ['string zero', '0'],
      ['boolean', true],
      ['object', { id: 1 }],
    ])('rejects a user_id that is %s with InvalidSessionPayloadError', (_name, value) => {
      expect(() => toAuthUser({ ...samplePayload, user_id: value })).toThrow(InvalidSessionPayloadError);
    });

    it('rejects a non-object payload', () => {
      expect(() => toAuthUser(null)).toThrow(InvalidSessionPayloadError);
      expect(() => toAuthUser('oops')).toThrow(InvalidSessionPayloadError);
    });

    it('defaults unparseable levels to 0 and missing text fields to empty strings', () => {
      const user = toAuthUser({ user_id: '5', user_level: 'x', email: null, profileURL: 12 });
      expect(user).toEqual({
        userId: 5,
        userLevel: 0,
        typeId: 0,
        email: '',
        firstName: '',
        profileUrl: '12',
        slideOutName: '',
        fullAddress: '',
      });
    });

    it('a bad payload leaves the session unresolved and a later valid check succeeds', async () => {
      const store = useAuthStore();
      const fetcher = vi
        .fn()
        .mockResolvedValueOnce({ ...samplePayload, user_id: 'not-a-number' })
        .mockResolvedValueOnce(samplePayload);

      await expect(store.ensureResolved(fetcher)).rejects.toBeInstanceOf(InvalidSessionPayloadError);
      expect(store.resolved).toBe(false);
      expect(store.user).toBeNull();

      await store.ensureResolved(fetcher);
      expect(store.user).toEqual(sampleUser);
    });

    it('a string-typed payload from the default fetcher is stored with numeric ids', async () => {
      vi.stubGlobal(
        'fetch',
        vi.fn().mockResolvedValue(jsonResponse(200, { ...samplePayload, user_id: '1', user_level: '3', type_id: '2' })),
      );
      const store = useAuthStore();
      await store.ensureResolved();
      expect(store.user).toEqual(sampleUser);
      expect(store.userLevel).toBe(3);
    });

    it('an HTML login page served as 200 leaves the session unresolved', async () => {
      vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response('<html>login</html>', { status: 200 })));
      const store = useAuthStore();
      await expect(store.ensureResolved()).rejects.toBeInstanceOf(ApiError);
      expect(store.resolved).toBe(false);
    });
  });

  describe('ensureResolved / fetchUser', () => {
    it('success path: stores the user from the payload and resolves', async () => {
      const store = useAuthStore();
      const fetcher = vi.fn().mockResolvedValue(samplePayload);

      await store.ensureResolved(fetcher);

      expect(fetcher).toHaveBeenCalledTimes(1);
      expect(store.user).toEqual(sampleUser);
      expect(store.resolved).toBe(true);
      expect(store.isLoggedIn).toBe(true);
    });

    it('401 path: clears the user but still marks resolved', async () => {
      const store = useAuthStore();
      await store.ensureResolved(vi.fn().mockRejectedValue(unauthorized()));

      expect(store.user).toBeNull();
      expect(store.isLoggedIn).toBe(false);
      expect(store.resolved).toBe(true);
    });

    it('uses POST /login/authenticate through the api client by default', async () => {
      const fetchMock = vi.fn().mockResolvedValue(jsonResponse(200, samplePayload));
      vi.stubGlobal('fetch', fetchMock);
      const store = useAuthStore();

      await store.ensureResolved();

      const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
      expect(url).toBe('/login/authenticate');
      expect(init.method).toBe('POST');
      expect(store.user).toEqual(sampleUser);
    });

    it('default fetcher 401 through the api client resolves as logged out', async () => {
      vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse(401, { message: 'Unauthenticated.' })));
      const store = useAuthStore();

      await store.ensureResolved();

      expect(store.user).toBeNull();
      expect(store.resolved).toBe(true);
    });

    it('concurrent callers share one request', async () => {
      const store = useAuthStore();
      const pending = deferred<SessionPayload>();
      const fetcher = vi.fn().mockReturnValue(pending.promise);

      const first = store.ensureResolved(fetcher);
      const second = store.ensureResolved(fetcher);
      const third = store.fetchUser(fetcher);
      expect(fetcher).toHaveBeenCalledTimes(1);

      pending.resolve(samplePayload);
      await Promise.all([first, second, third]);

      expect(fetcher).toHaveBeenCalledTimes(1);
      expect(store.user).toEqual(sampleUser);
    });

    it('does not fetch again once resolved', async () => {
      const store = useAuthStore();
      store.setUser(sampleUser);
      const fetcher = vi.fn();

      await store.ensureResolved(fetcher);

      expect(fetcher).not.toHaveBeenCalled();
    });

    it('a clear() during the first check does not mark resolved before the check finishes', async () => {
      const store = useAuthStore();
      const pending = deferred<SessionPayload>();
      const check = store.ensureResolved(() => pending.promise);

      store.clear();
      expect(store.resolved).toBe(false);

      pending.resolve(samplePayload);
      await check;
      expect(store.resolved).toBe(true);
    });

    it('a logout during an in-flight check is not overwritten when the check later succeeds', async () => {
      const store = useAuthStore();
      const pending = deferred<SessionPayload>();
      const check = store.ensureResolved(() => pending.promise);

      store.clear();
      pending.resolve(samplePayload);
      await check;

      expect(store.user).toBeNull();
      expect(store.isLoggedIn).toBe(false);
      expect(store.userLevel).toBe(0);
      expect(store.resolved).toBe(true);
    });

    it('a stale check that fails with a non-401 error is dropped without rejecting', async () => {
      const store = useAuthStore();
      const pending = deferred<SessionPayload>();
      const check = store.ensureResolved(() => pending.promise);

      store.clear();
      pending.reject(new Error('network down'));

      await expect(check).resolves.toBeUndefined();
      expect(store.user).toBeNull();
      expect(store.resolved).toBe(true);
    });

    it('a check started after a logout still stores its own user', async () => {
      const store = useAuthStore();
      const stale = deferred<SessionPayload>();
      const staleCheck = store.ensureResolved(() => stale.promise);
      store.clear();

      // Login completes after the logout and sets the user directly.
      store.setUser(sampleUser);
      stale.resolve({ ...samplePayload, first_name: 'Old' });
      await staleCheck;

      expect(store.user).toEqual(sampleUser);
    });

    it('a 401 from the check itself after a mid-check clear resolves as logged out', async () => {
      const store = useAuthStore();
      const pending = deferred<SessionPayload>();
      const check = store.ensureResolved(() => pending.promise);

      store.clear();
      expect(store.resolved).toBe(false);

      pending.reject(unauthorized());
      await check;
      expect(store.user).toBeNull();
      expect(store.resolved).toBe(true);
    });

    it('non-401 failures reject, leave the session unresolved, and allow a retry', async () => {
      const store = useAuthStore();
      const fetcher = vi
        .fn()
        .mockRejectedValueOnce(new Error('network down'))
        .mockResolvedValueOnce(samplePayload);

      await expect(store.ensureResolved(fetcher)).rejects.toThrow('network down');
      expect(store.resolved).toBe(false);

      await store.ensureResolved(fetcher);
      expect(fetcher).toHaveBeenCalledTimes(2);
      expect(store.user).toEqual(sampleUser);
    });

    it('fetchUser starts a fresh check once the previous one has settled', async () => {
      const store = useAuthStore();
      const fetcher = vi.fn().mockResolvedValue(samplePayload);

      await store.fetchUser(fetcher);
      await store.fetchUser(fetcher);

      expect(fetcher).toHaveBeenCalledTimes(2);
    });
  });
});
