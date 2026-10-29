import type { Response } from '@playwright/test';
import { test, expect, visit, shot } from './support/test';
import { seed, seededPii } from './support/seed';

/** JSON endpoints that describe members and cars: none may expose an email or a phone number to a visitor. */
const PII_ENDPOINTS = /\/(members|member|cars|car|event\/attendees|home)\//;

test.describe('anonymous visitor', () => {
  test('browses home, events, event tabs, directories, profiles and privacy without PII leaks', async ({ page }) => {
    const s = seed();
    const bodies: { url: string; body: string }[] = [];
    const pending: Promise<void>[] = [];
    page.on('response', (res: Response) => {
      const url = new URL(res.url());
      if (url.origin === new URL(s.appUrl).origin && PII_ENDPOINTS.test(url.pathname + '/')) {
        pending.push(
          res
            .text()
            .then((body) => void bodies.push({ url: res.url(), body }))
            .catch(() => undefined),
        );
      }
    });

    await visit(page, '/');
    await expect(page.getByRole('heading', { level: 1, name: 'Welcome to Southeast Sharkfest' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Login' })).toBeVisible();
    const current = page.getByRole('article', { name: s.events.current.name });
    await expect(current).toBeVisible();
    await shot(page, '01-home');

    await page.getByRole('navigation', { name: 'Main' }).getByRole('link', { name: 'Events' }).click();
    await expect(page.getByRole('heading', { level: 1, name: 'Events' })).toBeVisible();
    await expect(page.getByRole('region', { name: 'Current Shows' }).getByRole('article', { name: s.events.current.name })).toBeVisible();
    const past = page.getByRole('region', { name: 'Past Shows' }).getByRole('article', { name: new RegExp(s.events.past.name) });
    await expect(past).toBeVisible();
    // The fallback summary (no short description) is shown as text, without the description's HTML tags.
    await expect(past).toContainText('Last year in the sandbox.');
    await expect(past).not.toContainText('<p>');

    await page.getByRole('link', { name: `Event Info for ${s.events.current.name}` }).click();
    await expect(page.getByRole('heading', { level: 1, name: s.events.current.name })).toBeVisible();
    const tabs = page.getByRole('navigation', { name: 'Event sections' });
    await expect(page.getByRole('region', { name: 'Friday' })).toBeVisible();
    await shot(page, '01-event-info');

    await tabs.getByRole('link', { name: 'Judging' }).click();
    await expect(page.getByRole('region', { name: 'Judging classes' })).toBeVisible();
    await tabs.getByRole('link', { name: 'Schedule' }).click();
    await expect(page.getByText('Friday arrival, Saturday show, Sunday drive.')).toBeVisible();
    await tabs.getByRole('link', { name: 'Directions' }).click();
    await expect(page.getByRole('region', { name: 'Sandbox Speedway' })).toBeVisible();
    await tabs.getByRole('link', { name: 'Sponsors' }).click();
    for (const tier of ['///M Class', 'Gold Class', 'Silber Class', 'Bronzit Class']) {
      await expect(page.getByRole('region', { name: tier })).toBeVisible();
    }
    await tabs.getByRole('link', { name: "Who's Coming" }).click();
    await expect(page.getByRole('search', { name: 'Filter members' })).toBeVisible();
    await expect(page.getByRole('heading', { level: 2, name: `${s.users.payer.first} ${s.users.payer.last[0]}` })).toBeVisible();

    // Member directory with paging (21 members, 20 per page).
    await visit(page, '/members');
    await expect(page.getByRole('heading', { level: 1, name: 'Members' })).toBeVisible();
    const pager = page.getByRole('navigation', { name: 'Member list pages' });
    await expect(pager).toBeVisible();
    await pager.getByRole('button', { name: 'Next' }).click();
    await expect(pager.getByRole('button', { name: 'Previous' })).toBeEnabled();
    await expect(page.getByRole('article')).not.toHaveCount(0);

    await visit(page, '/cars');
    await expect(page.getByRole('heading', { level: 1, name: 'Cars' })).toBeVisible();
    await page.getByRole('combobox', { name: 'Body style' }).selectOption({ label: 'e24' });
    await expect(page.getByRole('article').first()).toBeVisible();

    // Member profile and car profile (Mia's allowed car is shown in her garage).
    await visit(page, s.users.mia.profilePath);
    await expect(page.getByRole('heading', { level: 1, name: 'Mia M' })).toBeVisible();
    await expect(page.getByRole('region', { name: 'Garage' })).toBeVisible();
    await page.getByRole('region', { name: 'Garage' }).getByRole('link', { name: 'Blue Shark' }).click();
    await expect(page).toHaveURL(new RegExp(`#/car/${s.cars.miaAllowed.id}$`));
    await expect(page.getByRole('heading', { level: 1 })).toContainText(/Blue Shark|635CSi/);
    await shot(page, '01-car-profile');

    await page.getByRole('contentinfo').getByRole('link', { name: 'Privacy Policy' }).click();
    await expect(page.getByRole('heading', { level: 1, name: 'Privacy Policy' })).toBeVisible();

    // No seeded email or phone in the DOM of any page we visited last, nor in any member/car API response.
    await Promise.all(pending);
    const pii = seededPii();
    expect(bodies.length, 'member/car API responses captured').toBeGreaterThan(5);
    for (const { url, body } of bodies) {
      for (const value of pii) {
        expect(body.includes(value), `${value} leaked in ${url}`).toBe(false);
      }
    }
    for (const path of ['/members', s.users.mia.profilePath, `/car/${s.cars.miaAllowed.id}`, `${s.events.current.path}/members`]) {
      await visit(page, path);
      const html = await page.content();
      for (const value of pii) {
        expect(html.includes(value), `${value} visible on ${path}`).toBe(false);
      }
    }
  });

  test('private pages send an anonymous visitor to sign in', async ({ page }) => {
    const s = seed();
    await visit(page, `${s.users.mia.profilePath}/billing`);
    await expect(page).toHaveURL(/#\/login\?redirect=/);
    await expect(page.getByRole('heading', { level: 1, name: 'Sign in' })).toBeVisible();
    await visit(page, `${s.events.current.path}/register`);
    await expect(page).toHaveURL(/#\/login\?redirect=/);
  });
});
