import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { screen, waitFor, fireEvent } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import { createPinia, setActivePinia } from 'pinia';
import RegisterPage from '../RegisterPage.vue';
import { callsTo, jsonResponse, renderAt, SESSION_PAYLOAD, stubFetch } from '../../../test/helpers';
import { useAuthStore } from '../../../stores/auth';
import type { TurnstileApi, TurnstileRenderOptions } from '../../../components/register/turnstileLoader';

const REGISTER = 'POST /member/register';
const SITE_KEY = '0x4AAAA-test-site';

let options: TurnstileRenderOptions[] = [];
let turnstile: TurnstileApi;

/** Installs a fake Turnstile API, so the loader resolves at once and the widget renders. */
function installTurnstile() {
  options = [];
  turnstile = {
    render: vi.fn((_el: HTMLElement, opts: TurnstileRenderOptions) => {
      options.push(opts);
      return 'widget-1';
    }),
    reset: vi.fn(),
  };
  window.turnstile = turnstile;
}

/** Simulates Cloudflare solving the challenge. */
async function solveChallenge(token = 'token-1') {
  options.at(-1)?.callback?.(token);
  await waitFor(() => expect(screen.queryByText('Please complete the verification.')).toBeNull());
}

const VALID_VALUES = {
  'First name': 'Shark',
  'Last name': 'Fan',
  Email: 'fan@example.test',
  'Confirm email': 'fan@example.test',
  Street: '1 Ocean Way',
  City: 'Charlotte',
  State: 'NC',
  'ZIP code': '28202',
  'Phone number': '1231231234',
  Password: 'secret1',
  'Confirm password': 'secret1',
};

/** Types every field with a valid value (the phone is typed as digits and formatted live). */
async function fillValid(user: ReturnType<typeof userEvent.setup>) {
  for (const [label, value] of Object.entries(VALID_VALUES)) {
    await user.type(screen.getByLabelText(label, { selector: 'input' }), value);
  }
  await user.click(screen.getByLabelText(/I consent to all terms/));
}

async function openPage() {
  stubFetch();
  return renderAt(RegisterPage, '/register');
}

beforeEach(() => {
  setActivePinia(createPinia());
  window.__SESF__ = { turnstileSiteKey: SITE_KEY };
  installTurnstile();
});

afterEach(() => {
  delete window.__SESF__;
  delete window.turnstile;
});

describe('RegisterPage layout and attributes', () => {
  it('renders the heading, sections and labelled inputs with autocomplete hints', async () => {
    await openPage();
    expect(screen.getByRole('heading', { level: 1, name: 'Create SESF Account' })).toBeTruthy();
    for (const section of ['User information', 'Address', 'Password', 'Security check']) {
      expect(screen.getByRole('group', { name: section })).toBeTruthy();
    }
    const expected: Record<string, string> = {
      'First name': 'given-name',
      'Last name': 'family-name',
      Email: 'email',
      Street: 'street-address',
      City: 'address-level2',
      State: 'address-level1',
      'ZIP code': 'postal-code',
      'Phone number': 'tel',
      Password: 'new-password',
      'Confirm password': 'new-password',
    };
    for (const [label, autocomplete] of Object.entries(expected)) {
      expect(screen.getByLabelText(label, { selector: 'input' }).getAttribute('autocomplete')).toBe(autocomplete);
    }
  });

  it('sets the maxlength of each field to the database width', async () => {
    await openPage();
    const widths: Record<string, number> = {
      'First name': 20,
      'Last name': 20,
      Email: 60,
      'Confirm email': 60,
      Street: 60,
      'Street (line 2)': 60,
      City: 60,
      State: 10,
      'ZIP code': 10,
      'Phone number': 12,
    };
    for (const [label, max] of Object.entries(widths)) {
      expect((screen.getByLabelText(label, { selector: 'input' }) as HTMLInputElement).maxLength).toBe(max);
    }
  });

  it('links to the privacy policy from the consent checkbox', async () => {
    await openPage();
    expect(screen.getByRole('link', { name: 'Privacy Policy' }).getAttribute('href')).toBe('/privacy-policy');
    expect(screen.getByLabelText(/I consent to all terms/).getAttribute('aria-required')).toBe('true');
  });

  it('has no detectable accessibility violations', async () => {
    const { container } = await openPage();
    expect(await axe(container)).toHaveNoViolations();
  });

  it('formats the phone number live as ###-###-####', async () => {
    const user = userEvent.setup();
    await openPage();
    const phone = screen.getByLabelText('Phone number', { selector: 'input' }) as HTMLInputElement;
    await user.type(phone, '1234567890');
    expect(phone.value).toBe('123-456-7890');
    await user.clear(phone);
    await user.type(phone, '(555) 12');
    expect(phone.value).toBe('555-12');
  });
});

