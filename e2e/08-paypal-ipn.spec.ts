import { request, type APIRequestContext, type Page } from '@playwright/test';
import { test, expect, visit, login, shot, APP_URL } from './support/test';
import { seed, type SeedUser } from './support/seed';
import { mailIds, messagesTo, waitForMail } from './support/mailpit';
import { formEncode, ipnFields, stubLast, stubMode, stubReset, uniqueTxn } from './support/paypal';

const ADMIN_INBOX = 'registration@sesf.test';

async function postIpn(api: APIRequestContext, body: string): Promise<number> {
  const res = await api.post('/paypal-ipn', {
    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'User-Agent': 'PayPal IPN ( https://www.paypal.com/ipn )' },
    data: body,
  });
  return res.status();
}

async function balanceDue(page: Page, user: SeedUser, registrationId: number): Promise<{ due: string; paid: boolean }> {
  await visit(page, `${user.profilePath}/billing/${registrationId}`);
  const invoice = page.getByRole('article').first();
  const due = invoice.getByRole('region', { name: 'Balance' }).getByText('Balance due', { exact: true }).locator('xpath=following-sibling::dd[1]');
  await expect(due).toBeVisible();
  const paid = (await invoice.getByText('Paid', { exact: true }).count()) > 0;
  return { due: (await due.textContent())?.trim() ?? '', paid };
}

test.describe.serial('PayPal IPN', () => {
  let api: APIRequestContext;

  test.beforeAll(async () => {
    api = await request.newContext({ baseURL: APP_URL });
  });

  test.afterAll(async () => {
    await stubReset();
    await api.dispose();
  });

  test.beforeEach(async () => {
    await stubReset();
  });

  test('a verified IPN for the full amount marks the invoice paid and mails the customer and the admin', async ({ page }) => {
    const s = seed();
    const payer = s.users.payer;
    const reg = s.registrations.payer;
    const customerBefore = await mailIds(payer.email);
    const adminBefore = await mailIds(ADMIN_INBOX);

    const fields = ipnFields({ registrationId: reg.id, gross: reg.owed, txnId: uniqueTxn('E2EPAID'), receiver: s.paypalEmail, payerEmail: payer.email });
    const body = formEncode(fields);
    expect(await postIpn(api, body)).toBe(200);

    // The app posted the IPN back to "PayPal" byte for byte, prefixed with cmd=_notify-validate.
    const last = await stubLast();
    expect(last.count).toBe(1);
    expect(last.last?.body).toBe(`cmd=_notify-validate&${body}`);

    const customer = await waitForMail(payer.email, /./, customerBefore);
    const admin = await waitForMail(ADMIN_INBOX, /./, adminBefore);
    expect(customer.Subject).toMatch(/payment/i);
    expect(customer.Text).toContain(`Hi ${payer.first} ${payer.last}`);
    expect(customer.Text).toMatch(/Paid/);
    expect(admin.Subject.length).toBeGreaterThan(0);

    await login(page, payer.email);
    const state = await balanceDue(page, payer, reg.id);
    expect(state).toEqual({ due: '$0.00', paid: true });
    await expect(page.getByRole('region', { name: 'Pay with PayPal' })).toHaveCount(0);
    await shot(page, '08-paid');

    // Replaying the same IPN (PayPal re-sends until it sees a 200) changes nothing and sends no mail.
    const customerAfter = await mailIds(payer.email);
    const adminAfter = await mailIds(ADMIN_INBOX);
    expect(await postIpn(api, body)).toBe(200);
    await page.waitForTimeout(2_000);
    expect(await mailIds(payer.email)).toEqual(customerAfter);
    expect(await mailIds(ADMIN_INBOX)).toEqual(adminAfter);
    expect(await balanceDue(page, payer, reg.id)).toEqual({ due: '$0.00', paid: true });
  });

  test('a partial payment leaves the invoice unpaid with the right balance', async ({ page }) => {
    const s = seed();
    const user = s.users.partial;
    const reg = s.registrations.partial;
    const owed = Number(reg.owed);
    const before = await mailIds(user.email);
    const body = formEncode(ipnFields({ registrationId: reg.id, gross: '40.00', txnId: uniqueTxn('E2EPART'), receiver: s.paypalEmail, payerEmail: user.email }));
    expect(await postIpn(api, body)).toBe(200);
    await waitForMail(user.email, /./, before);

    await login(page, user.email);
    expect(await balanceDue(page, user, reg.id)).toEqual({ due: `$${(owed - 40).toFixed(2)}`, paid: false });
    // The PayPal form now asks for the remaining balance.
    await expect(page.getByRole('region', { name: 'Pay with PayPal' }).locator('input[name="amount"]')).toHaveValue((owed - 40).toFixed(2));
  });

  test('an IPN that PayPal answers INVALID credits nothing; a failed postback asks PayPal to retry (503)', async ({ page }) => {
    const s = seed();
    const user = s.users.invalid;
    const reg = s.registrations.invalid;

    await stubMode('invalid');
    const invalid = formEncode(ipnFields({ registrationId: reg.id, gross: reg.owed, txnId: uniqueTxn('E2EINV'), receiver: s.paypalEmail, payerEmail: user.email }));
    expect(await postIpn(api, invalid)).toBe(200);
    expect((await stubLast()).count).toBe(1);

    await stubMode('down');
    const down = formEncode(ipnFields({ registrationId: reg.id, gross: reg.owed, txnId: uniqueTxn('E2EDOWN'), receiver: s.paypalEmail, payerEmail: user.email }));
    expect(await postIpn(api, down)).toBe(503);

    // A wrong receiver is rejected even when PayPal verifies the message.
    await stubMode('verified');
    const wrongReceiver = formEncode(ipnFields({ registrationId: reg.id, gross: reg.owed, txnId: uniqueTxn('E2EMIS'), receiver: 'someone-else@example.test', payerEmail: user.email }));
    expect(await postIpn(api, wrongReceiver)).toBe(200);

    expect((await messagesTo(user.email)).length, 'no customer mail for rejected IPNs').toBe(0);
    await login(page, user.email);
    expect(await balanceDue(page, user, reg.id)).toEqual({ due: `$${reg.owed}`, paid: false });
  });
});
