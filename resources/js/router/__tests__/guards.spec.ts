import { describe, it, expect, beforeEach } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';
import { createMemoryHistory, type RouteLocationNormalized } from 'vue-router';
import { accessRedirect, type AccessState } from '../guards';
import { createAppRouter } from '../index';
import { useAuthStore } from '../../stores/auth';
import { jsonResponse, SESSION_PAYLOAD, stubFetch } from '../../test/helpers';

const ANON: AccessState = { isLoggedIn: false, userId: null, userLevel: 0 };
const REGULAR: AccessState = { isLoggedIn: true, userId: 7, userLevel: 1 };
const JUDGE: AccessState = { isLoggedIn: true, userId: 8, userLevel: 2 };
const ADMIN: AccessState = { isLoggedIn: true, userId: 9, userLevel: 3 };

type Route = Pick<RouteLocationNormalized, 'meta' | 'params' | 'fullPath'>;

function route(meta: Route['meta'], params: Route['params'] = {}, fullPath = '/target'): Route {
  return { meta, params, fullPath };
}

const LOGIN_BACK = (fullPath: string) => ({ path: '/login', query: { redirect: fullPath } });
const HOME = { path: '/' };

describe('accessRedirect matrix', () => {
  it.each<[string, Route, AccessState, unknown]>([
    // guestOnly: logged-in users go home, anonymous users may enter
    ['guestOnly, anonymous', route({ guestOnly: true }), ANON, undefined],
    ['guestOnly, regular', route({ guestOnly: true }), REGULAR, HOME],
    ['guestOnly, judge', route({ guestOnly: true }), JUDGE, HOME],
    ['guestOnly, admin', route({ guestOnly: true }), ADMIN, HOME],

    // minUserLevel 1: any member
    ['minUserLevel 1, anonymous', route({ minUserLevel: 1 }, {}, '/cars'), ANON, LOGIN_BACK('/cars')],
    ['minUserLevel 1, regular', route({ minUserLevel: 1 }), REGULAR, undefined],
    ['minUserLevel 1, judge', route({ minUserLevel: 1 }), JUDGE, undefined],
    ['minUserLevel 1, admin', route({ minUserLevel: 1 }), ADMIN, undefined],

    // minUserLevel 3: admins only
    ['minUserLevel 3, anonymous', route({ minUserLevel: 3 }, {}, '/admin'), ANON, LOGIN_BACK('/admin')],
    ['minUserLevel 3, regular', route({ minUserLevel: 3 }), REGULAR, HOME],
    ['minUserLevel 3, judge', route({ minUserLevel: 3 }), JUDGE, HOME],
    ['minUserLevel 3, admin', route({ minUserLevel: 3 }), ADMIN, undefined],

    // privateProfileSection (member settings): the owner of :memberId, or an admin
    [
      'private section, anonymous',
      route({ minUserLevel: 1, privateProfileSection: true }, { memberId: '7' }, '/fan/u7/settings'),
      ANON,
      LOGIN_BACK('/fan/u7/settings'),
    ],
    ['private section, owner', route({ privateProfileSection: true }, { memberId: '7' }), REGULAR, undefined],
    ['private section, other member', route({ privateProfileSection: true }, { memberId: '8' }), REGULAR, HOME],
    ['private section, judge on own id', route({ privateProfileSection: true }, { memberId: '8' }), JUDGE, undefined],
    ['private section, judge on other id', route({ privateProfileSection: true }, { memberId: '7' }), JUDGE, HOME],
    ['private section, admin on any id', route({ privateProfileSection: true }, { memberId: '7' }), ADMIN, undefined],
    ['private section, repeated param', route({ privateProfileSection: true }, { memberId: ['7'] }), REGULAR, undefined],
    ['private section, missing param', route({ privateProfileSection: true }), REGULAR, HOME],
  ])('%s', (_label, to, state, expected) => {
    expect(accessRedirect(to, state)).toEqual(expected);
  });

  it('routes without meta are always allowed', () => {
    expect(accessRedirect(route({}), ANON)).toBeUndefined();
    expect(accessRedirect(route({}), ADMIN)).toBeUndefined();
  });
});

describe('auth guard on a real router', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
  });

  it('lets anonymous users reach the login page', async () => {
    stubFetch();
    const router = createAppRouter(createMemoryHistory(), () => useAuthStore());
    await router.push('/login');
    expect(router.currentRoute.value.path).toBe('/login');
  });

  it('sends a logged-in user away from the login page', async () => {
    stubFetch({ 'POST /login/authenticate': () => jsonResponse(200, SESSION_PAYLOAD) });
    const router = createAppRouter(createMemoryHistory(), () => useAuthStore());
    await router.push('/login');
    expect(router.currentRoute.value.path).toBe('/');
  });

  it('redirects anonymous users from a protected route to login with the intended path', async () => {
    stubFetch();
    const router = createAppRouter(createMemoryHistory(), () => useAuthStore());
    router.addRoute({ path: '/fan/u:memberId/settings', name: 'settings', component: { render: () => null }, meta: { minUserLevel: 1, privateProfileSection: true } });
    await router.push('/fan/u7/settings?tab=1');
    expect(router.currentRoute.value.path).toBe('/login');
    expect(router.currentRoute.value.query.redirect).toBe('/fan/u7/settings?tab=1');
  });

  it('remembers the attempted URL for the post-login redirect, but not auth pages', async () => {
    stubFetch();
    const pinia = createPinia();
    setActivePinia(pinia);
    const auth = useAuthStore(pinia);
    const router = createAppRouter(createMemoryHistory(), () => auth);
    await router.push('/forgot-password');
    expect(auth.nextUrl).toBeNull();
    await router.push('/members');
    expect(auth.nextUrl).toBe('/members');
  });

  it('treats a failed session check as anonymous and still lets public pages load', async () => {
    stubFetch({ 'POST /login/authenticate': () => Promise.reject(new TypeError('offline')) });
    const pinia = createPinia();
    setActivePinia(pinia);
    const auth = useAuthStore(pinia);
    const router = createAppRouter(createMemoryHistory(), () => auth);
    await router.push('/login');
    expect(router.currentRoute.value.path).toBe('/login');
    expect(auth.resolved).toBe(false);
  });
});
