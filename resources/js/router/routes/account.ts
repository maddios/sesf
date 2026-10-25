import type { RouteRecordRaw } from 'vue-router';

/**
 * Account settings, garage and car editing (legacy member settings and car_profile pages). Member pages need a
 * member-level login and are limited to the profile owner or an admin (privateProfileSection). Car pages need a
 * login only: the server decides whether the signed-in member owns the car. The billing tab is not registered
 * here (it belongs to the billing module).
 */
const memberParams = (route: { params: Record<string, string | string[]> }) => ({
  memberName: String(route.params.memberName),
  memberId: Number(route.params.memberId),
});

const routes: RouteRecordRaw[] = [
  {
    path: '/:memberName/u:memberId(\\d+)/settings',
    name: 'member-settings',
    component: () => import('../../pages/account/MemberSettingsPage.vue'),
    meta: { minUserLevel: 1, privateProfileSection: true, title: 'Account settings' },
    props: memberParams,
  },
  {
    path: '/:memberName/u:memberId(\\d+)/cars',
    name: 'member-garage',
    component: () => import('../../pages/account/GaragePage.vue'),
    meta: { minUserLevel: 1, privateProfileSection: true, title: 'Garage' },
    props: memberParams,
  },
  {
    path: '/car/new',
    name: 'car-new',
    component: () => import('../../pages/account/CarFormPage.vue'),
    meta: { minUserLevel: 1, title: 'Add car' },
  },
  {
    path: '/car/:carId(\\d+)/settings',
    name: 'car-settings',
    component: () => import('../../pages/account/CarFormPage.vue'),
    meta: { minUserLevel: 1, title: 'Edit car' },
    props: (route) => ({ carId: Number(route.params.carId) }),
  },
  {
    path: '/car/:carId(\\d+)/new-image',
    name: 'car-image',
    component: () => import('../../pages/account/CarImagePage.vue'),
    meta: { minUserLevel: 1, title: 'Add a photo' },
    props: (route) => ({ carId: Number(route.params.carId) }),
  },
];

export default routes;
