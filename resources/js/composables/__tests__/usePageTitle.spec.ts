import { describe, it, expect, beforeEach } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';
import { createMemoryHistory } from 'vue-router';
import { formatPageTitle, setPageTitle, usePageTitle, SITE_NAME } from '../usePageTitle';
import { createAppRouter } from '../../router';
import { stubFetch } from '../../test/helpers';

describe('page titles', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    stubFetch();
    document.title = '';
  });

  it('formats "Title | Southeast Sharkfest" and falls back to the site name', () => {
    expect(formatPageTitle('Events')).toBe('Events | Southeast Sharkfest');
    expect(formatPageTitle('  ')).toBe(SITE_NAME);
    expect(formatPageTitle(undefined)).toBe(SITE_NAME);
    expect(formatPageTitle(null)).toBe(SITE_NAME);
  });

  it('setPageTitle writes document.title, also through the composable', () => {
    setPageTitle('Southeast Sharkfest 2026');
    expect(document.title).toBe('Southeast Sharkfest 2026 | Southeast Sharkfest');
    usePageTitle().setPageTitle('Members');
    expect(document.title).toBe('Members | Southeast Sharkfest');
  });

  it('the router sets the title from route.meta.title after each navigation', async () => {
    const router = createAppRouter(createMemoryHistory());
    await router.push('/login');
    expect(document.title).toBe('Sign in | Southeast Sharkfest');
    await router.push('/');
    expect(document.title).toBe('Southeast Sharkfest');
    await router.push('/nowhere');
    expect(document.title).toBe('Page not found | Southeast Sharkfest');
  });

  it('every static route except home declares a title', async () => {
    const { routes } = await import('../../router');
    for (const route of routes.filter((r) => r.name !== 'home')) {
      expect(typeof route.meta?.title).toBe('string');
    }
  });
});
