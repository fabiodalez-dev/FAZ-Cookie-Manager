/**
 * Banner editor: switching the law reloads the law-appropriate notice copy.
 *
 * The CCPA default description names the "Do Not Sell or Share My Personal
 * Information" link; the GDPR default does not. Before this, changing the law
 * updated donotSell.status but left the old copy in place, so a CCPA
 * description could survive on a GDPR banner and promise a link the layout no
 * longer renders (the support-forum confusion). The editor now reloads the
 * default description for the new law — but only when the current copy is still
 * the previous law's untouched default, so customised text is never clobbered.
 */

import { test, expect } from '../fixtures/wp-fixture';
import type { Page } from '@playwright/test';
import { wpEval } from '../utils/wp-env';

const WP_BASE = process.env.WP_BASE_URL ?? 'http://127.0.0.1:9998';

/** Seed the default banner to CCPA with the exact bundled CCPA default copy
 *  (so it's recognised as un-customised) and return the original for restore. */
function seedCcpaDefaultCopy(): string {
  return wpEval(`
    global $wpdb;
    $row = $wpdb->get_row( "SELECT banner_id, settings, contents FROM {$wpdb->prefix}faz_banners WHERE banner_default = 1 LIMIT 1" );
    if ( ! $row ) { echo wp_json_encode( array( 'error' => 'no_default_banner' ) ); exit; }
    $original_settings = $row->settings;
    $original_contents = $row->contents;
    $settings = json_decode( $row->settings, true );
    if ( ! isset( $settings['settings'] ) || ! is_array( $settings['settings'] ) ) { $settings['settings'] = array(); }
    $settings['settings']['applicableLaw'] = 'ccpa';
    // Put the bundled CCPA default description into the default language so the
    // editor sees it as un-customised and the law-switch reload kicks in.
    $descs = \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Banner::get_law_notice_descriptions( 'en' );
    $contents = json_decode( $row->contents, true );
    if ( ! is_array( $contents ) ) { $contents = array(); }
    foreach ( array_keys( $contents ) as $lang ) {
      if ( ! isset( $contents[ $lang ]['notice']['elements'] ) || ! is_array( $contents[ $lang ]['notice']['elements'] ) ) { continue; }
      $contents[ $lang ]['notice']['elements']['description'] = $descs['ccpa'];
    }
    if ( empty( $contents ) ) { $contents = array( 'en' => array( 'notice' => array( 'elements' => array( 'description' => $descs['ccpa'] ) ) ) ); }
    $wpdb->update( $wpdb->prefix . 'faz_banners', array( 'settings' => wp_json_encode( $settings ), 'contents' => wp_json_encode( $contents ) ), array( 'banner_id' => $row->banner_id ), array( '%s', '%s' ), array( '%d' ) );
    \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();
    faz_clear_banner_template_cache();
    echo wp_json_encode( array( 'banner_id' => $row->banner_id, 'original_settings' => $original_settings, 'original_contents' => $original_contents ) );
  `).trim();
}

function restoreBanner(meta: { banner_id?: number; original_settings?: string; original_contents?: string }): void {
  if (!meta || !meta.banner_id) return;
  const s = Buffer.from(meta.original_settings ?? '{}', 'utf8').toString('base64');
  const c = Buffer.from(meta.original_contents ?? '{}', 'utf8').toString('base64');
  wpEval(`
    global $wpdb;
    $wpdb->update( $wpdb->prefix . 'faz_banners', array( 'settings' => base64_decode( '${s}' ), 'contents' => base64_decode( '${c}' ) ), array( 'banner_id' => ${meta.banner_id} ), array( '%s', '%s' ), array( '%d' ) );
    \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();
    faz_clear_banner_template_cache();
  `);
}

async function goToBannerPage(page: Page) {
  await page.goto(`${WP_BASE}/wp-admin/admin.php?page=faz-cookie-manager-banner`, { waitUntil: 'domcontentloaded', timeout: 45_000 });
  await page.waitForFunction(() => {
    const el = document.getElementById('faz-b-type') as HTMLSelectElement | null;
    return !!el && el.value !== '';
  }, { timeout: 10_000 });
}

/** Banner admin tabs hide their panels; clicking the tab button is required
 *  before interacting with elements inside a non-active tab. The law dropdown
 *  lives in "general", the notice editor in "content". */
async function openTab(page: Page, tab: string) {
  await page.click(`button.faz-tab[data-tab="${tab}"]`);
  await page.waitForSelector(`#tab-${tab}.active`, { timeout: 5_000 });
}

/** The TinyMCE instance is initialised on page load, but a fast test can race
 *  it; wait until the editor exists before reading/writing it. */
