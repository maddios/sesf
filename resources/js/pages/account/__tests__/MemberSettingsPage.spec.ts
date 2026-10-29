import { describe, it, expect } from 'vitest';
import { screen, waitFor } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import { jsonResponse, stubFetch } from '../../../test/helpers';
import { mountAccount, OWNER_ID } from '../../../components/account/__tests__/harness';
import MemberSettingsPage from '../MemberSettingsPage.vue';

function settingsHandlers() {
  return {
    'GET /member/profile?user_id=7': () => jsonResponse(200, { member_info: { has_image: false, imageURL: '' } }),
    'GET /member/member-info?user_id=7': () =>
      jsonResponse(200, { first: 'Shark', last: 'Fan', email: 'fan@example.test', phone: '' }),
    'GET /member/location': () =>
      jsonResponse(200, { address1: '', address2: '', city: 'Ames', state: 'IA', zip: '' }),
    'GET /member/private-preferences': () =>
      jsonResponse(200, { allow_system_email: false, allow_future_show_notifications: false, allow_private_message: false }),
  };
}

const props = { memberName: 'Fan', memberId: OWNER_ID };
const PATH = '/Fan/u7/settings';

describe('MemberSettingsPage', () => {
  it('shows every section to the owner, including password and delete', async () => {
    stubFetch(settingsHandlers());
    await mountAccount(MemberSettingsPage, { props, path: PATH });
    for (const name of ['Profile image', 'Account information', 'Password', 'Address', 'Email preferences', 'Delete account']) {
      expect(screen.getByRole('button', { name })).toBeTruthy();
    }
    expect(screen.getByRole('button', { name: 'Profile image' }).getAttribute('aria-expanded')).toBe('true');
    expect(screen.getByRole('button', { name: 'Address' }).getAttribute('aria-expanded')).toBe('false');
  });

  it('shows an admin viewing another member a read-only notice instead of forms', async () => {
    const fetchMock = stubFetch({
      'GET /member/profile?user_id=9': () => jsonResponse(200, { member_info: { has_image: false } }),
    });
    await mountAccount(MemberSettingsPage, { props: { memberName: 'Other', memberId: 9 }, admin: true, path: '/Other/u9/settings' });
    expect(await screen.findByText('Admins edit members in the admin area. These settings belong to the member who owns this account.')).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Profile image' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Account information' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Save account information' })).toBeNull();
    expect(screen.queryByLabelText('First name')).toBeNull();
    const urls = fetchMock.mock.calls.map(([url]) => String(url));
    expect(urls.some((url) => url.includes('member-info'))).toBe(false);
    expect(urls.some((url) => url.includes('location') || url.includes('private-preferences'))).toBe(false);
  });

  it('keeps unsaved input when switching sections', async () => {
    const user = userEvent.setup();
    stubFetch(settingsHandlers());
    await mountAccount(MemberSettingsPage, { props, path: PATH });
    await user.click(screen.getByRole('button', { name: 'Address' }));
    const city = (await screen.findByLabelText('City')) as HTMLInputElement;
    await user.clear(city);
    await user.type(city, 'Des Moines');
    await user.click(screen.getByRole('button', { name: 'Account information' }));
    await user.click(screen.getByRole('button', { name: 'Address' }));
    expect((screen.getByLabelText('City') as HTMLInputElement).value).toBe('Des Moines');
  });

  it('has the tabs and the accordion as one navigable set with no axe violations', async () => {
    stubFetch(settingsHandlers());
    const { container } = await mountAccount(MemberSettingsPage, { props, path: PATH });
    await waitFor(() => expect(screen.getByDisplayValue('Shark')).toBeTruthy());
    expect(await axe(container)).toHaveNoViolations();
  });
});
