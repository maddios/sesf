import { h, type Component } from 'vue';
import { RouterView, createMemoryHistory, createRouter, type RouteRecordRaw } from 'vue-router';
import { render } from '@testing-library/vue';
import { jsonResponse, stubFetch, type RouteHandler } from '../../../test/helpers';
import { installPageTitle } from '../../../composables/usePageTitle';
import NotFoundPage from '../../NotFoundPage.vue';
import eventRoutes from '../../../router/routes/events';

/** Raw payloads in the shape the backend sends (string ids, 0/1 flags), trimmed to what the UI reads. */
export const CAR_SHOW_RAW = {
  id: '36',
  name: 'Southeast Sharkfest 2026',
  short_name: 'sesf26',
  description: 'Long description',
  short_description: 'We are pleased to announce the date...',
  shortDescription: 'We are pleased to announce the date...',
  imageURL: 'images/shows/36.jpg',
  profileURL: '#/SoutheastSharkfest2026/e36',
  date_start: '2026-04-30',
  date_end: '2026-05-03',
  date_active: '2026-01-15 09:30:00',
  date_registration_deactivation: '2026-04-20 23:59:59',
  has_image: '1',
  ended: true,
  regOpen: false,
  primaryLocation: { name: 'Home Base', address: { cityState: 'Richmond, VA' } },
  num_registered_members: '12',
  num_registered_cars: 9,
};

export const INFO_RAW = {
  id: '36',
  profileURL: '#/SoutheastSharkfest2026/e36',
  memberProfileURL: '#/Shark%20F/u7',
  name: 'Southeast Sharkfest 2026',
  date_reg_deactivation: '2026-04-01',
  regOpen: true,
  showDates: [{ data: '2026-04-30', name: 'Check-in' }],
  hasSchedule: true,
  agendaTypes: { agenda: 2, judging: 1 },
};

export const AGENDA_RAW = [
  { id: 1, show_id: 36, type_id: 1, name: 'Welcome', text: '<p>Hello <strong>fans</strong></p>', is_pdf: 0, sort_order: 1 },
  { id: 2, show_id: 36, type_id: 1, name: 'Saturday', text: '<p>Concours day</p>', is_pdf: '0', sort_order: 2 },
];

export const JUDGING_RAW = [{ id: 9, show_id: 36, type_id: 2, name: 'Judging rules', text: '<p>Be fair</p>', is_pdf: 0, sort_order: 1 }];

export const LOCATIONS_RAW = [
  {
    name: 'Williamsburg Inn',
    description: '<p>Park in <em>lot B</em></p>',
    address: {
      address1: '136 E Francis St',
      address2: '',
      city: 'Williamsburg',
      state: 'VA',
      zip: '23185',
      cityState: 'Williamsburg, VA',
      fullAddress: '136 E Francis St, Williamsburg, VA 23185',
      lattitude: '37.2707',
      longitude: '-76.7075',
    },
  },
];

export const SPONSORS_RAW = {
  show_id: '36',
  sponsors: [
    { id: 26, name: 'Odometer Gears', URL: 'https://odometer.example/', imageURL: 'images/sponsor/26.jpg', shortDescription: 'Gears.', type_id: '1' },
    { id: 27, name: 'Gold Garage', URL: 'javascript:alert(1)', imageURL: '', description: 'Gold level', type_id: 2 },
    { id: 28, name: 'Mystery Co', URL: '', type_id: '9' },
  ],
};

export const SCHEDULE_HTML = { show_id: 36, schedule: '<p>Doors open at 8am</p>', is_pdf: 0, pdf_url: '' };
export const SCHEDULE_PDF = { show_id: 36, schedule: '', is_pdf: 1, pdf_url: '/images/shows/schedule36.pdf' };

/** Default answers for every event endpoint; tests override the ones they care about. */
export function eventHandlers(overrides: Record<string, RouteHandler> = {}): Record<string, RouteHandler> {
  return {
    'GET /event/info?show_id=36': () => jsonResponse(200, INFO_RAW),
    'GET /event/agenda?show_id=36&type_id=1': () => jsonResponse(200, AGENDA_RAW),
    'GET /event/agenda?show_id=36&type_id=2': () => jsonResponse(200, JUDGING_RAW),
    'GET /event/locations?show_id=36': () => jsonResponse(200, LOCATIONS_RAW),
    'GET /event/sponsors?show_id=36': () => jsonResponse(200, SPONSORS_RAW),
    'GET /event/schedule?show_id=36': () => jsonResponse(200, SCHEDULE_HTML),
    ...overrides,
  };
}

/**
 * A router with the event route module and a catch-all, plus the page-title hook. Built here rather than from
 * router/index.ts, which collects the area modules with import.meta.glob (empty under vitest at the moment).
 */
export function createEventTestRouter() {
  const routes: RouteRecordRaw[] = [
    ...eventRoutes,
    { path: '/:pathMatch(.*)*', name: 'not-found', component: NotFoundPage, meta: { title: 'Page not found' } },
  ];
  const router = createRouter({ history: createMemoryHistory(), routes });
  installPageTitle(router);
  return router;
}

/** Renders the event route tree at `path` (a RouterView root, so layouts and their tab pages both mount). */
export async function renderEventRoute(path: string, handlers: Record<string, RouteHandler> = eventHandlers()) {
  const fetchMock = stubFetch(handlers);
  const router = createEventTestRouter();
  await router.push(path);
  await router.isReady();
  const utils = render({ render: () => h(RouterView) }, { global: { plugins: [router] } });
  return { fetchMock, router, ...utils };
}

/** Renders one component with the event router installed (for RouterLink hrefs), at `path`. */
export async function renderWithEventRouter(component: Component, props: Record<string, unknown>, path = '/events') {
  const router = createEventTestRouter();
  await router.push(path);
  await router.isReady();
  return render(component, { props, global: { plugins: [router] } });
}
