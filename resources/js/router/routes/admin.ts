import type { RouteRecordRaw } from 'vue-router';
import { ADMIN_REGISTRY } from '../../admin/registry';
import { USER_TYPE_ADMIN, useAuthStore } from '../../stores/auth';

declare module 'vue-router' {
  interface RouteMeta {
    /** 'admin' renders the page in AdminLayout instead of the public site layout (App.vue). */
    layout?: 'admin';
    /** Registry key of the admin page (breadcrumb and active sidebar link). */
    adminKey?: string;
  }
}

/**
 * Admin area (/#/admin/...). The parent route renders AdminLayout and needs an admin (level 3). Children come from
 * admin/registry.ts; a child's minLevel overrides the parent's (route meta merges parent -> child), so judge pages
 * can open with level 2. Access is enforced again by the admin-api middleware.
 */
const routes: RouteRecordRaw[] = [
  {
    path: '/admin',
    component: () => import('../../layouts/AdminLayout.vue'),
    meta: { minUserLevel: 3, title: 'Admin', layout: 'admin' },
    children: [
      {
        path: '',
        name: 'admin',
        component: () => import('../../pages/admin/AdminDashboardPage.vue'),
        meta: { title: 'Admin', adminKey: 'dashboard', minUserLevel: 2 },
        beforeEnter: () => (useAuthStore().userLevel < USER_TYPE_ADMIN ? { name: 'admin-judging', replace: true } : true),
      },
      ...ADMIN_REGISTRY.map(
        (entry): RouteRecordRaw => ({
          path: entry.path,
          name: `admin-${entry.key}`,
          component: entry.component,
          meta: { title: `${entry.label} | Admin`, minUserLevel: entry.minLevel, adminKey: entry.key },
        }),
      ),
    ],
  },
];

export default routes;
