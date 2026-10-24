import { describe, it, expect, afterEach } from 'vitest';
import { render, screen } from '@testing-library/vue';
import { createApp } from 'vue';
import { createMemoryHistory, createWebHashHistory } from 'vue-router';
import { axe } from 'vitest-axe';
import { createAppRouter } from '../index';
import HomePage from '../../pages/HomePage.vue';
import NotFoundPage from '../../pages/NotFoundPage.vue';

/** Creates a hash-mode router and installs it on a throwaway app so the initial navigation runs. */
async function startHashRouter() {
  const router = createAppRouter(createWebHashHistory());
  createApp({ render: () => null }).use(router);
  await router.isReady();
  return router;
}

describe('app router (hash mode)', () => {
  afterEach(() => {
    window.location.hash = '';
  });

  it('builds hash URLs', () => {
    const router = createAppRouter(createWebHashHistory());
    expect(router.resolve('/').href).toBe('#/');
  });

  it('resolves "/" to HomePage', async () => {
    const router = createAppRouter(createWebHashHistory());
    await router.push('/');
    expect(router.currentRoute.value.name).toBe('home');
    expect(router.currentRoute.value.matched[0]?.components?.default).toBe(HomePage);
    expect(window.location.hash).toBe('#/');
  });

  it('resolves unknown routes to the not-found route and keeps the path', async () => {
    const router = createAppRouter(createWebHashHistory());
    await router.push('/does/not/exist');
    expect(router.currentRoute.value.name).toBe('not-found');
    expect(router.currentRoute.value.matched[0]?.components?.default).toBe(NotFoundPage);
    expect(router.currentRoute.value.fullPath).toBe('/does/not/exist');
  });

  it('renders NotFound for an unknown hash URL', async () => {
    window.location.hash = '#/nope';
    const router = await startHashRouter();
    expect(router.currentRoute.value.name).toBe('not-found');
    expect(window.location.hash).toBe('#/nope');
  });

  it('keeps the query string on an unknown hash URL', async () => {
    window.location.hash = '#/unknown?x=1';
    const router = await startHashRouter();
    expect(router.currentRoute.value.name).toBe('not-found');
    expect(router.currentRoute.value.query).toEqual({ x: '1' });
    expect(router.currentRoute.value.fullPath).toBe('/unknown?x=1');
  });

  it('works with an in-memory history for isolated tests', async () => {
    const router = createAppRouter(createMemoryHistory());
    await router.push('/nope');
    expect(router.currentRoute.value.name).toBe('not-found');
    expect(router.currentRoute.value.fullPath).toBe('/nope');
  });
});

describe('NotFoundPage', () => {
  it('renders a heading and a link home', async () => {
    const router = createAppRouter(createMemoryHistory());
    await router.push('/missing');
    render(NotFoundPage, { global: { plugins: [router] } });

    expect(screen.getByRole('heading', { level: 1, name: /page not found/i })).toBeTruthy();
    expect(screen.getByRole('link', { name: /go to the home page/i }).getAttribute('href')).toBe('/');
  });

  it('has no detectable accessibility violations', async () => {
    const router = createAppRouter(createMemoryHistory());
    await router.push('/missing');
    const { container } = render(NotFoundPage, { global: { plugins: [router] } });
    const results = await axe(container);
    expect(results).toHaveNoViolations();
  });
});
