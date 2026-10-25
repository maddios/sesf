import { h, defineComponent } from 'vue';
import { RouterView, createMemoryHistory, type Router } from 'vue-router';
import { createPinia, setActivePinia, type Pinia } from 'pinia';
import { render } from '@testing-library/vue';
import { jsonResponse, stubFetch, type RouteHandler } from '../../../test/helpers';
import { createAppRouter } from '../../../router';
import { useAuthStore, USER_TYPE_ADMIN, USER_TYPE_REGULAR } from '../../../stores/auth';

/** Raw payloads in the backend shape (string ids, 0/1 flags, decimal strings). */
export const EVENT_INFO_RAW = {
  id: '36',
  name: 'Southeast Sharkfest 2026',
  profileURL: '#/SoutheastSharkfest2026/e36',
  memberProfileURL: '#/Shark%20F/u7',
  date_reg_deactivation: '2026-04-01',
  regOpen: true,
  showDates: [
    { name: 'Check-in', data: '2026-04-30' },
    { name: 'Concours', data: '2026-05-01' },
  ],
  hasSchedule: false,
  agendaTypes: { agenda: 0, judging: 0 },
};

export const REGISTRATION_URL = 'GET /event/registration?show_id=36';
export const QUOTE_URL = 'POST /event/registration/quote?show_id=36';
export const SAVE_URL = 'POST /event/registration?show_id=36';
export const INFO_URL = 'GET /event/info?show_id=36';

export function registrationRaw(overrides: Record<string, unknown> = {}, registration: Record<string, unknown> = {}) {
  return {
    registration: {
      id: 12,
      show_id: 36,
      user_id: 7,
      invoice_id: 99,
      is_paid: 0,
      is_cancelled: 0,
      arrival_date: { data: '2026-04-30' },
      arrival_time: 'morning',
      donation: 10,
      discount: '0.00',
      amount_owed: '160.00',
      amount_received: '0.00',
      cars: [{ id: 3, judging_type: 21 }],
      guests: [{ id: 5, first: 'Pat', last: 'Guest', email: '', type: 2 }],
      shirts: [{ id: 8, type: 1 }],
      extras: { '4': { show_extra_id: 4, num_extras: 1 } },
      payment: { cars: '100.00', guests: '0.00', shirts: '20.00', extras: '40.00', total: '160.00', received: '0.00', discount: '0.00', owed: '160.00' },
      ...registration,
    },
    cars: [
      {
        id: 3,
        name: 'Blue Car',
        nickname: 'Blu',
        model: 'Sport',
        year: '1990',
        body_id: 1,
        body: 'Coupe',
        judging_types: [
          { id: 21, name: 'Concours' },
          { id: 22, name: 'Cruise' },
        ],
      },
      { id: 4, name: 'Red Car', nickname: '', model: 'Z', year: '2001', body_id: 2, body: 'Wagon', judging_types: [] },
    ],
    carBodies: [{ id: 1, name: 'Coupe' }],
    guestTypes: [
      { id: 1, name: 'Adult' },
      { id: 2, name: 'Child' },
    ],
    shirtTypes: [
      { id: 1, name: 'Small', price: '20.00' },
      { id: 2, name: 'Large', price: '22.00' },
    ],
    extras: [
      { id: 4, is_enabled: 1, show_id: 36, name: 'Dinner', description: 'Saturday dinner', auth_key: 'dinner36', price: '40.00', type_id: 2, min_value: 0, max_value: 3 },
      { id: 5, is_enabled: 1, show_id: 36, name: 'Pin', description: '', auth_key: 'pin36', price: '5.00', type_id: 1, min_value: 0, max_value: 1 },
      { id: 6, is_enabled: 0, show_id: 36, name: 'Hidden', description: '', auth_key: 'hidden36', price: '1.00', type_id: 1, min_value: 0, max_value: 1 },
    ],
    ...overrides,
  };
}

export const QUOTE_RAW = {
  payment: {
    cars: '100.00',
    guests: '0.00',
    shirts: '20.00',
    extras: '40.00',
    donation: '10.00',
    total: '170.00',
    received: '0.00',
    discount: '0.00',
    owed: '170.00',
    lineItems: [
      { kind: 'car', label: 'Concours registration', quantity: 1, unit: '100.00', amount: '100.00' },
      { kind: 'shirt', label: 'Small T-shirt', quantity: 1, unit: '20.00', amount: '20.00' },
    ],
  },
};

export const MEMBER = { id: 7, name: 'Shark F.', email: 'fan@example.test' };

export interface Scenario {
  /** Reuse a Pinia instance to simulate navigating away and back (the draft lives in the store). */
  pinia?: Pinia;
  auth?: 'member' | 'admin' | 'anonymous';
  path?: string;
  info?: Record<string, unknown>;
  handlers?: Record<string, RouteHandler>;
}

/** Pinia + router + fetch stub, with the signed-in member (or admin) and the page at `path`. */
export async function setupRegistration(scenario: Scenario = {}) {
  const pinia = scenario.pinia ?? createPinia();
  setActivePinia(pinia);
  const auth = useAuthStore();
  if (scenario.auth !== 'anonymous') {
    auth.setUser({
      userId: MEMBER.id,
      userLevel: scenario.auth === 'admin' ? USER_TYPE_ADMIN : USER_TYPE_REGULAR,
      typeId: 1,
      email: MEMBER.email,
      firstName: 'Shark',
      profileUrl: '/Shark%20F/u7',
      slideOutName: 'Shark F.',
      fullAddress: '1 Ocean Way',
    });
  }
  const fetchMock = stubFetch({
    [INFO_URL]: () => jsonResponse(200, { ...EVENT_INFO_RAW, ...(scenario.info ?? {}) }),
    [REGISTRATION_URL]: () => jsonResponse(200, registrationRaw()),
    [QUOTE_URL]: () => jsonResponse(200, QUOTE_RAW),
    ...(scenario.handlers ?? {}),
  });
  const router: Router = createAppRouter(createMemoryHistory(), () => auth);
  await router.push(scenario.path ?? '/SoutheastSharkfest2026/e36/register');
  await router.isReady();
  const Host = defineComponent({ render: () => h(RouterView) });
  const view = render(Host, { global: { plugins: [pinia, router] } });
  return { view, router, fetchMock, auth, pinia };
}

/** Waits for a number of milliseconds on the real clock (used where fake timers would block Testing Library). */
export function wait(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms));
}