async function ensureNoticeEditorReady(page: Page) {
  await page.waitForFunction(() => {
    const tm = (window as unknown as { tinyMCE?: { get: (id: string) => unknown } }).tinyMCE;
    return !!(tm && tm.get('faz-b-notice-desc'));
  }, { timeout: 10_000 });
}

/** Read the notice description straight from TinyMCE (works on a hidden tab). */
function noticeDescription(page: Page): Promise<string> {
  return page.evaluate(() => {
    const tm = (window as unknown as { tinyMCE?: { get: (id: string) => { getContent: () => string } | null } }).tinyMCE;
    const ed = tm && tm.get('faz-b-notice-desc');
    return ed ? ed.getContent() : (document.getElementById('faz-b-notice-desc') as HTMLTextAreaElement | null)?.value ?? '';
  });
}

test.describe('Banner law switch reloads the notice copy', () => {
  test('CCPA default copy is swapped for GDPR copy when the law changes (and back)', async ({ page, loginAsAdmin }) => {
    const meta = JSON.parse(seedCcpaDefaultCopy());
    let cleanupErr: unknown;
    try {
      expect(meta.error, 'install has a default banner').toBeUndefined();
      await loginAsAdmin(page);
      await goToBannerPage(page);

      // Starts on CCPA with the CCPA copy → mentions Do Not Sell.
      await expect(page.locator('#faz-b-law')).toHaveValue('ccpa');
      await openTab(page, 'content');
      await ensureNoticeEditorReady(page);
      await expect.poll(() => noticeDescription(page), { message: 'starts with CCPA copy' })
        .toMatch(/do not sell/i);

      // Switch to GDPR → un-customised copy reloads to the GDPR default, which
      // does NOT mention Do Not Sell.
      await openTab(page, 'general');
      await page.selectOption('#faz-b-law', 'gdpr');
      await openTab(page, 'content');
      await expect.poll(() => noticeDescription(page), { message: 'GDPR copy no longer mentions Do Not Sell' })
        .not.toMatch(/do not sell/i);

      // Switch back to CCPA → the CCPA copy (with the link) returns.
      await openTab(page, 'general');
      await page.selectOption('#faz-b-law', 'ccpa');
      await openTab(page, 'content');
      await expect.poll(() => noticeDescription(page), { message: 'CCPA copy restored' })
        .toMatch(/do not sell/i);
    } finally {
      try { restoreBanner(meta); } catch (e) { cleanupErr = e; }
    }
    if (cleanupErr) throw cleanupErr;
  });

  test('a customised description is not clobbered; a mismatch hint is shown', async ({ page, loginAsAdmin }) => {
    const meta = JSON.parse(seedCcpaDefaultCopy());
    let cleanupErr: unknown;
    try {
      expect(meta.error).toBeUndefined();
      await loginAsAdmin(page);
      await goToBannerPage(page);

      // Customise the description with a sentence that still mentions Do Not Sell.
      await openTab(page, 'content');
      await ensureNoticeEditorReady(page);
      const custom = '<p>Custom CCPA copy — Do Not Sell my info, please.</p>';
      await page.evaluate((html) => {
        const tm = (window as unknown as { tinyMCE?: { get: (id: string) => { setContent: (h: string) => void } | null } }).tinyMCE;
        const ed = tm && tm.get('faz-b-notice-desc');
        if (ed) ed.setContent(html);
      }, custom);

      // Switch to GDPR: the custom copy must be left intact, and the mismatch
      // hint must show because the custom copy still names the Do-Not-Sell link.
      await openTab(page, 'general');
      await page.selectOption('#faz-b-law', 'gdpr');
      await expect(page.locator('#faz-b-law-content-hint'), 'mismatch hint shown for custom copy').toBeVisible();
      await openTab(page, 'content');
      await expect.poll(() => noticeDescription(page), { message: 'custom copy untouched' })
        .toContain('Custom CCPA copy');
    } finally {
      try { restoreBanner(meta); } catch (e) { cleanupErr = e; }
    }
    if (cleanupErr) throw cleanupErr;
  });
});

/** Strip tags + collapse whitespace so editor HTML compares to a stored default. */
function stripNorm(s: string): string {
  return s.replace(/<[^>]*>/g, ' ').replace(/&nbsp;/g, ' ').replace(/\s+/g, ' ').trim();
}

/** Enable en+it, set the default banner to CCPA with each language carrying its
 *  OWN bundled CCPA default copy. Returns the originals (banner + faz_settings)
 *  plus the per-language gdpr/ccpa defaults for assertions. */
