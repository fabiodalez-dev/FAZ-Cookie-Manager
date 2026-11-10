/**
 * Brand logo beside the title (GitHub issue #393).
 *
 * The logo used to sit above the title in every banner, and putting it beside
 * the text took custom CSS. "Logo position" now offers "Beside the title and
 * text". Empty, the setting changes nothing.
 */
import type { Browser, Page } from '@playwright/test';
import { test, expect } from '../fixtures/wp-fixture';
import { resetBaseline } from '../utils/seed-defaults';
import { wpEval } from '../utils/wp-env';

const EDITOR = '/wp-admin/admin.php?page=faz-cookie-manager-banner';

/**
 * Opens the banner editor once the form holds the banner. The editor asks for
 * its first preview only after filling the form from the stored banner, so the
 * preview request is the signal; the banner name can be empty.
 */
async function openEditor(page: Page) {
  const filled = page.waitForResponse(
    (r) => /banners\/preview/.test(r.url()) && r.request().method() === 'POST' && r.status() === 200,
    { timeout: 30_000 },
  );
  await page.goto(EDITOR, { waitUntil: 'domcontentloaded' });
  await filled;
}

// resetBaseline() does not restore the banner row, so it is kept and put back.
const restoreBanner = `
  global $wpdb;
  $wpdb->update( $wpdb->prefix . 'faz_banners', array( 'settings' => get_option( 'faz_e2e_logo_snapshot' ) ), array( 'banner_default' => 1 ) );
  \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();
  delete_option( 'faz_banner_template' );
  if ( function_exists( 'faz_clear_banner_template_cache' ) ) { faz_clear_banner_template_cache(); }
`;

/** Writes the logo and banner type into the stored banner, as a save would. */
function setBanner(type: string, placement: string, preferenceCenterType = 'popup', logoOn = true) {
  wpEval(`
    global $wpdb;
    $t = $wpdb->prefix . 'faz_banners';
    $s = json_decode( $wpdb->get_var( "SELECT settings FROM {$t} WHERE banner_default = 1" ), true );
    $s['settings']['type'] = '${type}';
    $s['settings']['preferenceCenterType'] = '${preferenceCenterType}';
    $s['config']['notice']['elements']['brandLogo']['status'] = ${logoOn ? 'true' : 'false'};
    $s['config']['notice']['elements']['brandLogo']['meta']['url'] = plugins_url( 'frontend/images/cookie.png', WP_PLUGIN_DIR . '/faz-cookie-manager/faz-cookie-manager.php' );
    $s['config']['notice']['elements']['brandLogo']['meta']['placement'] = '${placement}';
    $wpdb->update( $t, array( 'settings' => wp_json_encode( $s ) ), array( 'banner_default' => 1 ) );
    \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();
    delete_option( 'faz_banner_template' );
    if ( function_exists( 'faz_clear_banner_template_cache' ) ) { faz_clear_banner_template_cache(); }
  `);
}

/** Opens the home page with no consent cookie and returns the logo, title, text and button boxes. */
async function layout(browser: Browser, width = 1280) {
  const context = await browser.newContext({ viewport: { width, height: 900 } });
  const front = await context.newPage();
  await front.goto('/', { waitUntil: 'domcontentloaded' });
  const bar = front.locator('.faz-consent-container .faz-consent-bar').first();
  await expect(bar.locator('[data-faz-tag="accept-button"]').first()).toBeVisible();
  await expect(bar.locator('.faz-custom-brand-logo')).toBeVisible();
  await front.waitForFunction(() => {
    const img = document.querySelector('.faz-consent-bar .faz-custom-brand-logo') as HTMLImageElement | null;
    return !!img && img.complete && img.naturalWidth > 0;
  });
  const box = async (selector: string) => {
    const b = await bar.locator(selector).first().boundingBox();
    if (!b) throw new Error(`${selector} has no box`);
    return b;
  };
  const result = {
    bar: await box(':scope'),
    logo: await box('.faz-custom-brand-logo'),
    title: await box('[data-faz-tag="title"]'),
    text: await box('[data-faz-tag="description"]'),
    buttons: await box('[data-faz-tag="notice-buttons"]'),
    // The text's own right edge: the description's box includes its padding.
    textRight: await bar.locator('[data-faz-tag="description"]').first().evaluate((el) => {
      const range = document.createRange();
      range.selectNodeContents(el);
      return range.getBoundingClientRect().right;
    }),
    overflow: await front.evaluate(() => document.documentElement.scrollWidth > window.innerWidth),
  };
  await context.close();
  return result;
}

test.beforeAll(() => {
  resetBaseline();
  wpEval(`
    global $wpdb;
    update_option( 'faz_e2e_logo_snapshot', $wpdb->get_var( "SELECT settings FROM {$wpdb->prefix}faz_banners WHERE banner_default = 1" ), false );
  `);
});

