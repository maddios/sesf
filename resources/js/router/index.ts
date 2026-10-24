import { createRouter, createWebHashHistory, type RouteRecordRaw, type RouterHistory } from 'vue-router';
import HomePage from '../pages/HomePage.vue';
import NotFoundPage from '../pages/NotFoundPage.vue';

export const routes: RouteRecordRaw[] = [
  { path: '/', name: 'home', component: HomePage },
  // Unmatched paths render a not-found page instead of silently redirecting.
  { path: '/:pathMatch(.*)*', name: 'not-found', component: NotFoundPage },
];

/** Creates the app router. Hash history keeps routing working on the single Laravel shell. */
export function createAppRouter(history: RouterHistory = createWebHashHistory()) {
  return createRouter({ history, routes });
}
