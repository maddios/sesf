import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { screen, waitFor } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import { jsonResponse, stubFetch, callsTo, type RouteHandler } from '../../../test/helpers';
import { invoiceBody, INVOICE_LIST, invoiceWithOwed, mountBilling, setClientConfig } from '../../../test/billing';
import BillingPage from '../BillingPage.vue';

const LIST_KEY = 'GET /member/invoice-list?user_id=7';
const INVOICE_512 = 'GET /member/invoice?registration_id=512';
const INVOICE_511 = 'GET /member/invoice?registration_id=511';

function handlers(extra: Record<string, RouteHandler> = {}): Record<string, RouteHandler> {
  return {
    [LIST_KEY]: () => jsonResponse(200, INVOICE_LIST),
    [INVOICE_512]: () => jsonResponse(200, invoiceBody()),
    [INVOICE_511]: () => jsonResponse(200, invoiceBody({ id: 511, invoice_id: 900, car_show: { id: 2, name: 'Southeast Sharkfest 2025', profileURL: '#/SoutheastSharkfest/e2', regOpen: false } }, { owed: '0.00', total: '1110.00', received: '1110.00' })),
    ...extra,
  };
}

/** The page for member 7 ("/Fan/u7/billing"), optionally at a given registration. */
function mountPage(registrationId?: number, options: { userId?: number | null; admin?: boolean } = {}) {
  const path = registrationId === undefined ? '/Fan/u7/billing' : `/Fan/u7/billing/${registrationId}`;
  return mountBilling(BillingPage, {
    props: { memberName: 'Fan', memberId: 7, registrationId },
    path,
    ...options,
  });
}

beforeEach(() => {
  setClientConfig({ paypalEmail: 'pay@example.test', appUrl: 'https://sesf.example.test' });
  vi.spyOn(window, 'print').mockImplementation(() => undefined);
});

afterEach(() => {
  delete window.__SESF__;
  vi.restoreAllMocks();
  document.title = '';
});

describe('BillingPage: which invoice is shown', () => {
  it('shows the newest registration when the URL has no id', async () => {
    const fetchMock = stubFetch(handlers());
    await mountPage();
    expect(await screen.findByRole('heading', { level: 2, name: 'Southeast Sharkfest 2026' })).toBeTruthy();
    expect(callsTo(fetchMock, INVOICE_512)).toHaveLength(1);
    expect(callsTo(fetchMock, LIST_KEY)).toHaveLength(1);
  });

  it('shows the invoice named in the URL', async () => {
    const fetchMock = stubFetch(handlers());
    await mountPage(512);
    expect(await screen.findByText('Invoice no. 1042')).toBeTruthy();
    expect(callsTo(fetchMock, INVOICE_512)).toHaveLength(1);
  });

  it('shows an invoice from the URL even when it is not in the list', async () => {
    stubFetch(handlers({ 'GET /member/invoice?registration_id=77': () => jsonResponse(200, invoiceBody({ id: 77, car_show: null })) }));
    await mountPage(77);
    expect(await screen.findByRole('heading', { level: 2, name: 'Registration' })).toBeTruthy();
    const select = screen.getByRole('combobox', { name: 'Registration' }) as HTMLSelectElement;
    expect(select.value).toBe('77');
    expect(select.options).toHaveLength(3);
  });

  it('lets the member pick another registration, which navigates to its URL', async () => {
    const user = userEvent.setup();
    stubFetch(handlers());
    const { router } = await mountPage();
    await screen.findByRole('heading', { level: 2, name: 'Southeast Sharkfest 2026' });
    await user.selectOptions(screen.getByRole('combobox', { name: 'Registration' }), '511');
    expect(router.currentRoute.value.path).toBe('/Fan/u7/billing/511');
  });

  it('shows the picker with the list and the current registration selected', async () => {
    stubFetch(handlers());
    await mountPage(512);
    const select = (await screen.findByRole('combobox', { name: 'Registration' })) as HTMLSelectElement;
    expect(select.value).toBe('512');
    expect(Array.from(select.options).map((option) => option.textContent?.trim())).toEqual([
      'Southeast Sharkfest 2026',
      'Southeast Sharkfest 2025',
    ]);
  });

  it('uses the admin view of another member', async () => {
    const fetchMock = stubFetch(handlers({ 'GET /member/invoice-list?user_id=7': () => jsonResponse(200, INVOICE_LIST) }));
    await mountPage(512, { userId: 1, admin: true });
    expect(await screen.findByText('Invoice no. 1042')).toBeTruthy();
    expect(callsTo(fetchMock, LIST_KEY)).toHaveLength(1);
  });
});

describe('BillingPage: states', () => {
  it('shows a loading spinner first', async () => {
    stubFetch(handlers());
    await mountPage();
    expect(screen.getByText('Loading invoices')).toBeTruthy();
    await screen.findByText('Invoice no. 1042');
  });

  it('shows the empty state with a link to events when there are no registrations', async () => {
    stubFetch(handlers({ [LIST_KEY]: () => jsonResponse(200, []) }));
    await mountPage();
    expect(await screen.findByText('You have no registrations yet.')).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Browse events' }).getAttribute('href')).toBe('/events');
    expect(screen.queryByLabelText('Registration')).toBeNull();
  });

  it('shows not found when the invoice is not available to the viewer', async () => {
    stubFetch(handlers({ [INVOICE_512]: () => jsonResponse(404, { message: 'Not found.' }) }));
    await mountPage(512);
    expect(await screen.findByRole('heading', { name: 'Invoice not found' })).toBeTruthy();
    expect(document.title).toBe('Invoice not found | Southeast Sharkfest');
  });

  it('shows an error with a retry that loads the invoice', async () => {
    const user = userEvent.setup();
    let fail = true;
    stubFetch(handlers({ [LIST_KEY]: () => (fail ? jsonResponse(500, {}) : jsonResponse(200, INVOICE_LIST)) }));
    await mountPage();
    expect(await screen.findByText('The invoice could not be loaded.')).toBeTruthy();
    fail = false;
    await user.click(screen.getByRole('button', { name: 'Try again' }));
    expect(await screen.findByText('Invoice no. 1042')).toBeTruthy();
  });

  it('shows an error when the server sends a body that is not an invoice', async () => {
    stubFetch(handlers({ [INVOICE_512]: () => jsonResponse(200, { nope: true }) }));
    await mountPage(512);
    expect(await screen.findByText('The invoice could not be loaded.')).toBeTruthy();
  });

  it('sets the tab title from the event name once the invoice loads', async () => {
    stubFetch(handlers());
    await mountPage(512);
    await screen.findByText('Invoice no. 1042');
    await waitFor(() => expect(document.title).toBe('Billing: Southeast Sharkfest 2026 | Southeast Sharkfest'));
  });
});

