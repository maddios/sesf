import { describe, it, expect, beforeEach } from 'vitest';
import { screen } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import { createPinia, setActivePinia } from 'pinia';
import ForgotPasswordPage from '../ForgotPasswordPage.vue';
import { callsTo, jsonResponse, renderAt, stubFetch } from '../../test/helpers';

const FORGOT = 'POST /login/forgot-password';
const GENERIC = 'If an account exists for that email';

beforeEach(() => {
  setActivePinia(createPinia());
});

describe('ForgotPasswordPage', () => {
  it('renders the request form', async () => {
    stubFetch();
    await renderAt(ForgotPasswordPage, '/forgot-password');
    expect(screen.getByRole('heading', { level: 1, name: 'Password request' })).toBeTruthy();
    expect(screen.getByLabelText('Email').getAttribute('autocomplete')).toBe('email');
  });

  it('has no detectable accessibility violations', async () => {
    stubFetch();
    const { container } = await renderAt(ForgotPasswordPage, '/forgot-password');
    expect(await axe(container)).toHaveNoViolations();
  });

  it('rejects a malformed email without calling the server', async () => {
    const fetchMock = stubFetch();
    const user = userEvent.setup();
    await renderAt(ForgotPasswordPage, '/forgot-password');
    await user.type(screen.getByLabelText('Email'), 'bad');
    await user.click(screen.getByRole('button', { name: 'Request password' }));
    expect(screen.getByText('Enter a valid email address.')).toBeTruthy();
    expect(callsTo(fetchMock, FORGOT)).toHaveLength(0);
  });

  it('shows the same generic success message after a request', async () => {
    const fetchMock = stubFetch({ [FORGOT]: () => jsonResponse(200, { ok: true }) });
    const user = userEvent.setup();
    const { container } = await renderAt(ForgotPasswordPage, '/forgot-password');
    await user.type(screen.getByLabelText('Email'), ' fan@example.test ');
    await user.click(screen.getByRole('button', { name: 'Request password' }));

    expect((await screen.findByRole('status')).textContent).toContain(GENERIC);
    expect(callsTo(fetchMock, FORGOT)).toEqual([{ email: 'fan@example.test' }]);
    expect(screen.getByRole('link', { name: 'Back to sign in' }).getAttribute('href')).toBe('/login');
    expect(await axe(container)).toHaveNoViolations();
  });

  it('shows the generic message even when the server reports no such account', async () => {
    stubFetch({ [FORGOT]: () => jsonResponse(200, { ok: true }) });
    const user = userEvent.setup();
    await renderAt(ForgotPasswordPage, '/forgot-password');
    await user.type(screen.getByLabelText('Email'), 'nobody@example.test');
    await user.click(screen.getByRole('button', { name: 'Request password' }));
    expect(await screen.findByText(new RegExp(GENERIC))).toBeTruthy();
  });

  it('shows a validation error from a 422 next to the email field', async () => {
    stubFetch({ [FORGOT]: () => jsonResponse(422, { message: 'Invalid.', errors: { email: ['Use a real address.'] } }) });
    const user = userEvent.setup();
    await renderAt(ForgotPasswordPage, '/forgot-password');
    await user.type(screen.getByLabelText('Email'), 'fan@example.test');
    await user.click(screen.getByRole('button', { name: 'Request password' }));
    expect(await screen.findByText('Use a real address.')).toBeTruthy();
    expect(screen.queryByRole('status')).toBeNull();
  });

  it('reports a connection failure', async () => {
    stubFetch({ [FORGOT]: () => Promise.reject(new TypeError('Failed to fetch')) });
    const user = userEvent.setup();
    await renderAt(ForgotPasswordPage, '/forgot-password');
    await user.type(screen.getByLabelText('Email'), 'fan@example.test');
    await user.click(screen.getByRole('button', { name: 'Request password' }));
    expect((await screen.findByRole('alert')).textContent).toContain('We could not reach the server.');
  });
});
