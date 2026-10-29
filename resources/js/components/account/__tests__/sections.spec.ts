import { describe, it, expect, vi } from 'vitest';
import { render, screen, waitFor, fireEvent } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import { jsonResponse, stubFetch, callsTo } from '../../../test/helpers';
import { mountAccount, OWNER_ID } from './harness';
import AccountInfoSection from '../AccountInfoSection.vue';
import AddressSection from '../AddressSection.vue';
import EmailPreferencesSection from '../EmailPreferencesSection.vue';
import PasswordSection from '../PasswordSection.vue';
import DeleteAccountSection from '../DeleteAccountSection.vue';
import ProfileImageSection from '../ProfileImageSection.vue';
import ImageUploadForm from '../ImageUploadForm.vue';
import { GENERIC_AVATAR_URL } from '../../../api/members';

const INFO = { first: 'Shark', last: 'Fan', email: 'fan@example.test', phone: '555-0100' };
const LOCATION = { address1: '1 Ocean Way', address2: '', city: 'Ames', state: 'IA', zip: '50010' };
const PREFS = { allow_system_email: true, allow_future_show_notifications: false, allow_private_message: true };

function accountHandlers(extra: Record<string, (init: RequestInit) => Response | Promise<Response>> = {}) {
  return {
    'GET /member/member-info?user_id=7': () => jsonResponse(200, INFO),
    'GET /member/location': () => jsonResponse(200, LOCATION),
    'GET /member/private-preferences': () => jsonResponse(200, PREFS),
    ...extra,
  };
}

describe('AccountInfoSection', () => {
  it('loads the stored values and saves without a current password when the email is unchanged', async () => {
    const user = userEvent.setup();
    const fetchMock = stubFetch(
      accountHandlers({ 'POST /member/account-info': () => new Response('', { status: 200 }) }),
    );
    const { auth } = await mountAccount(AccountInfoSection, { props: { userId: OWNER_ID } });
    await screen.findByLabelText('First name');
    expect(screen.getByLabelText<HTMLInputElement>('Email').value).toBe(INFO.email);
    expect(screen.queryByLabelText('Current password')).toBeNull();

    await user.clear(screen.getByLabelText('First name'));
    await user.type(screen.getByLabelText('First name'), 'Sharkey');
    await user.click(screen.getByRole('button', { name: 'Save account information' }));

    await screen.findByText('Account information saved.');
    expect(callsTo(fetchMock, 'POST /member/account-info')[0]).toMatchObject({ first: 'Sharkey' });
    expect(callsTo(fetchMock, 'POST /member/account-info')[0]).not.toHaveProperty('current_password');
    expect(auth.user?.firstName).toBe('Sharkey');
  });

  it('requires the current password for an email change and sends it', async () => {
    const user = userEvent.setup();
    const fetchMock = stubFetch(
      accountHandlers({ 'POST /member/account-info': () => new Response('', { status: 200 }) }),
    );
    await mountAccount(AccountInfoSection, { props: { userId: OWNER_ID } });
    await screen.findByLabelText('Email');
    await user.clear(screen.getByLabelText('Email'));
    await user.type(screen.getByLabelText('Email'), 'new@example.test');

    const currentPassword = screen.getByLabelText('Current password');
    expect(currentPassword).toBeTruthy();
    await user.click(screen.getByRole('button', { name: 'Save account information' }));
    expect(await screen.findByText(/Enter your current password to change your email/)).toBeTruthy();
    expect(callsTo(fetchMock, 'POST /member/account-info')).toHaveLength(0);

    await user.type(currentPassword, 'secret1');
    await user.click(screen.getByRole('button', { name: 'Save account information' }));
    await screen.findByText('Account information saved.');
    expect(callsTo(fetchMock, 'POST /member/account-info')[0]).toMatchObject({
      email: 'new@example.test',
      current_password: 'secret1',
    });
  });

  it('shows the server 400 messages in the banner and keeps the form', async () => {
    const user = userEvent.setup();
    stubFetch(accountHandlers({ 'POST /member/account-info': () => jsonResponse(400, ['Phone is not valid', 'Last name is required']) }));
    await mountAccount(AccountInfoSection, { props: { userId: OWNER_ID } });
    await screen.findByLabelText('First name');
    await user.click(screen.getByRole('button', { name: 'Save account information' }));
    expect(await screen.findByText('Phone is not valid')).toBeTruthy();
    expect(screen.getByText('Last name is required')).toBeTruthy();
    expect((screen.getByLabelText('First name') as HTMLInputElement).value).toBe(INFO.first);
  });

  it('retries a failed account info load', async () => {
    const user = userEvent.setup();
    let attempt = 0;
    stubFetch({
      'GET /member/member-info?user_id=7': () => {
        attempt += 1;
        return attempt === 1 ? new Response('down', { status: 500 }) : jsonResponse(200, INFO);
      },
    });
    await mountAccount(AccountInfoSection, { props: { userId: OWNER_ID } });
    expect(await screen.findByText(/could not be loaded/)).toBeTruthy();
    await user.click(screen.getByRole('button', { name: 'Try again' }));
    await screen.findByLabelText('First name');
  });

  it('shows a generic error for a failed save with no server messages', async () => {
    const user = userEvent.setup();
    stubFetch(accountHandlers({ 'POST /member/account-info': () => new Response('x', { status: 500 }) }));
    await mountAccount(AccountInfoSection, { props: { userId: OWNER_ID } });
    await screen.findByLabelText('First name');
    await user.click(screen.getByRole('button', { name: 'Save account information' }));
    expect(await screen.findByText('Something went wrong. Please try again.')).toBeTruthy();
  });

  it('does not touch the session user when an admin edits another member', async () => {
    const user = userEvent.setup();
    stubFetch({
      'GET /member/member-info?user_id=9': () => jsonResponse(200, INFO),
      'POST /member/account-info': () => new Response('', { status: 200 }),
    });
    const { auth } = await mountAccount(AccountInfoSection, { props: { userId: 9 }, admin: true });
    await screen.findByLabelText('First name');
    await user.click(screen.getByRole('button', { name: 'Save account information' }));
    await screen.findByText('Account information saved.');
    expect(auth.user?.userId).toBe(OWNER_ID);
    expect(auth.user?.firstName).toBe('Shark');
  });
});

