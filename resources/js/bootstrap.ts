import { createApp } from 'vue';
import { createPinia, type Pinia } from 'pinia';
import type { App as VueApp } from 'vue';
import type { Router } from 'vue-router';
import App from './App.vue';
import { createAppRouter } from './router';
import { setOnUnauthorized } from './api/client';
import { useAuthStore } from './stores/auth';

export interface SesfApp {
  app: VueApp;
  pinia: Pinia;
  router: Router;
}

/**
 * Builds the app without mounting it. Wires a 401 from the API client to the auth store's clear(),
 * so an expired session logs the user out wherever the request came from.
 */
export function createSesfApp(): SesfApp {
  const app = createApp(App);
  const pinia = createPinia();
  const router = createAppRouter();
  app.use(pinia);
  app.use(router);

  const auth = useAuthStore(pinia);
  setOnUnauthorized(auth.clear);

  return { app, pinia, router };
}
