import { render } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import { createMemoryHistory, createRouter, type RouteRecordRaw } from 'vue-router';
import type { Component } from 'vue';
import { vi } from 'vitest';
import memberRoutes from '../../../router/routes/members';
import { useAuthStore, type AuthUser } from '../../../stores/auth';
import { jsonResponse } from '../../../test/helpers';

/** Routes under test plus a catch-all, so this suite never depends on other modules' route files. */
const testRoutes: RouteRecordRaw[] = [
  { path: '/', name: 'home', component: { render: () => null } },
  { path: '/login', name: 'login', component: { render: () => null } },
  ...memberRoutes,
  { path: '/:pathMatch(.*)*', name: 'catch-all', component: { render: () => null } },
];

export const MEMBER_USER: AuthUser = {
  userId: 7,
  userLevel: 1,
  typeId: 1,
  email: 'fan@example.test',
  firstName: 'Shark',
  profileUrl: '/Shark/u7',
  slideOutName: 'Shark F.',
  fullAddress: '1 Ocean Way',
};

export const ADMIN_USER: AuthUser = { ...MEMBER_USER, userId: 99, userLevel: 3 };

interface MountOptions {
  path?: string;
  props?: Record<string, unknown>;
  user?: AuthUser | null;
}

/** Mounts a component with a fresh Pinia, a memory router at `path` and an optional signed-in user. */
export async function mountWithRouter(component: Component, options: MountOptions = {}) {
  const pinia = createPinia();
  setActivePinia(pinia);
  const auth = useAuthStore(pinia);
  if (options.user) {
    auth.setUser(options.user);
  } else {
    auth.clear();
  }
  const router = createRouter({ history: createMemoryHistory(), routes: testRoutes });
  await router.push(options.path ?? '/');
  await router.isReady();
  const utils = render(component, {
    props: options.props,
    global: { plugins: [pinia, router] },
  });
  return { router, auth, ...utils };
}

/** Paginated wrapper as returned by members/users and cars/list. */
export function pageBody(data: unknown[], overrides: Partial<{ last: number; page: number; total: number }> = {}) {
  return {
    last: overrides.last ?? 1,
    page: overrides.page ?? 1,
    perPage: 20,
    data,
    total: overrides.total ?? data.length,
  };
}

export const GUEST_ROW = {
  id: 12,
  has_image: true,
  profileURL: '#/ShawnB/u12',
  imageURL: 'images/users/12.jpg',
  firstNameLastInitial: 'Shawn B',
  fullName: 'Shawn B',
  cityState: 'MO',
};

export const MEMBER_ROW = {
  id: 44,
  has_image: false,
  profileURL: '#/KaiX/u44',
  imageURL: '',
  firstNameLastInitial: 'Kai X',
  fullName: 'Kai Xu',
  cityState: 'Tampa, FL',
  first: 'Kai',
  last: 'Xu',
};

export const CAR_ROW = {
  id: 78,
  has_image: true,
  name: '1987 BMW M635CSi',
  nickname: 'Sharky',
  year: '1987',
  model: 'M635CSi',
  body_id: 2,
  type_id: 1,
  profileURL: '#/car/78',
  imageURL: 'images/cars/78.jpg',
  user: GUEST_ROW,
};

export const MEMBER_FILTERS = [
  {
    type: 'dropdown',
    name: 'show_id',
    label: 'Attended Show',
    data: [
      { id: 36, name: 'Southeast Sharkfest 2026' },
      { id: 35, name: 'Southeast Sharkfest 2025' },
    ],
    value: null,
  },
  { type: 'textbox', name: 'name', label: 'Member Name', value: null },
];

export const CAR_FILTERS = [
  ...MEMBER_FILTERS.slice(0, 1),
  {
    type: 'dropdown',
    name: 'body_id',
    label: 'Body',
    data: [{ id: 2, name: 'Coupe' }],
    value: null,
  },
];

