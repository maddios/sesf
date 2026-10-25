import { describe, it, expect } from 'vitest';
import { screen, within, waitFor } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import { flushPromises } from '@vue/test-utils';
import MemberDirectory from '../MemberDirectory.vue';
import { GUEST_ROW, MEMBER_ROW, MEMBER_FILTERS, fetchWith, jsonResponse, mountWithRouter, pageBody, requested } from './fixtures';

function memberApi(pages: (url: URL) => unknown = () => pageBody([GUEST_ROW, MEMBER_ROW])) {
  return fetchWith((url) => {
    if (url.pathname === '/members/list-filters') {
      return jsonResponse(200, MEMBER_FILTERS);
    }
    if (url.pathname === '/members/users') {
      return jsonResponse(200, pages(url));
    }
    return jsonResponse(404, { message: 'Not found.' });
  });
}

describe('MemberDirectory (standalone)', () => {
  it('renders a heading, a labelled filter form and guest versus member rows', async () => {
    memberApi();
    await mountWithRouter(MemberDirectory, { path: '/members' });

    expect(await screen.findByRole('heading', { level: 1, name: 'Members' })).toBeTruthy();
    expect(screen.getByRole('search', { name: 'Filter members' })).toBeTruthy();
    expect(screen.getByLabelText('Member name')).toBeTruthy();
    expect(screen.getByLabelText('Attended show')).toBeTruthy();

    // Guest row: first name + last initial, state only, linked to the profile.
    const guest = await screen.findByRole('link', { name: 'Shawn B' });
    expect(guest.getAttribute('href')).toBe('/ShawnB/u12');
    expect(screen.getByText('MO')).toBeTruthy();

    // Member row: full name and "City, ST".
    expect(screen.getByRole('link', { name: 'Kai Xu' }).getAttribute('href')).toBe('/KaiX/u44');
    expect(screen.getByText('Tampa, FL')).toBeTruthy();
    expect(screen.getByText('2 members')).toBeTruthy();
  });

  it('uses the 4-column grid and 20 per page', async () => {
    const fn = memberApi();
    const { container } = await mountWithRouter(MemberDirectory, { path: '/members' });
    await screen.findByRole('link', { name: 'Shawn B' });

    const grid = container.querySelector('ul.grid');
    expect(grid?.className).toContain('lg:grid-cols-4');
    expect(grid?.className).not.toContain('lg:grid-cols-5');
    expect(requested(fn)).toContain('/members/users?limit=20&page=1');
  });

  it('reads show_id, name and page from the query on load', async () => {
    const fn = memberApi();
    await mountWithRouter(MemberDirectory, { path: '/members?show_id=36&name=Shawn&page=2' });
    await screen.findByRole('link', { name: 'Shawn B' });

    expect(requested(fn)).toContain('/members/users?limit=20&page=2&show_id=36&name=Shawn');
    expect((screen.getByLabelText('Attended show') as HTMLSelectElement).value).toBe('36');
    expect((screen.getByLabelText('Member name') as HTMLInputElement).value).toBe('Shawn');
  });

  it('writes a show selection to the query and resets to page 1', async () => {
    const user = userEvent.setup();
    const fn = memberApi();
    const { router } = await mountWithRouter(MemberDirectory, { path: '/members?page=3' });
    await screen.findByRole('link', { name: 'Shawn B' });

    await user.selectOptions(screen.getByLabelText('Attended show'), '35');
    await waitFor(() => expect(router.currentRoute.value.query).toEqual({ show_id: '35' }));
    await waitFor(() => expect(requested(fn)).toContain('/members/users?limit=20&page=1&show_id=35'));
  });

  it('submits the name textbox on Enter and clears it back out of the query', async () => {
    const user = userEvent.setup();
    const fn = memberApi();
    const { router } = await mountWithRouter(MemberDirectory, { path: '/members' });
    await screen.findByRole('link', { name: 'Shawn B' });

    await user.type(screen.getByLabelText('Member name'), '  Kai  {enter}');
    await waitFor(() => expect(router.currentRoute.value.query).toEqual({ name: 'Kai' }));
    await waitFor(() => expect(requested(fn)).toContain('/members/users?limit=20&page=1&name=Kai'));

    await user.clear(screen.getByLabelText('Member name'));
    await user.click(screen.getByRole('button', { name: 'Search' }));
    await waitFor(() => expect(router.currentRoute.value.query).toEqual({}));
  });

  it('pages through results with BasePagination and follows back navigation', async () => {
    const user = userEvent.setup();
    const fn = memberApi((url) => pageBody([GUEST_ROW], { last: 3, page: Number(url.searchParams.get('page')), total: 60 }));
    const { router } = await mountWithRouter(MemberDirectory, { path: '/members' });
    await screen.findByRole('link', { name: 'Shawn B' });

    // The pager unmounts while a page reloads, so it is queried again after each step.
    const pager = () => screen.getByRole('navigation', { name: 'Member list pages' });
    await user.click(within(pager()).getByRole('button', { name: 'Next' }));
    await waitFor(() => expect(router.currentRoute.value.query).toEqual({ page: '2' }));
    await waitFor(() => expect(requested(fn)).toContain('/members/users?limit=20&page=2'));
    await screen.findByRole('link', { name: 'Shawn B' });

    await user.click(within(pager()).getByRole('button', { name: 'Last' }));
    await waitFor(() => expect(router.currentRoute.value.query).toEqual({ page: '3' }));
    await screen.findByRole('link', { name: 'Shawn B' });

    router.back();
    await flushPromises();
    await waitFor(() => expect(router.currentRoute.value.query).toEqual({ page: '2' }));
    await screen.findByRole('link', { name: 'Shawn B' });
    await waitFor(() => expect(within(pager()).getByRole('button', { name: 'Page 2' }).getAttribute('aria-current')).toBe('page'));
  });

  it('shows the empty state when no members match', async () => {
    memberApi(() => pageBody([]));
    await mountWithRouter(MemberDirectory, { path: '/members?name=zzz' });
    expect(await screen.findByText('No members match these filters.')).toBeTruthy();
  });

  it('shows an error with retry, and retry loads the list again', async () => {
    const user = userEvent.setup();
    let fail = true;
    fetchWith((url) => {
      if (url.pathname === '/members/list-filters') {
        return jsonResponse(200, MEMBER_FILTERS);
      }
      if (fail) {
        return jsonResponse(500, { message: 'boom' });
      }
      return jsonResponse(200, pageBody([GUEST_ROW]));
    });
    await mountWithRouter(MemberDirectory, { path: '/members' });

    const alert = await screen.findByRole('alert');
    expect(within(alert).getByText('The member list could not be loaded.')).toBeTruthy();
    fail = false;
    await user.click(within(alert).getByRole('button', { name: 'Try again' }));
    expect(await screen.findByRole('link', { name: 'Shawn B' })).toBeTruthy();
  });

  it('warns when the filter options cannot be loaded but still lists members', async () => {
    fetchWith((url) => {
      if (url.pathname === '/members/list-filters') {
        return jsonResponse(500, {});
      }
      return jsonResponse(200, pageBody([GUEST_ROW]));
    });
    await mountWithRouter(MemberDirectory, { path: '/members' });
    expect(await screen.findByText('The show filter could not be loaded.')).toBeTruthy();
    expect(await screen.findByRole('link', { name: 'Shawn B' })).toBeTruthy();
  });

  it('has no accessibility violations', async () => {
    memberApi();
    const { container } = await mountWithRouter(MemberDirectory, { path: '/members' });
    await screen.findByRole('link', { name: 'Shawn B' });
    expect(await axe(container)).toHaveNoViolations();
  });
});

