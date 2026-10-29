import type { RouteLocationNormalized, RouteRecordRaw } from 'vue-router';

/**
 * Event registration: the registration form and the per-extra editor. Both are top-level routes (router modules
 * are merged flat), placed under the event path with a static segment, so they win over the event layout's
 * /:eventName/e:eventId(\d+) pattern. Hash-mode URLs; sign-in is required (minUserLevel 1).
 */
const eventParams = (route: RouteLocationNormalized) => ({
  eventName: String(route.params.eventName),
  eventId: Number(route.params.eventId),
});

/** Same rule as the event route: the gallery's /gallery/e{id} pages are not events. */
const notAnEvent = (to: RouteLocationNormalized) =>
  String(to.params.eventName) === 'gallery'
    ? { name: 'not-found', params: { pathMatch: to.path.split('/').filter((part) => part !== '') } }
    : true;

const routes: RouteRecordRaw[] = [
  {
    path: '/:eventName/e:eventId(\\d+)/register',
    name: 'event-register',
    component: () => import('../../pages/registration/RegistrationPage.vue'),
    meta: { minUserLevel: 1, title: 'Register' },
    beforeEnter: notAnEvent,
    props: (route) => {
      const userId = Number(route.query.user_id);
      return { ...eventParams(route), userId: Number.isInteger(userId) && userId > 0 ? userId : undefined };
    },
  },
  {
    path: '/:eventName/e:eventId(\\d+)/extra/:authKey',
    name: 'event-extra',
    component: () => import('../../pages/registration/ExtraPage.vue'),
    meta: { minUserLevel: 1, title: 'Extra' },
    beforeEnter: notAnEvent,
    props: (route) => ({ ...eventParams(route), authKey: String(route.params.authKey) }),
  },
];

export default routes;
