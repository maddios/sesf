import { test, expect, visit, login, shot, expectNoHorizontalScroll } from './support/test';
import { seed } from './support/seed';

test.use({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });

test.describe('mobile (390 x 844)', () => {
  test('home and login fit the screen and the menu works', async ({ page }) => {
    await visit(page, '/');
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    await expectNoHorizontalScroll(page);
    await shot(page, '11-mobile-home');

    // Desktop nav is hidden; the menu button toggles the mobile menu.
    const menuButton = page.getByRole('button', { name: 'Menu' });
    await expect(menuButton).toHaveAttribute('aria-expanded', 'false');
    await expect(page.getByRole('navigation', { name: 'Main menu' })).toBeHidden();
    await menuButton.click();
    await expect(menuButton).toHaveAttribute('aria-expanded', 'true');
    const menu = page.getByRole('navigation', { name: 'Main menu' });
    await expect(menu).toBeVisible();
    await shot(page, '11-mobile-menu');
    await menu.getByRole('link', { name: 'Events' }).click();
    await expect(page).toHaveURL(/#\/events$/);
    await expect(menu).toBeHidden();
    await expectNoHorizontalScroll(page);

    await menuButton.click();
    await page.locator('#mobile-menu').getByRole('link', { name: 'Login' }).click();
    await expect(page.getByRole('heading', { level: 1, name: 'Sign in' })).toBeVisible();
    await expectNoHorizontalScroll(page);
    // Escape closes the menu and returns focus to the button.
    await menuButton.click();
    await page.keyboard.press('Escape');
    await expect(menuButton).toHaveAttribute('aria-expanded', 'false');
    await expect(menuButton).toBeFocused();
  });

  test('the registration form fits the screen', async ({ page }) => {
    const s = seed();
    await login(page, s.users.a11y.email);
    await visit(page, `${s.events.current.path}/register`);
    await expect(page.getByRole('button', { name: 'Update registration' })).toBeVisible();
    await expect(page.getByRole('region', { name: 'Summary' })).toBeVisible();
    await expectNoHorizontalScroll(page);
    await shot(page, '11-mobile-registration');

    // The signed-in menu lists the account links.
    await page.getByRole('button', { name: 'Menu' }).click();
    await expect(page.locator('#mobile-menu').getByRole('link', { name: 'Billing' })).toBeVisible();
  });
});
