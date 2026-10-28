import { describe, it, expect } from 'vitest';
import { createRouter, createMemoryHistory, type RouteRecordRaw } from 'vue-router';
import galleryRoutes from '../gallery';
import eventRoutes from '../events';

const CATCH_ALL: RouteRecordRaw = { path: '/:pathMatch(.*)*', name: 'not-found', component: { render: () => null } };

/** Gallery routes plus a catch-all, so the tests do not depend on other area modules. */
function makeRouter(modules: RouteRecordRaw[] = galleryRoutes) {
  return createRouter({ history: createMemoryHistory(), routes: [...modules, CATCH_ALL] });
}

/** Props the matched record would hand its component for `path`. */
function propsFor(path: string, modules?: RouteRecordRaw[]): Record<string, unknown> {
  const route = makeRouter(modules).resolve(path);
  const child = route.matched[route.matched.length - 1];
  const props = child?.props as unknown as { default?: (r: unknown) => Record<string, unknown> } | undefined;
  return props?.default?.(route) ?? {};
}

describe('gallery routes', () => {
  const router = makeRouter();
  const resolve = (path: string) => router.resolve(path);

  it('gives /gallery the events list', () => {
    expect(resolve('/gallery').name).toBe('gallery');
  });

  it('gives /gallery/e36 the event gallery without a gallery id', () => {
    const route = resolve('/gallery/e36');
    expect(route.name).toBe('gallery-event');
    expect(propsFor('/gallery/e36')).toEqual({ eventId: 36, galleryId: undefined });
  });

  it('passes the gallery id for /gallery/e36/12', () => {
    expect(resolve('/gallery/e36/12').name).toBe('gallery-event');
    expect(propsFor('/gallery/e36/12')).toEqual({ eventId: 36, galleryId: 12 });
  });

  it.each(['/gallery/create/36', '/gallery/create/36/12'])('gives %s the create page for members and admins', (path) => {
    const route = resolve(path);
    expect(route.name).toBe('gallery-create');
    expect(route.meta.minUserLevel).toBe(1);
    expect(propsFor(path)).toEqual({ eventId: 36, galleryId: path.endsWith('/12') ? 12 : undefined });
  });

  it('gives /gallery/manage/12 the manage page with its gallery id', () => {
    const route = resolve('/gallery/manage/12');
    expect(route.name).toBe('gallery-manage');
    expect(route.meta.minUserLevel).toBe(1);
    expect(propsFor('/gallery/manage/12')).toEqual({ galleryId: 12 });
  });

  it('does not match a non-numeric event id to the event gallery', () => {
    expect(resolve('/gallery/eabc').name).not.toBe('gallery-event');
  });
});

describe('gallery and event routes together', () => {
  const combined = [...eventRoutes, ...galleryRoutes];

  it('resolves /gallery/e36 to the gallery page, not the event page', () => {
    expect(makeRouter(combined).resolve('/gallery/e36').name).toBe('gallery-event');
  });

  it('still resolves an event page with the event routes present', () => {
    expect(makeRouter(combined).resolve('/SoutheastSharkfest2026/e36').name).toBe('event-info');
  });
});
