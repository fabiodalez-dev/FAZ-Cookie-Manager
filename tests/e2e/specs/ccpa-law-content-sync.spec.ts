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
      await expect.poll(() => noticeDescription(page), { message: 'starts with CCPA copy' })
        .toMatch(/do not sell/i);

      // Switch to GDPR → un-customised copy reloads to the GDPR default, which
      // does NOT mention Do Not Sell.
      await page.selectOption('#faz-b-law', 'gdpr');
      await expect.poll(() => noticeDescription(page), { message: 'GDPR copy no longer mentions Do Not Sell' })
        .not.toMatch(/do not sell/i);

      // Switch back to CCPA → the CCPA copy (with the link) returns.
      await page.selectOption('#faz-b-law', 'ccpa');
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
      const custom = '<p>Custom CCPA copy — Do Not Sell my info, please.</p>';
      await page.evaluate((html) => {
        const tm = (window as unknown as { tinyMCE?: { get: (id: string) => { setContent: (h: string) => void } | null } }).tinyMCE;
        const ed = tm && tm.get('faz-b-notice-desc');
        if (ed) ed.setContent(html);
      }, custom);

      // Switch to GDPR: the custom copy must be left intact, and the mismatch
      // hint must show because the custom copy still names the Do-Not-Sell link.
      await page.selectOption('#faz-b-law', 'gdpr');
      await expect(page.locator('#faz-b-law-content-hint'), 'mismatch hint shown for custom copy').toBeVisible();
      await expect.poll(() => noticeDescription(page), { message: 'custom copy untouched' })
        .toContain('Custom CCPA copy');
    } finally {
      try { restoreBanner(meta); } catch (e) { cleanupErr = e; }
    }
    if (cleanupErr) throw cleanupErr;
  });
});
