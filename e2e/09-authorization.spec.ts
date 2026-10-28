import { request } from '@playwright/test';
import { test, expect, visit, login, xsrfHeaders, APP_URL } from './support/test';
import { seed } from './support/seed';

test.describe('authorization', () => {
  test("a member cannot open another member's billing or settings (guard and API)", async ({ page, context, allowConsole }) => {
    const s = seed();
    allowConsole(/status of (401|403|404)/);
    await login(page, s.users.mia.email);

    // SPA guard: another member's private sections send Mia home.
    for (const section of ['billing', 'settings', 'cars']) {
      await visit(page, `${s.users.ben.profilePath}/${section}`);
      await expect(page, `${section} of another member`).toHaveURL(/#\/$/);
    }
    await visit(page, `${s.users.ben.profilePath}/billing/${s.registrations.benPast.id}`);
    await expect(page).toHaveURL(/#\/$/);

    // API: Ben's invoice is not Mia's to read, and user_id cannot widen her view.
    const headers = await xsrfHeaders(context);
    const invoice = await page.request.get(`/member/invoice?registration_id=${s.registrations.benPast.id}`, { headers });
    expect([403, 404]).toContain(invoice.status());
    const list = await page.request.get(`/member/invoice-list?user_id=${s.users.ben.id}`, { headers });
    // Refused outright (legacy "Not Authorized" 401), or at most Mia's own list.
    if (list.status() === 200) {
      const ids = ((await list.json()) as { id: number }[]).map((r) => r.id);
      expect(ids).not.toContain(s.registrations.benPast.id);
    } else {
      expect([401, 403]).toContain(list.status());
    }
    const prefs = await page.request.get(`/member/private-preferences?user_id=${s.users.ben.id}`, { headers });
    if (prefs.status() === 200) {
      expect(await prefs.text()).not.toContain(s.users.ben.email);
    }
    // A member cannot register someone else (user_id is ignored for non-admins).
    const other = await page.request.get(`/event/registration?show_id=${s.events.current.id}&user_id=${s.users.ben.id}`, { headers });
    expect(other.status()).toBe(200);
    expect(((await other.json()) as { registration: { user_id: number } }).registration.user_id).toBe(s.users.mia.id);
  });

  test('anonymous API calls to member endpoints get 401', async () => {
    const s = seed();
    const api = await request.newContext({ baseURL: APP_URL, extraHTTPHeaders: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
    try {
      for (const path of [
        '/member/invoice-list',
        `/member/invoice?registration_id=${s.registrations.miaPast.id}`,
        `/event/registration?show_id=${s.events.current.id}`,
        '/member/location',
        '/admin-api/dashboard',
      ]) {
        expect((await api.get(path)).status(), path).toBe(401);
      }
    } finally {
      await api.dispose();
    }
  });

  test('a member gets 403 from admin-only endpoints; the admin gets through', async ({ browser }) => {
    const s = seed();
    for (const [who, expected] of [
      ['mia', 403],
      ['admin', 200],
    ] as const) {
      const context = await browser.newContext();
      const page = await context.newPage();
      await login(page, s.users[who].email);
      const headers = await xsrfHeaders(context);
      expect((await page.request.get('/admin-api/dashboard', { headers })).status(), `${who} dashboard`).toBe(expected);
      expect((await page.request.get('/admin-api/sponsors', { headers })).status(), `${who} sponsors`).toBe(expected);
      if (who === 'mia') {
        // The SPA admin area sends a member home.
        await visit(page, '/admin');
        await expect(page).toHaveURL(/#\/$/);
      }
      await context.close();
    }
  });

  test('the waiver portal says "not registered" instead of failing, and lists waivers for a registered member', async ({ page, allowConsole }) => {
    const s = seed();
    allowConsole(/status of 403/);
    await login(page, s.users.ben.email);

    await page.goto(`/waiver/${s.events.current.id}`);
    await expect(page.getByRole('alert')).toHaveText('You are not registered for this event.');
    await expect(page.getByText(`Signed in as ${s.users.ben.email}`)).toBeVisible();
    await expect(page.getByRole('button', { name: 'Log out' })).toBeVisible();

    await page.goto(`/waiver/${s.events.past.id}`);
    await expect(page.getByRole('heading', { level: 1, name: 'Waiver status' })).toBeVisible();
    await expect(page.getByRole('alert')).toBeHidden();
    await expect(page.getByText(/Ben Second/)).toBeVisible();
  });
});
