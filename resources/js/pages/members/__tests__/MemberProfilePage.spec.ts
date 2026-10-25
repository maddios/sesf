import { describe, it, expect, afterEach } from 'vitest';
import { screen, waitFor, within, fireEvent } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import MemberProfilePage from '../MemberProfilePage.vue';
import {
  ADMIN_USER,
  MEMBER_PROFILE_OWNER,
  MEMBER_PROFILE_PUBLIC,
  MEMBER_USER,
  fetchWith,
  jsonResponse,
  mountWithRouter,
} from './fixtures';

const PROFILE_PATH = '/KaiX/u44';
const props = { memberName: 'KaiX', memberId: 44 };

afterEach(() => {
  document.title = '';
});

function profileApi(body: unknown, status = 200, extra?: (url: URL, init: RequestInit) => Response | undefined) {
  return fetchWith((url, init) => {
    const custom = extra?.(url, init);
    if (custom) {
      return custom;
    }
    if (url.pathname === '/member/profile') {
      return jsonResponse(status, body);
    }
    return jsonResponse(404, { message: 'Not found.' });
  });
}

describe('MemberProfilePage public view', () => {
  it('shows the loading state, then name, location, garage, events and a public-only layout', async () => {
    const fn = profileApi(MEMBER_PROFILE_PUBLIC);
    const { container } = await mountWithRouter(MemberProfilePage, { path: PROFILE_PATH, props });

    expect(screen.getByText('Loading member profile')).toBeTruthy();
    expect(await screen.findByRole('heading', { level: 1, name: 'Kai X' })).toBeTruthy();
    expect(screen.getByText('FL')).toBeTruthy();
    expect(document.title).toBe('Kai X | Southeast Sharkfest');
    expect(fn.mock.calls[0]?.[0]).toBe('/member/profile?user_id=44');

    // No private tabs for a visitor.
    expect(screen.queryByRole('navigation', { name: 'Member profile sections' })).toBeNull();
    // Garage shows three thumbnails, no "See all" link for other viewers.
    const garage = screen.getByRole('heading', { name: 'Garage' }).closest('section') as HTMLElement;
    expect(within(garage).getAllByRole('link').filter((a) => a.getAttribute('href')?.startsWith('/car/'))).toHaveLength(3);
    expect(within(garage).queryByRole('link', { name: /See all/ })).toBeNull();
    // Events with the cars brought.
    expect(screen.getByRole('link', { name: 'Southeast Sharkfest 2026' }).getAttribute('href')).toBe('/SoutheastSharkfest2026/e36');
    // The car appears in the garage and under the event: two links to the same profile.
    expect(screen.getAllByRole('link', { name: '1990 850i' }).map((a) => a.getAttribute('href'))).toEqual(['/car/2', '/car/2']);
    expect(container.querySelector('img')?.getAttribute('src')).toBe('/images/generic_avatar_300.gif');
  });

  it('hides the contact button for guests', async () => {
    profileApi(MEMBER_PROFILE_PUBLIC);
    await mountWithRouter(MemberProfilePage, { path: PROFILE_PATH, props });
    await screen.findByRole('heading', { level: 1, name: 'Kai X' });
    expect(screen.queryByRole('button', { name: /Contact/ })).toBeNull();
  });

  it('hides the contact button when the member does not accept private messages', async () => {
    profileApi({ ...MEMBER_PROFILE_PUBLIC, member_preferences: { allow_private_message: 0 } });
    await mountWithRouter(MemberProfilePage, { path: PROFILE_PATH, props, user: MEMBER_USER });
    await screen.findByRole('heading', { level: 1, name: 'Kai X' });
    expect(screen.queryByRole('button', { name: /Contact/ })).toBeNull();
  });

  it('shows the contact button to a signed-in visitor when messages are allowed', async () => {
    profileApi(MEMBER_PROFILE_PUBLIC);
    await mountWithRouter(MemberProfilePage, { path: PROFILE_PATH, props, user: MEMBER_USER });
    expect(await screen.findByRole('button', { name: 'Contact Kai X' })).toBeTruthy();
  });

  it('falls back to the generic avatar when the photo fails', async () => {
    const body = { ...MEMBER_PROFILE_PUBLIC, member_info: { ...MEMBER_PROFILE_PUBLIC.member_info, has_image: true, imageURL: 'images/users/44.jpg' } };
    profileApi(body);
    await mountWithRouter(MemberProfilePage, { path: PROFILE_PATH, props });
    const img = await waitFor(() => {
      const found = document.querySelector('img[src="/images/users/44.jpg"]');
      expect(found).not.toBeNull();
      return found as HTMLImageElement;
    });
    fireEvent.error(img);
    await waitFor(() => expect(document.querySelector('img[src="/images/generic_avatar_300.gif"]')).not.toBeNull());
  });

  it('has no accessibility violations', async () => {
    profileApi(MEMBER_PROFILE_PUBLIC);
    const { container } = await mountWithRouter(MemberProfilePage, { path: PROFILE_PATH, props });
    await screen.findByRole('heading', { level: 1, name: 'Kai X' });
    expect(await axe(container)).toHaveNoViolations();
  });
});

