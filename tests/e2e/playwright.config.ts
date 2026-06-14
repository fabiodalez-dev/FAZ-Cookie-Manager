import { defineConfig, devices } from '@playwright/test';

const isCI = Boolean(process.env.CI);
// Use the 127.0.0.1 literal (not localhost) to match wp-fixture.ts and bypass
// the stale IPv6 ::1:9998 path a leftover `php -S` may still hold — nginx is
// bound to 127.0.0.1. A localhost/127.0.0.1 mismatch loses the auth cookie and
// REST nonce across the implied cross-host redirect.
const baseURL = process.env.WP_BASE_URL ?? 'http://127.0.0.1:9998';

export default defineConfig({
  testDir: './specs',
  timeout: 45_000,
  expect: {
    timeout: 10_000,
  },
  fullyParallel: false,
  forbidOnly: isCI,
  retries: isCI ? 2 : 1,
  workers: isCI ? 2 : 1,
  outputDir: './reports/artifacts',
  globalSetup: './global-setup.ts',
  reporter: [
    ['list'],
    ['html', { outputFolder: './reports/html', open: 'never' }],
    ['junit', { outputFile: './reports/junit/results.xml' }],
    ['json', { outputFile: './reports/results.json' }],
  ],
  use: {
    baseURL,
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
    actionTimeout: 15_000,
    navigationTimeout: 30_000,
    ignoreHTTPSErrors: true,
  },
  projects: [
    {
      name: 'chromium',
      use: {
        ...devices['Desktop Chrome'],
      },
    },
  ],
});