function seedMultilangCcpa(): string {
  return wpEval(`
    global $wpdb;
    $row = $wpdb->get_row( "SELECT banner_id, settings, contents FROM {$wpdb->prefix}faz_banners WHERE banner_default = 1 LIMIT 1" );
    if ( ! $row ) { echo wp_json_encode( array( 'error' => 'no_default_banner' ) ); exit; }
    $orig_settings        = get_option( 'faz_settings' );
    $orig_banner_settings = $row->settings;
    $orig_banner_contents = $row->contents;

    $settings = is_array( $orig_settings ) ? $orig_settings : array();
    if ( ! isset( $settings['languages'] ) || ! is_array( $settings['languages'] ) ) { $settings['languages'] = array(); }
    $settings['languages']['selected'] = array( 'en', 'it' );
    $settings['languages']['default']  = 'en';
    update_option( 'faz_settings', $settings );

    $bset = json_decode( $row->settings, true );
    if ( ! isset( $bset['settings'] ) || ! is_array( $bset['settings'] ) ) { $bset['settings'] = array(); }
    $bset['settings']['applicableLaw'] = 'ccpa';

    $en = \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Banner::get_law_notice_descriptions( 'en' );
    $it = \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Banner::get_law_notice_descriptions( 'it' );
    $contents = json_decode( $row->contents, true );
    if ( ! is_array( $contents ) ) { $contents = array(); }
    foreach ( array( 'en' => $en, 'it' => $it ) as $lang => $descs ) {
      if ( ! isset( $contents[ $lang ] ) || ! is_array( $contents[ $lang ] ) ) { $contents[ $lang ] = array(); }
      if ( ! isset( $contents[ $lang ]['notice']['elements'] ) || ! is_array( $contents[ $lang ]['notice']['elements'] ) ) {
        $contents[ $lang ]['notice'] = array( 'elements' => array() );
      }
      $contents[ $lang ]['notice']['elements']['description'] = $descs['ccpa'];
    }
    $wpdb->update( $wpdb->prefix . 'faz_banners', array( 'settings' => wp_json_encode( $bset ), 'contents' => wp_json_encode( $contents ) ), array( 'banner_id' => $row->banner_id ), array( '%s', '%s' ), array( '%d' ) );
    \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();
    faz_clear_banner_template_cache();
    echo wp_json_encode( array(
      'banner_id'            => $row->banner_id,
      'orig_banner_settings' => $orig_banner_settings,
      'orig_banner_contents' => $orig_banner_contents,
      'orig_settings'        => wp_json_encode( $orig_settings ),
      'en'                   => $en,
      'it'                   => $it,
    ) );
  `).trim();
}

function restoreMultilang(meta: { banner_id?: number; orig_banner_settings?: string; orig_banner_contents?: string; orig_settings?: string }): void {
  if (!meta || !meta.banner_id) return;
  const s = Buffer.from(meta.orig_banner_settings ?? '{}', 'utf8').toString('base64');
  const c = Buffer.from(meta.orig_banner_contents ?? '{}', 'utf8').toString('base64');
  const o = Buffer.from(meta.orig_settings ?? 'null', 'utf8').toString('base64');
  wpEval(`
    global $wpdb;
    $wpdb->update( $wpdb->prefix . 'faz_banners', array( 'settings' => base64_decode( '${s}' ), 'contents' => base64_decode( '${c}' ) ), array( 'banner_id' => ${meta.banner_id} ), array( '%s', '%s' ), array( '%d' ) );
    $orig = json_decode( base64_decode( '${o}' ), true );
    if ( null === $orig ) { delete_option( 'faz_settings' ); } else { update_option( 'faz_settings', $orig ); }
    \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();
    faz_clear_banner_template_cache();
  `);
}

/** Switch the editor's content language (Content tab) and wait for the editor
 *  to repaint the newly-loaded copy. */
async function switchEditorLanguage(page: Page, lang: string): Promise<void> {
  await openTab(page, 'content');
  await page.selectOption('#faz-b-content-lang', lang);
  await ensureNoticeEditorReady(page);
}

