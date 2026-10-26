import { request } from '@playwright/test';
import { test, expect, visit, shot, APP_URL } from './support/test';
import { mailIds, waitForMail, links } from './support/mailpit';

const TURNSTILE_JS = 'https://challenges.cloudflare.com/turnstile/v0/api.js';
const ADMIN_INBOX = 'registration@sesf.test';

async function cloudflareReachable(): Promise<boolean> {
  const ctx = await request.newContext();
  try {
    const res = await ctx.get(TURNSTILE_JS, { timeout: 8_000, maxRedirects: 5 });
    return res.ok();
  } catch {
    return false;
  } finally {
    await ctx.dispose();
  }
}

test.describe('create an account', () => {
  test('registers with the Turnstile test widget, lands signed in, and the welcome mails arrive', async ({ page }) => {
    test.skip(
      !(await cloudflareReachable()),
      `Turnstile needs outbound HTTPS to challenges.cloudflare.com (${TURNSTILE_JS} unreachable from the runner); skipping the registration spec.`,
    );
    const stamp = Date.now().toString(36);
    const email = `new-${stamp}@sesf.test`;
    const first = 'Nora';
    // An apostrophe (O'Shea) is refused by the server: known issue KI-1 in docs/e2e.md (see the fixme test below).
    const last = 'Shea-Lee';
    const adminBefore = await mailIds(ADMIN_INBOX);

    await visit(page, '/register');
    await expect(page.getByRole('heading', { level: 1, name: 'Create SESF Account' })).toBeVisible();
    const info = page.getByRole('group', { name: 'User information' });
    await info.getByRole('textbox', { name: 'First name' }).fill(first);
    await info.getByRole('textbox', { name: 'Last name' }).fill(last);
    await info.getByRole('textbox', { name: 'Email', exact: true }).fill(email);
    await info.getByRole('textbox', { name: 'Confirm email' }).fill(email);
    const address = page.getByRole('group', { name: 'Address' });
    await address.getByRole('textbox', { name: 'Street', exact: true }).fill('1 Sandbox Way');
    await address.getByRole('textbox', { name: 'City' }).fill('Orlando');
    await address.getByRole('textbox', { name: 'State' }).fill('FL');
    await address.getByRole('textbox', { name: 'ZIP code' }).fill('32803');
    await address.getByRole('textbox', { name: 'Phone number' }).fill('555-123-4567');
    const pw = page.getByRole('group', { name: 'Password' });
    await pw.getByRole('textbox', { name: 'Password', exact: true }).fill('Password-123');
    await pw.getByRole('textbox', { name: 'Confirm password' }).fill('Password-123');
    await page.getByRole('checkbox', { name: /I consent to all terms/ }).check();

    // Cloudflare's always-pass test key solves itself; wait until the widget has produced its token.
    // (The widget's iframe lives in a closed shadow root; its response input is in the light DOM.)
    await expect(page.getByRole('group', { name: 'Security check' }).locator('input[name="cf-turnstile-response"]')).toHaveValue(/.+/, { timeout: 20_000 });
    await shot(page, '02-register-form');

    const [response] = await Promise.all([
      page.waitForResponse((r) => r.url().endsWith('/member/register') && r.request().method() === 'POST'),
      page.getByRole('button', { name: 'Create Account' }).click(),
    ]);
    expect(response.status(), await response.text()).toBe(200);
    await expect(page.getByRole('button', { name: `Welcome ${first}` })).toBeVisible();
    await expect(page).toHaveURL(/#\/NoraS\/u\d+$/);
    await shot(page, '02-registered');

    // Welcome mail to the member, and the new-account notice to the registration inbox.
    const welcome = await waitForMail(email, /Registration Confirmation/);
    expect(welcome.To.map((t) => t.Address)).toEqual([email]);
    expect(welcome.Text).toContain(email);
    const appLinks = links(welcome);
    expect(appLinks.length).toBeGreaterThan(0);
    for (const link of appLinks) {
      expect(link.startsWith(APP_URL), `link ${link} is built from APP_URL`).toBe(true);
    }
    const notice = await waitForMail(ADMIN_INBOX, /New Member Registration/, adminBefore);
    expect(notice.Text).toContain(`${first} ${last} just registered.`);
    // Nothing from the form is rendered unescaped (the name rule only allows letters, digits, '.', '-' and spaces today).
    expect(notice.HTML).not.toMatch(/<script/i);
  });

  // KI-1 (docs/e2e.md): RegisterRequest's name regex rejects apostrophes, so "O'Shea" cannot register, while the
  // browser-side check accepts it and guest names allow it. When fixed, this also proves the HTML escaping of the name.
  test.fixme("accepts an apostrophe in the last name (O'Shea) and escapes it in the admin mail", async ({ page }) => {
    const email = `oshea-${Date.now().toString(36)}@sesf.test`;
    const adminBefore = await mailIds(ADMIN_INBOX);
    await visit(page, '/register');
    const info = page.getByRole('group', { name: 'User information' });
    await info.getByRole('textbox', { name: 'First name' }).fill('Nora');
    await info.getByRole('textbox', { name: 'Last name' }).fill("O'Shea");
    await info.getByRole('textbox', { name: 'Email', exact: true }).fill(email);
    await info.getByRole('textbox', { name: 'Confirm email' }).fill(email);
    const address = page.getByRole('group', { name: 'Address' });
    await address.getByRole('textbox', { name: 'Street', exact: true }).fill('1 Sandbox Way');
    await address.getByRole('textbox', { name: 'City' }).fill('Orlando');
    await address.getByRole('textbox', { name: 'State' }).fill('FL');
    await address.getByRole('textbox', { name: 'ZIP code' }).fill('32803');
    await address.getByRole('textbox', { name: 'Phone number' }).fill('555-123-4567');
    const pw = page.getByRole('group', { name: 'Password' });
    await pw.getByRole('textbox', { name: 'Password', exact: true }).fill('Password-123');
    await pw.getByRole('textbox', { name: 'Confirm password' }).fill('Password-123');
    await page.getByRole('checkbox', { name: /I consent to all terms/ }).check();
    await expect(page.locator('input[name="cf-turnstile-response"]')).toHaveValue(/.+/, { timeout: 20_000 });
    await page.getByRole('button', { name: 'Create Account' }).click();
    await expect(page.getByRole('button', { name: 'Welcome Nora' })).toBeVisible();
    const notice = await waitForMail(ADMIN_INBOX, /New Member Registration/, adminBefore);
    expect(notice.HTML).toContain('O&#039;Shea');
  });
});
