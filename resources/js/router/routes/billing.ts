import type { RouteRecordRaw } from 'vue-router';

/**
 * Member billing (legacy member billing and invoice pages). The invoice is /:memberName/u:memberId/billing/:registrationId,
 * and the registration id may be omitted to show the newest one. Only the profile owner or an admin may view it
 * (privateProfileSection), and a member must be signed in.
 */
const routes: RouteRecordRaw[] = [
  {
    path: '/:memberName/u:memberId(\\d+)/billing/:registrationId(\\d+)?',
    name: 'member-billing',
    component: () => import('../../pages/billing/BillingPage.vue'),
    meta: { minUserLevel: 1, privateProfileSection: true, title: 'Billing' },
    props: (route) => ({
      memberName: String(route.params.memberName),
      memberId: Number(route.params.memberId),
      registrationId: route.params.registrationId ? Number(route.params.registrationId) : undefined,
    }),
  },
];

export default routes;
