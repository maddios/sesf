import { test as base, expect, type ConsoleMessage, type Page, type BrowserContext } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { join } from 'node:path';
import { seed } from './seed';

export { expect };

export const APP_URL = process.env.E2E_BASE_URL ?? 'http://localhost:8001';
const APP_ORIGIN = new URL(APP_URL).origin;

/** Decides whether a console error is expected. Return true to allow it. */
export type ConsoleAllow = (message: { text: string; url: string }) => boolean;

/**
 * Console errors that are always expected:
 *  - the anonymous session probe: POST /login/authenticate answers 401 when nobody is signed in;
 *  - image 404s: seeded rows point at photos that do not exist in the sandbox file store;
 *  - anything from another origin (YouTube embeds, Cloudflare Turnstile, map tiles): not this app.
 */
const ALWAYS_ALLOWED: ConsoleAllow[] = [
  ({ text, url }) => /status of 401/.test(text) && url.endsWith('/login/authenticate'),
  ({ text, url }) => /status of 404/.test(text) && (/\/images\//.test(url) || /\.(jpe?g|png|gif|webp|svg)(\?|$)/i.test(url)),
  ({ url }) => url !== '' && !url.startsWith(APP_ORIGIN),
];

interface Fixtures {
  /** Registers an extra allowed console error for the current test (e.g. an expected 422). */
  allowConsole: (rule: ConsoleAllow | RegExp) => void;
  /** Collected unexpected console errors; asserted empty after each test. */
  consoleErrors: string[];
}

function describe(msg: ConsoleMessage): { text: string; url: string } {
  return { text: msg.text(), url: msg.location().url ?? '' };
}

export const test = base.extend<Fixtures>({
  consoleErrors: [
    async ({ page }, use) => {
      const errors: string[] = [];
      const extra: ConsoleAllow[] = [];
      (page as Page & { __allow?: ConsoleAllow[] }).__allow = extra;
      page.on('console', (msg) => {
        if (msg.type() !== 'error') {
          return;
        }
        const info = describe(msg);
        if ([...ALWAYS_ALLOWED, ...extra].some((rule) => rule(info))) {
          return;
        }
        errors.push(`${info.text} @ ${info.url}`);
      });
      page.on('pageerror', (error) => errors.push(`pageerror: ${error.message}`));
      await use(errors);
      expect(errors, 'unexpected console errors').toEqual([]);
    },
    { auto: true },
  ],
  allowConsole: async ({ page }, use) => {
    await use((rule) => {
      const list = (page as Page & { __allow?: ConsoleAllow[] }).__allow!;
      list.push(rule instanceof RegExp ? ({ text, url }) => rule.test(`${text} @ ${url}`) : rule);
    });
  },
});

/** Opens a hash route and waits for the SPA to settle. */
export async function visit(page: Page, hashPath: string): Promise<void> {
  await page.goto(`/#${hashPath}`);
  await settle(page);
}

/** Signs in through the login form (password tab). */
export async function login(page: Page, email: string, password = seed().password): Promise<void> {
  await visit(page, '/login');
  await page.getByRole('textbox', { name: 'Email' }).fill(email);
  await page.getByRole('textbox', { name: 'Password' }).fill(password);
  const [response] = await Promise.all([
    page.waitForResponse((r) => r.url().endsWith('/login/login') && r.request().method() === 'POST'),
    page.getByRole('button', { name: 'Sign in' }).click(),
  ]);
  expect(response.status(), `login as ${email}`).toBe(200);
  await expect(page).not.toHaveURL(/#\/login/);
  await settle(page);
}

/**
 * Waits until the app's own requests are done. Third-party embeds (YouTube on the home page) can keep the
 * network busy forever, so networkidle is best effort with a short cap; assertions still auto-wait.
 */
export async function settle(page: Page): Promise<void> {
  await page.locator('main').first().waitFor();
  await page.waitForLoadState('networkidle', { timeout: 5_000 }).catch(() => undefined);
}

/** Reads the XSRF cookie for same-origin API calls made with page.request / context.request. */
export async function xsrfHeaders(context: BrowserContext): Promise<Record<string, string>> {
  const cookie = (await context.cookies(APP_URL)).find((c) => c.name === 'XSRF-TOKEN');
  return {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
    ...(cookie ? { 'X-XSRF-TOKEN': decodeURIComponent(cookie.value) } : {}),
  };
}

/** Curated screenshot into docs/e2e-shots/ (only with E2E_SHOTS=1). */
export async function shot(page: Page, name: string): Promise<void> {
  if (process.env.E2E_SHOTS !== '1') {
    return;
  }
  const dir = join(process.cwd(), 'docs', 'e2e-shots');
  mkdirSync(dir, { recursive: true });
  await page.screenshot({ path: join(dir, `${name}.png`), fullPage: true });
}

/** Asserts the page does not scroll horizontally. */
export async function expectNoHorizontalScroll(page: Page): Promise<void> {
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  expect(overflow, 'horizontal overflow in px').toBeLessThanOrEqual(0);
}
