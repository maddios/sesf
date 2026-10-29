import { describe, it, expect, beforeEach } from 'vitest';
import { createMemoryHistory, createRouter, type RouteRecordNormalized, type RouteRecordRaw } from 'vue-router';
import accountRoutes from '../account';
import { createTestRouter, stubFetch, jsonResponse, SESSION_PAYLOAD } from '../../../test/helpers';
import { useAuthStore, toAuthUser, USER_TYPE_ADMIN, USER_TYPE_REGULAR } from '../../../stores/auth';

function ownRouter() {
  return createRouter({ history: createMemoryHistory(), routes: accountRoutes as RouteRecordRaw[] });
}

describe('account routes: parsing', () => {
  it('parses the member settings and garage paths', () => {
    const router = ownRouter();
    const settings = router.resolve('/Fan/u7/settings');
    expect(settings.name).toBe('member-settings');
    expect(settings.params).toEqual({ memberName: 'Fan', memberId: '7' });
    expect(router.resolve('/Fan/u7/cars').name).toBe('member-garage');
  });

  it('turns the member params into typed props', () => {
    const router = ownRouter();
    const resolved = router.resolve('/Fan/u7/settings');
    const record = resolved.matched.at(-1) as RouteRecordNormalized;
    const toProps = record.props.default as (r: typeof resolved) => Record<string, unknown>;
    expect(toProps(resolved)).toEqual({ memberName: 'Fan', memberId: 7 });
  });

  it('does not match a non-numeric member id', () => {
    expect(ownRouter().resolve('/Fan/uabc/settings').name).not.toBe('member-settings');
  });

  it('keeps /car/new separate from /car/:carId settings and photo routes', () => {
    const router = ownRouter();
    expect(router.resolve('/car/new').name).toBe('car-new');
    expect(router.resolve('/car/new').params).toEqual({});
    const settings = router.resolve('/car/12/settings');
    expect(settings.name).toBe('car-settings');
    expect(settings.params).toEqual({ carId: '12' });
    const record = settings.matched.at(-1) as RouteRecordNormalized;
    expect((record.props.default as (r: typeof settings) => unknown)(settings)).toEqual({ carId: 12 });
    expect(router.resolve('/car/12/new-image').name).toBe('car-image');
  });

  it('does not match /car/new/settings or a non-numeric car id', () => {
    const router = ownRouter();
    expect(router.resolve('/car/new/settings').matched).toHaveLength(0);
    expect(router.resolve('/car/abc/settings').matched).toHaveLength(0);
  });

  it('lazy-loads every page component', async () => {
    for (const route of accountRoutes) {
      const load = route.component as () => Promise<{ default: unknown }>;
      expect((await load()).default).toBeDefined();
    }
  });

  it('does not register billing', () => {
    expect(ownRouter().resolve('/Fan/u7/billing').matched).toHaveLength(0);
  });
});

describe('account routes: guard matrix', () => {
  beforeEach(() => {
    stubFetch({});
  });

  /** Runs the real app guard for a path as the given visitor and returns where the navigation ended. */
  async function landing(path: string, visitor: { userId?: number; level?: number } | null) {
    const { router, pinia } = createTestRouter();
    const auth = useAuthStore(pinia);
    if (visitor !== null) {
      auth.setUser(toAuthUser({ ...SESSION_PAYLOAD, user_id: visitor.userId ?? 7, user_level: visitor.level ?? USER_TYPE_REGULAR }));
    } else {
      auth.setUser(toAuthUser(SESSION_PAYLOAD));
      auth.clear();
    }
    await router.push(path);
    return router.currentRoute.value.fullPath;
  }

  it('sends anonymous visitors to login from every account page', async () => {
    for (const path of ['/Fan/u7/settings', '/Fan/u7/cars', '/car/new', '/car/5/settings', '/car/5/new-image']) {
      expect(await landing(path, null), path).toMatch(/^\/login\?redirect=/);
    }
  });

  it('lets the owner into their own settings and garage', async () => {
    expect(await landing('/Fan/u7/settings', { userId: 7 })).toBe('/Fan/u7/settings');
    expect(await landing('/Fan/u7/cars', { userId: 7 })).toBe('/Fan/u7/cars');
  });

  it('sends another member away from private settings and garage', async () => {
    expect(await landing('/Other/u9/settings', { userId: 7 })).toBe('/');
    expect(await landing('/Other/u9/cars', { userId: 7 })).toBe('/');
  });

  it('lets an admin into another member settings', async () => {
    expect(await landing('/Other/u9/settings', { userId: 1, level: USER_TYPE_ADMIN })).toBe('/Other/u9/settings');
  });

  it('lets any logged-in member use the car pages, the server decides ownership', async () => {
    expect(await landing('/car/new', { userId: 7 })).toBe('/car/new');
    expect(await landing('/car/5/settings', { userId: 7 })).toBe('/car/5/settings');
    expect(await landing('/car/5/new-image', { userId: 7 })).toBe('/car/5/new-image');
  });

  it('keeps a session-check that returns 401 anonymous', async () => {
    stubFetch({ 'POST /login/authenticate': () => jsonResponse(401, []) });
    expect(await landing('/car/new', null)).toMatch(/^\/login/);
  });
});