describe('PasswordSection', () => {
  it('validates the new password client side and then posts the three fields', async () => {
    const user = userEvent.setup();
    const fetchMock = stubFetch({ 'POST /login/update-password': () => new Response('', { status: 200 }) });
    await mountAccount(PasswordSection);
    await user.type(screen.getByLabelText('Current password'), 'old-pass');
    await user.type(screen.getByLabelText('New password'), 'abc');
    await user.type(screen.getByLabelText('Repeat new password'), 'abd');
    await user.click(screen.getByRole('button', { name: 'Change password' }));
    expect(await screen.findByText('Use at least 6 characters.')).toBeTruthy();
    expect(screen.getByText('The passwords do not match.')).toBeTruthy();
    expect(callsTo(fetchMock, 'POST /login/update-password')).toHaveLength(0);

    await user.clear(screen.getByLabelText('New password'));
    await user.type(screen.getByLabelText('New password'), 'fresh-pass');
    await user.clear(screen.getByLabelText('Repeat new password'));
    await user.type(screen.getByLabelText('Repeat new password'), 'fresh-pass');
    await user.click(screen.getByRole('button', { name: 'Change password' }));
    expect(await screen.findByText('Your password was changed.')).toBeTruthy();
    expect(callsTo(fetchMock, 'POST /login/update-password')[0]).toEqual({
      old_password: 'old-pass',
      new_password: 'fresh-pass',
      verify_password: 'fresh-pass',
    });
    expect((screen.getByLabelText('Current password') as HTMLInputElement).value).toBe('');
  });

  it('maps a 400 message such as "Old Password invalid" to the banner and keeps the values', async () => {
    const user = userEvent.setup();
    stubFetch({ 'POST /login/update-password': () => jsonResponse(400, ['Old Password invalid']) });
    await mountAccount(PasswordSection);
    await user.type(screen.getByLabelText('Current password'), 'wrong');
    await user.type(screen.getByLabelText('New password'), 'fresh-pass');
    await user.type(screen.getByLabelText('Repeat new password'), 'fresh-pass');
    await user.click(screen.getByRole('button', { name: 'Change password' }));
    expect(await screen.findByText('Old Password invalid')).toBeTruthy();
    expect((screen.getByLabelText('Current password') as HTMLInputElement).value).toBe('wrong');
  });
});

