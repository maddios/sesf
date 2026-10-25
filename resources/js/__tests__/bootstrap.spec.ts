import { describe, it, expect, afterEach, beforeEach, vi } from 'vitest';
import { createSesfApp } from '../bootstrap';
import { api, setOnUnauthorized } from '../api/client';
import { useAuthStore, type AuthUser } from '../stores/auth';

const sampleUser: AuthUser = {
  userId: 1,
  userLevel: 3,
  typeId: 2,
  email: 'fan@example.test',
  firstName: 'Shark',
  profileUrl: '/p/1',
  slideOutName: 'Shark F.',
  fullAddress: '1 Ocean Way',
};

function jsonResponse(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

beforeEach(() => {
  // Anonymous session and no home data: the mounted shell must not hit the network.
  vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(401, [])));
});

afterEach(() => {
  setOnUnauthorized(undefined);
  vi.unstubAllGlobals();
  document.body.innerHTML = '';
  window.location.hash = '';
});

describe('createSesfApp', () => {
  it('a 401 from the api client clears the auth store through the wiring', async () => {
    const { pinia } = createSesfApp();
    const auth = useAuthStore(pinia);
    auth.setUser(sampleUser);
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse(401, { message: 'Unauthenticated.' })));

    await expect(api.get('/api/me')).rejects.toMatchObject({ status: 401 });

    expect(auth.user).toBeNull();
    expect(auth.isLoggedIn).toBe(false);
    expect(auth.resolved).toBe(true);
  });

  it('a non-401 error from the api client leaves the auth store untouched', async () => {
    const { pinia } = createSesfApp();
    const auth = useAuthStore(pinia);
    auth.setUser(sampleUser);
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse(500, { message: 'boom' })));

    await expect(api.get('/api/me')).rejects.toMatchObject({ status: 500 });

    expect(auth.user).toEqual(sampleUser);
  });

  it('mounts the app with the hash router and renders the home route', async () => {
    window.location.hash = '#/';
    const container = document.createElement('div');
    container.id = 'app';
    document.body.appendChild(container);

    const { app, router } = createSesfApp();
    app.mount(container);
    await router.isReady();

    expect(router.options.history.location).toBe('/');
    expect(window.location.hash).toBe('#/');
    expect(container.querySelector('h1')?.textContent).toMatch(/welcome to southeast sharkfest/i);
    expect(container.querySelector('header img')?.getAttribute('alt')).toMatch(/southeast sharkfest/i);
    expect(container.querySelector('main#main')).not.toBeNull();

    app.unmount();
  });

  it('routes unknown hash URLs to the not-found page when mounted', async () => {
    window.location.hash = '#/missing';
    const container = document.createElement('div');
    document.body.appendChild(container);

    const { app, router } = createSesfApp();
    app.mount(container);
    await router.isReady();

    expect(router.currentRoute.value.name).toBe('not-found');
    expect(container.querySelector('h1')?.textContent).toMatch(/page not found/i);

    app.unmount();
  });

  it('app.ts entry mounts #app with the hash router', async () => {
    window.location.hash = '#/';
    const container = document.createElement('div');
    container.id = 'app';
    document.body.appendChild(container);

    await import('../app');
    await vi.waitFor(() => expect(container.querySelector('h1')?.textContent).toMatch(/welcome/i));
    expect(window.location.hash).toBe('#/');
  });

  it('gives each call its own pinia and router', () => {
    const first = createSesfApp();
    const second = createSesfApp();
    expect(first.pinia).not.toBe(second.pinia);
    expect(first.router).not.toBe(second.router);
  });
});
