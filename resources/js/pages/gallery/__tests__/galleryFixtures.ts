import { h } from 'vue';
import { RouterView, createMemoryHistory, createRouter, type RouteRecordRaw } from 'vue-router';
import { render } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import { jsonResponse, SESSION_PAYLOAD, stubFetch, type RouteHandler } from '../../../test/helpers';
import { ADMIN_SESSION } from '../../../test/admin';
import { toAuthUser, useAuthStore } from '../../../stores/auth';
import galleryRoutes from '../../../router/routes/gallery';

/** A regular member (user_id 7). */
export const MEMBER_SESSION = SESSION_PAYLOAD;
/** An admin (user_id 1, user_level 3). */
export const ADMIN = ADMIN_SESSION;

export { jsonResponse };

export const ok = (body: unknown): RouteHandler => () => jsonResponse(200, body);

export const SHOW_RAW = { id: 36, name: 'Southeast Sharkfest 2026', imageURL: 'images/shows/36.jpg' };

export const EVENT_INFO_KEY = 'GET /event/info?show_id=36';

export function galleryRaw(overrides: Record<string, unknown> = {}) {
  return {
    id: 5,
    show_id: 36,
    user_id: 7,
    parent_id: null,
    title: 'Cars',
    is_enabled: 1,
    default_item_id: null,
    default_item: null,
    ...overrides,
  };
}

export function itemRaw(id: number, title: string, overrides: Record<string, unknown> = {}) {
  return {
    id,
    title,
    sort_order: id,
    date_taken: '2026-04-30',
    imageURL: `images/gallery/${id}.jpg`,
    user: { fullName: 'Jane Doe', profileURL: '#/Jane/u7' },
    ...overrides,
  };
}

export function pageRaw(items: unknown[], count = items.length, perPage = 24) {
  return { items, count, per_page: perPage };
}

export function breadcrumbRaw(galleries: unknown[] = []) {
  return { car_show: { id: 36, name: 'Southeast Sharkfest 2026' }, galleries };
}

/** Event-level endpoints (no gallery selected). Tests override the ones they care about. */
export function eventLevelHandlers(overrides: Record<string, RouteHandler> = {}): Record<string, RouteHandler> {
  return {
    'GET /gallery/breadcrumb?show_id=36': ok(breadcrumbRaw()),
    'GET /gallery/list?show_id=36': ok([]),
    'GET /gallery/items?show_id=36&get_subcategory_items=1': ok(pageRaw([itemRaw(1, 'Front')])),
    ...overrides,
  };
}

/** Endpoints for gallery 5 inside event 36. */
export function galleryLevelHandlers(overrides: Record<string, RouteHandler> = {}): Record<string, RouteHandler> {
  return {
    'GET /gallery/breadcrumb?show_id=36&gallery_id=5': ok(breadcrumbRaw([galleryRaw({ id: 3, title: 'Parent' })])),
    'GET /gallery/list?show_id=36&gallery_id=5': ok([]),
    'GET /gallery/info?gallery_id=5': ok(galleryRaw()),
    'GET /gallery/items?show_id=36&gallery_id=5&get_subcategory_items=1': ok(pageRaw([itemRaw(1, 'Front')])),
    ...overrides,
  };
}

/**
 * Renders the gallery route tree at `path` as a logged-in `session` (or as a guest when null). The auth store is
 * set directly, so no session check is requested. Unknown requests reject, as in the other page specs.
 */
export async function renderGalleryAt(
  path: string,
  handlers: Record<string, RouteHandler>,
  session: Record<string, unknown> | null = null,
) {
  const fetchMock = stubFetch(handlers);
  const pinia = createPinia();
  setActivePinia(pinia);
  if (session !== null) useAuthStore(pinia).setUser(toAuthUser(session));
  const routes: RouteRecordRaw[] = [
    ...galleryRoutes,
    { path: '/:pathMatch(.*)*', name: 'not-found', component: { render: () => null } },
  ];
  const router = createRouter({ history: createMemoryHistory(), routes });
  await router.push(path);
  await router.isReady();
  const utils = render({ render: () => h(RouterView) }, { global: { plugins: [pinia, router] } });
  return { fetchMock, router, ...utils };
}
