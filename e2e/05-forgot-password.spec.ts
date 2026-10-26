import { test, expect, visit, login, shot, APP_URL } from './support/test';
import { seed } from './support/seed';
import { mailIds, waitForMail, links } from './support/mailpit';

test.describe('forgot password', () => {
  test('emails a reset link, resets the password, and the new password works', async ({ page, allowConsole }) => {
    allowConsole(/status of 4\d\d .*@ .*\/login\/login/);
    const user = seed().users.reset;
    const newPassword = `New-Pass-${Date.now().toString(36)}`;
    const before = await mailIds(user.email);

    await visit(page, '/forgot-password');
    await page.getByRole('textbox', { name: 'Email' }).fill(user.email);
    await page.getByRole('button', { name: 'Request password' }).click();
    await expect(page.getByRole('status').or(page.getByText(/link|email/i).first()).first()).toBeVisible();

    const mail = await waitForMail(user.email, /reset your password/i, before);
    const link = links(mail).find((l) => l.includes('/#/reset-password?token='));
    expect(link, 'hash-route reset link').toBeDefined();
    expect(link!.startsWith(`${APP_URL}/#/reset-password?token=`), 'built from APP_URL').toBe(true);
    expect(link).toContain(`email=${encodeURIComponent(user.email)}`);

    await page.goto(link!);
    // The token is moved into component state and dropped from the address bar.
    await expect(page).toHaveURL(/#\/reset-password$/);
    expect(page.url()).not.toContain('token=');
    await expect(page.getByRole('textbox', { name: 'Email' })).toHaveValue(user.email);
    await page.getByLabel('New password', { exact: true }).fill(newPassword);
    await page.getByLabel('Confirm new password').fill(newPassword);
    await shot(page, '05-reset-form');
    await page.getByRole('button', { name: 'Reset password' }).click();
    await expect(page.getByRole('button', { name: `Welcome ${user.first}` })).toBeVisible();

    // Log out; the old password is refused and the new one works.
    await page.getByRole('button', { name: `Welcome ${user.first}` }).click();
    await page.getByRole('menuitem', { name: 'Logout' }).click();
    await expect(page.getByRole('link', { name: 'Login' })).toBeVisible();

    await visit(page, '/login');
    await page.getByRole('textbox', { name: 'Email' }).fill(user.email);
    await page.getByRole('textbox', { name: 'Password' }).fill(seed().password);
    const [old] = await Promise.all([
      page.waitForResponse((r) => r.url().endsWith('/login/login')),
      page.getByRole('button', { name: 'Sign in' }).click(),
    ]);
    expect(old.status()).not.toBe(200);

    await login(page, user.email, newPassword);
    await expect(page.getByRole('button', { name: `Welcome ${user.first}` })).toBeVisible();

    // The reset link is single use.
    await page.getByRole('button', { name: `Welcome ${user.first}` }).click();
    await page.getByRole('menuitem', { name: 'Logout' }).click();
    await expect(page.getByRole('link', { name: 'Login' })).toBeVisible();
    allowConsole(/status of 4\d\d .*@ .*\/login\/reset-password/);
    await page.goto(link!);
    await page.getByLabel('New password', { exact: true }).fill(`${newPassword}x`);
    await page.getByLabel('Confirm new password').fill(`${newPassword}x`);
    const [reuse] = await Promise.all([
      page.waitForResponse((r) => r.url().endsWith('/login/reset-password')),
      page.getByRole('button', { name: 'Reset password' }).click(),
    ]);
    expect(reuse.status()).not.toBe(200);
    await expect(page.getByRole('alert').first()).toBeVisible();
  });
});
