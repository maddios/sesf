import { describe, it, expect } from 'vitest';
import { createMemoryHistory, createRouter, type RouteRecordNormalized, type RouteRecordRaw } from 'vue-router';
import billingRoutes from '../billing';
import { accessRedirect } from '../../guards';
import { USER_TYPE_ADMIN, USER_TYPE_JUDGE, USER_TYPE_REGULAR } from '../../../stores/auth';

/** Billing routes plus a catch-all, so unmatched paths resolve quietly instead of logging a router warning. */
function ownRouter() {
  const catchAll: RouteRecordRaw = { path: '/:pathMatch(.*)*', name: 'not-found', component: { render: () => null } };
  return createRouter({ history: createMemoryHistory(), routes: [...(billingRoutes as RouteRecordRaw[]), catchAll] });
}

describe('billing routes: parsing', () => {
  it('matches the invoice list without a registration id', () => {
    const resolved = ownRouter().resolve('/ShawnB/u44/billing');
    expect(resolved.name).toBe('member-billing');
    expect(resolved.params.registrationId).toBeUndefined();
    const record = resolved.matched.at(-1) as RouteRecordNormalized;
    expect((record.props.default as (r: typeof resolved) => unknown)(resolved)).toEqual({
      memberName: 'ShawnB',
      memberId: 44,
      registrationId: undefined,
    });
  });

  it('matches a single invoice with its registration id as a number', () => {
    const resolved = ownRouter().resolve('/ShawnB/u44/billing/512');
    expect(resolved.name).toBe('member-billing');
    const record = resolved.matched.at(-1) as RouteRecordNormalized;
    expect((record.props.default as (r: typeof resolved) => unknown)(resolved)).toEqual({
      memberName: 'ShawnB',
      memberId: 44,
      registrationId: 512,
    });
  });

  it('does not match a non-numeric registration id or member id', () => {
    const router = ownRouter();
    expect(router.resolve('/ShawnB/u44/billing/abc').name).not.toBe('member-billing');
    expect(router.resolve('/ShawnB/uabc/billing').name).not.toBe('member-billing');
  });

  it('carries the billing guard meta and title', () => {
    const route = billingRoutes[0];
    expect(route.meta).toEqual({ minUserLevel: 1, privateProfileSection: true, title: 'Billing' });
  });

  it('lazy-loads the page component', async () => {
    for (const route of billingRoutes) {
      const load = route.component as () => Promise<{ default: unknown }>;
      expect((await load()).default).toBeDefined();
    }
  });
});

describe('billing routes: access', () => {
  const access = (path: string, state: { isLoggedIn: boolean; userId: number | null; userLevel: number }) => {
    const resolved = ownRouter().resolve(path);
    return accessRedirect(resolved, state);
  };

  it('sends anonymous visitors to sign in', () => {
    expect(access('/Fan/u7/billing', { isLoggedIn: false, userId: null, userLevel: 0 })).toEqual({
      path: '/login',
      query: { redirect: '/Fan/u7/billing' },
    });
  });

  it('lets the owner in and keeps everyone else out', () => {
    const owner = { isLoggedIn: true, userId: 7, userLevel: USER_TYPE_REGULAR };
    expect(access('/Fan/u7/billing/512', owner)).toBeUndefined();
    expect(access('/Fan/u8/billing', owner)).toEqual({ path: '/' });
    expect(access('/Fan/u8/billing', { isLoggedIn: true, userId: 7, userLevel: USER_TYPE_JUDGE })).toEqual({ path: '/' });
  });

  it('lets an admin view another member', () => {
    expect(access('/Fan/u7/billing', { isLoggedIn: true, userId: 1, userLevel: USER_TYPE_ADMIN })).toBeUndefined();
  });
});
