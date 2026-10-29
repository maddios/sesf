import { test, expect, visit, login } from './support/test';
import { seed } from './support/seed';
import { expectNoSeriousA11yViolations } from './support/axe';

test.describe('accessibility (axe, WCAG 2.1 A/AA, serious and critical)', () => {
  test('public pages', async ({ page }) => {
    const s = seed();
    const pages: [string, string, () => Promise<void>][] = [
      ['home', '/', () => expect(page.getByRole('heading', { level: 1 })).toBeVisible()],
      ['events', '/events', () => expect(page.getByRole('region', { name: 'Current Shows' }).getByRole('article').first()).toBeVisible()],
      ['event page', s.events.current.path, () => expect(page.getByRole('region', { name: 'Friday' })).toBeVisible()],
      ['login', '/login', () => expect(page.getByRole('heading', { level: 1, name: 'Sign in' })).toBeVisible()],
      ['register', '/register', () => expect(page.getByRole('button', { name: 'Create Account' })).toBeVisible()],
    ];
    for (const [label, path, ready] of pages) {
      await visit(page, path);
      await ready();
      await expectNoSeriousA11yViolations(page, label);
    }
  });

  test('member pages: registration, billing, settings', async ({ page }) => {
    const s = seed();
    const user = s.users.a11y;
    await login(page, user.email);

    await visit(page, `${s.events.current.path}/register`);
    await expect(page.getByRole('button', { name: 'Update registration' })).toBeVisible();
    await expect(page.getByRole('region', { name: 'Summary' }).getByText('Total', { exact: true })).toBeVisible();
    await expectNoSeriousA11yViolations(page, 'registration');

    await visit(page, `${user.profilePath}/billing/${s.registrations.a11y.id}`);
    await expect(page.getByRole('heading', { level: 1, name: 'Billing' })).toBeVisible();
    await expect(page.getByRole('region', { name: 'Balance' })).toBeVisible();
    await expectNoSeriousA11yViolations(page, 'billing');

    await visit(page, `${user.profilePath}/settings`);
    await expect(page.getByRole('heading', { level: 1, name: 'Account settings' })).toBeVisible();
    await expectNoSeriousA11yViolations(page, 'settings');
    // Each collapsed settings section, opened.
    for (const section of ['Account information', 'Password', 'Address', 'Email preferences']) {
      await page.getByRole('button', { name: section, exact: true }).click();
    }
    await expectNoSeriousA11yViolations(page, 'settings (all sections open)');
  });
});
