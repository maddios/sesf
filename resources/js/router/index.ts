import { createRouter, createWebHashHistory, type RouteRecordRaw, type RouterHistory } from 'vue-router';
import HomePage from '../pages/HomePage.vue';

export const routes: RouteRecordRaw[] = [
  { path: '/', name: 'home', component: HomePage },
  { path: '/:pathMatch(.*)*', redirect: '/' },
];

/** Creates the app router. Hash history keeps routing working on the single Laravel shell. */
export function createAppRouter(history: RouterHistory = createWebHashHistory()) {
  return createRouter({ history, routes });
}
