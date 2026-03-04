import { defineConfig, devices } from '@playwright/test';

const isCI = Boolean(process.env.CI);
const baseURL = process.env.WP_BASE_URL ?? 'http://localhost:9998';

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