describe('BillingPage: payment and links', () => {
  it('offers PayPal for an unpaid balance with the plain amount and the receiver', async () => {
    stubFetch(handlers({ [INVOICE_512]: () => jsonResponse(200, invoiceWithOwed('1000.00')) }));
    const { container } = await mountPage(512);
    await screen.findByText('Invoice no. 1042');
    const form = container.querySelector<HTMLFormElement>('form[action="https://www.paypal.com/cgi-bin/webscr"]');
    expect(form).not.toBeNull();
    const value = (name: string) => (form!.querySelector(`input[name="${name}"]`) as HTMLInputElement).value;
    expect(value('amount')).toBe('1000.00');
    expect(value('business')).toBe('pay@example.test');
    expect(value('item_number')).toBe('512');
    expect(value('notify_url')).toBe('https://sesf.example.test/paypal-ipn');
  });

  it('refreshes the invoice from the PayPal section', async () => {
    const user = userEvent.setup();
    let owed = '1000.00';
    const fetchMock = stubFetch(handlers({ [INVOICE_512]: () => jsonResponse(200, invoiceWithOwed(owed)) }));
    await mountPage(512);
    await screen.findByText('Invoice no. 1042');
    owed = '0.00';
    await user.click(screen.getByRole('button', { name: 'Refresh' }));
    await waitFor(() => expect(callsTo(fetchMock, INVOICE_512)).toHaveLength(2));
    expect(await screen.findByText('Paid')).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Refresh' })).toBeNull();
  });

  it('hides PayPal for a zero balance and for a credit', async () => {
    stubFetch(handlers({ [INVOICE_512]: () => jsonResponse(200, invoiceWithOwed('0.00')) }));
    const zero = await mountPage(512);
    await screen.findByText('Invoice no. 1042');
    expect(zero.container.querySelector('form')).toBeNull();
    zero.unmount();

    stubFetch(handlers({ [INVOICE_512]: () => jsonResponse(200, invoiceWithOwed('-40.00')) }));
    const credit = await mountPage(512);
    expect(await screen.findByText('Credit $40.00')).toBeTruthy();
    expect(credit.container.querySelector('form')).toBeNull();
  });

  it('hides PayPal for a cancelled invoice and shows the cancelled status', async () => {
    stubFetch(handlers({ [INVOICE_512]: () => jsonResponse(200, invoiceWithOwed('1000.00', { is_cancelled: true })) }));
    const { container } = await mountPage(512);
    expect(await screen.findByText('Cancelled')).toBeTruthy();
    expect(container.querySelector('form')).toBeNull();
  });

  it('shows the dev notice when PayPal is not configured', async () => {
    vi.stubEnv('DEV', true);
    setClientConfig({ paypalEmail: '', appUrl: 'https://sesf.example.test' });
    stubFetch(handlers());
    const { container } = await mountPage(512);
    await screen.findByText('Invoice no. 1042');
    expect(screen.getByText(/PayPal not configured/)).toBeTruthy();
    expect(container.querySelector('form')).toBeNull();
    vi.unstubAllEnvs();
  });

  it('links to the registration form only while registration is open', async () => {
    stubFetch(handlers());
    await mountPage(512);
    expect(await screen.findByRole('link', { name: 'Edit registration' })).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Edit registration' }).getAttribute('href')).toBe('/SoutheastSharkfest/e3/register');
  });

  it('has no edit link for a closed event', async () => {
    stubFetch(handlers());
    await mountPage(511);
    expect(await screen.findByText('Invoice no. 900')).toBeTruthy();
    expect(screen.queryByRole('link', { name: 'Edit registration' })).toBeNull();
  });

  it('prints the invoice with the browser print dialog', async () => {
    const user = userEvent.setup();
    stubFetch(handlers());
    await mountPage(512);
    await screen.findByText('Invoice no. 1042');
    await user.click(screen.getByRole('button', { name: 'Print invoice' }));
    expect(window.print).toHaveBeenCalledTimes(1);
  });
});

describe('BillingPage: accessibility', () => {
  it('has no accessibility violations on a loaded invoice', async () => {
    stubFetch(handlers());
    const { container } = await mountPage(512);
    await screen.findByText('Invoice no. 1042');
    expect(await axe(container, { rules: { region: { enabled: false } } })).toHaveNoViolations();
  });

  it('has no accessibility violations on the empty state', async () => {
    stubFetch(handlers({ [LIST_KEY]: () => jsonResponse(200, []) }));
    const { container } = await mountPage();
    await screen.findByText('You have no registrations yet.');
    expect(await axe(container, { rules: { region: { enabled: false } } })).toHaveNoViolations();
  });
});
