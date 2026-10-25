import { createRouter, createWebHashHistory, type RouteRecordRaw, type RouterHistory } from 'vue-router';
import HomePage from '../pages/HomePage.vue';
import NotFoundPage from '../pages/NotFoundPage.vue';
import LoginPage from '../pages/LoginPage.vue';
import ForgotPasswordPage from '../pages/ForgotPasswordPage.vue';
import ResetPasswordPage from '../pages/ResetPasswordPage.vue';
import { useAuthStore } from '../stores/auth';
import { installPageTitle } from '../composables/usePageTitle';
import { installAuthGuard, type AuthStore } from './guards';

// Area route modules live in router/routes/*.ts (default export: RouteRecordRaw[]) and are collected here
// so feature work on different areas never edits this file. Order matters for hash patterns like
// /:name/u:id vs /:name/e:id, so modules are sorted by file name and each module orders its own routes.
const areaModules = import.meta.glob<{ default: RouteRecordRaw[] }>('./routes/*.ts', { eager: true });
const areaRoutes: RouteRecordRaw[] = Object.keys(areaModules)
  .sort()
  .flatMap((file) => areaModules[file].default);

export const routes: RouteRecordRaw[] = [
  // Home has no meta.title: the tab shows the bare site name.
  { path: '/', name: 'home', component: HomePage },
  { path: '/login', name: 'login', component: LoginPage, meta: { guestOnly: true, title: 'Sign in' } },
  {
    path: '/forgot-password',
    name: 'forgot-password',
    component: ForgotPasswordPage,
    meta: { guestOnly: true, title: 'Forgot password' },
  },
  {
    path: '/reset-password',
    name: 'reset-password',
    component: ResetPasswordPage,
    meta: { guestOnly: true, title: 'Reset password' },
  },
  ...areaRoutes,
  // Unmatched paths render a not-found page instead of silently redirecting.
  { path: '/:pathMatch(.*)*', name: 'not-found', component: NotFoundPage, meta: { title: 'Page not found' } },
];

/**
 * Creates the app router with the auth guard and the page-title hook installed. Hash history keeps routing
 * working on the single Laravel shell. `getAuth` defaults to the active Pinia store; pass a pinia-bound getter
 * when the router is created before the app.
 */
export function createAppRouter(
  history: RouterHistory = createWebHashHistory(),
  getAuth: () => AuthStore = () => useAuthStore(),
) {
  const router = createRouter({
    history,
    routes,
    // New pages start at the top; back/forward restores the old position.
    scrollBehavior: (_to, _from, savedPosition) => savedPosition ?? { top: 0 },
  });
  installAuthGuard(router, getAuth);
  installPageTitle(router);
  return router;
}
