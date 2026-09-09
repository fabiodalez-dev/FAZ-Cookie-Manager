import { defineConfig, devices } from '@playwright/test';
const selected = process.env.FAZ_INTENT_BROWSER;
const names = ['chromium', 'firefox', 'webkit'];
if (selected && !names.includes(selected)) throw new Error('Unknown FAZ_INTENT_BROWSER');
export default defineConfig({
  testDir: '.', testMatch: '*.spec.ts', timeout: 30_000,
  fullyParallel: true, workers: 2, retries: 0, forbidOnly: !!process.env.CI,
  outputDir: '../e2e/reports/intent-artifacts',
  reporter: [['list'], ['json', { outputFile: '../e2e/reports/intent-results.json' }]],
  use: { trace: 'retain-on-failure' },
  projects: names.filter(n => !selected || selected === n).map(name => ({
    name, use: { ...devices[name === 'firefox' ? 'Desktop Firefox' : name === 'webkit' ? 'Desktop Safari' : 'Desktop Chrome'] },
  })),
});
