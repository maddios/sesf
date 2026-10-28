import type { Page } from '@playwright/test';
import { test, expect, visit, login, shot, expectNoHorizontalScroll } from './support/test';
import { expectNoSeriousA11yViolations } from './support/axe';
import { seed } from './support/seed';

/**
 * Per-event shirts: the admin sets a price for one type and stops offering another (Events > Actions > Shirts…);
 * a member then sees only the offered types at the event price, and the summary and invoice use it. Stateful (it
 * changes the current event's shirt offer and registers Ben): run it on a fresh seed (scripts/e2e.sh reseed).
 */

const PRICED = 'Mens L T-Shirt';
const REMOVED = 'Mens XL T-Shirt';
const PRICE = 20;

async function openShirts(page: Page): Promise<void> {
  const s = seed();
  await visit(page, '/admin/events');
  await page.getByRole('button', { name: `Actions for ${s.events.current.name}` }).filter({ visible: true }).first().click();
  await page.getByRole('menu', { name: `Actions for ${s.events.current.name}` }).getByRole('menuitem', { name: 'Shirts…' }).click();
}

function shirtsDialog(page: Page) {
  return page.getByRole('dialog', { name: `Shirts for ${seed().events.current.name}` });
}

test.describe('per-event shirts', () => {
  test('the admin sets a price and removes a type in the Shirts dialog', async ({ page }) => {
    await login(page, seed().users.admin.email);
    await openShirts(page);
    const dialog = shirtsDialog(page);
    await expect(dialog.getByText('Prices apply to new orders only.')).toBeVisible();
    const price = dialog.getByLabel(`Price for ${PRICED}`, { exact: true });
    await expect(price).toHaveAttribute('placeholder', 'Default $35.00');
    await price.fill(String(PRICE));
    await price.blur();
    await dialog.getByRole('checkbox', { name: REMOVED, exact: true }).uncheck();
    await expectNoSeriousA11yViolations(page, 'admin Shirts dialog');
    await shot(page, '13-admin-shirts-dialog');

    const [saved] = await Promise.all([
      page.waitForResponse((r) => r.url().includes('/shirts') && r.request().method() === 'PUT'),
      dialog.getByRole('button', { name: 'Save shirts' }).click(),
    ]);
    expect(saved.status()).toBe(200);
    await expect(page.getByText('Shirts saved.')).toBeVisible();
    await expect(dialog).toBeHidden();

    // Reopening shows the stored choice, also on a phone: a full-width sheet with 44 px targets and no sideways scroll.
    await page.setViewportSize({ width: 390, height: 844 });
    await openShirts(page);
    const sheet = shirtsDialog(page);
    await expect(sheet.getByLabel(`Price for ${PRICED}`, { exact: true })).toHaveValue('20.00');
    await expect(sheet.getByRole('checkbox', { name: REMOVED, exact: true })).not.toBeChecked();
    expect((await sheet.boundingBox())?.width ?? 0).toBeGreaterThanOrEqual(388);
    const label = sheet.locator('label').filter({ has: page.getByRole('checkbox', { name: PRICED, exact: true }) });
    expect((await label.boundingBox())?.height ?? 0).toBeGreaterThanOrEqual(44);
    expect((await sheet.getByLabel(`Price for ${PRICED}`, { exact: true }).boundingBox())?.height ?? 0).toBeGreaterThanOrEqual(44);
    const name = sheet.getByText(PRICED, { exact: true });
    const lineHeight = await name.evaluate((el) => parseFloat(getComputedStyle(el).lineHeight));
    expect((await name.boundingBox())?.height ?? 0, 'type name on one line').toBeLessThan(lineHeight * 1.6);
    await expectNoHorizontalScroll(page);
    await shot(page, '13-admin-shirts-dialog-phone');
  });

  test('a member sees only the offered shirts at the event price, and the summary and invoice use it', async ({ page }) => {
    const s = seed();
    const ev = s.events.current;
    await login(page, s.users.ben.email);
    await visit(page, `${ev.path}/register`);
    const form = page.getByRole('form', { name: `Registration for ${ev.name}` });
    const summary = page.getByRole('region', { name: 'Summary' });

    const cars = form.getByRole('group', { name: '1. Cars' });
    await cars.getByRole('checkbox').first().check();
    await cars.getByRole('combobox', { name: 'Judging type' }).selectOption({ label: 'Concours' });
    const shirts = form.getByRole('group', { name: '3. T-shirts' });
    await shirts.getByRole('button', { name: 'Add shirt' }).click();
    const size = shirts.getByRole('combobox', { name: 'Size' });
    const options = await size.locator('option').allTextContents();
    expect(options.map((o) => o.trim())).not.toContain(REMOVED);
    expect(options.map((o) => o.trim())).toContain(PRICED);
    await size.selectOption({ label: PRICED });
    await expect(shirts.getByText(`($${PRICE.toFixed(2)})`)).toBeVisible();
    const arrival = form.getByRole('group', { name: /Approximate arrival/ });
    await arrival.getByRole('combobox', { name: 'Day' }).selectOption({ index: 1 });
    await arrival.getByRole('combobox', { name: 'Time' }).selectOption({ label: 'Morning' });

    const expected = ev.priceCar + PRICE;
    await expect(summary.getByText('Total', { exact: true }).locator('xpath=following-sibling::dd[1]')).toHaveText(`$${expected.toFixed(2)}`);
    await expect(summary.getByText(PRICED, { exact: true })).toBeVisible();
    await expect(summary.getByText('1984 633CSi')).toBeVisible();
    await shot(page, '13-member-registration-shirts');

    await form.getByRole('checkbox', { name: /I have read and understood/ }).check();
    await page.getByRole('button', { name: 'Register', exact: true }).click();
    await expect(page).toHaveURL(/\/billing\/\d+$/);
    const invoice = page.getByRole('article', { name: ev.name });
    await expect(invoice.getByRole('rowheader', { name: `Shirt ${PRICED}` })).toBeVisible();
    const balance = invoice.getByRole('region', { name: 'Balance' });
    await expect(balance.getByText('Total', { exact: true }).locator('xpath=following-sibling::dd[1]')).toHaveText(`$${expected.toFixed(2)}`);
    await shot(page, '13-member-billing-shirts');
    await expect(invoice.getByRole('rowheader', { name: 'Car 1984 633CSi' })).toBeVisible();

    // On a phone: the summary and the invoice name the shirt without sideways scroll.
    await page.setViewportSize({ width: 390, height: 844 });
    await expectNoHorizontalScroll(page);
    await shot(page, '13-member-billing-phone');
    await visit(page, `${ev.path}/register`);
    await expect(summary.getByText(PRICED, { exact: true })).toBeVisible();
    await expectNoHorizontalScroll(page);
    await shot(page, '13-member-registration-phone');
  });
});
