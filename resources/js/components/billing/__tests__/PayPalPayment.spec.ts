import { describe, it, expect, vi, afterEach } from 'vitest';
import { render, screen } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import PayPalPayment from '../PayPalPayment.vue';

const PAYPAL_URL = 'https://www.paypal.com/cgi-bin/webscr';

function props(overrides: Record<string, unknown> = {}) {
  return {
    registrationId: 512,
    owed: '1000.00',
    cancelled: false,
    email: 'pay@example.test',
    appUrl: 'https://sesf.example.test',
    ...overrides,
  };
}

function fieldValues(container: Element): Record<string, string> {
  const form = container.querySelector<HTMLFormElement>(`form[action="${PAYPAL_URL}"]`);
  expect(form).not.toBeNull();
  const values: Record<string, string> = {};
  for (const input of Array.from(form!.querySelectorAll<HTMLInputElement>('input[type="hidden"]'))) {
    values[input.name] = input.value;
  }
  return values;
}

afterEach(() => {
  vi.unstubAllEnvs();
});

describe('PayPalPayment', () => {
  it('posts the xclick fields with a plain amount and notify_url on this app', () => {
    const { container } = render(PayPalPayment, { props: props({ owed: '1000.00' }) });
    const form = container.querySelector<HTMLFormElement>('form')!;
    expect(form.getAttribute('method')).toBe('post');
    expect(form.getAttribute('target')).toBe('_blank');
    expect(form.getAttribute('rel')).toBe('noopener');
    const values = fieldValues(container);
    expect(values).toMatchObject({
      cmd: '_xclick',
      business: 'pay@example.test',
      item_name: 'Registration fee',
      item_number: '512',
      amount: '1000.00',
      currency_code: 'USD',
      notify_url: 'https://sesf.example.test/paypal-ipn',
      no_shipping: '1',
    });
    expect(values.return).toBe(window.location.href);
  });

  it('keeps a value of 1000 or more free of separators', () => {
    const { container } = render(PayPalPayment, { props: props({ owed: '12345.6' }) });
    expect(fieldValues(container).amount).toBe('12345.60');
  });

  it('tells the member that confirmation arrives by email and offers Refresh', async () => {
    const user = userEvent.setup();
    const { emitted } = render(PayPalPayment, { props: props() });
    expect(screen.getByText(/confirmation arrives by email/)).toBeTruthy();
    await user.click(screen.getByRole('button', { name: 'Refresh' }));
    expect(emitted('refresh')).toHaveLength(1);
  });

  it('renders nothing for a cancelled invoice', () => {
    const { container } = render(PayPalPayment, { props: props({ cancelled: true }) });
    expect(container.querySelector('form')).toBeNull();
    expect(container.textContent?.trim()).toBe('');
  });

  it('renders nothing when the balance is zero or a credit', () => {
    const zero = render(PayPalPayment, { props: props({ owed: '0.00' }) });
    expect(zero.container.querySelector('form')).toBeNull();
    zero.unmount();
    const credit = render(PayPalPayment, { props: props({ owed: '-2.00' }) });
    expect(credit.container.querySelector('form')).toBeNull();
  });

  it('shows the dev notice instead of the form when the receiver is not configured', () => {
    vi.stubEnv('DEV', true);
    const { container } = render(PayPalPayment, { props: props({ email: '' }) });
    expect(container.querySelector('form')).toBeNull();
    expect(screen.getByText(/PayPal not configured/)).toBeTruthy();
  });

  it('hides the form and the notice when the app URL is missing in production', () => {
    vi.stubEnv('DEV', false);
    const { container } = render(PayPalPayment, { props: props({ appUrl: '' }) });
    expect(container.querySelector('form')).toBeNull();
    expect(screen.queryByText(/PayPal not configured/)).toBeNull();
  });

  it('has no accessibility violations', async () => {
    const { container } = render(PayPalPayment, { props: props() });
    expect(await axe(container, { rules: { region: { enabled: false } } })).toHaveNoViolations();
  });
});