test.describe('Banner law switch reloads EVERY language (not just the open one)', () => {
  test('switching CCPA→GDPR reloads the non-visible Italian translation too', async ({ page, loginAsAdmin }) => {
    const meta = JSON.parse(seedMultilangCcpa());
    let cleanupErr: unknown;
    try {
      expect(meta.error, 'install has a default banner').toBeUndefined();
      await loginAsAdmin(page);
      await goToBannerPage(page);

      // Per-law defaults are localized for BOTH selected languages (finding #2:
      // no single-language English-only cache).
      const localized = await page.evaluate(() => {
        const w = window as unknown as { fazConfig?: { lawNoticeDescriptions?: Record<string, { gdpr: string; ccpa: string }> } };
        const m = (w.fazConfig && w.fazConfig.lawNoticeDescriptions) || {};
        return { langs: Object.keys(m), enCcpa: m.en?.ccpa ?? '', itCcpa: m.it?.ccpa ?? '' };
      });
      expect(localized.langs, 'localized for en + it').toEqual(expect.arrayContaining(['en', 'it']));
      expect(localized.itCcpa.length, 'Italian CCPA default is present (not English)').toBeGreaterThan(0);

      // English starts on its CCPA copy.
      await expect(page.locator('#faz-b-law')).toHaveValue('ccpa');
      await openTab(page, 'content');
      await ensureNoticeEditorReady(page);
      await expect.poll(() => noticeDescription(page).then(stripNorm), { message: 'en starts CCPA' })
        .toBe(stripNorm(meta.en.ccpa));

      // Switch the LAW to GDPR (General tab) while Italian is NOT the open tab.
      await openTab(page, 'general');
      await page.selectOption('#faz-b-law', 'gdpr');

      // English (the visible language) reloaded to the GDPR default.
      await openTab(page, 'content');
      await expect.poll(() => noticeDescription(page).then(stripNorm), { message: 'en reloaded to GDPR' })
        .toBe(stripNorm(meta.en.gdpr));

      // The Italian translation — never opened during the law change — must ALSO
      // have been reloaded to the Italian GDPR default (finding #3). Open it now
      // and read what populateContents() loads from the updated state.
      await switchEditorLanguage(page, 'it');
      await expect.poll(() => noticeDescription(page).then(stripNorm), { message: 'it reloaded to GDPR (non-visible language)' })
        .toBe(stripNorm(meta.it.gdpr));
      await expect.poll(() => noticeDescription(page).then(stripNorm), { message: 'it no longer the CCPA copy' })
        .not.toBe(stripNorm(meta.it.ccpa));
    } finally {
      try { restoreMultilang(meta); } catch (e) { cleanupErr = e; }
    }
    if (cleanupErr) throw cleanupErr;
  });
});

test.describe('Law/content mismatch hint refreshes outside the law-change event', () => {
  test('a saved GDPR banner already carrying CCPA copy shows the hint on load', async ({ page, loginAsAdmin }) => {
    // Seed a GDPR banner whose copy is the CCPA default (names Do-Not-Sell) —
    // the exact stranded state from the support thread. The hint must surface on
    // load, not only after a law change (finding #4).
    const meta = JSON.parse(seedGdprWithCcpaCopy());
    let cleanupErr: unknown;
    try {
      expect(meta.error, 'install has a default banner').toBeUndefined();
      await loginAsAdmin(page);
      await goToBannerPage(page);

      await expect(page.locator('#faz-b-law'), 'law loaded as GDPR').toHaveValue('gdpr');
      await expect(page.locator('#faz-b-law-content-hint'), 'mismatch hint visible on load').toBeVisible();
    } finally {
      try { restoreBanner(meta); } catch (e) { cleanupErr = e; }
    }
    if (cleanupErr) throw cleanupErr;
  });

  test('detection is language-agnostic: Italian copy + [faz_do_not_sell] shortcode (no English phrase) still flags the mismatch', async ({ page, loginAsAdmin }) => {
    // Italian prose with the real opt-out shortcode and NO English "do not sell"
    // phrase — the old English-only regex would have missed this entirely.
    const custom = '<p>Per disattivare la vendita dei tuoi dati personali usa questo controllo: [faz_do_not_sell]</p>';
    const meta = JSON.parse(seedGdprWithCustomCopy(custom));
    let cleanupErr: unknown;
    try {
      expect(meta.error, 'install has a default banner').toBeUndefined();
      await loginAsAdmin(page);
      await goToBannerPage(page);

      await expect(page.locator('#faz-b-law'), 'law loaded as GDPR').toHaveValue('gdpr');
      await expect(page.locator('#faz-b-law-content-hint'), 'hint flags the shortcode/Italian copy under GDPR').toBeVisible();
    } finally {
      try { restoreBanner(meta); } catch (e) { cleanupErr = e; }
    }
    if (cleanupErr) throw cleanupErr;
  });
});

/** Default banner → applicableLaw=gdpr, donotSell off, every language's copy set
 *  to an arbitrary custom HTML string (base64-passed to dodge shell escaping). */