test.beforeEach(() => {
  wpEval(restoreBanner);
});

test.afterAll(() => {
  wpEval(`${restoreBanner} delete_option( 'faz_e2e_logo_snapshot' );`);
});

test('the setting is saved from the editor and read back', async ({ page, loginAsAdmin }) => {
  await loginAsAdmin(page);
  await openEditor(page);
  await expect(page.locator('#faz-b-brandlogo-placement')).toHaveValue('');
  await page.locator('#faz-b-brandlogo-toggle').click();
  await page.selectOption('#faz-b-brandlogo-placement', 'beside');
  const response = page.waitForResponse(
    (r) => r.url().includes('banners') && !r.url().includes('preview')
      && (r.request().method() === 'PUT' || r.request().method() === 'POST'),
  );
  await page.click('#faz-b-save');
  expect((await response).status()).toBe(200);

  const stored = wpEval(`
    global $wpdb;
    $s = json_decode( $wpdb->get_var( "SELECT settings FROM {$wpdb->prefix}faz_banners WHERE banner_default = 1" ), true );
    echo $s['config']['notice']['elements']['brandLogo']['meta']['placement'];
  `);
  expect(stored.trim()).toBe('beside');

  await openEditor(page);
  await expect(page.locator('#faz-b-brandlogo-placement')).toHaveValue('beside');
});

test('an unknown value is stored as empty', async () => {
  const stored = wpEval(`
    $settings = \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Banner::sanitize_settings(
      array( \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Banner::class, 'sanitize_option' ),
      array( 'config' => array( 'notice' => array( 'elements' => array( 'brandLogo' => array( 'meta' => array( 'placement' => 'left; display:none' ) ) ) ) ) ),
      json_decode( file_get_contents( WP_PLUGIN_DIR . '/faz-cookie-manager/admin/modules/banners/includes/configs/gdpr.json' ), true )
    );
    echo wp_json_encode( $settings['config']['notice']['elements']['brandLogo']['meta']['placement'] );
  `);
  expect(stored.trim()).toBe('""');
});

test('empty placement keeps the logo above the title', async ({ browser }) => {
  setBanner('box', '');
  const l = await layout(browser);
  expect(l.logo.y + l.logo.height).toBeLessThanOrEqual(l.title.y + 1);
});

for (const [type, pref] of [['box', 'popup'], ['banner', 'popup'], ['classic', 'pushdown']] as const) {
  test(`beside: the ${type} banner puts the logo left of the title, text and buttons`, async ({ browser }) => {
    setBanner(type, 'beside', pref);
    const l = await layout(browser);
    const right = l.logo.x + l.logo.width;
    // Logo in its own column, the text column starting 16px after it.
    for (const part of [l.title, l.text, l.buttons]) {
      expect(part.x).toBeGreaterThanOrEqual(right + 15);
    }
    expect(Math.abs(l.title.x - right - 16)).toBeLessThanOrEqual(1);
    // Top-aligned with the title, and nothing pushed below it.
    expect(Math.abs(l.logo.y - l.title.y)).toBeLessThanOrEqual(13);
    expect(l.overflow).toBe(false);
  });
}

test('beside on a phone: same 16px gap, no horizontal scroll', async ({ browser }) => {
  setBanner('box', 'beside');
  const l = await layout(browser, 375);
  const right = l.logo.x + l.logo.width;
  expect(Math.abs(l.title.x - right - 16)).toBeLessThanOrEqual(1);
  expect(l.logo.x).toBeGreaterThanOrEqual(l.bar.x + 20);
  expect(l.textRight).toBeLessThanOrEqual(l.bar.x + l.bar.width - 20);
  expect(l.overflow).toBe(false);
});

test('with the logo switched off, beside adds nothing and the text keeps its padding', async ({ browser }) => {
  setBanner('box', 'beside', 'popup', false);
  const context = await browser.newContext({ viewport: { width: 375, height: 700 } });
  const front = await context.newPage();
  await front.goto('/', { waitUntil: 'domcontentloaded' });
  const bar = front.locator('.faz-consent-container .faz-consent-bar').first();
  await expect(bar.locator('[data-faz-tag="accept-button"]').first()).toBeVisible();
  await expect(bar.locator('.faz-custom-brand-logo')).toHaveCount(0);
  expect(await bar.evaluate((el) => getComputedStyle(el).display)).not.toBe('grid');
  const barBox = await bar.boundingBox();
  const textLeft = await bar.locator('[data-faz-tag="description"]').first().evaluate((el) => {
    const range = document.createRange();
    range.selectNodeContents(el);
    return range.getBoundingClientRect().left;
  });
  expect(textLeft).toBeGreaterThanOrEqual((barBox?.x || 0) + 20);
  await context.close();
});
