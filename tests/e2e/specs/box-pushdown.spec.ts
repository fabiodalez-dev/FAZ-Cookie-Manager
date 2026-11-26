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

for (const width of [375, 500]) {
  test(`at ${width}px Accept and Reject have the same width in the default phone layout`, async ({ browser }) => {
    setBanner('box', 'pushdown', 'bottom-right');
    const { context, container } = await visit(browser, width, 800);
    const box = async (cls: string) => (await container.locator(`.faz-notice-btn-wrapper .${cls}`).first().boundingBox())?.width || 0;
    const accept = await box('faz-btn-accept');
    expect(accept).toBeGreaterThan(0);
    expect(Math.abs(accept - (await box('faz-btn-reject')))).toBeLessThanOrEqual(1);
    expect(Math.abs(accept - (await box('faz-btn-customize')))).toBeLessThanOrEqual(1);
    await context.close();
  });
}

// On a desktop the box leaves the three buttons 390px (329px with the logo
// beside). Sized from their labels on a row that wraps, the Italian ones put
// Accept alone on a second row, twice as wide as Reject. The pair now shares a
// row in equal parts in every allowed order, and Customise has its own.
const italianLabels = { settings: 'Personalizza', reject: 'Rifiuta tutto', accept: 'Accettare tutto' };

function setLabelsOrderAndLogo(order: string, logoBeside: boolean) {
  wpEval(`
    global $wpdb;
    $t = $wpdb->prefix . 'faz_banners';
    $row = $wpdb->get_row( "SELECT settings, contents FROM {$t} WHERE banner_default = 1", ARRAY_A );
    if ( false === get_option( 'faz_e2e_boxpd_contents', false ) ) {
      update_option( 'faz_e2e_boxpd_contents', $row['contents'], false );
    }
    $contents = json_decode( $row['contents'], true );
    if ( ! is_array( $contents ) ) { $contents = array(); }
    if ( ! isset( $contents['en'] ) ) { $contents['en'] = array(); }
    foreach ( array_keys( $contents ) as $lang ) {
      if ( is_string( $contents[ $lang ] ) ) { $contents[ $lang ] = json_decode( $contents[ $lang ], true ); }
      $contents[ $lang ]['notice']['elements']['buttons']['elements']['settings'] = '${italianLabels.settings}';
      $contents[ $lang ]['notice']['elements']['buttons']['elements']['reject'] = '${italianLabels.reject}';
      $contents[ $lang ]['notice']['elements']['buttons']['elements']['accept'] = '${italianLabels.accept}';
    }
    $s = json_decode( $row['settings'], true );
    $s['settings']['buttonOrder'] = '${order}';
    $s['config']['notice']['elements']['brandLogo']['status'] = ${logoBeside ? 'true' : 'false'};
    $s['config']['notice']['elements']['brandLogo']['meta']['url'] = plugins_url( 'frontend/images/cookie.png', WP_PLUGIN_DIR . '/faz-cookie-manager/faz-cookie-manager.php' );
    $s['config']['notice']['elements']['brandLogo']['meta']['placement'] = '${logoBeside ? 'beside' : 'above'}';
    $wpdb->update( $t, array( 'settings' => wp_json_encode( $s ), 'contents' => wp_json_encode( $contents ) ), array( 'banner_default' => 1 ) );
    \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();
    delete_option( 'faz_banner_template' );
    if ( function_exists( 'faz_clear_banner_template_cache' ) ) { faz_clear_banner_template_cache(); }
  `);
}

const restoreContents = `
  global $wpdb;
  $saved = get_option( 'faz_e2e_boxpd_contents', false );
  if ( false !== $saved ) {
    $wpdb->update( $wpdb->prefix . 'faz_banners', array( 'contents' => $saved ), array( 'banner_default' => 1 ) );
    delete_option( 'faz_e2e_boxpd_contents' );
  }
`;

