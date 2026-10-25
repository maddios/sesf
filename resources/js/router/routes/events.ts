import type { RouteLocationNormalized, RouteRecordRaw } from 'vue-router';

/**
 * Event routes: /{name}/e{id} and its tabs. /gallery/e{id} is a gallery page, not an event. vue-router cannot
 * exclude one literal name from a param regex (nested groups are not supported there), so the event route
 * hands that one name to the not-found page. The gallery routes outrank this pattern anyway (static segment).
 */
export const EVENT_BASE_PATH = '/:eventName/e:eventId(\\d+)';

const eventIdProps = (route: RouteLocationNormalized) => ({ eventId: Number(route.params.eventId) });

const routes: RouteRecordRaw[] = [
  {
    path: '/events',
    name: 'events',
    component: () => import('../../pages/events/EventsPage.vue'),
    meta: { title: 'Events' },
  },
  {
    path: EVENT_BASE_PATH,
    // Shown only until the layout has loaded the event and set its name as the title.
    meta: { title: 'Event' },
    beforeEnter: (to) =>
      String(to.params.eventName) === 'gallery'
        ? { name: 'not-found', params: { pathMatch: to.path.split('/').filter((part) => part !== '') } }
        : true,
    component: () => import('../../pages/events/EventLayout.vue'),
    children: [
      { path: '', name: 'event-info', component: () => import('../../pages/events/EventInfoPage.vue'), props: eventIdProps },
      { path: 'judging', name: 'event-judging', component: () => import('../../pages/events/EventJudgingPage.vue'), props: eventIdProps },
      { path: 'schedule', name: 'event-schedule', component: () => import('../../pages/events/EventSchedulePage.vue'), props: eventIdProps },
      { path: 'directions', name: 'event-directions', component: () => import('../../pages/events/EventDirectionsPage.vue'), props: eventIdProps },
      { path: 'sponsors', name: 'event-sponsors', component: () => import('../../pages/events/EventSponsorsPage.vue'), props: eventIdProps },
      { path: 'news', name: 'event-news', component: () => import('../../pages/events/EventNewsPage.vue') },
      {
        path: 'members',
        name: 'event-members',
        component: () => import('../../pages/members/MemberDirectory.vue'),
        props: (route) => ({ showId: Number(route.params.eventId), embedded: true }),
      },
      {
        path: 'cars',
        name: 'event-cars',
        component: () => import('../../pages/members/CarDirectory.vue'),
        props: (route) => ({ showId: Number(route.params.eventId), embedded: true }),
      },
    ],
  },
];

export default routes;
