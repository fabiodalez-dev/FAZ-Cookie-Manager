/**
 * Box banner with the Pushdown preference centre (GitHub issue #392).
 *
 * Pushdown used to be offered only with the Full-width and Classic types, and
 * a Box fell back to the Popup preference centre. Box + Pushdown now opens the
 * categories inside the corner box, without a separate window.
 */
import type { Browser, Page } from '@playwright/test';
import { test, expect } from '../fixtures/wp-fixture';
import { resetBaseline } from '../utils/seed-defaults';
import { wpEval } from '../utils/wp-env';

const EDITOR = '/wp-admin/admin.php?page=faz-cookie-manager-banner';

/** Opens the banner editor once it has loaded the banner (the name can be empty). */
async function openEditor(page: Page) {
  const loaded = page.waitForResponse(
    (r) => /banners/.test(r.url()) && r.request().method() === 'GET' && r.status() === 200,
    { timeout: 30_000 },
  );
  await page.goto(EDITOR, { waitUntil: 'domcontentloaded' });
  await loaded;
  await page.waitForTimeout(500);
}

const restoreBanner = `
  global $wpdb;
  $wpdb->update( $wpdb->prefix . 'faz_banners', array( 'settings' => get_option( 'faz_e2e_boxpd_snapshot' ) ), array( 'banner_default' => 1 ) );
  \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();
  delete_option( 'faz_banner_template' );
  if ( function_exists( 'faz_clear_banner_template_cache' ) ) { faz_clear_banner_template_cache(); }
`;

function setBanner(type: string, ptype: string, position: string, law = 'gdpr') {
  wpEval(`
    global $wpdb;
    $t = $wpdb->prefix . 'faz_banners';
    $s = json_decode( $wpdb->get_var( "SELECT settings FROM {$t} WHERE banner_default = 1" ), true );
    $s['settings']['type'] = '${type}';
    $s['settings']['preferenceCenterType'] = '${ptype}';
    $s['settings']['position'] = '${position}';
    $s['settings']['applicableLaw'] = '${law}';
    $wpdb->update( $t, array( 'settings' => wp_json_encode( $s ) ), array( 'banner_default' => 1 ) );
    \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();
    delete_option( 'faz_banner_template' );
    if ( function_exists( 'faz_clear_banner_template_cache' ) ) { faz_clear_banner_template_cache(); }
  `);
}

async function visit(browser: Browser, width = 1280, height = 900) {
  const context = await browser.newContext({ viewport: { width, height } });
  const front = await context.newPage();
  await front.goto('/', { waitUntil: 'domcontentloaded' });
  const container = front.locator('.faz-consent-container').first();
  await expect(container.locator('[data-faz-tag="accept-button"]').first()).toBeVisible();
  return { context, front, container };
}

test.beforeAll(() => {
  resetBaseline();
  wpEval(`
    global $wpdb;
    update_option( 'faz_e2e_boxpd_snapshot', $wpdb->get_var( "SELECT settings FROM {$wpdb->prefix}faz_banners WHERE banner_default = 1" ), false );
  `);
});

test.beforeEach(() => {
  wpEval(restoreBanner);
});

test.afterAll(() => {
  wpEval(`${restoreBanner} delete_option( 'faz_e2e_boxpd_snapshot' );`);
});

test('the editor keeps Pushdown for a Box and saves it', async ({ page, loginAsAdmin }) => {
  await loginAsAdmin(page);
  await openEditor(page);
  await page.selectOption('#faz-b-type', 'box');
  await page.selectOption('#faz-b-pref-type', 'pushdown');
  await expect(page.locator('#faz-b-pref-type')).toHaveValue('pushdown');
  const response = page.waitForResponse(
    (r) => r.url().includes('banners') && !r.url().includes('preview')
      && (r.request().method() === 'PUT' || r.request().method() === 'POST'),
  );
  await page.click('#faz-b-save');
  expect((await response).status()).toBe(200);
  await openEditor(page);
  await expect(page.locator('#faz-b-type')).toHaveValue('box');
  await expect(page.locator('#faz-b-pref-type')).toHaveValue('pushdown');
});

