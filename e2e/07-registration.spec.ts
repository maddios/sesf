import type { Response } from '@playwright/test';
import { test, expect, visit, login, shot } from './support/test';
import { seed } from './support/seed';

test.describe('event registration', () => {
  test('Mia registers with a car, guests, a shirt, extras, arrival and a donation; billing matches the quote', async ({ page }) => {
    const s = seed();
    const mia = s.users.mia;
    const ev = s.events.current;
    await login(page, mia.email);

    await visit(page, ev.path);
    await expect(page.getByRole('heading', { level: 1, name: ev.name })).toBeVisible();
    await page.getByRole('navigation', { name: 'Event sections' }).getByRole('link', { name: 'Register' }).click();
    await expect(page.getByRole('heading', { level: 1, name: 'Register' })).toBeVisible();
    const form = page.getByRole('form', { name: `Registration for ${ev.name}` });
    const summary = page.getByRole('region', { name: 'Summary' });
    const quotes: Response[] = [];
    page.on('response', (r) => {
      if (r.url().includes('/event/registration/quote')) {
        expect(r.status(), 'quote is only requested for a complete form').toBe(200);
        quotes.push(r);
      }
    });

    // 1. Cars: the allowed car, judged Concours.
    const cars = form.getByRole('group', { name: '1. Cars' });
    await cars.getByRole('checkbox', { name: /Blue Shark/ }).check();
    await cars.getByRole('combobox', { name: 'Judging type' }).selectOption({ label: 'Concours' });

    // 2. Guests: an adult and a child (one car gives no free guest).
    const guests = form.getByRole('group', { name: '2. Guests' });
    await guests.getByRole('button', { name: 'Add guest' }).click();
    const g1 = guests.getByRole('group', { name: 'Guest 1' });
    await g1.getByRole('textbox', { name: 'First name' }).fill('Sam');
    await g1.getByRole('textbox', { name: 'Last name' }).fill("O'Member");
    await g1.getByRole('combobox', { name: 'Guest type' }).selectOption({ label: 'Adult' });
    await guests.getByRole('button', { name: 'Add guest' }).click();
    const g2 = guests.getByRole('group', { name: 'Guest 2' });
    await g2.getByRole('textbox', { name: 'First name' }).fill('Kit');
    await g2.getByRole('textbox', { name: 'Last name' }).fill('Member');
    await g2.getByRole('combobox', { name: 'Guest type' }).selectOption({ label: 'Child (Under 13)' });

    // 3. A shirt.
    const shirts = form.getByRole('group', { name: '3. T-shirts' });
    await shirts.getByRole('button', { name: 'Add shirt' }).click();
    await shirts.getByRole('combobox', { name: 'Size' }).selectOption({ label: 'Mens L T-Shirt' });

    // 4. Extras: the checkbox extra and two of the numeric one.
    const extras = form.getByRole('group', { name: '4. Extras' });
    await extras.getByRole('checkbox', { name: `Add ${s.extras.checkbox.name}` }).check();
    await extras.getByRole('spinbutton', { name: 'Quantity' }).fill('2');

    // 5. Arrival, 6. donation.
    const arrival = form.getByRole('group', { name: '5. Approximate arrival' });
    await arrival.getByRole('combobox', { name: 'Day' }).selectOption({ index: 1 });
    await arrival.getByRole('combobox', { name: 'Time' }).selectOption({ label: 'Morning' });
    await form.getByRole('combobox', { name: 'Donation amount' }).selectOption({ label: '$25' });

    // Expected: car 100 + adult 50 + child 25 + shirt 35 + extras 15 + 2 x 5 + donation 25 = 260.
    const expected = ev.priceCar + ev.priceAdult + ev.priceChild + 35 + s.extras.checkbox.price + 2 * s.extras.numeric.price + 25;
    await expect(summary.getByText('Total', { exact: true }).locator('xpath=following-sibling::dd[1]')).toHaveText(`$${expected.toFixed(2)}`);
    // The live total is the server's quote (never computed in the browser).
    expect(quotes.length, 'quote requests').toBeGreaterThan(0);
    const last = (await quotes[quotes.length - 1].json()) as { payment: { total: string } };
    expect(Number(last.payment.total)).toBe(expected);
    await expect(summary.getByText('Owed', { exact: true }).locator('xpath=following-sibling::dd[1]')).toHaveText(`$${expected.toFixed(2)}`);
    await expect(summary.getByRole('alert')).toHaveCount(0);
    await shot(page, '07-registration-form');

    // 7. Policy acknowledgement and submit; the member lands on the invoice.
    await form.getByRole('checkbox', { name: /I have read and understood/ }).check();
    const [save] = await Promise.all([
      page.waitForResponse((r) => r.url().includes('/event/registration?') && r.request().method() === 'POST'),
      page.getByRole('button', { name: 'Register', exact: true }).click(),
    ]);
    expect(save.status()).toBe(200);
    await expect(page).toHaveURL(new RegExp(`#${mia.profilePath}/billing/\\d+$`));
    const registrationId = Number(page.url().match(/billing\/(\d+)$/)![1]);

    const invoice = page.getByRole('article', { name: ev.name });
    await expect(invoice).toBeVisible();
    await expect(invoice.getByText('Unpaid').or(invoice.getByText(/due/i)).first()).toBeVisible();
    const balance = invoice.getByRole('region', { name: 'Balance' });
    await expect(balance.getByText('Total', { exact: true }).locator('xpath=following-sibling::dd[1]')).toHaveText(`$${expected.toFixed(2)}`);
    await expect(balance.getByText('Balance due', { exact: true }).locator('xpath=following-sibling::dd[1]')).toHaveText(`$${expected.toFixed(2)}`);
    await shot(page, '07-billing');

    // The PayPal Buy Now form posts the owed amount with a '.' decimal to the sandbox receiver.
    const paypal = page.getByRole('region', { name: 'Pay with PayPal' });
    await expect(paypal).toBeVisible();
    const field = (name: string) => paypal.locator(`input[type=hidden][name="${name}"]`);
    await expect(field('amount')).toHaveValue(expected.toFixed(2));
    await expect(field('business')).toHaveValue(s.paypalEmail);
    await expect(field('item_number')).toHaveValue(String(registrationId));
    await expect(field('notify_url')).toHaveValue(`${s.appUrl}/paypal-ipn`);

    // Editing keeps one registration: the form reloads with the saved selections.
    await visit(page, `${ev.path}/register`);
    await expect(page.getByRole('button', { name: 'Update registration' })).toBeVisible();
    await expect(page.getByRole('group', { name: 'Guest 2' }).getByRole('textbox', { name: 'First name' })).toHaveValue('Kit');
  });
});
