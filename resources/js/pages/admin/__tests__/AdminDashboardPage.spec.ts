import { describe, it, expect } from 'vitest';
import { screen, within } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import AdminDashboardPage from '../AdminDashboardPage.vue';
import { renderAt, jsonResponse } from '../../../test/helpers';
import { stubAdminFetch } from '../../../test/admin';

const DATA = {
  event: { id: 9, name: 'Spring Sharkfest', short_name: 'spring', date_start: '2026-05-10', date_end: '2026-05-11', is_active: true, reg_open: true },
  event_counts: { registrations: 42, unpaid: 5, checked_in: 3, cars: 51 },
  totals: { members: 900, events: 12, sponsors_enabled: 7 },
};

describe('AdminDashboardPage', () => {
  it('shows the current event, its counts, totals and shortcuts', async () => {
    stubAdminFetch(({ path }) => (path === '/admin-api/dashboard' ? jsonResponse(200, DATA) : undefined));
    const { container } = await renderAt(AdminDashboardPage, '/admin');
    expect(await screen.findByText('Spring Sharkfest')).toBeTruthy();
    expect(screen.getByText('Registration open')).toBeTruthy();
    const unpaid = screen.getByText('Unpaid').closest('div')!;
    expect(within(unpaid).getByText('5')).toBeTruthy();
    expect(screen.getByText('900')).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Sponsors' }).getAttribute('href')).toBe('/admin/sponsors');
    expect(await axe(container)).toHaveNoViolations();
  });

  it('handles no events and a closed registration', async () => {
    stubAdminFetch(({ path }) => (path === '/admin-api/dashboard' ? jsonResponse(200, { ...DATA, event: null, event_counts: null }) : undefined));
    await renderAt(AdminDashboardPage, '/admin');
    expect(await screen.findByText('No events yet.')).toBeTruthy();
  });

  it('shows an error with a retry', async () => {
    let calls = 0;
    stubAdminFetch(({ path }) => {
      if (path !== '/admin-api/dashboard') return undefined;
      calls += 1;
      return calls === 1 ? jsonResponse(500, { message: 'Server Error' }) : jsonResponse(200, { ...DATA, event: { ...DATA.event, reg_open: false, date_end: DATA.event.date_start } });
    });
    const user = userEvent.setup();
    await renderAt(AdminDashboardPage, '/admin');
    expect((await screen.findByRole('alert')).textContent).toContain('The dashboard could not be loaded.');
    await user.click(screen.getByRole('button', { name: 'Try again' }));
    expect(await screen.findByText('Registration closed')).toBeTruthy();
  });
});
