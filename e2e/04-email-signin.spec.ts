import type { Page } from '@playwright/test';
import { test, expect, visit, shot, APP_URL } from './support/test';
import { seed } from './support/seed';
import { mailIds, waitForMail, links, type MailMessage } from './support/mailpit';

const SUBJECT = /sign-in code/i;

async function requestCode(page: Page, email: string): Promise<MailMessage> {
  const before = await mailIds(email);
  await visit(page, '/login');
  await page.getByRole('tab', { name: 'Email me a code' }).click();
  await page.getByRole('tabpanel', { name: 'Email me a code' }).getByRole('textbox', { name: 'Email' }).fill(email);
  await page.getByRole('button', { name: 'Email me a sign-in code' }).click();
  await expect(page.getByText('Check your email. We sent you a code.')).toBeVisible();
  return waitForMail(email, SUBJECT, before);
}

function codeOf(mail: MailMessage): string {
  const match = mail.Text.match(/\b(\d{6})\b/) ?? mail.HTML.match(/>\s*(\d{6})\s*</);
  expect(match, 'six-digit code in the mail').not.toBeNull();
  return match![1];
}

function consumeLink(mail: MailMessage): string {
  const link = links(mail).find((l) => l.includes('/login/email-signin/consume/'));
  expect(link, 'magic link in the mail').toBeDefined();
  return link!;
}

test.describe('emailed sign-in', () => {
  test('signs in with the emailed code; the link of a used code no longer works', async ({ page }) => {
    const user = seed().users.code;
    const mail = await requestCode(page, user.email);
    expect(mail.To.map((t) => t.Address)).toEqual([user.email]);
    const link = consumeLink(mail);
    expect(link.startsWith(`${APP_URL}/login/email-signin/consume/`), 'link is built from APP_URL').toBe(true);

    await page.getByRole('textbox', { name: 'Your code' }).fill(codeOf(mail));
    await expect(page.getByRole('button', { name: `Welcome ${user.first}` })).toBeVisible();
    await shot(page, '04-code-signed-in');

    // Logout, then the magic link of the already-used request is refused.
    await page.getByRole('button', { name: `Welcome ${user.first}` }).click();
    await page.getByRole('menuitem', { name: 'Logout' }).click();
    await expect(page.getByRole('link', { name: 'Login' })).toBeVisible();
    await page.goto(link);
    await expect(page).toHaveURL(/#\/login\?email_signin=invalid/);
    await expect(page.getByText(/no longer valid/)).toBeVisible();
  });

  test('closes the code after five wrong attempts', async ({ page, allowConsole }) => {
    allowConsole(/status of 4\d\d .*@ .*\/login\/email-signin\/verify-code/);
    const user = seed().users.codelock;
    const mail = await requestCode(page, user.email);
    const real = codeOf(mail);
    const wrong = real === '111111' ? '222222' : '111111';
    const input = page.getByRole('textbox', { name: 'Your code' });
    for (let attempt = 1; attempt <= 5; attempt++) {
      const [response] = await Promise.all([
        page.waitForResponse((r) => r.url().endsWith('/login/email-signin/verify-code')),
        input.fill(wrong),
      ]);
      expect(response.ok(), `wrong attempt ${attempt} is refused`).toBe(false);
      await expect(input).toHaveValue('');
    }
    // The right code is refused too once the request is closed.
    const [response] = await Promise.all([
      page.waitForResponse((r) => r.url().endsWith('/login/email-signin/verify-code')),
      input.fill(real),
    ]);
    expect(response.ok()).toBe(false);
    await expect(page.getByRole('alert').first()).toBeVisible();
    await expect(page.getByRole('link', { name: 'Login' })).toBeVisible();
    await shot(page, '04-code-locked');
  });

  test('signs in with the magic link from the email', async ({ page }) => {
    const user = seed().users.magic;
    const mail = await requestCode(page, user.email);
    await page.goto(consumeLink(mail));
    await expect(page.getByRole('button', { name: `Welcome ${user.first}` })).toBeVisible();
  });
});