for (const logoBeside of [false, true]) {
  for (const order of ['settings-reject-accept', 'settings-accept-reject', 'reject-accept-settings', 'accept-reject-settings']) {
    test(`at 1280px Accept and Reject share a row in equal parts with Italian labels (${order}, ${logoBeside ? 'logo beside' : 'no logo'})`, async ({ browser }) => {
      setBanner('box', 'pushdown', 'bottom-right');
      setLabelsOrderAndLogo(order, logoBeside);
      try {
        const { context, container } = await visit(browser, 1280, 900);
        const row = container.locator('.faz-notice-btn-wrapper');
        await expect(row.locator('.faz-btn-accept').first()).toHaveText(italianLabels.accept);
        await expect(row.locator('.faz-btn-reject').first()).toHaveText(italianLabels.reject);
        if (logoBeside) await expect(container.locator('.faz-custom-brand-logo')).toBeVisible();
        const box = async (cls: string) => {
          const b = await row.locator(`.${cls}`).first().boundingBox();
          if (!b) throw new Error(`${cls} has no box`);
          return b;
        };
        const accept = await box('faz-btn-accept');
        const reject = await box('faz-btn-reject');
        const customize = await box('faz-btn-customize');
        // Same width, same height, same row: neither choice outweighs the other.
        expect(Math.abs(accept.width - reject.width)).toBeLessThanOrEqual(1);
        expect(Math.abs(accept.height - reject.height)).toBeLessThanOrEqual(1);
        expect(Math.abs(accept.y - reject.y)).toBeLessThanOrEqual(1);
        // Customise is on a row of its own, on the side the order puts it.
        if (order.startsWith('settings')) expect(customize.y + customize.height).toBeLessThanOrEqual(accept.y + 1);
        else expect(customize.y).toBeGreaterThanOrEqual(accept.y + accept.height - 1);
        // Markup order is visual order: the first of the pair is on the left.
        const first = order.indexOf('accept') < order.indexOf('reject') ? accept : reject;
        const second = first === accept ? reject : accept;
        expect(first.x).toBeLessThan(second.x);
        // No label is cut by its button.
        const clipped = await row.locator('.faz-btn').evaluateAll((buttons) => buttons.some((b) => b.scrollWidth > b.clientWidth + 1));
        expect(clipped).toBe(false);
        await context.close();
      } finally {
        wpEval(restoreContents);
      }
    });
  }
}

test('in a right-to-left language the button row lines up with the text', async ({ browser }) => {
  setBanner('box', 'pushdown', 'bottom-right');
  const { context, container } = await visit(browser);
  const margins = await container.evaluate((el) => {
    el.querySelector('[data-faz-tag="notice"]')?.classList.add('faz-rtl');
    const row = getComputedStyle(el.querySelector('.faz-notice-btn-wrapper')!);
    return [row.marginLeft, row.marginRight];
  });
  expect(margins).toEqual(['0px', '0px']);
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

test('the compact phone layout keeps Accept and Reject the same width, and the chevron off the label', async ({ browser }) => {
  setBanner('box', 'pushdown', 'bottom-right');
  wpEval(`
    $s = get_option( 'faz_settings', array() );
    update_option( 'faz_e2e_boxpd_layout', isset( $s['banner_control']['mobile_layout'] ) ? $s['banner_control']['mobile_layout'] : '', false );
    $s['banner_control']['mobile_layout'] = 'compact';
    update_option( 'faz_settings', $s );
    delete_option( 'faz_banner_template' );
  `);
  try {
    const { context, container } = await visit(browser, 375, 700);
    const width = async (cls: string) => (await container.locator(`.faz-notice-btn-wrapper .${cls}`).first().boundingBox())?.width || 0;
    expect(Math.abs((await width('faz-btn-accept')) - (await width('faz-btn-reject')))).toBeLessThanOrEqual(1);
    const customize = container.locator('.faz-notice-btn-wrapper .faz-btn-customize').first();
    expect(await customize.evaluate((el) => parseFloat(getComputedStyle(el).paddingRight))).toBeGreaterThanOrEqual(28);
    await context.close();
  } finally {
    wpEval(`
      $s = get_option( 'faz_settings', array() );
      $s['banner_control']['mobile_layout'] = get_option( 'faz_e2e_boxpd_layout', '' );
      update_option( 'faz_settings', $s );
      delete_option( 'faz_e2e_boxpd_layout' );
      delete_option( 'faz_banner_template' );
    `);
  }
});
