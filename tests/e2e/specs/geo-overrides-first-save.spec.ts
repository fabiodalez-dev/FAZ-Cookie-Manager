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

function wp(args: string[], input?: string): string {
  return execFileSync('wp', [`--path=${WP_PATH}`, ...args], { encoding: 'utf8', input }).trim();
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

/** The table rows, once the re-render after a save or delete is in the DOM. */
async function waitForCountries(page: Page, expected: string[]): Promise<void> {
  await expect
    .poll(async () => (await listedCountries(page)).slice().sort(), { timeout: 10_000 })
    .toEqual(expected.slice().sort());
}

async function addOverride(page: Page, cc: string, expectedAfter: string[]): Promise<void> {
  await page.fill('#ov-country', cc);
  const saved = page.waitForResponse((r) => r.request().method() === 'POST' && /\/faz\/v1\/geo\/overrides(?:[?]|$)/.test(r.url()));
  await page.click('#faz-geo-add-override button[type="submit"]');
  expect((await saved).status()).toBe(200);
  // The GET that follows only starts the re-render; wait for the rows.
  await waitForCountries(page, expectedAfter);
}

async function listedCountries(page: Page): Promise<string[]> {
  return page.locator('#faz-geo-overrides-content table tbody tr td:first-child').allInnerTexts();
}

test.describe('Geo routing: per-country overrides persist from the first save (#349)', () => {
  test.skip(!WP_PATH, 'requires WP_PATH to reset the override option via wp-cli');
  let overridesSnapshot: string | null = null;
  test.beforeAll(() => {
    // Serialize the WordPress value to retain its type as well as absence.
    const snapshot = wp(['eval', `
      $sentinel = new stdClass();
      $value = get_option( 'faz_geo_admin_overrides', $sentinel );
      echo base64_encode( serialize( array( 'exists' => $value !== $sentinel, 'value' => $value === $sentinel ? null : $value ) ) );
    `]);
    if (!/^[A-Za-z0-9+/]+={0,2}$/.test(snapshot)) throw new Error('Could not snapshot the original geographic overrides');
    overridesSnapshot = snapshot;
  });
  test.beforeEach(clearOverrides);
  test.afterAll(() => {
    if (overridesSnapshot === null) return;
    // STDIN avoids putting a potentially large saved map into one CLI argument.
    wp(['eval-file', '-'], `<?php
      $snapshot = unserialize( base64_decode( '${overridesSnapshot}', true ) );
      if ( $snapshot['exists'] ) {
        update_option( 'faz_geo_admin_overrides', $snapshot['value'] );
      } else {
        delete_option( 'faz_geo_admin_overrides' );
      }
    `);
  });

  test('the first override survives a reload, and later edits keep the others', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);

    const empty = await openOverrides(page);
    expect(Array.isArray(empty), 'an empty override map is a JSON object, not []').toBe(false);
    expect(empty).toEqual({});

    await addOverride(page, 'US', ['US']);

    // Survives a full reload of the settings page.
    await openOverrides(page);
    await waitForCountries(page, ['US']);

    // A second country keeps the first.
    await addOverride(page, 'DE', ['DE', 'US']);
    expect(Object.keys(JSON.parse(wp(['option', 'get', 'faz_geo_admin_overrides', '--format=json']))).sort()).toEqual(['DE', 'US']);
  });

  test('a second submit while a save is in flight is ignored', async ({ page, loginAsAdmin }) => {
    // Each save replaces the whole stored map, so two overlapping saves built
    // from the same map would silently drop one country.
    await loginAsAdmin(page);
    await openOverrides(page);
    let posts = 0;
    page.on('request', (r) => {
      if (r.method() === 'POST' && /\/faz\/v1\/geo\/overrides(?:[?]|$)/.test(r.url())) posts += 1;
    });
    await page.fill('#ov-country', 'US');
    await page.evaluate(() => {
      const form = document.getElementById('faz-geo-add-override') as HTMLFormElement;
      form.requestSubmit();
      form.requestSubmit();
    });
    await waitForCountries(page, ['US']);
    expect(posts, 'only one save request is sent').toBe(1);
  });

  test('deleting the last override and adding a new first one works', async ({ page, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await openOverrides(page);
    await addOverride(page, 'US', ['US']);

    page.on('dialog', (d) => d.accept());
    await page.click('#faz-geo-overrides-content button[data-country="US"]');
    await waitForCountries(page, []);

    await addOverride(page, 'FR', ['FR']);
    await openOverrides(page);
    await waitForCountries(page, ['FR']);
  });
});
