import { describe, it, expect } from 'vitest';
import { createMemoryHistory, createRouter, type RouteRecordNormalized, type RouteRecordRaw } from 'vue-router';
import memberRoutes from '../members';

/** Only this module's routes, so the test does not depend on other areas. */
function routerWithOwnRoutes() {
  return createRouter({ history: createMemoryHistory(), routes: memberRoutes as RouteRecordRaw[] });
}

function lastRecord(path: string) {
  const router = routerWithOwnRoutes();
  return router.resolve(path).matched.at(-1) as RouteRecordNormalized | undefined;
}

describe('member and car routes', () => {
  it('parses /ShawnB/u44 into memberName and memberId', () => {
    const router = routerWithOwnRoutes();
    const resolved = router.resolve('/ShawnB/u44');
    expect(resolved.name).toBe('member-profile');
    expect(resolved.params).toEqual({ memberName: 'ShawnB', memberId: '44' });
    const props = lastRecord('/ShawnB/u44')?.props.default as (r: typeof resolved) => Record<string, unknown>;
    expect(props(resolved)).toEqual({ memberName: 'ShawnB', memberId: 44 });
  });

  it('does not match a member profile without digits after u', () => {
    expect(routerWithOwnRoutes().resolve('/ShawnB/uabc').name).not.toBe('member-profile');
  });

  it('parses /car/78 into carId and does not match /car/new', () => {
    const router = routerWithOwnRoutes();
    const resolved = router.resolve('/car/78');
    expect(resolved.name).toBe('car-profile');
    expect(resolved.params).toEqual({ carId: '78' });
    const props = lastRecord('/car/78')?.props.default as (r: typeof resolved) => Record<string, unknown>;
    expect(props(resolved)).toEqual({ carId: 78 });
    expect(router.resolve('/car/new').matched).toHaveLength(0);
  });

  it('does not register the car settings route, which another module owns', () => {
    expect(routerWithOwnRoutes().resolve('/car/78/settings').matched).toHaveLength(0);
  });

  it('registers the directories by name', () => {
    const router = routerWithOwnRoutes();
    expect(router.resolve('/members').name).toBe('members');
    expect(router.resolve('/cars').name).toBe('cars');
  });

  it('loads every page component lazily', async () => {
    const records = memberRoutes.map((route) => route.component as () => Promise<{ default: unknown }>);
    for (const load of records) {
      const module = await load();
      expect(module.default).toBeDefined();
    }
  });
});
