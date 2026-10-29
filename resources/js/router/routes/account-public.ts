import type { RouteRecordRaw } from 'vue-router';

// Public account pages: registration (guests only) and the privacy policy (everyone).
const routes: RouteRecordRaw[] = [
  {
    path: '/register',
    name: 'register',
    component: () => import('../../pages/register/RegisterPage.vue'),
    meta: { guestOnly: true, title: 'Create account' },
  },
  {
    path: '/privacy-policy',
    name: 'privacy-policy',
    component: () => import('../../pages/PrivacyPolicyPage.vue'),
    meta: { title: 'Privacy Policy' },
  },
];

export default routes;
