import type { RouteRecordRaw } from 'vue-router';

/**
 * Member and car directories plus the public member and car profiles. Hash-mode URLs.
 * The member profile is /:memberName/u:memberId(\d+). The car profile param is digits only, so /car/new is
 * never captured by it. The legacy car address /:carName/c:carId(\d+) shows the same car profile. Settings and garage routes belong to other modules.
 */
const routes: RouteRecordRaw[] = [
  {
    path: '/members',
    name: 'members',
    component: () => import('../../pages/members/MemberDirectory.vue'),
    meta: { title: 'Members' },
  },
  {
    path: '/cars',
    name: 'cars',
    component: () => import('../../pages/members/CarDirectory.vue'),
    meta: { title: 'Cars' },
  },
  {
    path: '/:memberName/u:memberId(\\d+)',
    name: 'member-profile',
    component: () => import('../../pages/members/MemberProfilePage.vue'),
    // Fallback tab title; the page replaces it with the member's name once loaded.
    meta: { title: 'Member profile' },
    props: (route) => ({
      memberName: String(route.params.memberName),
      memberId: Number(route.params.memberId),
    }),
  },
  {
    path: '/car/:carId(\\d+)',
    name: 'car-profile',
    component: () => import('../../pages/cars/CarProfilePage.vue'),
    // Fallback tab title; the page replaces it with the car's title once loaded.
    meta: { title: 'Car' },
    props: (route) => ({ carId: Number(route.params.carId) }),
  },
  {
    // Legacy car address (Angular `/:carName/c:carId`), e.g. /1972-BMW-2002/c78: the same car profile.
    path: '/:carName/c:carId(\\d+)',
    name: 'car-profile-alias',
    component: () => import('../../pages/cars/CarProfilePage.vue'),
    meta: { title: 'Car' },
    props: (route) => ({ carId: Number(route.params.carId) }),
  },
];

export default routes;