describe('MemberDirectory (embedded in an event tab)', () => {
  it('has no page heading, filters by the fixed show, uses 5 columns and 25 per page', async () => {
    const fn = memberApi();
    const { container, router } = await mountWithRouter(MemberDirectory, {
      path: '/SoutheastSharkfest2026/e36',
      props: { showId: 36, embedded: true },
    });
    await screen.findByRole('link', { name: 'Shawn B' });

    expect(screen.queryByRole('heading', { level: 1 })).toBeNull();
    expect(screen.queryByLabelText('Attended show')).toBeNull();
    expect(container.querySelector('ul.grid')?.className).toContain('lg:grid-cols-5');
    expect(requested(fn)).toContain('/members/users?limit=25&page=1&show_id=36');
    expect(container.querySelector('section')?.className).toContain('space-y-4');
    expect(router.currentRoute.value.path).toBe('/SoutheastSharkfest2026/e36');
  });

  it('keeps filters and pages in local state so the event URL is not changed', async () => {
    const user = userEvent.setup();
    const fn = memberApi((url) => pageBody([GUEST_ROW], { last: 2, page: Number(url.searchParams.get('page')), total: 30 }));
    const { router } = await mountWithRouter(MemberDirectory, {
      path: '/SoutheastSharkfest2026/e36',
      props: { showId: 36, embedded: true },
    });
    await screen.findByRole('link', { name: 'Shawn B' });

    await user.type(screen.getByLabelText('Member name'), 'Shawn{enter}');
    await waitFor(() => expect(requested(fn)).toContain('/members/users?limit=25&page=1&show_id=36&name=Shawn'));
    const pager = screen.getByRole('navigation', { name: 'Member list pages' });
    await user.click(within(pager).getByRole('button', { name: 'Next' }));
    await waitFor(() => expect(requested(fn)).toContain('/members/users?limit=25&page=2&show_id=36&name=Shawn'));

    expect(router.currentRoute.value.query).toEqual({});
  });

  it('ignores a show_id in the URL when a fixed show is given', async () => {
    const fn = memberApi();
    await mountWithRouter(MemberDirectory, {
      path: '/x?show_id=35',
      props: { showId: 36, embedded: true },
    });
    await screen.findByRole('link', { name: 'Shawn B' });
    expect(requested(fn).some((path) => path.includes('show_id=35'))).toBe(false);
  });

  it('renders an empty embedded directory without a heading', async () => {
    memberApi(() => pageBody([]));
    await mountWithRouter(MemberDirectory, { path: '/x', props: { showId: 36, embedded: true } });
    expect(await screen.findByText('No members match these filters.')).toBeTruthy();
    expect(screen.queryByRole('heading', { level: 1 })).toBeNull();
  });
});
