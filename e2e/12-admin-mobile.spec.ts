import type { Locator, Page } from '@playwright/test';
import { test, expect, visit, login, shot, expectNoHorizontalScroll } from './support/test';
import { expectNoSeriousA11yViolations } from './support/axe';
import { seed } from './support/seed';

/** Every admin page (registry paths) with the query that selects the current seeded event where the page needs one. */
function adminPages(eventId: number): Array<[string, string]> {
  return [
    ['dashboard', '/admin'],
    ['users', '/admin/users'],
    ['cars', '/admin/cars'],
    ['events', '/admin/events'],
    ['agenda', `/admin/agenda?f.show_id=${eventId}`],
    ['locations', `/admin/locations?f.show_id=${eventId}`],
    ['extras', '/admin/extras'],
    ['extra-registrations', '/admin/extra-registrations'],
    ['checkin', `/admin/checkin?show_id=${eventId}`],
    ['tshirts', `/admin/tshirts?show_id=${eventId}`],
    ['expenses', `/admin/expenses?show_id=${eventId}`],
    ['judging', `/admin/judging?show=${eventId}`],
    ['registrations', '/admin/registrations'],
    ['registration-guests', '/admin/registration-guests'],
    ['waivers', `/admin/waivers?show_id=${eventId}`],
    ['sponsors', '/admin/sponsors'],
    ['report-members', '/admin/reports/members'],
    ['report-event-summary', `/admin/reports/event-summary?show_id=${eventId}`],
    ['report-extras', `/admin/reports/extras?show_id=${eventId}`],
  ];
}

/** The image upload dialogs, opened from each list's row Actions menu. */
const IMAGE_DIALOGS: Array<[path: string, item: string]> = [
  ['/admin/events', 'Upload image'],
  ['/admin/sponsors', 'Upload logo'],
  ['/admin/cars', 'Upload photo'],
];

async function openRowAction(page: Page, item: string): Promise<void> {
  await page.getByRole('button', { name: /^Actions for / }).filter({ visible: true }).first().click();
  await page.getByRole('menu').getByRole('menuitem', { name: item }).click();
}

/** Text in the dialog keeps a readable width: nothing with a sentence of text is squeezed into a narrow column. */
async function expectReadableDialog(dialog: Locator, name: string): Promise<void> {
  const squeezed = await dialog.evaluate((root) =>
    Array.from(root.querySelectorAll<HTMLElement>('label, p, input, button, img, [role="img"]'))
      .filter((element) => element.offsetParent !== null && (element.textContent ?? '').trim().length > 20)
      .map((element) => ({ text: (element.textContent ?? '').trim().slice(0, 40), width: element.getBoundingClientRect().width, lines: element.getBoundingClientRect().height / parseFloat(getComputedStyle(element).lineHeight || '20') }))
      .filter((element) => element.width < 240 || element.lines > 3),
  );
  expect(squeezed, `${name}: squeezed text`).toEqual([]);
  const input = dialog.locator('input[type="file"]');
  expect((await input.boundingBox())?.width ?? 0, `${name}: file input width`).toBeGreaterThanOrEqual(240);
}

