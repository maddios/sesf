import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';
import { ApiError } from '../../api/client';
import { useAuthStore, toAuthUser, type AuthUser, type SessionPayload } from '../auth';

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

    it('a 401 clear() during the first check does not mark resolved before the check finishes', async () => {
      const store = useAuthStore();
      const pending = deferred<SessionPayload>();
      const check = store.ensureResolved(() => pending.promise);

      store.clear();
      expect(store.resolved).toBe(false);

      pending.resolve(samplePayload);
      await check;
      expect(store.resolved).toBe(true);
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
