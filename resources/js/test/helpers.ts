import { vi } from 'vitest';
import { render } from '@testing-library/vue';
import { createPinia, setActivePinia, type Pinia } from 'pinia';
import { createMemoryHistory, type Router } from 'vue-router';
import type { Component } from 'vue';
import { createAppRouter } from '../router';
import { useAuthStore } from '../stores/auth';

export function jsonResponse(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

/** Body of the request that was sent, parsed back from JSON. */
export function requestBody(init: RequestInit | undefined): unknown {
  return init?.body === undefined ? undefined : JSON.parse(String(init.body));
}

export const SESSION_PAYLOAD = {
  user_id: 7,
  user_level: 1,
  type_id: 1,
  email: 'fan@example.test',
  first_name: 'Shark',
  profileURL: '/fan/u7',
  slideOutName: 'Shark F.',
  fullAddress: '1 Ocean Way',
};

export type RouteHandler = (init: RequestInit) => Response | Promise<Response>;

/**
 * Replaces global fetch. Handlers are keyed by "METHOD /path". By default the session check answers 401
 * (anonymous). A request with no handler rejects, so a test fails loudly on an unexpected call.
 */
export function stubFetch(handlers: Record<string, RouteHandler> = {}) {
  const all: Record<string, RouteHandler> = {
    'POST /login/authenticate': () => jsonResponse(401, []),
    ...handlers,
  };
  const fetchMock = vi.fn(async (input: string, init: RequestInit = {}) => {
    const key = `${init.method ?? 'GET'} ${input}`;
    const handler = all[key];
    if (handler === undefined) {
      throw new Error(`Unexpected request: ${key}`);
    }
    return handler(init);
  });
  vi.stubGlobal('fetch', fetchMock);
  return fetchMock;
}

/** Calls made to a stubbed fetch for one "METHOD /path" key, with their parsed JSON bodies. */
export function callsTo(fetchMock: ReturnType<typeof stubFetch>, key: string): unknown[] {
  const [method, path] = [key.split(' ')[0], key.slice(key.indexOf(' ') + 1)];
  return fetchMock.mock.calls
    .filter(([input, init]) => input === path && (init?.method ?? 'GET') === method)
    .map(([, init]) => requestBody(init));
}

/** A fresh Pinia and a memory-history router with the auth guard installed. */
export function createTestRouter(): { router: Router; pinia: Pinia } {
  const pinia = createPinia();
  setActivePinia(pinia);
  const router = createAppRouter(createMemoryHistory(), () => useAuthStore(pinia));
  return { router, pinia };
}

/** Navigates to `path` (running the guard), then renders `component` with the router and store installed. */
export async function renderAt(component: Component, path: string) {
  const { router, pinia } = createTestRouter();
  await router.push(path);
  await router.isReady();
  const utils = render(component, { global: { plugins: [pinia, router] } });
  return { router, pinia, ...utils };
}
