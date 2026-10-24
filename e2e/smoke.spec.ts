import { test, expect } from '@playwright/test';

test('SPA shell loads and the app mounts', async ({ page }) => {
    await page.goto('/');
    await expect(page).toHaveTitle('Southeast Sharkfest');
    await expect(page.locator('#app header')).toBeVisible();
    await expect(page.locator('#app h1')).toBeVisible();
});
