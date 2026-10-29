import { describe, it, expect } from 'vitest';
import { createRouter, createMemoryHistory, type RouteRecordRaw } from 'vue-router';
import routes from '../events';

/** Event routes plus a catch-all, so the tests do not depend on other area modules. */
function makeRouter() {
  const all: RouteRecordRaw[] = [
    ...routes,
    { path: '/:pathMatch(.*)*', name: 'not-found', component: { render: () => null } },
  ];
  return createRouter({ history: createMemoryHistory(), routes: all });
}

function childProps(path: string): Record<string, unknown> {
  const router = makeRouter();
  const route = router.resolve(path);
  const child = route.matched[route.matched.length - 1];
  const props = child?.props as unknown as { default?: (r: unknown) => Record<string, unknown> } | undefined;
  return props?.default?.(route) ?? {};
}

describe('event routes', () => {
  const router = makeRouter();
  const resolve = (path: string) => router.resolve(path);

  it('parses the event name and id from a hash-style event path', () => {
    const route = resolve('/SoutheastSharkfest2026/e36');
    expect(route.params).toEqual({ eventName: 'SoutheastSharkfest2026', eventId: '36' });
    expect(route.name).toBe('event-info');
  });

  it.each([
    ['/SoutheastSharkfest2026/e36/judging', 'event-judging'],
    ['/SoutheastSharkfest2026/e36/schedule', 'event-schedule'],
    ['/SoutheastSharkfest2026/e36/directions', 'event-directions'],
    ['/SoutheastSharkfest2026/e36/sponsors', 'event-sponsors'],
    ['/SoutheastSharkfest2026/e36/members', 'event-members'],
    ['/SoutheastSharkfest2026/e36/cars', 'event-cars'],
  ])('maps %s to %s', (path, name) => {
    expect(resolve(path).name).toBe(name);
  });

  it('gives the events list its own route', () => {
    expect(resolve('/events').name).toBe('events');
    expect(resolve('/events').meta.title).toBe('Events');
  });

  it('does not treat /gallery/e36 as an event', async () => {
    const gallery = makeRouter();
    await gallery.push('/gallery/e36');
    expect(gallery.currentRoute.value.name).toBe('not-found');
    expect(gallery.currentRoute.value.params.pathMatch).toEqual(['gallery', 'e36']);
  });

  it('does not match a non-numeric event id', () => {
    expect(resolve('/SoutheastSharkfest2026/eabc').name).not.toBe('event-info');
  });

  it('passes the numeric event id to the tab pages', () => {
    expect(childProps('/SoutheastSharkfest2026/e36/schedule')).toEqual({ eventId: 36 });
  });

  it('passes showId and embedded to the directory tabs', () => {
    expect(childProps('/SoutheastSharkfest2026/e36/members')).toEqual({ showId: 36, embedded: true });
    expect(childProps('/SoutheastSharkfest2026/e36/cars')).toEqual({ showId: 36, embedded: true });
  });
});
