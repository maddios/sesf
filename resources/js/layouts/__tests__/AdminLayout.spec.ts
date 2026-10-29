import { describe, it, expect, afterEach, vi } from 'vitest';
import { screen, within } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import App from '../../App.vue';
import { renderAt, SESSION_PAYLOAD, jsonResponse } from '../../test/helpers';
import { ADMIN_SESSION, listBody, stubAdminFetch } from '../../test/admin';
import { useToast } from '../../composables/admin/useToast';

const DASHBOARD = {
  event: null,
  event_counts: null,
  totals: { members: 3, events: 0, sponsors_enabled: 1 },
};

function stubAdminApi(session: unknown = ADMIN_SESSION, impersonating = false) {
  return stubAdminFetch(({ method, path }) => {
    if (method === 'GET' && path === '/admin-api/dashboard') return jsonResponse(200, DASHBOARD);
    if (method === 'GET' && path === '/admin-api/impersonation') return jsonResponse(200, { data: { impersonating, impersonator_name: impersonating ? 'Ada Admin' : null } });
    if (method === 'GET' && path === '/admin-api/sponsors') return jsonResponse(200, listBody([]));
    if (method === 'POST' && path === '/login/logout') return jsonResponse(200, {});
    return undefined;
  }, session);
}

afterEach(() => useToast().clear());

describe('AdminLayout', () => {
  it('shows the impersonation banner above the admin header while logged in as a member', async () => {
    stubAdminApi(ADMIN_SESSION, true);
    await renderAt(App, '/admin');
    expect(await screen.findByText(/You are logged in as this member/)).toBeTruthy();
    expect(screen.getByText(/Ada Admin/)).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Return to my account' })).toBeTruthy();
  });

  it('does not show the banner when not impersonating', async () => {
    stubAdminApi();
    await renderAt(App, '/admin');
    expect(await screen.findByRole('heading', { name: 'Dashboard' })).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Return to my account' })).toBeNull();
  });

  it('renders the admin shell instead of the site layout, with grouped navigation and a breadcrumb', async () => {
    stubAdminApi();
    const { container } = await renderAt(App, '/admin');
    const nav = await screen.findByRole('navigation', { name: 'Admin' });
    expect(within(nav).getByRole('link', { name: 'Dashboard' }).getAttribute('aria-current')).toBe('page');
    expect(within(nav).getByText('Sponsors', { selector: 'p' })).toBeTruthy();
    expect(within(nav).getByRole('link', { name: 'Sponsors' })).toBeTruthy();
    expect(within(screen.getByRole('navigation', { name: 'Breadcrumb' })).getByText('Dashboard').getAttribute('aria-current')).toBe('page');
    expect(screen.getByRole('link', { name: 'Back to site' }).getAttribute('href')).toBe('#/');
    expect(screen.getByRole('status', { name: 'Notifications' })).toBeTruthy();
    // The public header/footer are not rendered.
    expect(screen.queryByRole('contentinfo')).toBeNull();
    expect(await screen.findByRole('heading', { name: 'Dashboard' })).toBeTruthy();
    expect(await axe(container)).toHaveNoViolations();
  });

  it('marks the current page, updates the breadcrumb and moves focus to main after navigating', async () => {
    stubAdminApi();
    const user = userEvent.setup();
    const { router } = await renderAt(App, '/admin');
    const nav = await screen.findByRole('navigation', { name: 'Admin' });
    await user.click(within(nav).getByRole('link', { name: 'Sponsors' }));
    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('admin-sponsors'));
    await screen.findByRole('heading', { level: 1, name: 'Sponsors' });
    expect(within(nav).getByRole('link', { name: 'Sponsors' }).getAttribute('aria-current')).toBe('page');
    const crumbs = within(screen.getByRole('navigation', { name: 'Breadcrumb' }));
    expect(crumbs.getByRole('link', { name: 'Admin' })).toBeTruthy();
    expect(crumbs.getByText('Sponsors').getAttribute('aria-current')).toBe('page');
    expect(document.activeElement?.id).toBe('main');
    expect(document.title).toBe('Sponsors | Admin | Southeast Sharkfest');
  });

  it('toggles the mobile menu with aria-expanded', async () => {
    stubAdminApi();
    const user = userEvent.setup();
    await renderAt(App, '/admin');
    const toggle = await screen.findByRole('button', { name: /Menu/ });
    expect(toggle.getAttribute('aria-controls')).toBe('admin-sidebar');
    expect(toggle.getAttribute('aria-expanded')).toBe('false');
    expect(document.getElementById('admin-sidebar')?.className).toContain('hidden');
    await user.click(toggle);
    expect(toggle.getAttribute('aria-expanded')).toBe('true');
    expect(document.getElementById('admin-sidebar')?.className).not.toMatch(/(^|\s)hidden(\s|$)/);
  });

  it('signs out from the user menu', async () => {
    const { seen } = stubAdminApi();
    const user = userEvent.setup();
    const { router } = await renderAt(App, '/admin');
    await user.click(await screen.findByRole('button', { name: 'Ada' }));
    await user.click(screen.getByRole('menuitem', { name: 'Sign out' }));
    await vi.waitFor(() => expect(router.currentRoute.value.path).toBe('/'));
    expect(seen.some((r) => r.path === '/login/logout')).toBe(true);
  });

  it('shows a judge only the judging pages', async () => {
    stubAdminFetch(({ path }) => {
      if (path === '/admin-api/judging/shows') return jsonResponse(200, { data: [] });
      if (path === '/admin-api/impersonation') return jsonResponse(200, { data: { impersonating: false, impersonator_name: null } });
      return undefined;
    }, { ...ADMIN_SESSION, user_level: 2, type_id: 2 });
    const { router } = await renderAt(App, '/admin');
    expect(router.currentRoute.value.name).toBe('admin-judging');
    const nav = await screen.findByRole('navigation', { name: 'Admin' });
    expect(within(nav).getAllByRole('link').map((link) => link.textContent?.trim())).toEqual(['Judging']);
    expect(within(screen.getByRole('navigation', { name: 'Breadcrumb' })).queryByRole('link')).toBeNull();
  });

  it('sends members home and guests to the login page', async () => {
    stubAdminApi(SESSION_PAYLOAD);
    const member = await renderAt(App, '/admin/sponsors');
    expect(member.router.currentRoute.value.path).toBe('/');
    member.unmount();

    stubAdminApi(null);
    const guest = await renderAt(App, '/admin');
    expect(guest.router.currentRoute.value.path).toBe('/login');
    expect(guest.router.currentRoute.value.query.redirect).toBe('/admin');
  });
});