test.describe('admin on a phone (390 x 844)', () => {
  test.use({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });

  test('every admin page fits the screen and shows its heading and the menu button', async ({ page }) => {
    const s = seed();
    await login(page, s.users.admin.email);
    for (const [name, path] of adminPages(s.events.current.id)) {
      await visit(page, path);
      await expect(page.getByRole('heading', { level: 1 }), name).toHaveCount(1);
      await expect(page.getByRole('button', { name: 'Menu' }), name).toBeVisible();
      await expect(page.getByRole('navigation', { name: 'Admin' }), name).toBeHidden();
      await expectNoHorizontalScroll(page);
      await shot(page, `12-admin-mobile-${name}`);
    }
  });

  test('the menu is a drawer that closes on Escape and after navigating', async ({ page }) => {
    const s = seed();
    await login(page, s.users.admin.email);
    await visit(page, '/admin');
    const menuButton = page.getByRole('button', { name: 'Menu' });
    await menuButton.click();
    const nav = page.getByRole('navigation', { name: 'Admin' });
    await expect(nav).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(nav).toBeHidden();
    await expect(menuButton).toBeFocused();

    await menuButton.click();
    await nav.getByRole('link', { name: 'T-shirts' }).click();
    await expect(page).toHaveURL(/#\/admin\/tshirts/);
    await expect(nav).toBeHidden();
  });

  test('check-in and T-shirts work as cards with full-width dialogs', async ({ page }) => {
    const s = seed();
    await login(page, s.users.admin.email);
    await visit(page, `/admin/checkin?show_id=${s.events.current.id}`);
    const checkIn = page.getByRole('button', { name: /^Check-in/ }).first();
    await expect(checkIn).toBeVisible();
    await checkIn.click();
    const dialog = page.getByRole('dialog');
    await expect(dialog).toBeVisible();
    const box = await dialog.boundingBox();
    expect(box?.width).toBeGreaterThanOrEqual(380);
    await expect(dialog.getByRole('button', { name: 'Close' }).last()).toBeInViewport();
    await expectNoHorizontalScroll(page);
    await shot(page, '12-admin-mobile-checkin-dialog');
    await page.keyboard.press('Escape');

    await visit(page, `/admin/tshirts?show_id=${s.events.current.id}`);
    await page.getByRole('button', { name: 'Set shirt inventory' }).click();
    const inventory = page.getByRole('dialog', { name: 'Shirt inventory for this event' });
    await expect(inventory.getByRole('button', { name: 'Save inventory' })).toBeInViewport();
    await expectNoHorizontalScroll(page);
  });

  test('row actions: a labelled Edit button and an Actions bottom sheet with large labelled rows', async ({ page }) => {
    const s = seed();
    await login(page, s.users.admin.email);
    await visit(page, '/admin/events');
    const card = page.locator('.admin-card').filter({ hasText: s.events.current.name });
    await expect(card.getByRole('button', { name: `Edit ${s.events.current.name}` })).toBeVisible();
    const trigger = card.getByRole('button', { name: `Actions for ${s.events.current.name}` });
    await expect(trigger).toHaveText(/Actions/);
    await trigger.click();
    const menu = page.getByRole('menu', { name: `Actions for ${s.events.current.name}` });
    await expect(menu).toBeInViewport();
    const items = menu.getByRole('menuitem');
    for (const box of await items.evaluateAll((elements) => elements.map((element) => element.getBoundingClientRect().height))) {
      expect(box).toBeGreaterThanOrEqual(48);
    }
    await expect(items.last()).toHaveText('Delete…');
    const send = menu.getByRole('menuitem', { name: 'Send judging results' });
    await expect(send).toHaveAttribute('aria-disabled', 'true');
    await expect(send).toContainText('Release the judging results first.');
    await expectNoHorizontalScroll(page);
    await shot(page, '12-admin-mobile-row-actions-sheet');
    await page.keyboard.press('Escape');
    await expect(menu).toBeHidden();
    await expect(trigger).toBeFocused();

    await trigger.click();
    await menu.getByRole('menuitem', { name: 'Sponsors' }).click();
    await expect(page.getByRole('dialog', { name: `Sponsors for ${s.events.current.name}` })).toBeVisible();
  });

  test('image upload dialogs keep the file picker and its text readable', async ({ page }) => {
    const s = seed();
    await login(page, s.users.admin.email);
    for (const [path, item] of IMAGE_DIALOGS) {
      await visit(page, path);
      await openRowAction(page, item);
      const dialog = page.getByRole('dialog');
      await expect(dialog).toBeVisible();
      await expectReadableDialog(dialog, `${path} ${item}`);
      await expectNoHorizontalScroll(page);
      await page.keyboard.press('Escape');
      await expect(dialog).toBeHidden();
    }
  });

  test('admin list filters open as a sheet and the combobox lists the events', async ({ page }) => {
    const s = seed();
    await login(page, s.users.admin.email);
    await visit(page, '/admin/sponsors');
    await page.getByRole('button', { name: /^Filters/ }).click();
    const event = page.getByRole('combobox', { name: 'Event' });
    await expect(event).toBeInViewport();
    await event.click();
    await expect(page.getByRole('option', { name: new RegExp(s.events.current.name) })).toBeVisible();
    await expectNoHorizontalScroll(page);
  });
});

test.describe('admin on a desktop (1280)', () => {
  test('row Actions open a popover menu by keyboard; image dialogs stay readable', async ({ page }) => {
    const s = seed();
    await login(page, s.users.admin.email);
    await visit(page, '/admin/events');
    const row = page.getByRole('table', { name: 'Events' }).getByRole('row').filter({ hasText: s.events.current.name });
    const trigger = row.getByRole('button', { name: `Actions for ${s.events.current.name}` });
    await trigger.focus();
    await page.keyboard.press('ArrowDown');
    const menu = page.getByRole('menu', { name: `Actions for ${s.events.current.name}` });
    await expect(menu).toBeVisible();
    await expect(menu.getByRole('menuitem').first()).toBeFocused();
    await page.keyboard.press('End');
    await expect(menu.getByRole('menuitem', { name: 'Delete…' })).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(menu).toBeHidden();
    await expect(trigger).toBeFocused();

    for (const [path, item] of IMAGE_DIALOGS) {
      await visit(page, path);
      await openRowAction(page, item);
      const dialog = page.getByRole('dialog');
      await expect(dialog).toBeVisible();
      await expectReadableDialog(dialog, `${path} ${item}`);
      await page.keyboard.press('Escape');
      await expect(dialog).toBeHidden();
    }
  });
});

test.describe('admin accessibility', () => {
  test('axe finds no serious or critical violations on the admin pages', async ({ page }) => {
    const s = seed();
    await login(page, s.users.admin.email);
    const failures: string[] = [];
    for (const [name, path] of adminPages(s.events.current.id)) {
      await visit(page, path);
      await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1);
      // Collect every page's report so one run lists them all.
      await expectNoSeriousA11yViolations(page, `admin ${name}`).catch((error: Error) => failures.push(error.message));
    }
    expect(failures).toEqual([]);
  });
});
