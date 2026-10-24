import { describe, it, expect, afterEach } from 'vitest';
import { createMemoryHistory, createWebHashHistory } from 'vue-router';
import { createAppRouter } from '../index';
import HomePage from '../../pages/HomePage.vue';

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

  it('redirects unknown routes to "/"', async () => {
    const router = createAppRouter(createWebHashHistory());
    await router.push('/does/not/exist');
    expect(router.currentRoute.value.fullPath).toBe('/');
    expect(router.currentRoute.value.name).toBe('home');
  });

  it('works with an in-memory history for isolated tests', async () => {
    const router = createAppRouter(createMemoryHistory());
    await router.push('/nope');
    expect(router.currentRoute.value.fullPath).toBe('/');
  });
});