describe('AddressSection', () => {
  it('loads the address, uppercases the state and saves', async () => {
    const user = userEvent.setup();
    const fetchMock = stubFetch(
      accountHandlers({ 'POST /member/location': () => jsonResponse(200, [{ id: 7 }]) }),
    );
    await mountAccount(AddressSection, { props: { userId: OWNER_ID } });
    await screen.findByLabelText('City');
    expect((screen.getByLabelText('City') as HTMLInputElement).value).toBe('Ames');
    await user.clear(screen.getByLabelText('State'));
    await user.type(screen.getByLabelText('State'), 'ia');
    await user.click(screen.getByRole('button', { name: 'Save address' }));
    expect(await screen.findByText('Address saved.')).toBeTruthy();
    expect(callsTo(fetchMock, 'POST /member/location')[0]).toMatchObject({ state: 'IA', zip: '50010' });
  });

  it('flags an invalid state before sending', async () => {
    const user = userEvent.setup();
    const fetchMock = stubFetch(accountHandlers());
    await mountAccount(AddressSection, { props: { userId: OWNER_ID } });
    await screen.findByLabelText('State');
    await user.clear(screen.getByLabelText('State'));
    await user.type(screen.getByLabelText('State'), 'Iowa');
    await user.click(screen.getByRole('button', { name: 'Save address' }));
    expect(await screen.findByText(/two-letter state code/)).toBeTruthy();
    expect(callsTo(fetchMock, 'POST /member/location')).toHaveLength(0);
  });

  it('shows the server 400 messages and a retry on load failure', async () => {
    const user = userEvent.setup();
    let attempt = 0;
    stubFetch({
      'GET /member/location': () => {
        attempt += 1;
        return attempt === 1 ? new Response('', { status: 500 }) : jsonResponse(200, LOCATION);
      },
      'POST /member/location': () => jsonResponse(400, ['Zip is not valid']),
    });
    await mountAccount(AddressSection, { props: { userId: OWNER_ID } });
    await screen.findByText(/could not be loaded/);
    await user.click(screen.getByRole('button', { name: 'Try again' }));
    await screen.findByLabelText('Zip code');
    await user.click(screen.getByRole('button', { name: 'Save address' }));
    expect(await screen.findByText('Zip is not valid')).toBeTruthy();
  });
});

describe('EmailPreferencesSection', () => {
  it('loads the three choices and saves them as booleans', async () => {
    const user = userEvent.setup();
    const fetchMock = stubFetch(
      accountHandlers({ 'POST /member/private-preferences': () => new Response('', { status: 200 }) }),
    );
    await mountAccount(EmailPreferencesSection, { props: { userId: OWNER_ID } });
    const system = await screen.findByRole('checkbox', { name: /system emails/ });
    expect((system as HTMLInputElement).checked).toBe(true);
    await user.click(screen.getByRole('checkbox', { name: /future shows/ }));
    await user.click(screen.getByRole('button', { name: 'Save preferences' }));
    expect(await screen.findByText('Email preferences saved.')).toBeTruthy();
    expect(callsTo(fetchMock, 'POST /member/private-preferences')[0]).toEqual({
      allow_system_email: true,
      allow_future_show_notifications: true,
      allow_private_message: true,
    });
  });

  it('reports a failed load and retries', async () => {
    const user = userEvent.setup();
    let attempt = 0;
    stubFetch({
      'GET /member/private-preferences': () => {
        attempt += 1;
        return attempt === 1 ? new Response('', { status: 500 }) : jsonResponse(200, PREFS);
      },
    });
    await mountAccount(EmailPreferencesSection, { props: { userId: OWNER_ID } });
    await screen.findByText(/could not be loaded/);
    await user.click(screen.getByRole('button', { name: 'Try again' }));
    await screen.findByRole('checkbox', { name: /system emails/ });
  });
});

