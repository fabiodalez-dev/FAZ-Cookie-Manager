import { defineConfig, devices } from '@playwright/test';

const isCI = Boolean(process.env.CI);
// Use the 127.0.0.1 literal (not localhost) to match wp-fixture.ts and bypass
// the stale IPv6 ::1:9998 path a leftover `php -S` may still hold — nginx is
// bound to 127.0.0.1. A localhost/127.0.0.1 mismatch loses the auth cookie and
// REST nonce across the implied cross-host redirect.
const baseURL = process.env.WP_BASE_URL ?? 'http://127.0.0.1:9998';

// Several specs skip fixture-page tests when the site is served by PHP's
// built-in server, whose is_singular() handling is unreliable. That check
// reads FAZ_E2E_SERVER and defaults to 'php-built-in', so on the documented
// nginx + PHP-FPM stack the tests silently opted themselves out — which is
// how a provider script running before consent went unnoticed. Default to
// nginx here, matching the documented setup; export FAZ_E2E_SERVER=php-built-in
// to get the old behaviour back.
process.env.FAZ_E2E_SERVER = process.env.FAZ_E2E_SERVER ?? 'nginx';

export default defineConfig({
  testDir: './specs',
  timeout: 45_000,
  expect: {
    timeout: 10_000,
  },
  fullyParallel: false,
  forbidOnly: isCI,
  // Retry twice everywhere. A handful of frontend specs are timing-sensitive
  // (banner-render / MutationObserver / AJAX-settled counts) and occasionally
  // flake on the first attempt under machine load; CI already retried twice
  // while a local run retried once, which is why the same green-in-CI spec
  // could show red locally. Matching the two keeps local == CI reliability.
  retries: 2,
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
