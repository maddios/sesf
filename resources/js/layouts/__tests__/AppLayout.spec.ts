import { describe, it, expect, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import { defineComponent, h } from 'vue';
import { RouterView } from 'vue-router';
import AppLayout from '../AppLayout.vue';
import { createTestRouter, jsonResponse, stubFetch, callsTo, SESSION_PAYLOAD } from '../../test/helpers';
import { useAuthStore } from '../../stores/auth';

const Shell = defineComponent({
  emits: ['contact'],
  setup(_props, { emit }) {
    return () => h(AppLayout, { onContact: () => emit('contact') }, () => h(RouterView));
  },
});

async function renderShell(path = '/login', handlers: Parameters<typeof stubFetch>[0] = {}) {
  const fetchMock = stubFetch({ 'GET /home/events?limit=1': () => jsonResponse(200, []), 'GET /event/sponsors?limit=6&all=true': () => jsonResponse(200, { sponsors: [] }), ...handlers });
  const { router, pinia } = createTestRouter();
  await router.push(path);
  await router.isReady();
  const utils = render(Shell, { global: { plugins: [pinia, router] } });
  return { router, pinia, fetchMock, ...utils };
}

afterEach(() => {
  delete window.__SESF__;
});

describe('AppLayout', () => {
  it('renders the skip link first, the logo link home, the main nav, utility links and the footer', async () => {
    const { container } = await renderShell('/login');
    const first = container.querySelector('a');
    expect(first?.textContent).toBe('Skip to main content');
    expect(screen.getByRole('link', { name: 'Southeast Sharkfest home' }).getAttribute('href')).toBe('/');
    const nav = screen.getByRole('navigation', { name: 'Main' });
    expect(Array.from(nav.querySelectorAll('a')).map((a) => a.textContent?.trim())).toEqual(['Events', 'Members', 'Gallery']);
    expect(screen.getAllByRole('link', { name: 'Login' })[0]?.getAttribute('href')).toBe('/login');
    expect(screen.getAllByRole('link', { name: 'Create Account' })[0]?.getAttribute('href')).toBe('#/register');
    expect(screen.getByRole('contentinfo').textContent).toMatch(/Southeast Sharkfest, Inc\. © \d{4}/);
    expect(screen.getByRole('link', { name: 'Privacy Policy' }).getAttribute('href')).toBe('/privacy-policy');
  });

  it('shows FORUM and SHOP only when their URLs are configured', async () => {
    window.__SESF__ = { forumUrl: 'https://forum.example.test/', shopUrl: 'https://shop.example.test/' };
    await renderShell('/login');
    const nav = screen.getByRole('navigation', { name: 'Main' });
    const links = Array.from(nav.querySelectorAll('a'));
    expect(links.map((a) => a.textContent?.trim())).toEqual(['Events', 'Members', 'Gallery', 'Forum', 'Shop']);
    expect(links[3]?.getAttribute('href')).toBe('https://forum.example.test/');
    expect(links[4]?.getAttribute('href')).toBe('https://shop.example.test/');
  });

  it('marks the active section with aria-current', async () => {
    const { router } = await renderShell('/login');
    const nav = screen.getByRole('navigation', { name: 'Main' });
    expect(nav.querySelector('[aria-current="page"]')).toBeNull();
    await router.push('/SoutheastSharkfest2026/e36');
    await waitFor(() => expect(nav.querySelector('[aria-current="page"]')?.textContent?.trim()).toBe('Events'));
  });

  it('the skip link moves focus to main without changing the URL', async () => {
    const user = userEvent.setup();
    const { router } = await renderShell('/login');
    await user.click(screen.getByText('Skip to main content'));
    expect(document.activeElement?.id).toBe('main');
    expect(router.currentRoute.value.path).toBe('/login');
  });

  it('moves focus to main after a route change, but not for a query-only change', async () => {
    const { router } = await renderShell('/login');
    expect(document.activeElement?.id).not.toBe('main');
    await router.push('/forgot-password');
    await waitFor(() => expect(document.activeElement?.id).toBe('main'));
    (document.activeElement as HTMLElement).blur();
    await router.push('/forgot-password?x=1');
    await new Promise((resolve) => setTimeout(resolve, 0));
    expect(document.activeElement?.id).not.toBe('main');
  });

  it('the mobile menu button toggles the menu, closes on Escape and on navigation', async () => {
    const user = userEvent.setup();
    const { router } = await renderShell('/login');
    const button = screen.getByRole('button', { name: 'Menu' });
    const menu = document.getElementById('mobile-menu') as HTMLElement;
    expect(button.getAttribute('aria-controls')).toBe('mobile-menu');
    expect(button.getAttribute('aria-expanded')).toBe('false');
    expect(menu.style.display).toBe('none');

    await user.click(button);
    expect(button.getAttribute('aria-expanded')).toBe('true');
    expect(menu.style.display).toBe('');
    const mobileNav = screen.getByRole('navigation', { name: 'Main menu' });
    expect(mobileNav.textContent).toContain('Gallery');

    (mobileNav.querySelector('a') as HTMLElement).focus();
    await user.keyboard('{Escape}');
    expect(button.getAttribute('aria-expanded')).toBe('false');
    expect(document.activeElement).toBe(button);

    await user.click(button);
    await router.push('/forgot-password');
    await waitFor(() => expect(button.getAttribute('aria-expanded')).toBe('false'));
  });

  it('emits contact from the footer button', async () => {
    const user = userEvent.setup();
    const { emitted } = await renderShell('/login');
    await user.click(screen.getByRole('button', { name: 'Contact Us' }));
    expect(emitted().contact).toHaveLength(1);
  });

  it('shows the Welcome account menu for a signed-in user and logs out from it', async () => {
    const user = userEvent.setup();
    const { pinia, router, fetchMock } = await renderShell('/', {
      'POST /login/authenticate': () => jsonResponse(200, { ...SESSION_PAYLOAD, user_level: 3, type_id: 3 }),
      'POST /login/logout': () => jsonResponse(200, { message: 'bye' }),
    });
    const auth = useAuthStore(pinia);
    expect(auth.isLoggedIn).toBe(true);
    expect(screen.queryAllByRole('link', { name: 'Login' })).toHaveLength(0);

    const trigger = screen.getByRole('button', { name: 'Welcome Shark' });
    await user.click(trigger);
    const menu = screen.getByRole('menu');
    expect(Array.from(menu.querySelectorAll('[role="menuitem"]')).map((el) => el.textContent?.trim())).toEqual([
      'Profile',
      'Settings',
      'Billing',
      'Admin',
      'Judging',
      'Logout',
    ]);
    expect(screen.getByRole('menuitem', { name: 'Profile' }).getAttribute('href')).toBe('/fan/u7');

    await user.click(screen.getByRole('menuitem', { name: 'Logout' }));
    await waitFor(() => expect(auth.isLoggedIn).toBe(false));
    expect(callsTo(fetchMock, 'POST /login/logout')).toHaveLength(1);
    expect(router.currentRoute.value.path).toBe('/');
    expect(screen.getAllByRole('link', { name: 'Login' }).length).toBeGreaterThan(0);
  });

  it('lists the account links in the mobile menu for a signed-in user', async () => {
    const user = userEvent.setup();
    const { pinia } = await renderShell('/', {
      'POST /login/authenticate': () => jsonResponse(200, { ...SESSION_PAYLOAD, first_name: '' }),
      'POST /login/logout': () => jsonResponse(200, {}),
    });
    await user.click(screen.getByRole('button', { name: 'Menu' }));
    const menu = document.getElementById('mobile-menu') as HTMLElement;
    expect(menu.textContent).toContain('Welcome');
    expect(menu.querySelector('a[href="/fan/u7/billing"]')).not.toBeNull();
    const logout = Array.from(menu.querySelectorAll('button')).find((b) => b.textContent?.trim() === 'Logout') as HTMLElement;
    await user.click(logout);
    await waitFor(() => expect(useAuthStore(pinia).isLoggedIn).toBe(false));
  });

  it('has no detectable accessibility violations', async () => {
    const { container } = await renderShell('/login');
    expect(await axe(container)).toHaveNoViolations();
  });
});