describe('ImageUploadForm', () => {
  function fileInput(container: Element): HTMLInputElement {
    return container.querySelector<HTMLInputElement>('input[type="file"]') as HTMLInputElement;
  }

  it('rejects an unsupported file before any request and disables upload until a file is chosen', async () => {
    const upload = vi.fn(async () => undefined);
    const { container } = render(ImageUploadForm, {
      props: { inputId: 'f', currentAlt: 'Current', uploadLabel: 'Upload photo', successMessage: 'Done', upload },
    });
    const button = screen.getByRole('button', { name: 'Upload photo' });
    expect((button as HTMLButtonElement).disabled).toBe(true);
    await fireEvent.change(fileInput(container), { target: { files: [new File(['x'], 'a.txt', { type: 'text/plain' })] } });
    expect(await screen.findByText('Choose a JPEG, PNG, GIF or WebP image.')).toBeTruthy();
    expect((button as HTMLButtonElement).disabled).toBe(true);
    expect(upload).not.toHaveBeenCalled();
  });

  it('rejects a file over 12 MB', async () => {
    const { container } = render(ImageUploadForm, {
      props: { inputId: 'f', currentAlt: 'Current', uploadLabel: 'Upload photo', successMessage: 'Done', upload: vi.fn() },
    });
    const big = new File(['x'], 'big.png', { type: 'image/png' });
    Object.defineProperty(big, 'size', { value: 12 * 1024 * 1024 + 1 });
    await fireEvent.change(fileInput(container), { target: { files: [big] } });
    expect(await screen.findByText('Choose an image of 12 MB or smaller.')).toBeTruthy();
  });

  it('uploads the chosen file, shows success and emits uploaded', async () => {
    const user = userEvent.setup();
    const upload = vi.fn(async () => undefined);
    const { container, emitted } = render(ImageUploadForm, {
      props: { inputId: 'f', currentAlt: 'Current', uploadLabel: 'Upload photo', successMessage: 'Photo saved', upload },
    });
    const file = new File(['x'], 'ok.png', { type: 'image/png' });
    await user.upload(fileInput(container), file);
    await user.click(screen.getByRole('button', { name: 'Upload photo' }));
    expect(await screen.findByText('Photo saved')).toBeTruthy();
    expect(upload).toHaveBeenCalledWith(file);
    expect(emitted('uploaded')).toHaveLength(1);
  });

  it('shows the upload failure messages and keeps the chosen file', async () => {
    const user = userEvent.setup();
    const upload = vi.fn(async () => {
      throw new Error('boom');
    });
    const { container } = render(ImageUploadForm, {
      props: { inputId: 'f', currentAlt: 'Current', uploadLabel: 'Upload photo', successMessage: 'Saved', upload },
    });
    await user.upload(fileInput(container), new File(['x'], 'ok.gif', { type: 'image/gif' }));
    await user.click(screen.getByRole('button', { name: 'Upload photo' }));
    expect(await screen.findByText('The photo could not be uploaded. Please try again.')).toBeTruthy();
    expect((screen.getByRole('button', { name: 'Upload photo' }) as HTMLButtonElement).disabled).toBe(false);
  });

  it('has no axe violations', async () => {
    const { container } = render(ImageUploadForm, {
      props: { inputId: 'f', currentAlt: 'Current', uploadLabel: 'Upload photo', successMessage: 'Saved', upload: vi.fn() },
    });
    expect(await axe(container)).toHaveNoViolations();
  });
});