for (const position of ['bottom-right', 'bottom-left'] as const) {
  test(`Customize opens the categories inside the ${position} box`, async ({ browser }) => {
    setBanner('box', 'pushdown', position);
    const { context, front, container } = await visit(browser);

    await expect(container).toHaveClass(new RegExp(`faz-box-${position}`));
    const closed = await container.boundingBox();
    if (!closed) throw new Error('no banner box');
    expect(Math.round(closed.width)).toBe(440);
    expect(Math.round(900 - closed.y - closed.height)).toBe(40);
    if (position === 'bottom-right') expect(Math.round(1280 - closed.x - closed.width)).toBe(40);
    else expect(Math.round(closed.x)).toBe(40);
    // No inline category toggles in the closed box: those belong to Classic.
    await expect(container.locator('.faz-category-direct-preview-wrapper')).toHaveCount(0);

    await container.locator('[data-faz-tag="settings-button"]').first().click();

    const wrapper = container.locator('.faz-preference-wrapper');
    await expect(wrapper).toBeVisible();
    await expect(front.locator('.faz-modal.faz-modal-open')).toHaveCount(0);
    await expect(wrapper.locator('[data-faz-tag="detail-save-button"]').first()).toBeVisible();
    const open = await container.boundingBox();
    if (!open) throw new Error('no open box');
    expect(Math.round(open.width)).toBe(440);
    expect(open.y).toBeGreaterThanOrEqual(0);
    expect(open.y + open.height).toBeLessThanOrEqual(900);

    // Saving from inside the box records the choice and closes the banner.
    await wrapper.locator('[data-faz-tag="detail-save-button"]').first().click();
    await expect(container.locator('[data-faz-tag="accept-button"]').first()).toBeHidden();
    const consent = (await context.cookies()).find((c) => c.name === 'fazcookie-consent');
    expect(decodeURIComponent(consent?.value || '')).toMatch(/action:yes/);
    await context.close();
  });
}

test('a short window scrolls the open categories inside the box', async ({ browser }) => {
  setBanner('box', 'pushdown', 'bottom-right');
  const { context, container } = await visit(browser, 1280, 500);
  await container.locator('[data-faz-tag="settings-button"]').first().click();
  await expect(container.locator('.faz-preference-wrapper')).toBeVisible();
  const open = await container.boundingBox();
  if (!open) throw new Error('no open box');
  expect(open.y).toBeGreaterThanOrEqual(0);
  await expect(container.locator('[data-faz-tag="detail-save-button"]').first()).toBeInViewport();
  await context.close();
});

test('on a phone the box takes the full width', async ({ browser }) => {
  setBanner('box', 'pushdown', 'bottom-right');
  const { context, front, container } = await visit(browser, 375, 700);
  const closed = await container.boundingBox();
  expect(Math.round(closed?.width || 0)).toBe(375);
  await container.locator('[data-faz-tag="settings-button"]').first().click();
  await expect(container.locator('.faz-preference-wrapper')).toBeVisible();
  expect(await front.evaluate(() => document.documentElement.scrollWidth > window.innerWidth)).toBe(false);
  await context.close();
});

test('a Box with the Popup preference centre is unchanged', async ({ browser }) => {
  setBanner('box', 'popup', 'bottom-right');
  const { context, front, container } = await visit(browser);
  await expect(container.locator('.faz-preference-wrapper')).toHaveCount(0);
  await container.locator('[data-faz-tag="settings-button"]').first().click();
  await expect(front.locator('.faz-modal.faz-modal-open')).toBeVisible();
  await context.close();
});

test('the close button in the open box goes back to the notice', async ({ browser }) => {
  setBanner('box', 'pushdown', 'bottom-right');
  const { context, container } = await visit(browser);
  const settings = container.locator('[data-faz-tag="settings-button"]').first();
  await settings.click();
  const wrapper = container.locator('.faz-preference-wrapper');
  await expect(wrapper).toBeVisible();
  await expect(container.locator('.faz-consent-bar')).toBeHidden();
  // With the notice hidden, all three choices stay in the panel.
  for (const tag of ['detail-accept-button', 'detail-save-button', 'detail-reject-button']) {
    await expect(wrapper.locator(`[data-faz-tag="${tag}"]`).first()).toBeVisible();
  }

  await wrapper.locator('[data-faz-tag="detail-close"]').first().click();
  await expect(wrapper).toBeHidden();
  await expect(container.locator('[data-faz-tag="accept-button"]').first()).toBeVisible();
  await expect(settings).toHaveAttribute('aria-expanded', 'false');
  await context.close();
});
