import { defineConfig, devices } from '@playwright/test';

/**
 * E2E suite against the docker sandbox (scripts/e2e.sh, docs/e2e.md). The sandbox app is at
 * http://localhost:8001 (= its APP_URL), Mailpit at :8025, the PayPal stub control API at :9001.
 * Specs share one seeded database, so they run in one worker, in file order; each spec uses its own
 * seeded accounts (e2e/.state/seed.json) or unique emails.
 */
const baseURL = process.env.E2E_BASE_URL ?? 'http://localhost:8001';

export default defineConfig({
  testDir: 'e2e',
  testMatch: /.*\.spec\.ts$/,
  testIgnore: ['**/.state/**', '**/artifacts/**'],
  outputDir: 'e2e/artifacts',
  fullyParallel: false,
  workers: 1,
  forbidOnly: !!process.env.CI,
  retries: 0,
  timeout: 60_000,
  expect: { timeout: 10_000 },
  reporter: process.env.CI
    ? [['list'], ['html', { open: 'never', outputFolder: 'playwright-report' }]]
    : [['list'], ['html', { open: 'never', outputFolder: 'playwright-report' }]],
  use: {
    baseURL,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'off',
    actionTimeout: 15_000,
    navigationTimeout: 30_000,
  },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'], viewport: { width: 1280, height: 900 } },
    },
  ],
});
