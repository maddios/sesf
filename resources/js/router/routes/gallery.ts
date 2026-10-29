import type { RouteLocationNormalized, RouteRecordRaw } from 'vue-router';

/**
 * Gallery routes, same hash URLs as the legacy Angular app:
 * /gallery, /gallery/e{eventId}/{galleryId?}, /gallery/create/{eventId}/{galleryId?}, /gallery/manage/{galleryId}.
 */
function optionalId(value: unknown): number | undefined {
  return typeof value === 'string' && value !== '' ? Number(value) : undefined;
}

const galleryProps = (route: RouteLocationNormalized) => ({
  eventId: Number(route.params.eventId),
  galleryId: optionalId(route.params.galleryId),
});

const routes: RouteRecordRaw[] = [
  {
    path: '/gallery',
    name: 'gallery',
    component: () => import('../../pages/gallery/GalleryEventsPage.vue'),
    meta: { title: 'Gallery' },
  },
  {
    path: '/gallery/create/:eventId(\\d+)/:galleryId(\\d+)?',
    name: 'gallery-create',
    component: () => import('../../pages/gallery/GalleryCreatePage.vue'),
    meta: { title: 'Create gallery', minUserLevel: 1 },
    props: galleryProps,
  },
  {
    path: '/gallery/e:eventId(\\d+)/:galleryId(\\d+)?',
    name: 'gallery-event',
    component: () => import('../../pages/gallery/GalleryPage.vue'),
    meta: { title: 'Gallery' },
    props: galleryProps,
  },
  {
    path: '/gallery/manage/:galleryId(\\d+)',
    name: 'gallery-manage',
    component: () => import('../../pages/gallery/GalleryManagePage.vue'),
    meta: { title: 'Manage gallery', minUserLevel: 1 },
    props: (route) => ({ galleryId: Number(route.params.galleryId) }),
  },
];

export default routes;
