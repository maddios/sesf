import { describe, it, expect, beforeEach } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';
import { ApiError } from '../../api/client';
import { useAuthStore, InvalidSessionPayloadError, USER_TYPE_ADMIN, USER_TYPE_JUDGE, USER_TYPE_REGULAR } from '../auth';
import { jsonResponse, SESSION_PAYLOAD, stubFetch, type RouteHandler } from '../../test/helpers';

function serve(handlers: Record<string, RouteHandler>) {
  return stubFetch(handlers);
}

describe('auth store session actions', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
  });

  it('exposes the user level constants', () => {
    expect([USER_TYPE_REGULAR, USER_TYPE_JUDGE, USER_TYPE_ADMIN]).toEqual([1, 2, 3]);
  });

  it('login stores the user from the payload and resolves the session', async () => {
    serve({
      'POST /login/login': () => jsonResponse(200, { ...SESSION_PAYLOAD, user_id: '7', user_level: '2' }),
    });
    const store = useAuthStore();
    const user = await store.login({ email: 'fan@example.test', password: 'pw', remember: false });
    expect(user.userId).toBe(7);
    expect(store.isLoggedIn).toBe(true);
    expect(store.userId).toBe(7);
    expect(store.userLevel).toBe(2);
    expect(store.resolved).toBe(true);
  });

  it('login rejects with ApiError on bad credentials and stays logged out', async () => {
    serve({ 'POST /login/login': () => jsonResponse(401, ['Invalid credentials.']) });
    const store = useAuthStore();
    await expect(store.login({ email: 'x@y.test', password: 'no', remember: false })).rejects.toBeInstanceOf(ApiError);
    expect(store.user).toBeNull();
    expect(store.isLoggedIn).toBe(false);
  });

  it('login rejects when the 200 body is not a usable session', async () => {
    serve({ 'POST /login/login': () => jsonResponse(200, { nope: true }) });
    const store = useAuthStore();
    await expect(store.login({ email: 'x@y.test', password: 'pw', remember: false })).rejects.toBeInstanceOf(
      InvalidSessionPayloadError,
    );
    expect(store.isLoggedIn).toBe(false);
  });

  it('loginWithEmailCode returns the server redirect_path and stores the session without it', async () => {
    serve({
      'POST /login/email-signin/verify-code': () => jsonResponse(200, { ...SESSION_PAYLOAD, redirect_path: '/waiver/9' }),
    });
    const store = useAuthStore();
    await expect(store.loginWithEmailCode('fan@example.test', '123456')).resolves.toBe('/waiver/9');
    expect(store.isLoggedIn).toBe(true);
    expect(store.user).not.toHaveProperty('redirectPath');
  });

  it('loginWithEmailCode returns null when no redirect_path is sent', async () => {
    serve({ 'POST /login/email-signin/verify-code': () => jsonResponse(200, SESSION_PAYLOAD) });
    const store = useAuthStore();
    await expect(store.loginWithEmailCode('fan@example.test', '123456')).resolves.toBeNull();
  });

  it('applyPayload stores a valid payload and rejects an invalid one', () => {
    const store = useAuthStore();
    expect(store.applyPayload(SESSION_PAYLOAD).email).toBe('fan@example.test');
    expect(store.isLoggedIn).toBe(true);
    expect(() => store.applyPayload({})).toThrow(InvalidSessionPayloadError);
  });

  it('logout clears the user after the server call', async () => {
    serve({
      'POST /login/authenticate': () => jsonResponse(200, SESSION_PAYLOAD),
      'POST /login/logout': () => jsonResponse(200, { message: 'ok' }),
    });
    const store = useAuthStore();
    await store.ensureResolved();
    expect(store.isLoggedIn).toBe(true);
    await store.logout();
    expect(store.isLoggedIn).toBe(false);
    expect(store.resolved).toBe(true);
  });

  it('logout still clears locally when the server call fails, and surfaces the error', async () => {
    serve({
      'POST /login/authenticate': () => jsonResponse(200, SESSION_PAYLOAD),
      'POST /login/logout': () => jsonResponse(500, { message: 'down' }),
    });
    const store = useAuthStore();
    await store.ensureResolved();
    await expect(store.logout()).rejects.toBeInstanceOf(ApiError);
    expect(store.isLoggedIn).toBe(false);
  });

  it.each([
    [USER_TYPE_REGULAR, false, false],
    [USER_TYPE_JUDGE, true, false],
    [USER_TYPE_ADMIN, true, true],
  ])('level %i gives isJudge=%s and isAdmin=%s', (level, judge, admin) => {
    const store = useAuthStore();
    store.setUser({
      userId: 1,
      userLevel: level,
      typeId: 1,
      email: 'a@b.test',
      firstName: 'A',
      profileUrl: '',
      slideOutName: '',
      fullAddress: '',
    });
    expect(store.isJudge).toBe(judge);
    expect(store.isAdmin).toBe(admin);
  });

  it('judge and admin getters are false when logged out', () => {
    const store = useAuthStore();
    expect(store.isJudge).toBe(false);
    expect(store.isAdmin).toBe(false);
    expect(store.userId).toBeNull();
  });

  it('remembers the next URL and hands it out once', () => {
    const store = useAuthStore();
    expect(store.consumeNextUrl()).toBeNull();
    store.rememberNextUrl('/members?page=2');
    expect(store.nextUrl).toBe('/members?page=2');
    expect(store.consumeNextUrl()).toBe('/members?page=2');
    expect(store.consumeNextUrl()).toBeNull();
  });
});