export const MEMBER_PROFILE_PUBLIC = {
  is_private: false,
  member_info: {
    id: 44,
    has_image: false,
    profileURL: '#/KaiX/u44',
    imageURL: '',
    firstNameLastInitial: 'Kai X',
    fullName: 'Kai X',
    cityState: 'FL',
  },
  car_list: {
    num_cars: 4,
    cars: [
      { id: 1, has_image: false, name: 'A', nickname: 'Blue', year: '1988', model: 'M6', body: 'Coupe', type_id: 1, profileURL: '#/car/1', imageURL: '', shows: [] },
      { id: 2, has_image: false, name: 'B', nickname: '', year: '1990', model: '850i', body: 'Coupe', type_id: 1, profileURL: '#/car/2', imageURL: '', shows: [] },
      { id: 3, has_image: true, name: 'C', nickname: '', year: '1985', model: '635', body: 'Coupe', type_id: 1, profileURL: '#/car/3', imageURL: 'images/cars/3.jpg', shows: [] },
      { id: 4, has_image: false, name: 'D', nickname: '', year: '1979', model: '633', body: 'Coupe', type_id: 1, profileURL: '#/car/4', imageURL: '', shows: [] },
    ],
  },
  event_list: {
    num_events: 1,
    events: [
      {
        id: 9,
        show_id: 36,
        showName: 'Southeast Sharkfest 2026',
        shortDate: 'Apr 2026',
        profileURL: '#/SoutheastSharkfest2026/e36',
        carShow: { id: 36, name: 'Southeast Sharkfest 2026', shortDate: 'Apr 2026', profileURL: '#/SoutheastSharkfest2026/e36', imageURL: 'images/shows/36.jpg' },
        cars: [{ id: 2, has_image: false, name: 'B', nickname: '', year: '1990', model: '850i', body: 'Coupe', type_id: 1, profileURL: '#/car/2', imageURL: '', shows: [] }],
      },
    ],
  },
  member_preferences: { allow_private_message: 1 },
};

export const MEMBER_PROFILE_OWNER = {
  ...MEMBER_PROFILE_PUBLIC,
  is_private: true,
  member_info: { ...MEMBER_PROFILE_PUBLIC.member_info, fullName: 'Kai Xu', cityState: 'Tampa, FL' },
};

export const CAR_PROFILE_PUBLIC = {
  user_id: 12,
  model: 'M635CSi',
  year: '1987',
  imageURL: 'images/cars/78.jpg',
  name: '1987 BMW M635CSi',
  body: { name: 'Coupe' },
  type: { type: 'Sport' },
  profileURL: '#/car/78',
  is_private: false,
  user: { profileURL: '#/ShawnB/u12', firstNameLastInitial: 'Shawn B', profileImageURL: 'images/users/12.jpg' },
  shows: [{ name: 'Southeast Sharkfest 2026', shortDate: 'Apr 2026', profileURL: '#/SoutheastSharkfest2026/e36' }],
};

export const CAR_PROFILE_OWNER = {
  id: 78,
  user_id: 7,
  model: 'M635CSi',
  year: '1987',
  nickname: 'Sharky',
  imageURL: '',
  name: '1987 BMW M635CSi',
  is_private: true,
  body: { id: 2, name: 'Coupe' },
  type: { id: 1, name: 'Sport' },
  shows: [{ name: 'Southeast Sharkfest 2026', shortDate: 'Apr 2026', profileURL: '#/SoutheastSharkfest2026/e36', judgingLink: '#/SoutheastSharkfest2026/judging' }],
};

/** Replaces fetch with a handler that sees the parsed URL (path + query), so tests can assert exact query strings. */
export function fetchWith(handler: (url: URL, init: RequestInit) => Response | Promise<Response>) {
  const fn = vi.fn(async (input: string, init: RequestInit = {}) => handler(new URL(input, 'http://localhost'), init));
  vi.stubGlobal('fetch', fn);
  return fn;
}

/** Every request made so far as "path?query". */
export function requested(fn: ReturnType<typeof fetchWith>): string[] {
  return fn.mock.calls.map(([input]) => {
    const url = new URL(input, 'http://localhost');
    return `${url.pathname}${url.search}`;
  });
}

export { jsonResponse };