function seedGdprWithCustomCopy(html: string): string {
  const b64 = Buffer.from(html, 'utf8').toString('base64');
  return wpEval(`
    global $wpdb;
    $row = $wpdb->get_row( "SELECT banner_id, settings, contents FROM {$wpdb->prefix}faz_banners WHERE banner_default = 1 LIMIT 1" );
    if ( ! $row ) { echo wp_json_encode( array( 'error' => 'no_default_banner' ) ); exit; }
    $original_settings = $row->settings;
    $original_contents = $row->contents;
    $settings = json_decode( $row->settings, true );
    if ( ! isset( $settings['settings'] ) || ! is_array( $settings['settings'] ) ) { $settings['settings'] = array(); }
    $settings['settings']['applicableLaw'] = 'gdpr';
    $custom = base64_decode( '${b64}' );
    $contents = json_decode( $row->contents, true );
    if ( ! is_array( $contents ) || empty( $contents ) ) { $contents = array( 'en' => array( 'notice' => array( 'elements' => array() ) ) ); }
    foreach ( array_keys( $contents ) as $lang ) {
      if ( ! isset( $contents[ $lang ]['notice']['elements'] ) || ! is_array( $contents[ $lang ]['notice']['elements'] ) ) {
        $contents[ $lang ]['notice'] = array( 'elements' => array() );
      }
      $contents[ $lang ]['notice']['elements']['description'] = $custom;
      if ( isset( $contents[ $lang ]['notice']['elements']['donotSell'] ) && is_array( $contents[ $lang ]['notice']['elements']['donotSell'] ) ) {
        $contents[ $lang ]['notice']['elements']['donotSell']['status'] = false;
      }
    }
    $wpdb->update( $wpdb->prefix . 'faz_banners', array( 'settings' => wp_json_encode( $settings ), 'contents' => wp_json_encode( $contents ) ), array( 'banner_id' => $row->banner_id ), array( '%s', '%s' ), array( '%d' ) );
    \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();
    faz_clear_banner_template_cache();
    echo wp_json_encode( array( 'banner_id' => $row->banner_id, 'original_settings' => $original_settings, 'original_contents' => $original_contents ) );
  `).trim();
}

/** Default banner → applicableLaw=gdpr but every language's copy is the English
 *  CCPA default (which names the Do-Not-Sell link). donotSell.status is forced
 *  off so the law dropdown resolves to plain 'gdpr', not 'gdpr_ccpa'. */
function seedGdprWithCcpaCopy(): string {
  return wpEval(`
    global $wpdb;
    $row = $wpdb->get_row( "SELECT banner_id, settings, contents FROM {$wpdb->prefix}faz_banners WHERE banner_default = 1 LIMIT 1" );
    if ( ! $row ) { echo wp_json_encode( array( 'error' => 'no_default_banner' ) ); exit; }
    $original_settings = $row->settings;
    $original_contents = $row->contents;
    $settings = json_decode( $row->settings, true );
    if ( ! isset( $settings['settings'] ) || ! is_array( $settings['settings'] ) ) { $settings['settings'] = array(); }
    $settings['settings']['applicableLaw'] = 'gdpr';
    $en = \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Banner::get_law_notice_descriptions( 'en' );
    $contents = json_decode( $row->contents, true );
    if ( ! is_array( $contents ) ) { $contents = array(); }
    foreach ( array_keys( $contents ) as $lang ) {
      if ( ! isset( $contents[ $lang ]['notice']['elements'] ) || ! is_array( $contents[ $lang ]['notice']['elements'] ) ) { continue; }
      $contents[ $lang ]['notice']['elements']['description'] = $en['ccpa'];
      // Force the Do-Not-Sell button off so loadBanner keeps the law at 'gdpr'.
      if ( isset( $contents[ $lang ]['notice']['elements']['donotSell'] ) && is_array( $contents[ $lang ]['notice']['elements']['donotSell'] ) ) {
        $contents[ $lang ]['notice']['elements']['donotSell']['status'] = false;
      }
    }
    if ( empty( $contents ) ) { $contents = array( 'en' => array( 'notice' => array( 'elements' => array( 'description' => $en['ccpa'] ) ) ) ); }
    $wpdb->update( $wpdb->prefix . 'faz_banners', array( 'settings' => wp_json_encode( $settings ), 'contents' => wp_json_encode( $contents ) ), array( 'banner_id' => $row->banner_id ), array( '%s', '%s' ), array( '%d' ) );
    \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();
    faz_clear_banner_template_cache();
    echo wp_json_encode( array( 'banner_id' => $row->banner_id, 'original_settings' => $original_settings, 'original_contents' => $original_contents ) );
  `).trim();
}
