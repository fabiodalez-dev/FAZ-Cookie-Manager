/**
 * Issue #349 (part 1): the first per-country override ever saved was lost.
 *
 * GET /faz/v1/geo/overrides returned the empty map as `[]` (PHP encodes an
 * empty array that way). geo-routing.js added the country as a string key to
 * that array and JSON.stringify() dropped it, so the POST carried `[]` and the
 * override never persisted. The endpoints now always answer with an object,
 * and the page treats anything that is not a plain object as the empty map.
 */

import type { Page, Response } from '@playwright/test';
import { test, expect } from '../fixtures/wp-fixture';
import { execFileSync } from 'node:child_process';

const WP_PATH = process.env.WP_PATH || '';
const ADMIN_PAGE = '/wp-admin/admin.php?page=faz-cookie-manager-geo-routing';

function wp(args: string[]): string {
  return execFileSync('wp', [`--path=${WP_PATH}`, ...args], { encoding: 'utf8' }).trim();
}

function clearOverrides(): void {
  // Deleting an absent option exits non-zero and prints a warning; both mean
  // "already clean".
  try {
    execFileSync('wp', [`--path=${WP_PATH}`, 'option', 'delete', 'faz_geo_admin_overrides'], { stdio: 'ignore' });
  } catch { /* absent already */ }
}

const isOverridesGet = (r: Response) =>
  r.request().method() === 'GET' && /\/faz\/v1\/geo\/overrides(?:[?]|$)/.test(r.url());

async function openOverrides(page: Page): Promise<unknown> {
  const listed = page.waitForResponse(isOverridesGet);
  await page.goto(ADMIN_PAGE, { waitUntil: 'domcontentloaded' });
  await page.click('#faz-geo-tab-overrides');
  const body = await (await listed).json();
  await expect(page.locator('#faz-geo-add-override')).toBeVisible();
  return (body as { overrides: unknown }).overrides;
}

async function addOverride(page: Page, cc: string): Promise<void> {
  await page.fill('#ov-country', cc);
  const saved = page.waitForResponse((r) => r.request().method() === 'POST' && /\/faz\/v1\/geo\/overrides(?:[?]|$)/.test(r.url()));
  const relisted = page.waitForResponse(isOverridesGet);
  await page.click('#faz-geo-add-override button[type="submit"]');
  expect((await saved).status()).toBe(200);
  await relisted;
}

async function listedCountries(page: Page): Promise<string[]> {
  return page.locator('#faz-geo-overrides-content table tbody tr td:first-child').allInnerTexts();
}

test.describe('Geo routing: per-country overrides persist from the first save (#349)', () => {
  test.skip(!WP_PATH, 'requires WP_PATH to reset the override option via wp-cli');
  test.beforeEach(clearOverrides);
  test.afterAll(clearOverrides);

  test('the first override survives a reload, and later edits keep the others', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);

    const empty = await openOverrides(page);
    expect(Array.isArray(empty), 'an empty override map is a JSON object, not []').toBe(false);
    expect(empty).toEqual({});

    await addOverride(page, 'US');
    expect(await listedCountries(page)).toEqual(['US']);

    // Survives a full reload of the settings page.
    await openOverrides(page);
    expect(await listedCountries(page)).toEqual(['US']);

    // A second country keeps the first.
    await addOverride(page, 'DE');
    expect((await listedCountries(page)).sort()).toEqual(['DE', 'US']);
    expect(Object.keys(JSON.parse(wp(['option', 'get', 'faz_geo_admin_overrides', '--format=json']))).sort()).toEqual(['DE', 'US']);
  });

  test('deleting the last override and adding a new first one works', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await openOverrides(page);
    await addOverride(page, 'US');

    page.on('dialog', (d) => d.accept());
    const relisted = page.waitForResponse(isOverridesGet);
    await page.click('#faz-geo-overrides-content button[data-country="US"]');
    await relisted;
    expect(await listedCountries(page)).toEqual([]);

    await addOverride(page, 'FR');
    await openOverrides(page);
    expect(await listedCountries(page)).toEqual(['FR']);
  });
});
