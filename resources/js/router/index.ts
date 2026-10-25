import { createRouter, createWebHashHistory, type RouteRecordRaw, type RouterHistory } from 'vue-router';
import HomePage from '../pages/HomePage.vue';
import NotFoundPage from '../pages/NotFoundPage.vue';
import LoginPage from '../pages/LoginPage.vue';
import ForgotPasswordPage from '../pages/ForgotPasswordPage.vue';
import ResetPasswordPage from '../pages/ResetPasswordPage.vue';
import { useAuthStore } from '../stores/auth';
import { installAuthGuard, type AuthStore } from './guards';

export const routes: RouteRecordRaw[] = [
  { path: '/', name: 'home', component: HomePage },
  { path: '/login', name: 'login', component: LoginPage, meta: { guestOnly: true } },
  { path: '/forgot-password', name: 'forgot-password', component: ForgotPasswordPage, meta: { guestOnly: true } },
  { path: '/reset-password', name: 'reset-password', component: ResetPasswordPage, meta: { guestOnly: true } },
  // Unmatched paths render a not-found page instead of silently redirecting.
  { path: '/:pathMatch(.*)*', name: 'not-found', component: NotFoundPage },
];

/**
 * Creates the app router with the auth guard installed. Hash history keeps routing working on the single Laravel shell.
 * `getAuth` defaults to the active Pinia store; pass a pinia-bound getter when the router is created before the app.
 */
export function createAppRouter(
  history: RouterHistory = createWebHashHistory(),
  getAuth: () => AuthStore = () => useAuthStore(),
) {
  const router = createRouter({ history, routes });
  installAuthGuard(router, getAuth);
  return router;
}