describe('RegisterPage client validation', () => {
  it('shows every problem, focuses the first invalid field and does not call the server', async () => {
    const user = userEvent.setup();
    const fetchMock = stubFetch();
    await renderAt(RegisterPage, '/register');
    await user.click(screen.getByRole('button', { name: 'Create Account' }));

    expect(screen.getByText('Enter your first name.')).toBeTruthy();
    expect(screen.getByText('Enter your email address.')).toBeTruthy();
    expect(screen.getByText('Enter a password.')).toBeTruthy();
    expect(screen.getByText('Accept the terms and privacy policy to create an account.')).toBeTruthy();
    expect(document.activeElement?.id).toBe('reg-first-name');
    expect(callsTo(fetchMock, REGISTER)).toHaveLength(0);
  });

  it('rejects mismatched email confirmation and short passwords with aria-describedby wiring', async () => {
    const user = userEvent.setup();
    await openPage();
    await user.type(screen.getByLabelText('Email', { selector: 'input' }), 'fan@example.test');
    await user.type(screen.getByLabelText('Confirm email'), 'other@example.test');
    await user.type(screen.getByLabelText('Password', { selector: 'input' }), 'abc');
    await user.type(screen.getByLabelText('Confirm password'), 'abd');
    await user.click(screen.getByRole('button', { name: 'Create Account' }));

    expect(screen.getByText('Email confirmation must match your email address.')).toBeTruthy();
    expect(screen.getByText('Password must be at least 6 characters long.')).toBeTruthy();
    expect(screen.getByText('Password confirmation must match your password.')).toBeTruthy();
    const email = screen.getByLabelText('Confirm email');
    expect(email.getAttribute('aria-invalid')).toBe('true');
    expect(email.getAttribute('aria-describedby')).toContain('reg-email-confirmation-error');
  });

  it('shows a field error once the user leaves an empty field', async () => {
    const user = userEvent.setup();
    await openPage();
    await user.click(screen.getByLabelText('City', { selector: 'input' }));
    await user.tab();
    expect(screen.getByText('Enter your city.')).toBeTruthy();
  });

  it('flags a phone number that is not complete', async () => {
    const user = userEvent.setup();
    await openPage();
    await user.type(screen.getByLabelText('Phone number', { selector: 'input' }), '12345');
    await user.click(screen.getByRole('button', { name: 'Create Account' }));
    expect(screen.getByText('Enter a phone number in the format 123-123-1234.')).toBeTruthy();
  });

  it('rejects text that is not Latin-1 (no emoji)', async () => {
    const user = userEvent.setup();
    await openPage();
    await user.type(screen.getByLabelText('First name'), 'Sha🦈rk');
    await user.click(screen.getByRole('button', { name: 'Create Account' }));
    expect(screen.getByText('Please use only standard letters (no emoji).')).toBeTruthy();
  });

  it('requires the privacy consent checkbox', async () => {
    const user = userEvent.setup();
    const fetchMock = stubFetch({ [REGISTER]: () => jsonResponse(200, SESSION_PAYLOAD) });
    await renderAt(RegisterPage, '/register');
    await fillValid(user);
    await user.click(screen.getByLabelText(/I consent to all terms/));
    await solveChallenge();
    await user.click(screen.getByRole('button', { name: 'Create Account' }));

    expect(screen.getByText('Accept the terms and privacy policy to create an account.')).toBeTruthy();
    expect(callsTo(fetchMock, REGISTER)).toHaveLength(0);
  });

  it('requires the Turnstile token before sending', async () => {
    const user = userEvent.setup();
    const fetchMock = stubFetch({ [REGISTER]: () => jsonResponse(200, SESSION_PAYLOAD) });
    await renderAt(RegisterPage, '/register');
    await fillValid(user);
    await user.click(screen.getByRole('button', { name: 'Create Account' }));
    expect(screen.getByText('Please complete the verification.')).toBeTruthy();
    expect(callsTo(fetchMock, REGISTER)).toHaveLength(0);
  });
});

