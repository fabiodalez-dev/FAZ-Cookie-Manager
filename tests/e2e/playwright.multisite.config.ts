import { defineConfig, devices } from '@playwright/test';

const baseURL = process.env.WP_BASE_URL;
if (!baseURL) {
  throw new Error('WP_BASE_URL is required for the isolated multisite suite.');
}

export default defineConfig({
  testDir: './specs',
  testMatch: 'release-verify-multisite-scanner.spec.ts',
  timeout: 180_000,
  expect: { timeout: 15_000 },
  fullyParallel: false,
  retries: 0,
  workers: 1,
  reporter: [['list']],
  use: {
    baseURL,
    ignoreHTTPSErrors: true,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    actionTimeout: 20_000,
    navigationTimeout: 60_000,
  },
  projects: [{ name: 'multisite-chromium', use: { ...devices['Desktop Chrome'] } }],
});
