/**
 * Divi's mobile menu, on the real theme.
 *
 * et_duplicate_menu clones the server menu, appends the clone, then queries it
 * on the next line. A clone the bootstrap holds leaves that query empty, and
 * the phone menu has no entries. The clone-carrying rules are pinned in
 * tests/unit/js/bootstrap-served-markup-move.test.mjs; this checks them in a
 * browser on the theme's own markup, with a parked tracker script inside the
 * menu, the case that used to hold the whole clone.
 *
 * Skipped when the Divi theme is not installed.
 */
import { test, expect } from '../fixtures/wp-fixture';
import { wpEval } from '../utils/wp-env';
import { resetDefaultBannerState } from '../utils/seed-defaults';
import * as fs from 'fs';
import * as path from 'path';

const WP_PATH = process.env.WP_PATH || '';
const FIXTURE = path.join(WP_PATH, 'wp-content/mu-plugins/faz-e2e-divi-menu.php');
const TRACKER = 'https://connect.facebook.net/en_US/fbevents.js';

let previousTheme = '';
let previousLocations = '';
let menuId = 0;
let hasDivi = false;

test.beforeAll(() => {
  hasDivi = !!WP_PATH && fs.existsSync(path.join(WP_PATH, 'wp-content/themes/Divi/style.css'));
  if (!hasDivi) return;
  resetDefaultBannerState();
  // The tracker the buffer parks, inside a menu item, as a menu widget emits it.
  fs.mkdirSync(path.dirname(FIXTURE), { recursive: true });
  fs.writeFileSync(FIXTURE, `<?php
add_filter( 'nav_menu_item_title', function ( $title ) {
	return $title . '<script src="${TRACKER}"></script>';
} );
`);
  // Read first and kept apart from the changes below: if those fail halfway,
  // afterAll still knows what to put back.
  const state = JSON.parse(wpEval(`
    echo wp_json_encode( array( 'theme' => get_stylesheet(), 'locations' => wp_json_encode( get_theme_mod( 'nav_menu_locations' ) ) ) );
  `).trim());
  previousTheme = state.theme;
  previousLocations = state.locations;
  menuId = Number(wpEval(`
    switch_theme( 'Divi' );
    $id = wp_create_nav_menu( 'FAZ E2E Divi menu ' . wp_generate_password( 6, false ) );
    if ( is_wp_error( $id ) ) { WP_CLI::error( $id->get_error_message() ); }
    foreach ( array( 'First', 'Second' ) as $label ) {
      wp_update_nav_menu_item( $id, 0, array( 'menu-item-title' => $label, 'menu-item-url' => home_url( '/' ), 'menu-item-status' => 'publish' ) );
    }
    set_theme_mod( 'nav_menu_locations', array( 'primary-menu' => $id ) );
    faz_clear_banner_template_cache();
    echo (int) $id;
  `).trim());
});

test.afterAll(() => {
  if (!hasDivi) return;
  fs.rmSync(FIXTURE, { force: true });
  if (!previousTheme) return;
  wpEval(`
    if ( ${menuId} > 0 ) { wp_delete_nav_menu( ${menuId} ); }
    switch_theme( '${previousTheme}' );
    $locations = json_decode( '${previousLocations}', true );
    set_theme_mod( 'nav_menu_locations', is_array( $locations ) ? $locations : array() );
    faz_clear_banner_template_cache();
  `);
});

test('a menu copy carrying a parked script stays synchronous, and the tracker does not load', async ({ browser, wpBaseURL }) => {
  test.skip(!hasDivi, 'Divi theme not installed');
  const ctx = await browser.newContext({ viewport: { width: 390, height: 800 } });
  const requests: string[] = [];
  ctx.on('request', (r) => requests.push(r.url()));
  const page = await ctx.newPage();
  // The bootstrap holds insertions only until the runtime arrives, so keep the
  // runtime on its way. Here Divi's ready handler waits for the deferred runtime
  // and runs after it; a page that loads the runtime later (a delay plugin, for
  // example) is where the theme runs inside this window. So this performs the
  // theme's own operation at that moment, on the theme's own markup.
  await page.route(/faz-cookie-manager\/frontend\/js\/script(\.min)?\.js/, async (route) => {
    await new Promise((resolve) => setTimeout(resolve, 12000));
    await route.continue();
  });
  try {
    void page.goto(wpBaseURL + '/', { waitUntil: 'domcontentloaded' }).catch(() => {});
    const parked = page.locator('#top-menu script[src*="fbevents"]').first();
    await expect(parked).toHaveAttribute('type', 'text/plain', { timeout: 10_000 });

    const during = await page.evaluate(() => {
      const copy = document.querySelector('#top-menu')!.cloneNode(true) as HTMLElement;
      const destination = document.createElement('div');
      document.body.appendChild(destination);
      destination.appendChild(copy);
      return {
        connected: copy.isConnected,
        // et_duplicate_menu's next line queries the destination.
        entries: destination.querySelectorAll('li').length,
        parkedType: copy.querySelector('script')?.getAttribute('type'),
      };
    });
    expect(during.connected, 'the copy is in the destination on the next line').toBe(true);
    expect(during.entries).toBeGreaterThanOrEqual(2);
    expect(during.parkedType).toBe('text/plain');

    // And once everything has run, Divi's own phone menu has its entries.
    await expect.poll(() => page.locator('#mobile_menu li').count(), { message: 'Divi filled the phone menu', timeout: 25_000 }).toBeGreaterThanOrEqual(2);

    expect(requests.filter((u) => u.includes('fbevents')), 'no tracker request before consent').toEqual([]);
    expect(await page.evaluate(() => typeof (window as any).fbq), 'the tracker did not run').toBe('undefined');
  } finally {
    await ctx.close();
  }
});