describe('RegisterPage submit', () => {
  it('sends the trimmed payload with the token, applies the session and goes to the profile', async () => {
    const user = userEvent.setup();
    const fetchMock = stubFetch({ [REGISTER]: () => jsonResponse(200, SESSION_PAYLOAD) });
    const { router, pinia } = await renderAt(RegisterPage, '/register');
    await fillValid(user);
    await solveChallenge('token-xyz');
    await user.type(screen.getByLabelText('Street (line 2)'), '  Suite 4 ');
    await user.click(screen.getByRole('button', { name: 'Create Account' }));

    await waitFor(() => expect(router.currentRoute.value.path).toBe('/fan/u7'));
    expect(callsTo(fetchMock, REGISTER)).toEqual([
      {
        first_name: 'Shark',
        last_name: 'Fan',
        email: 'fan@example.test',
        email_confirmation: 'fan@example.test',
        street: '1 Ocean Way',
        street2: 'Suite 4',
        city: 'Charlotte',
        state: 'NC',
        zip: '28202',
        phone: '123-123-1234',
        password: 'secret1',
        password_confirmation: 'secret1',
        accept_privacy_policy: true,
        cf_turnstile_token: 'token-xyz',
      },
    ]);
    expect(useAuthStore(pinia).isLoggedIn).toBe(true);
    expect(turnstile.reset).not.toHaveBeenCalled();
  });

  it('keeps the button disabled while the request is running', async () => {
    const user = userEvent.setup();
    let finish: (response: Response) => void = () => undefined;
    stubFetch({ [REGISTER]: () => new Promise<Response>((resolve) => (finish = resolve)) });
    await renderAt(RegisterPage, '/register');
    await fillValid(user);
    await solveChallenge();
    await user.click(screen.getByRole('button', { name: 'Create Account' }));

    const busy = await screen.findByRole('button', { name: /Creating account/ });
    expect((busy as HTMLButtonElement).disabled).toBe(true);
    finish(jsonResponse(400, ['Something went wrong.']));
    await waitFor(() => expect((screen.getByRole('button', { name: 'Create Account' }) as HTMLButtonElement).disabled).toBe(false));
  });

  it('maps a duplicate-email 400 to the email field and resets Turnstile after the failure', async () => {
    const user = userEvent.setup();
    stubFetch({ [REGISTER]: () => jsonResponse(400, ['That email address is already registered.']) });
    await renderAt(RegisterPage, '/register');
    await fillValid(user);
    await solveChallenge('token-1');
    await user.click(screen.getByRole('button', { name: 'Create Account' }));

    expect(await screen.findByText('That email address is already registered.')).toBeTruthy();
    const email = screen.getByLabelText('Email', { selector: 'input' });
    expect(email.getAttribute('aria-invalid')).toBe('true');
    expect(turnstile.reset).toHaveBeenCalledWith('widget-1');
    expect((screen.getByRole('button', { name: 'Create Account' }) as HTMLButtonElement).disabled).toBe(false);
  });

  it('shows unplaceable server messages in the banner and the rest next to their fields', async () => {
    const user = userEvent.setup();
    stubFetch({ [REGISTER]: () => jsonResponse(400, ['Something unexpected happened.', 'The zip code is not valid.']) });
    await renderAt(RegisterPage, '/register');
    await fillValid(user);
    await solveChallenge();
    await user.click(screen.getByRole('button', { name: 'Create Account' }));

    expect((await screen.findByRole('alert')).textContent).toContain('Something unexpected happened.');
    expect(screen.getByText('The zip code is not valid.')).toBeTruthy();
    expect(document.activeElement?.id).toBe('reg-form-status');
  });

  it('maps a 422 errors object to the matching fields, and ignores unknown keys', async () => {
    const user = userEvent.setup();
    stubFetch({
      [REGISTER]: () =>
        jsonResponse(422, { message: 'Invalid.', errors: { phone: ['Phone is taken.'], unknown_field: ['Nope.'] } }),
    });
    await renderAt(RegisterPage, '/register');
    await fillValid(user);
    await solveChallenge();
    await user.click(screen.getByRole('button', { name: 'Create Account' }));

    expect(await screen.findByText('Phone is taken.')).toBeTruthy();
    expect(screen.queryByText('Nope.')).toBeNull();
  });

  it('shows the network message when the request cannot be sent', async () => {
    const user = userEvent.setup();
    stubFetch({ [REGISTER]: () => Promise.reject(new TypeError('offline')) });
    await renderAt(RegisterPage, '/register');
    await fillValid(user);
    await solveChallenge();
    await user.click(screen.getByRole('button', { name: 'Create Account' }));
    expect((await screen.findByRole('alert')).textContent).toContain('We could not reach the server');
  });

  it('shows the generic fallback for a body the form cannot read', async () => {
    const user = userEvent.setup();
    stubFetch({ [REGISTER]: () => new Response('<html>oops</html>', { status: 500 }) });
    await renderAt(RegisterPage, '/register');
    await fillValid(user);
    await solveChallenge();
    await user.click(screen.getByRole('button', { name: 'Create Account' }));
    expect((await screen.findByRole('alert')).textContent).toContain('We could not create your account.');
  });

  it('clears a server error for a field as soon as that field changes', async () => {
    const user = userEvent.setup();
    stubFetch({ [REGISTER]: () => jsonResponse(400, ['The phone number is taken.']) });
    await renderAt(RegisterPage, '/register');
    await fillValid(user);
    await solveChallenge();
    await user.click(screen.getByRole('button', { name: 'Create Account' }));
    expect(await screen.findByText('The phone number is taken.')).toBeTruthy();

    // The field is full at its maxlength, so clearing it is the edit that clears the server error.
    await user.clear(screen.getByLabelText('Phone number', { selector: 'input' }));
    await waitFor(() => expect(screen.queryByText('The phone number is taken.')).toBeNull());
  });

  it('resets Turnstile after every failed attempt so the next token is fresh', async () => {
    const user = userEvent.setup();
    stubFetch({ [REGISTER]: () => jsonResponse(400, ['Something went wrong.']) });
    await renderAt(RegisterPage, '/register');
    await fillValid(user);
    await solveChallenge('token-1');
    await user.click(screen.getByRole('button', { name: 'Create Account' }));
    await screen.findByRole('alert');

    expect(turnstile.reset).toHaveBeenCalledTimes(1);
    expect(screen.getByText('Please complete the verification.')).toBeTruthy();
  });
});

