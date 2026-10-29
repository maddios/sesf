import { describe, it, expect, beforeEach } from 'vitest';
import { screen, waitFor, fireEvent } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import LoginPage from '../LoginPage.vue';
import { callsTo, jsonResponse, renderAt, SESSION_PAYLOAD, stubFetch } from '../../test/helpers';
import { setActivePinia, createPinia } from 'pinia';

const LOGIN = 'POST /login/login';
const REQUEST = 'POST /login/email-signin/request';
const VERIFY = 'POST /login/email-signin/verify-code';

beforeEach(() => {
  setActivePinia(createPinia());
});

describe('LoginPage', () => {
  it('renders the password form with accessible labels and autocomplete hints', async () => {
    stubFetch();
    await renderAt(LoginPage, '/login');
    expect(screen.getByRole('heading', { level: 1, name: 'Sign in' })).toBeTruthy();
    expect(screen.getByLabelText('Email').getAttribute('autocomplete')).toBe('username');
    expect(screen.getByLabelText('Password', { selector: 'input' }).getAttribute('autocomplete')).toBe('current-password');
    expect(screen.getByLabelText('Remember me on this device')).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Forgot password?' }).getAttribute('href')).toBe('/forgot-password');
    expect(screen.getByRole('link', { name: 'Create an account' }).getAttribute('href')).toBe('#/register');
    expect(screen.getByRole('tab', { name: 'Email me a code' })).toBeTruthy();
  });

  it('has no detectable accessibility violations on the password tab', async () => {
    stubFetch();
    const { container } = await renderAt(LoginPage, '/login');
    expect(await axe(container)).toHaveNoViolations();
  });

  it('shows the legacy notice for an expired sign-in link', async () => {
    stubFetch();
    await renderAt(LoginPage, '/login?email_signin=invalid');
    expect(screen.getByRole('status').textContent).toContain('no longer valid');
  });

  it('validates locally and does not call the server', async () => {
    const fetchMock = stubFetch();
    const user = userEvent.setup();
    await renderAt(LoginPage, '/login');
    await user.type(screen.getByLabelText('Email'), 'not-an-email');
    await user.click(screen.getByRole('button', { name: 'Sign in' }));
    expect(screen.getByText('Enter a valid email address.')).toBeTruthy();
    expect(screen.getByText('Enter your password.')).toBeTruthy();
    expect(callsTo(fetchMock, LOGIN)).toHaveLength(0);
    expect(screen.getByLabelText('Email').getAttribute('aria-describedby')).toContain('login-email-error');
  });

  it('signs in and follows the ?redirect= path', async () => {
    const fetchMock = stubFetch({ [LOGIN]: () => jsonResponse(200, SESSION_PAYLOAD) });
    const user = userEvent.setup();
    const { router } = await renderAt(LoginPage, '/login?redirect=%2Fsome-page');
    await user.type(screen.getByLabelText('Email'), ' fan@example.test ');
    await user.type(screen.getByLabelText('Password', { selector: 'input' }), 'correct-horse');
    await user.click(screen.getByLabelText('Remember me on this device'));
    await user.click(screen.getByRole('button', { name: 'Sign in' }));

    await waitFor(() => expect(router.currentRoute.value.path).toBe('/some-page'));
    expect(callsTo(fetchMock, LOGIN)).toEqual([{ email: 'fan@example.test', password: 'correct-horse', remember: true }]);
  });

  it('shows the server message on bad credentials and clears the password', async () => {
    stubFetch({ [LOGIN]: () => jsonResponse(401, ['These credentials do not match our records.']) });
    const user = userEvent.setup();
    await renderAt(LoginPage, '/login');
    await user.type(screen.getByLabelText('Email'), 'fan@example.test');
    await user.type(screen.getByLabelText('Password', { selector: 'input' }), 'wrong');
    await user.click(screen.getByRole('button', { name: 'Sign in' }));

    expect((await screen.findByRole('alert')).textContent).toContain('do not match our records');
    expect((screen.getByLabelText('Password', { selector: 'input' }) as HTMLInputElement).value).toBe('');
  });

  it('shows server validation errors next to their field', async () => {
    stubFetch({ [LOGIN]: () => jsonResponse(422, { message: 'Invalid.', errors: { email: ['This account is locked.'] } }) });
    const user = userEvent.setup();
    await renderAt(LoginPage, '/login');
    await user.type(screen.getByLabelText('Email'), 'fan@example.test');
    await user.type(screen.getByLabelText('Password', { selector: 'input' }), 'pw');
    await user.click(screen.getByRole('button', { name: 'Sign in' }));

    expect(await screen.findByText('This account is locked.')).toBeTruthy();
    expect(screen.getByLabelText('Email').getAttribute('aria-invalid')).toBe('true');
  });

  it('disables the button while the sign-in request is running', async () => {
    let finish: (response: Response) => void = () => undefined;
    stubFetch({ [LOGIN]: () => new Promise<Response>((resolve) => (finish = resolve)) });
    const user = userEvent.setup();
    await renderAt(LoginPage, '/login');
    await user.type(screen.getByLabelText('Email'), 'fan@example.test');
    await user.type(screen.getByLabelText('Password', { selector: 'input' }), 'pw');
    await user.click(screen.getByRole('button', { name: 'Sign in' }));
    expect((await screen.findByRole('button', { name: /Signing in/ })) as HTMLButtonElement).toBeTruthy();
    expect((screen.getByRole('button', { name: /Signing in/ }) as HTMLButtonElement).disabled).toBe(true);
    finish(jsonResponse(401, ['late']));
  });

  describe('emailed code tab', () => {
    async function openCodeTab(fetchMock: ReturnType<typeof stubFetch>) {
      const user = userEvent.setup();
      const rendered = await renderAt(LoginPage, '/login');
      await user.click(screen.getByRole('tab', { name: 'Email me a code' }));
      expect(fetchMock).toBeTruthy();
      return { user, ...rendered };
    }

    it('requests a code and then verifies it once six digits are entered', async () => {
      const fetchMock = stubFetch({
        [REQUEST]: () => jsonResponse(200, { ok: true }),
        [VERIFY]: () => jsonResponse(200, SESSION_PAYLOAD),
      });
      const { user, router } = await openCodeTab(fetchMock);
      await user.type(screen.getByLabelText('Email'), 'fan@example.test');
      await user.click(screen.getByRole('button', { name: 'Email me a sign-in code' }));

      const codeInput = (await screen.findByLabelText('Your code')) as HTMLInputElement;
      expect(codeInput.getAttribute('autocomplete')).toBe('one-time-code');
      expect(codeInput.getAttribute('inputmode')).toBe('numeric');
      expect(screen.getByText('Check your email. We sent you a code.')).toBeTruthy();
      expect(callsTo(fetchMock, REQUEST)).toEqual([{ email: 'fan@example.test' }]);

      await user.type(codeInput, '12345');
      expect(callsTo(fetchMock, VERIFY)).toHaveLength(0);
      await user.type(codeInput, '6');
      await waitFor(() => expect(callsTo(fetchMock, VERIFY)).toHaveLength(1));
      expect(callsTo(fetchMock, VERIFY)).toEqual([{ email: 'fan@example.test', code: '123456' }]);
      await waitFor(() => expect(router.currentRoute.value.path).toBe('/'));
    });

    it('keeps only digits and never submits a code with fewer than six', async () => {
      const fetchMock = stubFetch({ [REQUEST]: () => jsonResponse(200, { ok: true }), [VERIFY]: () => jsonResponse(200, SESSION_PAYLOAD) });
      const { user } = await openCodeTab(fetchMock);
      await user.type(screen.getByLabelText('Email'), 'fan@example.test');
      await user.click(screen.getByRole('button', { name: 'Email me a sign-in code' }));
      const codeInput = (await screen.findByLabelText('Your code')) as HTMLInputElement;
      await user.type(codeInput, 'a1-2');
      expect(codeInput.value).toBe('12');
      await user.click(screen.getByRole('button', { name: 'Continue' }));
      expect(await screen.findByText('Enter the 6-digit code from the email.')).toBeTruthy();
      expect(callsTo(fetchMock, VERIFY)).toHaveLength(0);
    });

    it('sends the ?redirect= path with the request and follows the server redirect_path after verifying', async () => {
      const fetchMock = stubFetch({
        [REQUEST]: () => jsonResponse(200, { ok: true }),
        [VERIFY]: () => jsonResponse(200, { ...SESSION_PAYLOAD, redirect_path: '/some-page' }),
      });
      const user = userEvent.setup();
      const { router } = await renderAt(LoginPage, '/login?redirect=%2Fignored');
      await user.click(screen.getByRole('tab', { name: 'Email me a code' }));
      await user.type(screen.getByLabelText('Email'), 'fan@example.test');
      await user.click(screen.getByRole('button', { name: 'Email me a sign-in code' }));
      expect(callsTo(fetchMock, REQUEST)).toEqual([{ email: 'fan@example.test', redirect_path: '/ignored' }]);
      await user.type(await screen.findByLabelText('Your code'), '654321');
      await waitFor(() => expect(router.currentRoute.value.path).toBe('/some-page'));
    });

    it('explains an invalid or expired code and clears the field', async () => {
      const fetchMock = stubFetch({
        [REQUEST]: () => jsonResponse(200, { ok: true }),
        [VERIFY]: () => jsonResponse(401, ['That code is not valid.']),
      });
      const { user } = await openCodeTab(fetchMock);
      await user.type(screen.getByLabelText('Email'), 'fan@example.test');
      await user.click(screen.getByRole('button', { name: 'Email me a sign-in code' }));
      const codeInput = (await screen.findByLabelText('Your code')) as HTMLInputElement;
      await user.type(codeInput, '000000');
      expect((await screen.findByRole('alert')).textContent).toContain('That code is not valid.');
      await waitFor(() => expect(codeInput.value).toBe(''));
    });

    it('lets the user go back and use a different email', async () => {
      const fetchMock = stubFetch({ [REQUEST]: () => jsonResponse(200, { ok: true }) });
      const { user } = await openCodeTab(fetchMock);
      await user.type(screen.getByLabelText('Email'), 'fan@example.test');
      await user.click(screen.getByRole('button', { name: 'Email me a sign-in code' }));
      await user.click(await screen.findByRole('button', { name: 'Use a different email' }));
      expect(screen.getByRole('button', { name: 'Email me a sign-in code' })).toBeTruthy();
    });

    it('checks the email format before requesting a code', async () => {
      const fetchMock = stubFetch();
      const { user } = await openCodeTab(fetchMock);
      await user.type(screen.getByLabelText('Email'), 'nope');
      await user.click(screen.getByRole('button', { name: 'Email me a sign-in code' }));
      expect(await screen.findByText('Enter a valid email address.')).toBeTruthy();
      expect(callsTo(fetchMock, REQUEST)).toHaveLength(0);
    });

    it('hides the code tab and explains when emailed sign-in is unavailable (503)', async () => {
      const fetchMock = stubFetch({
        [REQUEST]: () => jsonResponse(503, { ok: false, message: 'Emailed sign-in is off today.' }),
      });
      const { user } = await openCodeTab(fetchMock);
      await user.type(screen.getByLabelText('Email'), 'fan@example.test');
      await user.click(screen.getByRole('button', { name: 'Email me a sign-in code' }));

      expect((await screen.findByRole('alert')).textContent).toContain('Emailed sign-in is off today.');
      expect(screen.queryByRole('tab', { name: 'Email me a code' })).toBeNull();
      expect(screen.getByLabelText('Password', { selector: 'input' })).toBeTruthy();
    });

    it('reports a failed code request in the code tab', async () => {
      const fetchMock = stubFetch({ [REQUEST]: () => jsonResponse(500, 'oops') });
      const { user } = await openCodeTab(fetchMock);
      await user.type(screen.getByLabelText('Email'), 'fan@example.test');
      await user.click(screen.getByRole('button', { name: 'Email me a sign-in code' }));
      expect((await screen.findByRole('alert')).textContent).toContain('We could not send a code.');
    });

    it('has no detectable accessibility violations on the code tab', async () => {
      const fetchMock = stubFetch({ [REQUEST]: () => jsonResponse(200, { ok: true }) });
      const user = userEvent.setup();
      const { container } = await renderAt(LoginPage, '/login');
      await user.click(screen.getByRole('tab', { name: 'Email me a code' }));
      expect(await axe(container)).toHaveNoViolations();
      await user.type(screen.getByLabelText('Email'), 'fan@example.test');
      await user.click(screen.getByRole('button', { name: 'Email me a sign-in code' }));
      await screen.findByLabelText('Your code');
      expect(await axe(container)).toHaveNoViolations();
      expect(fetchMock).toBeTruthy();
    });
  });

  it('ignores a stray Enter on the code field before six digits exist', async () => {
    const fetchMock = stubFetch({ [REQUEST]: () => jsonResponse(200, { ok: true }) });
    const user = userEvent.setup();
    await renderAt(LoginPage, '/login');
    await user.click(screen.getByRole('tab', { name: 'Email me a code' }));
    await user.type(screen.getByLabelText('Email'), 'fan@example.test');
    await user.click(screen.getByRole('button', { name: 'Email me a sign-in code' }));
    const codeInput = await screen.findByLabelText('Your code');
    await fireEvent.keyDown(codeInput, { key: 'Enter' });
    expect(callsTo(fetchMock, VERIFY)).toHaveLength(0);
  });
});
