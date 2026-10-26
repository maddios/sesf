import { test, expect, visit, login, shot } from './support/test';
import { seed } from './support/seed';

test.describe('password sign-in', () => {
  test('signs in, shows the account menu, and logs out', async ({ page }) => {
    const s = seed();
    await login(page, s.users.mia.email);
    const menu = page.getByRole('button', { name: 'Welcome Mia' });
    await expect(menu).toBeVisible();
    await shot(page, '03-signed-in');
    await menu.click();
    await expect(page.getByRole('menuitem', { name: 'Billing' })).toBeVisible();
    await page.getByRole('menuitem', { name: 'Logout' }).click();
    await expect(page.getByRole('link', { name: 'Login' })).toBeVisible();
    // The session is really gone: a private page sends us to sign in again.
    await visit(page, `${s.users.mia.profilePath}/settings`);
    await expect(page).toHaveURL(/#\/login/);
  });

  test('rejects a wrong password and keeps the visitor on the form', async ({ page, allowConsole }) => {
    const s = seed();
    allowConsole(/status of 4\d\d .*@ .*\/login\/login/);
    await visit(page, '/login');
    await page.getByRole('textbox', { name: 'Email' }).fill(s.users.ben.email);
    await page.getByRole('textbox', { name: 'Password' }).fill('not-the-password');
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page.getByRole('alert')).toBeVisible();
    await expect(page).toHaveURL(/#\/login/);
    await expect(page.getByRole('textbox', { name: 'Password' })).toHaveValue('');
    await shot(page, '03-login-failed');
  });

  test('throttles repeated failures for one account with a clear message', async ({ page, allowConsole }) => {
    const s = seed();
    allowConsole(/status of 4\d\d .*@ .*\/login\/login/);
    await visit(page, '/login');
    const email = page.getByRole('textbox', { name: 'Email' });
    const password = page.getByRole('textbox', { name: 'Password' });
    let throttled = false;
    // The login limiter allows 10 attempts a minute per email and IP.
    for (let attempt = 1; attempt <= 12 && !throttled; attempt++) {
      await email.fill(s.users.lockout.email);
      await password.fill(`wrong-${attempt}`);
      const [response] = await Promise.all([
        page.waitForResponse((r) => r.url().endsWith('/login/login')),
        page.getByRole('button', { name: 'Sign in' }).click(),
      ]);
      throttled = response.status() === 429;
    }
    expect(throttled, 'the 11th attempt is throttled').toBe(true);
    await expect(page.getByRole('alert')).toContainText(/too many|try again/i);
    await shot(page, '03-login-throttled');
    // Even the right password is refused while throttled.
    await email.fill(s.users.lockout.email);
    await password.fill(s.password);
    const [response] = await Promise.all([
      page.waitForResponse((r) => r.url().endsWith('/login/login')),
      page.getByRole('button', { name: 'Sign in' }).click(),
    ]);
    expect(response.status()).toBe(429);
    await expect(page).toHaveURL(/#\/login/);
  });
});
