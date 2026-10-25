import { describe, it, expect, beforeEach } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';
import { createMemoryHistory, createRouter, type RouteRecordRaw } from 'vue-router';
import { installAuthGuard } from '../guards';
import { stubFetch, jsonResponse, SESSION_PAYLOAD } from '../../test/helpers';
import { useAuthStore } from '../../stores/auth';
import routes from '../routes/account-public';

/** The account routes plus a home route and a catch-all, with the real auth guard. The app router is not used here. */
function makeRouter(pinia = createPinia()) {
  const all: RouteRecordRaw[] = [
    { path: '/', name: 'home', component: { render: () => null } },
    ...routes,
    { path: '/:pathMatch(.*)*', name: 'not-found', component: { render: () => null } },
  ];
  const router = createRouter({ history: createMemoryHistory(), routes: all });
  installAuthGuard(router, () => useAuthStore(pinia));
  return router;
}

describe('account-public routes', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
  });

  it('exports the register and privacy routes with their titles', () => {
    const byPath = Object.fromEntries(routes.map((route) => [route.path, route]));
    expect(byPath['/register']?.name).toBe('register');
    expect(byPath['/register']?.meta?.title).toBe('Create account');
    expect(byPath['/privacy-policy']?.name).toBe('privacy-policy');
    expect(byPath['/privacy-policy']?.meta?.title).toBe('Privacy Policy');
  });

  it('resolves both paths to their named routes, not the catch-all', () => {
    const router = makeRouter();
    expect(router.resolve('/register').name).toBe('register');
    expect(router.resolve('/privacy-policy').name).toBe('privacy-policy');
  });

  it('keeps registration to guests: a signed-in user is sent home', async () => {
    stubFetch({ 'POST /login/authenticate': () => jsonResponse(200, SESSION_PAYLOAD) });
    const router = makeRouter();
    await router.push('/register');
    expect(router.currentRoute.value.path).toBe('/');
  });

  it('lets an anonymous visitor read the privacy policy', async () => {
    stubFetch();
    const router = makeRouter();
    await router.push('/privacy-policy');
    expect(router.currentRoute.value.path).toBe('/privacy-policy');
  });
});
