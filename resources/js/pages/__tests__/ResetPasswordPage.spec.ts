import { describe, it, expect, beforeEach } from 'vitest';
import { screen, waitFor } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import { createPinia, setActivePinia } from 'pinia';
import ResetPasswordPage from '../ResetPasswordPage.vue';
import { callsTo, jsonResponse, renderAt, SESSION_PAYLOAD, stubFetch } from '../../test/helpers';

const RESET = 'POST /login/reset-password';
const QUERY = '/reset-password?token=tok123&email=fan%40example.test';

beforeEach(() => {
  setActivePinia(createPinia());
});

describe('ResetPasswordPage', () => {
  it('prefills the email from the emailed link and uses password autocomplete', async () => {
    stubFetch();
    await renderAt(ResetPasswordPage, QUERY);
    expect(screen.getByRole('heading', { level: 1, name: 'Password reset' })).toBeTruthy();
    expect((screen.getByLabelText('Email') as HTMLInputElement).value).toBe('fan@example.test');
    expect(screen.getByLabelText('New password').getAttribute('autocomplete')).toBe('new-password');
    expect(screen.getByLabelText('Confirm new password').getAttribute('autocomplete')).toBe('new-password');
  });

  it('has no detectable accessibility violations', async () => {
    stubFetch();
    const { container } = await renderAt(ResetPasswordPage, QUERY);
    expect(await axe(container)).toHaveNoViolations();
  });

  it('shows an incomplete-link message instead of the form when the token is missing', async () => {
    stubFetch();
    const { container } = await renderAt(ResetPasswordPage, '/reset-password?email=fan%40example.test');
    expect(screen.queryByRole('button', { name: 'Reset password' })).toBeNull();
    expect(screen.getByRole('alert').textContent).toContain('This reset link is incomplete.');
    expect(screen.getByRole('link', { name: 'Request a new reset email' }).getAttribute('href')).toBe('/forgot-password');
    expect(await axe(container)).toHaveNoViolations();
  });

  it('checks length and confirmation locally without calling the server', async () => {
    const fetchMock = stubFetch();
    const user = userEvent.setup();
    await renderAt(ResetPasswordPage, QUERY);
    await user.type(screen.getByLabelText('New password'), 'short');
    await user.type(screen.getByLabelText('Confirm new password'), 'different');
    await user.click(screen.getByRole('button', { name: 'Reset password' }));
    expect(screen.getByText('Password must be at least 6 characters long.')).toBeTruthy();
    expect(screen.getByText('The passwords do not match.')).toBeTruthy();
    expect(callsTo(fetchMock, RESET)).toHaveLength(0);
  });

  it('resets the password, logs the user in and goes home', async () => {
    const fetchMock = stubFetch({ [RESET]: () => jsonResponse(200, SESSION_PAYLOAD) });
    const user = userEvent.setup();
    const { router, pinia } = await renderAt(ResetPasswordPage, QUERY);
    await user.type(screen.getByLabelText('New password'), 'new-secret');
    await user.type(screen.getByLabelText('Confirm new password'), 'new-secret');
    await user.click(screen.getByRole('button', { name: 'Reset password' }));

    await waitFor(() => expect(router.currentRoute.value.path).toBe('/'));
    expect(callsTo(fetchMock, RESET)).toEqual([
      { token: 'tok123', email: 'fan@example.test', password: 'new-secret', password_confirmation: 'new-secret' },
    ]);
    const { useAuthStore } = await import('../../stores/auth');
    expect(useAuthStore(pinia).isLoggedIn).toBe(true);
  });

  it('shows a server error for the token next to the banner', async () => {
    stubFetch({ [RESET]: () => jsonResponse(422, { message: 'Invalid.', errors: { token: ['This link has expired.'] } }) });
    const user = userEvent.setup();
    await renderAt(ResetPasswordPage, QUERY);
    await user.type(screen.getByLabelText('New password'), 'new-secret');
    await user.type(screen.getByLabelText('Confirm new password'), 'new-secret');
    await user.click(screen.getByRole('button', { name: 'Reset password' }));
    expect((await screen.findByRole('alert')).textContent).toContain('This link has expired.');
    expect((screen.getByLabelText('New password') as HTMLInputElement).value).toBe('');
  });

  it('shows the password error from the server next to its field', async () => {
    stubFetch({ [RESET]: () => jsonResponse(422, { message: 'Invalid.', errors: { password: ['Too common.'] } }) });
    const user = userEvent.setup();
    await renderAt(ResetPasswordPage, QUERY);
    await user.type(screen.getByLabelText('New password'), 'password');
    await user.type(screen.getByLabelText('Confirm new password'), 'password');
    await user.click(screen.getByRole('button', { name: 'Reset password' }));
    expect(await screen.findByText('Too common.')).toBeTruthy();
  });
});
