import { describe, it, expect } from 'vitest';
import { createMemoryHistory, createRouter, type RouteRecordRaw } from 'vue-router';
import eventRoutes from '../events';
import registrationRoutes from '../registration';
import NotFoundPage from '../../../pages/NotFoundPage.vue';

/** Same order as the app: area modules are sorted by file name (events before registration). */
function appRouter() {
  const routes: RouteRecordRaw[] = [
    ...eventRoutes,
    ...registrationRoutes,
    { path: '/:pathMatch(.*)*', name: 'not-found', component: NotFoundPage },
  ];
  return createRouter({ history: createMemoryHistory(), routes });
}

type PropsFn = (route: unknown) => Record<string, unknown>;

/** Props of the matched record's default view, computed the way the router does. */
function propsOf(route: { matched: { props: unknown }[] }): Record<string, unknown> {
  const record = route.matched[route.matched.length - 1];
  const fn = (record?.props as { default: PropsFn }).default;
  return fn(route);
}

describe('registration routes', () => {
  it('resolves the register path to the registration page, not the event layout', () => {
    const resolved = appRouter().resolve('/SoutheastSharkfest2026/e36/register');
    expect(resolved.name).toBe('event-register');
    expect(resolved.matched).toHaveLength(1);
    expect(resolved.params).toEqual({ eventName: 'SoutheastSharkfest2026', eventId: '36' });
    expect(resolved.meta).toMatchObject({ minUserLevel: 1, title: 'Register' });
  });

  it('keeps the event tabs on their own paths', () => {
    const router = appRouter();
    expect(router.resolve('/SoutheastSharkfest2026/e36').name).toBe('event-info');
    expect(router.resolve('/SoutheastSharkfest2026/e36/judging').name).toBe('event-judging');
  });

  it('resolves the extra editor with its auth key', () => {
    const resolved = appRouter().resolve('/SoutheastSharkfest2026/e36/extra/abc123');
    expect(resolved.name).toBe('event-extra');
    expect(resolved.params).toEqual({ eventName: 'SoutheastSharkfest2026', eventId: '36', authKey: 'abc123' });
  });

  it('does not match a non-numeric event id', () => {
    expect(appRouter().resolve('/SoutheastSharkfest2026/eabc/register').name).toBe('not-found');
  });

  it('sends the gallery pages to not found, as the event route does', async () => {
    const router = appRouter();
    await router.push('/gallery/e36/register');
    expect(router.currentRoute.value.name).toBe('not-found');
    await router.push('/gallery/e36/extra/abc');
    expect(router.currentRoute.value.name).toBe('not-found');
  });

  it('passes the admin user id from the query, and ignores invalid values', () => {
    const route = appRouter().resolve('/SoutheastSharkfest2026/e36/register?user_id=9');
    const props = propsOf(route);
    expect(props).toEqual({ eventName: 'SoutheastSharkfest2026', eventId: 36, userId: 9 });
    const bad = appRouter().resolve('/SoutheastSharkfest2026/e36/register?user_id=-4');
    const badProps = propsOf(bad);
    expect(badProps.userId).toBeUndefined();
  });
});
