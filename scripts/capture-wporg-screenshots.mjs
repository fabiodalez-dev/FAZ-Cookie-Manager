#!/usr/bin/env node
/**
 * Capture WordPress.org screenshots for FAZ Cookie Manager.
 *
 * Produces PNGs at 1280x800 suitable for wp.org plugin screenshots.
 * Outputs to `.wordpress-org/screenshots-src/` in the repo root.
 *
 * Usage:
 *   WP_BASE_URL=http://localhost:9998 \
 *   WP_ADMIN_USER=admin WP_ADMIN_PASS=admin \
 *   node scripts/capture-wporg-screenshots.mjs
 */

import { chromium } from 'playwright';
import { mkdir } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const BASE = process.env.WP_BASE_URL || 'http://localhost:9998';
const USER = process.env.WP_ADMIN_USER || 'admin';
const PASS = process.env.WP_ADMIN_PASS || 'admin';

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);
const OUT = resolve(__dirname, '..', '.wordpress-org', 'screenshots-src');

const VIEWPORT = { width: 1280, height: 960 };

/**
 * Login to WP admin via wp-login.php form submit.
 */
async function login(page) {
  await page.goto(`${BASE}/wp-login.php`, { waitUntil: 'domcontentloaded' });
  await page.fill('#user_login', USER);
  await page.fill('#user_pass', PASS);
  await Promise.all([
    page.waitForLoadState('domcontentloaded'),
    page.click('#wp-submit'),
  ]);
}

async function shot(page, name, { fullPage = false } = {}) {
  const path = resolve(OUT, `${name}.png`);
  await page.screenshot({ path, fullPage });
  console.log(`  → ${name}.png`);
}

/**
 * Wait for the admin page shell to be ready (wpbody exists).
 */
async function waitAdmin(page) {
  await page.waitForSelector('#wpbody', { timeout: 15000 }).catch(() => {});
  await page.waitForTimeout(600);
}

async function main() {
  await mkdir(OUT, { recursive: true });
  console.log(`Capturing to: ${OUT}`);

  const browser = await chromium.launch();
  const context = await browser.newContext({
    viewport: VIEWPORT,
    deviceScaleFactor: 2, // retina PNGs for crisp wp.org screenshots
  });
  const page = await context.newPage();

  // ---------- FRONTEND ----------
  // 1) Home with banner (fresh context so consent cookie isn't set).
  await page.goto(`${BASE}/`, { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('.faz-consent-container', { timeout: 10000 }).catch(() => {});
  // Hide the WP admin bar for cleaner public-looking shots.
  await page.addStyleTag({
    content: `
      #wpadminbar { display: none !important; }
      html { margin-top: 0 !important; }
    `,
  });
  await page.waitForTimeout(1200);
  await shot(page, '01-frontend-home-banner');

  // 2) Preference center (click Customize).
  try {
    await page.click('[data-faz-tag="settings-button"]', { timeout: 3000 });
    await page.waitForTimeout(900);
    await shot(page, '02-frontend-preference-center');
  } catch (e) {
    console.warn('  ! preference center not opened:', e.message);
  }

  // ---------- ADMIN ----------
  await login(page);

  // 3) Dashboard.
  await page.goto(`${BASE}/wp-admin/admin.php?page=faz-cookie-manager`, {
    waitUntil: 'domcontentloaded',
  });
  await waitAdmin(page);
  await shot(page, '03-admin-dashboard');

  // 4) Banner editor.
  await page.goto(`${BASE}/wp-admin/admin.php?page=faz-cookie-manager-banner`, {
    waitUntil: 'domcontentloaded',
  });
  await waitAdmin(page);
  await shot(page, '04-admin-banner-editor');

  // 5) Cookies list.
  await page.goto(`${BASE}/wp-admin/admin.php?page=faz-cookie-manager-cookies`, {
    waitUntil: 'domcontentloaded',
  });
  await waitAdmin(page);
  await shot(page, '05-admin-cookies-list');

  // 6) IAB TCF Global Vendor List — a unique feature worth surfacing.
  await page.goto(`${BASE}/wp-admin/admin.php?page=faz-cookie-manager-gvl`, {
    waitUntil: 'domcontentloaded',
  });
  await waitAdmin(page);
  // Give the REST call that hydrates vendor data a moment to resolve.
  await page.waitForTimeout(1200);
  await shot(page, '06-admin-iab-tcf-vendors');

  // 7) Consent logs.
  await page.goto(`${BASE}/wp-admin/admin.php?page=faz-cookie-manager-consent-logs`, {
    waitUntil: 'domcontentloaded',
  });
  await waitAdmin(page);
  await page.waitForTimeout(900); // let the REST call hydrate the table
  await shot(page, '07-admin-consent-logs');

  // 8) Google Consent Mode v2.
  await page.goto(`${BASE}/wp-admin/admin.php?page=faz-cookie-manager-gcm`, {
    waitUntil: 'domcontentloaded',
  });
  await waitAdmin(page);
  await shot(page, '08-admin-google-consent-mode');

  // 9) Languages.
  await page.goto(`${BASE}/wp-admin/admin.php?page=faz-cookie-manager-languages`, {
    waitUntil: 'domcontentloaded',
  });
  await waitAdmin(page);
  await shot(page, '09-admin-languages');

  // 10) Settings.
  await page.goto(`${BASE}/wp-admin/admin.php?page=faz-cookie-manager-settings`, {
    waitUntil: 'domcontentloaded',
  });
  await waitAdmin(page);
  await shot(page, '10-admin-settings');

  await browser.close();
  console.log('\nDone.');
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