describe('MemberProfilePage owner and admin view', () => {
  it('shows the private tabs and a See all link to the owner', async () => {
    profileApi(MEMBER_PROFILE_OWNER);
    await mountWithRouter(MemberProfilePage, { path: PROFILE_PATH, props, user: MEMBER_USER });

    const tabs = await screen.findByRole('navigation', { name: 'Member profile sections' });
    expect(within(tabs).getByRole('link', { name: 'Profile' }).getAttribute('href')).toBe('/KaiX/u44');
    expect(within(tabs).getByRole('link', { name: 'Garage' }).getAttribute('href')).toBe('/KaiX/u44/cars');
    expect(screen.getByRole('link', { name: 'See all 4 cars' }).getAttribute('href')).toBe('/KaiX/u44/cars');
    // Owners do not contact themselves.
    expect(screen.queryByRole('button', { name: /Contact/ })).toBeNull();
  });

  it('shows the private tabs to an admin who is not the owner', async () => {
    profileApi(MEMBER_PROFILE_PUBLIC);
    await mountWithRouter(MemberProfilePage, { path: PROFILE_PATH, props, user: ADMIN_USER });
    expect(await screen.findByRole('navigation', { name: 'Member profile sections' })).toBeTruthy();
    expect(screen.queryByRole('link', { name: /See all/ })).toBeNull();
  });
});

describe('MemberProfilePage states', () => {
  it('shows a not-found state for an unknown member', async () => {
    profileApi({ message: 'Not found.' }, 404);
    await mountWithRouter(MemberProfilePage, { path: PROFILE_PATH, props });
    expect(await screen.findByRole('heading', { level: 1, name: 'Member not found' })).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Browse all members' }).getAttribute('href')).toBe('/members');
    expect(document.title).toBe('Member not found | Southeast Sharkfest');
  });

  it('shows an error with retry for other failures', async () => {
    const user = userEvent.setup();
    let fail = true;
    profileApi(MEMBER_PROFILE_PUBLIC, 200, () => (fail ? jsonResponse(500, {}) : undefined));
    await mountWithRouter(MemberProfilePage, { path: PROFILE_PATH, props });
    const alert = await screen.findByRole('alert');
    expect(within(alert).getByText('The member profile could not be loaded.')).toBeTruthy();
    fail = false;
    await user.click(within(alert).getByRole('button', { name: 'Try again' }));
    expect(await screen.findByRole('heading', { level: 1, name: 'Kai X' })).toBeTruthy();
  });

  it('reloads when the member id changes on the same route', async () => {
    const fn = profileApi(MEMBER_PROFILE_PUBLIC);
    const { rerender } = await mountWithRouter(MemberProfilePage, { path: PROFILE_PATH, props });
    await screen.findByRole('heading', { level: 1, name: 'Kai X' });
    await rerender({ memberName: 'KaiX', memberId: 45 });
    await waitFor(() => expect(fn.mock.calls.map(([input]) => input)).toContain('/member/profile?user_id=45'));
  });
});

describe('MemberProfilePage contact flow', () => {
  it('sends a private message through the modal and reports success', async () => {
    const user = userEvent.setup();
    let sent: unknown;
    profileApi(MEMBER_PROFILE_PUBLIC, 200, (url, init) => {
      if (url.pathname === '/member/message') {
        sent = JSON.parse(String(init.body));
        return jsonResponse(200, '');
      }
      return undefined;
    });
    await mountWithRouter(MemberProfilePage, { path: PROFILE_PATH, props, user: MEMBER_USER });

    await user.click(await screen.findByRole('button', { name: 'Contact Kai X' }));
    const dialog = await screen.findByRole('dialog', { name: 'Contact Kai X' });
    await user.type(within(dialog).getByLabelText('Subject'), 'Hello');
    await user.type(within(dialog).getByLabelText('Message'), 'Nice car');
    await user.click(within(dialog).getByRole('button', { name: 'Send message' }));

    expect(await within(dialog).findByText('Your message was sent.')).toBeTruthy();
    expect(sent).toEqual({ user_id: 44, subject: 'Hello', message: 'Nice car' });
  });

  it('shows one generic failure message for any send error', async () => {
    const user = userEvent.setup();
    profileApi(MEMBER_PROFILE_PUBLIC, 200, (url) =>
      url.pathname === '/member/message' ? jsonResponse(401, ['Invalid User']) : undefined,
    );
    await mountWithRouter(MemberProfilePage, { path: PROFILE_PATH, props, user: MEMBER_USER });

    await user.click(await screen.findByRole('button', { name: 'Contact Kai X' }));
    const dialog = await screen.findByRole('dialog');
    await user.type(within(dialog).getByLabelText('Subject'), 'Hello');
    await user.type(within(dialog).getByLabelText('Message'), 'Hi there');
    await user.click(within(dialog).getByRole('button', { name: 'Send message' }));

    expect(await within(dialog).findByText('Your message could not be sent. Please try again later.')).toBeTruthy();
  });
});