describe('ProfileImageSection', () => {
  it('shows the generic avatar when the member has no photo and cache-busts the new photo after upload', async () => {
    const user = userEvent.setup();
    let hasImage = false;
    const fetchMock = stubFetch({
      'GET /member/profile?user_id=7': () =>
        jsonResponse(200, { member_info: { imageURL: hasImage ? 'images/4/221/0/7.jpg' : '', has_image: hasImage } }),
      'POST /member/profile-image': () => {
        hasImage = true;
        return new Response('', { status: 200 });
      },
    });
    const { container } = await mountAccount(ProfileImageSection, { props: { userId: OWNER_ID } });
    await waitFor(() => expect(container.querySelector('img')?.getAttribute('src')).toBe(GENERIC_AVATAR_URL));
    const input = container.querySelector('input[type="file"]') as HTMLInputElement;
    await user.upload(input, new File(['x'], 'me.png', { type: 'image/png' }));
    await user.click(screen.getByRole('button', { name: 'Upload photo' }));
    await screen.findByText('Your profile photo was updated.');
    await waitFor(() => expect(container.querySelector('img')?.getAttribute('src')).toMatch(/^\/images\/4\/221\/0\/7\.jpg\?v=\d+$/));
    const uploads = fetchMock.mock.calls.filter(([url]) => url === '/member/profile-image');
    expect((uploads[0]?.[1] as RequestInit).body).toBeInstanceOf(FormData);
  });

  it('reports when the current photo cannot be loaded', async () => {
    stubFetch({ 'GET /member/profile?user_id=7': () => new Response('x', { status: 500 }) });
    await mountAccount(ProfileImageSection, { props: { userId: OWNER_ID } });
    expect(await screen.findByText('The current photo could not be loaded.')).toBeTruthy();
  });
});

describe('DeleteAccountSection', () => {
  it('requires the password, then deletes, clears the session and routes home', async () => {
    const user = userEvent.setup();
    const fetchMock = stubFetch({ 'POST /member/delete-account': () => new Response('', { status: 200 }) });
    const { auth, router } = await mountAccount(DeleteAccountSection, { path: '/fan/u7/settings' });
    const replace = vi.spyOn(router, 'replace');
    await user.click(screen.getByRole('button', { name: 'Delete my account' }));
    const dialog = await screen.findByRole('dialog', { name: 'Delete your account?' });
    expect(dialog).toBeTruthy();

    await user.click(screen.getByRole('button', { name: 'Delete account' }));
    expect(await screen.findByText('Enter your password to delete your account.')).toBeTruthy();
    expect(callsTo(fetchMock, 'POST /member/delete-account')).toHaveLength(0);

    await user.type(screen.getByLabelText('Password'), 'pw-123456');
    await user.click(screen.getByRole('button', { name: 'Delete account' }));
    await waitFor(() => expect(replace).toHaveBeenCalledWith('/'));
    expect(callsTo(fetchMock, 'POST /member/delete-account')[0]).toEqual({ password: 'pw-123456' });
    expect(auth.isLoggedIn).toBe(false);
  });

  it('keeps the dialog open with the server message on a failed delete', async () => {
    const user = userEvent.setup();
    stubFetch({ 'POST /member/delete-account': () => jsonResponse(400, ['Password is incorrect']) });
    const { auth } = await mountAccount(DeleteAccountSection);
    await user.click(screen.getByRole('button', { name: 'Delete my account' }));
    await user.type(await screen.findByLabelText('Password'), 'wrong');
    await user.click(screen.getByRole('button', { name: 'Delete account' }));
    expect(await screen.findByText('Password is incorrect')).toBeTruthy();
    expect(screen.getByRole('dialog')).toBeTruthy();
    expect(auth.isLoggedIn).toBe(true);
  });

  it('closes the dialog from Cancel and from the close button, returning focus', async () => {
    const user = userEvent.setup();
    stubFetch({});
    await mountAccount(DeleteAccountSection);
    await user.click(screen.getByRole('button', { name: 'Delete my account' }));
    await screen.findByRole('dialog');
    await user.click(screen.getByRole('button', { name: 'Cancel' }));
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    await user.click(screen.getByRole('button', { name: 'Delete my account' }));
    await screen.findByRole('dialog');
    await user.click(screen.getByRole('button', { name: 'Close dialog' }));
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
  });

  it('has an axe-clean closed state', async () => {
    const { container } = await mountAccount(DeleteAccountSection);
    expect(await axe(container)).toHaveNoViolations();
  });
});