describe('RegisterPage without a Turnstile site key', () => {
  it('shows the dev-only notice and disables submit', async () => {
    delete window.__SESF__;
    stubFetch();
    await renderAt(RegisterPage, '/register');
    expect(screen.getByText(/verification service is not configured/)).toBeTruthy();
    expect((screen.getByRole('button', { name: 'Create Account' }) as HTMLButtonElement).disabled).toBe(true);
    expect(turnstile.render).not.toHaveBeenCalled();
  });

  it('ignores a submit event on the form while unconfigured', async () => {
    delete window.__SESF__;
    const fetchMock = stubFetch({ [REGISTER]: () => jsonResponse(200, SESSION_PAYLOAD) });
    const { container } = await renderAt(RegisterPage, '/register');
    fireEvent.submit(container.querySelector('form') as HTMLFormElement);
    expect(callsTo(fetchMock, REGISTER)).toHaveLength(0);
  });
});

describe('RegisterPage request body', () => {
  it('sends the consent flag as a boolean', async () => {
    const user = userEvent.setup();
    const fetchMock = stubFetch({ [REGISTER]: () => jsonResponse(200, SESSION_PAYLOAD) });
    await renderAt(RegisterPage, '/register');
    await fillValid(user);
    await solveChallenge();
    await user.click(screen.getByRole('button', { name: 'Create Account' }));
    await waitFor(() => expect(callsTo(fetchMock, REGISTER)).toHaveLength(1));
    const [body] = callsTo(fetchMock, REGISTER) as Array<{ accept_privacy_policy: unknown }>;
    expect(body?.accept_privacy_policy).toBe(true);
  });
});
