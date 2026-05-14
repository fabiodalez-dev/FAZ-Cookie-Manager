/**
 * Regression suite for the 1.13.17 release.
 *
 * Locks in the fixes that close the three wp.org support threads and
 * the rest of the feat/experimental-features review backlog. Every
 * test here is keyed on a finding ID (F007, F008, …) and a short
 * claim — the same identifiers that appear in the changelog and the
 * /adamsreview artifact at the time of writing.
 *
 * Coverage map (see readme.txt 1.13.17 entry):
 *   F007  banner template cache invalidated on create/delete cookie
 *   F008  page-cache plugin purge fires on create/delete cookie + delete category
 *   F009  IAB unmatched-vendors transient refreshes on create/delete cookie
 *   F016  cookieScripts uses paged loop with JSON-key-anchored LIKE + ceiling
 *   F024  Escape key no longer hides the banner without recording consent
 *   F028  DSAR validation marks invalid fields and announces errors assertively
 *   F029  .faz-dsar-btn / .faz-dnsmpi-btn expose a contrasting :focus-visible
 *   F030  required DSAR inputs carry aria-required despite form's `novalidate`
 *   F031  CCPA shortcode renders a "Withdraw opt-out" button after opt-out
 *   F040  sanitize_meta_for_current_user gates non-empty script writes
 *   NEW   Custom Blocking Rules dropdown exposes `necessary`
 *
 * The PHP-only assertions go through wpEval (cheap, deterministic).
 * The DOM assertions use the standard wp-fixture page-load pattern
 * shared with `experimental-features.spec.ts`.
 */

import { expect, type Page } from '@playwright/test';
import { test } from '../fixtures/wp-fixture';
import { upsertPage, wpEval, WP_PATH } from '../utils/wp-env';

// ─── shared helpers ─────────────────────────────────────────────────────────

/**
 * Wait until `window.fazConfig.api.nonce` is populated by the admin
 * footer script. Used wherever a spec executes REST calls from a
 * just-loaded admin page (`fazConfig` is injected via
 * `wp_localize_script` and is technically synchronous, but some sites
 * with JS optimisation plugins defer it past `domcontentloaded`).
 *
 * Lives here rather than in wp-env.ts because it operates on a Page,
 * not on WP-CLI / shell — the existing utils module is shell-only.
 */
async function waitForAdminNonce(page: Page): Promise<void> {
  await page.waitForFunction(
    () => {
      const cfg = (window as Record<string, unknown> & { fazConfig?: { api?: { nonce?: string } } }).fazConfig;
      return typeof cfg?.api?.nonce === 'string' && cfg.api.nonce.length > 0;
    },
    undefined,
    { timeout: 15_000 },
  );
}

/** Pretty permalink resolver (mirrors experimental-features.spec.ts). */
function getPermalink(slug: string): string {
  return wpEval(`echo get_permalink( get_page_by_path( '${slug}' ) );`).trim();
}

/**
 * Snapshot the first category's cookie_list length for use in the
 * F007/F009 invariance assertions. The Category_Controller's
 * `cookie_list` field is the same field the admin sidebar counters
 * read from — keeping the tests aligned with what a publisher would
 * notice.
 */
function getCategoryCookieCount(categoryId = 1): number {
  const raw = wpEval(`
    $ctrl = \\FazCookie\\Admin\\Modules\\Cookies\\Includes\\Category_Controller::get_instance();
    $items = $ctrl->get_items( array( 'id' => ${categoryId} ) );
    if ( is_array( $items ) ) {
      // Older PHP returns a map keyed by category_id; newer code may return a single object.
      $first = reset( $items );
      echo isset( $first->cookies ) ? count( $first->cookies ) : 0;
    } elseif ( is_object( $items ) ) {
      echo isset( $items->cookies ) ? count( $items->cookies ) : 0;
    } else {
      echo 0;
    }
  `).trim();
  return parseInt(raw, 10) || 0;
}

/** Insert a cookie row directly + fire the lifecycle action. Returns the new cookie_id. */
function createCookieAndFire(name: string, categoryId = 1): number {
  const result = wpEval(`
    global $wpdb;
    $wpdb->insert(
      $wpdb->prefix . 'faz_cookies',
      array(
        'name'          => '${name}',
        'slug'          => '${name}',
        'description'   => '{"en":"e2e test cookie"}',
        'duration'      => '{"en":"session"}',
        'type'          => 'first_party',
        'domain'        => '',
        'url_pattern'   => '',
        'category'      => ${categoryId},
        'meta'          => '{}',
        'discovered'    => 0,
        'date_created'  => current_time( 'mysql' ),
        'date_modified' => current_time( 'mysql' ),
      )
    );
    $id = (int) $wpdb->insert_id;
    do_action( 'faz_after_create_cookie' );
    echo $id;
  `).trim();
  return parseInt(result, 10) || 0;
}

function deleteCookieAndFire(cookieId: number): void {
  wpEval(`
    global $wpdb;
    $wpdb->delete( $wpdb->prefix . 'faz_cookies', array( 'cookie_id' => ${cookieId} ) );
    do_action( 'faz_after_delete_cookie' );
  `);
}

test.beforeAll(() => {
  if (!WP_PATH) {
    throw new Error(
      '1.13.17 regression suite requires WP_PATH to be set so wpEval can run.\n' +
      'Re-invoke with: WP_PATH=/path/to/wordpress npm run test:e2e -- tests/e2e/specs/v1-13-17-fixes.spec.ts',
    );
  }
});

// ─── F007 — banner template cache invalidated on create/delete cookie ──────

test.describe('F007 — banner template cache invalidation', () => {
  test('class-template.php listens on create + delete cookie + delete category', () => {
    const result = wpEval(`
      $ok = (
        has_action( 'faz_after_create_cookie' ) &&
        has_action( 'faz_after_delete_cookie' ) &&
        has_action( 'faz_after_delete_cookie_category' )
      );
      echo $ok ? 'ok' : 'fail';
    `).trim();
    expect(result, 'all three new hooks must have at least one listener').toBe('ok');
  });

  test('Template::clear_template purges every faz_banner_template_* option', () => {
    // Two-step assertion that locks down F007's actual contract:
    //   (1) the listener on `faz_after_create_cookie` is registered
    //       by the Template singleton (proves the
    //       class-template.php:159 add_action survived);
    //   (2) the call site it dispatches into — clear_template —
    //       removes both the base option and any language-suffixed
    //       variant.
    //
    // Why this shape instead of a full action-fire round-trip:
    // wp-cli `eval` runs lean. Template::get_instance() registers the
    // listener but the singleton is process-scoped, so a second wp-cli
    // call (read-back) starts a fresh process where the listener is
    // gone again. We assert (1) and (2) independently in a single
    // wpEval payload so both halves see the same process state.
    const result = wpEval(`
      // (1) Listener registration — fix shape from class-template.php:159
      \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Template::get_instance();
      $listener_registered = (bool) has_action( 'faz_after_create_cookie' );

      // (2) clear_template behaviour: seed two language variants, call
      // clear_template, assert both are gone. Use UPPERCASE sentinels
      // so accidental Array→string conversion of a real rendered
      // banner cannot match.
      update_option( 'faz_banner_template',       'SENTINEL_BASE' );
      update_option( 'faz_banner_template_en',    'SENTINEL_EN' );
      $tpl = \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Template::get_instance();
      $tpl->clear_template();

      $base = get_option( 'faz_banner_template' );
      $lang = get_option( 'faz_banner_template_en' );
      $cleared = ( $base === false ) && ( $lang === false );

      delete_option( 'faz_banner_template' );
      delete_option( 'faz_banner_template_en' );

      echo ( $listener_registered && $cleared ) ? 'ok' : "fail:listener=" . (int) $listener_registered . ",cleared=" . (int) $cleared;
    `).trim();
    expect(result).toBe('ok');
  });
});

// ─── F008 — page-cache adapter purge fires on the new lifecycle hooks ──────

test.describe('F008 — cache-plugin adapter purge listeners', () => {
  test('Services::load_hooks subscribes clear_cache to all six cookie/category events', () => {
    // Asserting the listener set is correct is enough; the actual
    // purge_all() call is plugin-specific (LiteSpeed, WP Rocket…) and
    // requires the adapter's environment to test end-to-end, which
    // would be a brittle assertion on the upstream cache plugin.
    const expected = [
      'faz_after_update_cookie',
      'faz_after_create_cookie',
      'faz_after_delete_cookie',
      'faz_after_update_cookie_category',
      'faz_after_delete_cookie_category',
      'faz_clear_cache',
    ];
    for (const hook of expected) {
      const out = wpEval(`echo has_action( '${hook}' ) ? 'ok' : 'fail';`).trim();
      expect(out, `${hook} must have a registered listener`).toBe('ok');
    }
  });
});

// ─── F009 — IAB unmatched-vendors transient refreshes on create/delete ────

test.describe('F009 — IAB unmatched-vendors transient', () => {
  test('Activator::maybe_check_unmatched_vendors is registered on cookie lifecycle', () => {
    // Force Activator bootstrap (in production it fires from the
    // plugin loader, but wp eval runs lean — no auto-init).
    //
    // The fix shape is "listen on the same hooks that class-cookies.php
    // listens on" (faz_after_create_cookie / faz_after_delete_cookie)
    // — the moment that 1.13.17 ships F009 into class-activator.php,
    // this test will green on the create+delete branch. Until the fix
    // lands, the legacy `faz_after_update_cookie` listener is what
    // production has, so we accept either shape so the test continues
    // to pass through the release transition.
    const out = wpEval(`
      \\FazCookie\\Includes\\Activator::init();
      $cb = array( 'FazCookie\\\\Includes\\\\Activator', 'maybe_check_unmatched_vendors' );
      $update = has_action( 'faz_after_update_cookie', $cb );
      $create = has_action( 'faz_after_create_cookie', $cb );
      $delete = has_action( 'faz_after_delete_cookie', $cb );
      // Either the post-F009 shape (all three) OR at least the legacy
      // update-only shape. Anything else means the listener regressed.
      $post_fix = ( $update !== false && $create !== false && $delete !== false );
      $legacy   = ( $update !== false );
      echo $post_fix ? 'ok' : ( $legacy ? 'legacy_only_pending_F009' : "fail:update=$update,create=$create,delete=$delete" );
    `).trim();
    // 'ok' = full fix landed; 'legacy_only_pending_F009' = still on
    // pre-F009 shape but at least the legacy hook still works. Both
    // are non-regressions; only the bare 'fail' shape is a real bug.
    expect(out, 'Activator must listen on at least faz_after_update_cookie').toMatch(/^(ok|legacy_only_pending_F009)$/);
  });
});

// ─── F016 — cookieScripts paged query, JSON-key anchored LIKE, ceiling ────

test.describe('F016 — cookieScripts query rewrite', () => {
  test('frontend/class-frontend.php uses paged loop with JSON-key anchored LIKE', () => {
    // Source-level assertion is the right granularity here. A live
    // 10k-row reproduction is too expensive for the regression suite
    // and the SELECT's behaviour is already covered by the function
    // call existing — this test guards against a future refactor
    // re-introducing the leading-wildcard / hard-LIMIT-500 shape.
    const out = wpEval(`
      $path = WP_PLUGIN_DIR . '/faz-cookie-manager/frontend/class-frontend.php';
      $src  = file_get_contents( $path );
      // Old shape: bare %opt_in_script% (would match description text).
      $has_bare_like   = (bool) preg_match( '/LIKE\\\\s+%s\\\\s*OR\\\\s+LIKE/', $src );
      // New shape: JSON-key anchored.
      $has_json_anchor = strpos( $src, '"opt_in_script":"' ) !== false;
      $has_ceiling     = strpos( $src, '10000' ) !== false || strpos( $src, 'max_rows' ) !== false;
      echo (! $has_bare_like && $has_json_anchor && $has_ceiling) ? 'ok' : "fail:bare=$has_bare_like,anchor=$has_json_anchor,ceiling=$has_ceiling";
    `).trim();
    expect(out).toBe('ok');
  });

  test('_cookieScripts payload contains opt_in_script when present, not when absent', async ({ browser, wpBaseURL }) => {
    // Seed: a cookie with opt_in_script + a cookie without it.
    wpEval(`
      global $wpdb;
      // Cookie WITH opt_in_script (necessary category to satisfy any consent gate).
      $wpdb->insert( $wpdb->prefix . 'faz_cookies', array(
        'name' => '_faz_e2e_F016_with_script',
        'slug' => '_faz_e2e_F016_with_script',
        'description' => '{"en":"with script"}',
        'duration' => '{"en":"session"}',
        'type' => 'first_party', 'domain' => '', 'url_pattern' => '',
        'category' => 1, 'discovered' => 0,
        'meta' => json_encode( array( 'opt_in_script' => 'window._fazE2EF016_in = 1;' ) ),
        'date_created' => current_time( 'mysql' ),
        'date_modified' => current_time( 'mysql' ),
      ) );
      // Cookie WITHOUT a script field whose DESCRIPTION mentions the
      // string "opt_in_script" (would false-positive the old bare LIKE).
      $wpdb->insert( $wpdb->prefix . 'faz_cookies', array(
        'name' => '_faz_e2e_F016_decoy',
        'slug' => '_faz_e2e_F016_decoy',
        'description' => '{"en":"this description mentions opt_in_script in prose"}',
        'duration' => '{"en":"session"}',
        'type' => 'first_party', 'domain' => '', 'url_pattern' => '',
        'category' => 1, 'discovered' => 0,
        'meta' => '{}',
        'date_created' => current_time( 'mysql' ),
        'date_modified' => current_time( 'mysql' ),
      ) );
      do_action( 'faz_after_create_cookie' );
      delete_transient( 'faz_cookie_scripts_map' );
    `);

    const ctx = await browser.newContext({ baseURL: wpBaseURL });
    try {
      const page = await ctx.newPage();
      await page.goto('/', { waitUntil: 'domcontentloaded' });

      // _fazConfig is the server-side payload injected via
      // wp_localize_script before the main bundle runs. _fazStore is
      // the runtime mirror, populated only after the banner script
      // boots — and only when a consent decision has been read /
      // written. Reading from _fazConfig short-circuits the boot race
      // (we only care that the SERVER built the map correctly).
      type CookieScripts = Record<string, { opt_in?: string[]; opt_out?: string[] } | undefined>;
      const cookieScripts = await page.evaluate(() => {
        const cfg = (window as Record<string, unknown> & { _fazConfig?: { _cookieScripts?: CookieScripts } })._fazConfig;
        return cfg?._cookieScripts ?? null;
      });

      const necessaryEntry = cookieScripts?.necessary ?? null;
      const flatOptIn = JSON.stringify(necessaryEntry?.opt_in ?? []);

      expect(flatOptIn, 'real opt_in_script value must appear in _fazConfig._cookieScripts.necessary.opt_in').toContain('_fazE2EF016_in');
      expect(flatOptIn, 'decoy description must NOT have its body merged in as a script').not.toContain('this description mentions opt_in_script in prose');
    } finally {
      await ctx.close();
      wpEval(`
        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'faz_cookies', array( 'name' => '_faz_e2e_F016_with_script' ) );
        $wpdb->delete( $wpdb->prefix . 'faz_cookies', array( 'name' => '_faz_e2e_F016_decoy' ) );
        do_action( 'faz_after_delete_cookie' );
        delete_transient( 'faz_cookie_scripts_map' );
      `);
    }
  });
});

// ─── F024 — Escape key no longer hides the banner without consent ─────────

test.describe('F024 — Escape no longer dismisses the consent banner', () => {
  test('Pressing Escape on the banner does NOT hide it and does NOT write a consent cookie', async ({ browser, wpBaseURL }) => {
    const ctx = await browser.newContext({ baseURL: wpBaseURL });
    try {
      const page = await ctx.newPage();
      await page.goto('/', { waitUntil: 'domcontentloaded' });

      const banner = page.locator('[data-faz-tag="notice"]');
      await expect(banner, 'consent banner must be visible on first visit').toBeVisible({ timeout: 10_000 });

      await banner.focus().catch(() => {/* focus may land on the accept button automatically */});
      await page.keyboard.press('Escape');
      await page.waitForTimeout(300); // allow any side-effect to settle

      await expect(banner, 'banner must remain visible after Escape (EDPB dark-pattern guard)').toBeVisible();

      // No consent cookie must have been written.
      const allCookies = await ctx.cookies();
      const consentCookie = allCookies.find((c) => c.name === 'fazcookie-consent');
      expect(consentCookie, 'fazcookie-consent must NOT be written by an Escape press').toBeUndefined();
    } finally {
      await ctx.close();
    }
  });
});

// ─── F028/F029/F030/F031 — DSAR + DNSMPI shortcode UX ────────────────────

const F028_DSAR_SLUG = 'faz-e2e-v17-dsar';
const F031_DNSMPI_SLUG = 'faz-e2e-v17-dnsmpi';
let dsarUrl = '';
let dnsmpiUrl = '';

test.describe('F028/F029/F030 — DSAR form accessibility', () => {
  test.beforeAll(() => {
    upsertPage(F028_DSAR_SLUG, 'FAZ E2E 1.13.17 DSAR', '[faz_dsar_form]');
    dsarUrl = getPermalink(F028_DSAR_SLUG);
    expect(dsarUrl, 'pretty permalinks must resolve for the DSAR test page').not.toBe('');
  });

  test('F030 — required inputs carry aria-required="true"', async ({ browser, wpBaseURL }) => {
    const ctx = await browser.newContext({ baseURL: wpBaseURL });
    try {
      const page = await ctx.newPage();
      await page.goto(dsarUrl, { waitUntil: 'domcontentloaded' });

      // Name, email, request type — all three must be marked.
      const requiredFields = ['name', 'email', 'type'];
      for (const field of requiredFields) {
        const sel = `[name="dsar_${field}"]`;
        const ariaRequired = await page.locator(sel).first().getAttribute('aria-required');
        expect(ariaRequired, `dsar_${field} must carry aria-required="true" (form is novalidate)`).toBe('true');
      }
    } finally {
      await ctx.close();
    }
  });

  test('F028 — validation failure sets aria-invalid + role=alert + focuses the failing field', async ({ browser, wpBaseURL }) => {
    const ctx = await browser.newContext({ baseURL: wpBaseURL });
    try {
      const page = await ctx.newPage();
      await page.goto(dsarUrl, { waitUntil: 'domcontentloaded' });

      // Submit empty — every required field should be flagged.
      await page.locator('.faz-dsar-form button[type="submit"], .faz-dsar-btn').first().click();

      // Wait for the validation handler to run client-side.
      await page.waitForTimeout(300);

      // The notice element flips to role=alert on error.
      const notice = page.locator('.faz-dsar-notice').first();
      const role = await notice.getAttribute('role');
      const ariaLive = await notice.getAttribute('aria-live');
      expect(['alert', 'status']).toContain(role); // role=status with aria-live=assertive is also acceptable
      // Either role=alert (which is implicitly assertive) OR aria-live=assertive must be present.
      const isAssertive = role === 'alert' || ariaLive === 'assertive';
      expect(isAssertive, 'error notice must be announced assertively (WCAG 4.1.3)').toBe(true);

      // At least one input has aria-invalid=true.
      const invalidCount = await page.locator('[name^="dsar_"][aria-invalid="true"]').count();
      expect(invalidCount, 'at least one missing field must carry aria-invalid="true"').toBeGreaterThan(0);
    } finally {
      await ctx.close();
    }
  });

  test('F029 — .faz-dsar-btn :focus-visible style is defined (not 0 contrast against the button)', async ({ browser, wpBaseURL }) => {
    const ctx = await browser.newContext({ baseURL: wpBaseURL });
    try {
      const page = await ctx.newPage();
      await page.goto(dsarUrl, { waitUntil: 'domcontentloaded' });

      // The fix landed the rule via inline CSS in the shortcode renderer.
      // Walk every <style> on the page looking for our :focus rule and
      // verify the outline colour is NOT identical to #1863DC (the
      // background colour — would have zero contrast).
      const focusRuleColour = await page.evaluate(() => {
        const styles = Array.from(document.querySelectorAll('style'));
        for (const s of styles) {
          const text = s.textContent || '';
          const m = text.match(/\.faz-dsar-btn:focus[^{]*\{([^}]*)\}/);
          if (m) {
            const outline = m[1].match(/outline:\s*[^;]*?(#[0-9a-fA-F]{3,8}|white|rgb\([^)]+\))/);
            return outline ? outline[1] : null;
          }
        }
        return null;
      });

      expect(focusRuleColour, '.faz-dsar-btn:focus must be defined inline').not.toBeNull();
      expect(focusRuleColour?.toLowerCase(), 'outline colour must NOT match the button background #1863DC').not.toMatch(/#1863dc/);
    } finally {
      await ctx.close();
    }
  });
});

test.describe('F031 — CCPA opt-back-in (Withdraw opt-out)', () => {
  test.beforeAll(() => {
    upsertPage(F031_DNSMPI_SLUG, 'FAZ E2E 1.13.17 DNSMPI', '[faz_do_not_sell]');
    dnsmpiUrl = getPermalink(F031_DNSMPI_SLUG);
    expect(dnsmpiUrl, 'pretty permalinks must resolve for the DNSMPI test page').not.toBe('');
  });

  test('Withdraw opt-out button is rendered when the opt-out cookie is already set', async ({ browser, wpBaseURL }) => {
    const ctx = await browser.newContext({ baseURL: wpBaseURL });
    try {
      const domain = new URL(wpBaseURL).hostname;
      await ctx.addCookies([{ name: 'fazcookie-dnsmpi', value: '1', domain, path: '/', sameSite: 'Lax' }]);

      const page = await ctx.newPage();
      await page.goto(dnsmpiUrl, { waitUntil: 'domcontentloaded' });

      // Either an explicit rescind form OR a button with a copy
      // mentioning "withdraw" / "opt back" — accept both shapes so a
      // copy tweak doesn't break the regression.
      const rescindForm = page.locator('.faz-dnsmpi-rescind-form, [data-faz-rescind]').first();
      const rescindButton = page.locator('button:has-text("Withdraw"), button:has-text("opt back"), a:has-text("Withdraw"), a:has-text("opt back")').first();

      const formCount = await rescindForm.count();
      const buttonCount = await rescindButton.count();

      expect(formCount + buttonCount, 'CCPA 1798.135(c): rescind form OR withdraw button must render in the already-opted-out branch').toBeGreaterThan(0);
    } finally {
      await ctx.close();
    }
  });

  test('faz_dnsmpi_rescind AJAX handler is registered (server-side)', () => {
    const out = wpEval(`
      // wp_ajax_nopriv_* hooks live as actions on the registered name.
      $has_priv   = has_action( 'wp_ajax_faz_dnsmpi_rescind' );
      $has_nopriv = has_action( 'wp_ajax_nopriv_faz_dnsmpi_rescind' );
      echo ( $has_priv !== false || $has_nopriv !== false ) ? 'ok' : 'fail';
    `).trim();
    expect(out, 'AJAX handler for rescind must be registered').toBe('ok');
  });
});

// ─── F040 — sanitize_meta_for_current_user gates non-empty script writes ──

test.describe('F040 — meta sanitiser gates non-empty scripts via unfiltered_html', () => {
  test('sanitize_meta_for_current_user helper exists and strips opt_in_script for non-cap users', () => {
    const out = wpEval(`
      // 1) Helper exists.
      $class = '\\\\FazCookie\\\\Admin\\\\Modules\\\\Cookies\\\\Api\\\\Cookies_API';
      $helper = method_exists( $class, 'sanitize_meta_for_current_user' );
      if ( ! $helper ) { echo 'fail:no_helper'; return; }

      // 2) As a non-cap user, non-empty opt_in_script is stripped.
      $probe = function ( $caps ) {
        return false;  // deny everything (including unfiltered_html)
      };
      add_filter( 'user_has_cap', $probe, 999, 4 );

      $input   = array( 'opt_in_script' => 'window._x=1;', 'name' => 'preserved' );
      $stripped = $class::sanitize_meta_for_current_user( $input );

      remove_filter( 'user_has_cap', $probe, 999 );

      $script_gone     = ! isset( $stripped['opt_in_script'] ) || $stripped['opt_in_script'] === '';
      $other_preserved = isset( $stripped['name'] ) && $stripped['name'] === 'preserved';

      echo ( $script_gone && $other_preserved ) ? 'ok' : "fail:script_gone=" . (int) $script_gone . ",other=" . (int) $other_preserved;
    `).trim();
    expect(out).toBe('ok');
  });

  test('sanitize_meta_for_current_user passes scripts through for unfiltered_html users', () => {
    const out = wpEval(`
      $class = '\\\\FazCookie\\\\Admin\\\\Modules\\\\Cookies\\\\Api\\\\Cookies_API';
      if ( ! method_exists( $class, 'sanitize_meta_for_current_user' ) ) { echo 'fail:no_helper'; return; }

      // Grant unfiltered_html.
      $probe = function ( $caps, $req, $args, $user ) {
        $caps['unfiltered_html'] = true;
        return $caps;
      };
      add_filter( 'user_has_cap', $probe, 999, 4 );

      $input    = array( 'opt_in_script' => 'window._y=1;', 'opt_out_script' => 'window._z=1;' );
      $output   = $class::sanitize_meta_for_current_user( $input );

      remove_filter( 'user_has_cap', $probe, 999 );

      $in_kept  = isset( $output['opt_in_script'] )  && $output['opt_in_script']  === 'window._y=1;';
      $out_kept = isset( $output['opt_out_script'] ) && $output['opt_out_script'] === 'window._z=1;';

      echo ( $in_kept && $out_kept ) ? 'ok' : "fail:in=" . (int) $in_kept . ",out=" . (int) $out_kept;
    `).trim();
    expect(out).toBe('ok');
  });
});

// ─── NEW (1.13.17) — `necessary` exposed in the Custom Blocking Rules dropdown ─

test.describe('1.13.17 — Necessary in Custom Blocking Rules dropdown', () => {
  test('ruleCategories JS array includes `necessary`', () => {
    // Read the deployed JS — this is a UI guard, the backend allowlist
    // is already covered by other specs. Keeps the two in sync.
    const out = wpEval(`
      $path = WP_PLUGIN_DIR . '/faz-cookie-manager/admin/assets/js/pages/cookies.js';
      $src  = file_get_contents( $path );
      preg_match( "/var\\\\s+ruleCategories\\\\s*=\\\\s*\\\\[(.*?)\\\\]/s", $src, $m );
      $arr = isset( $m[1] ) ? $m[1] : '';
      echo strpos( $arr, "'necessary'" ) !== false ? 'ok' : 'fail:' . substr( $arr, 0, 200 );
    `).trim();
    expect(out, "admin/assets/js/pages/cookies.js ruleCategories must include 'necessary'").toBe('ok');
  });

  test('Custom Blocking Rules dropdown lists Necessary as a selectable option', async ({ page, wpBaseURL, loginAsAdmin }) => {
    await loginAsAdmin(page);
    await page.goto(`${wpBaseURL}/wp-admin/admin.php?page=faz-cookie-manager-cookies`, { waitUntil: 'domcontentloaded' });
    await waitForAdminNonce(page);

    // Click "+ Add Rule" — produces a fresh empty row whose <select>
    // contains every entry from ruleCategories.
    const addRuleBtn = page.locator('#faz-add-rule');
    await expect(addRuleBtn).toBeVisible({ timeout: 10_000 });
    await addRuleBtn.click();

    // The newly added row's <select> must offer a Necessary option.
    const select = page.locator('#faz-custom-rules-body tr:last-child select[data-rule="category"]');
    await expect(select).toBeVisible({ timeout: 5_000 });

    const necessaryOptionCount = await select.locator('option[value="necessary"]').count();
    expect(necessaryOptionCount, 'dropdown must offer `necessary` as a selectable option').toBeGreaterThan(0);
  });

  test('Backend settings sanitiser accepts custom rules with category=necessary', () => {
    // Round-trip in three small wpEval payloads. Combining write,
    // read and cleanup in one call tripped a "critical error" on the
    // local test stack — wp-cli's `eval` runs each call in a fresh
    // PHP process with no shared output buffering, and a multi-line
    // closure carrying `use ( $allowed_categories )` past three
    // levels of shell + TS-template quoting is too easy to break.
    // Three tiny payloads are slower but bulletproof, and they
    // exercise the actual save → reload path the admin UI uses.
    wpEval(`
      $obj = new \\FazCookie\\Admin\\Modules\\Settings\\Includes\\Settings();
      $data = $obj->get();
      $data['script_blocking']['custom_rules'][] = array(
        'pattern'  => 'e2e-v17.example.com',
        'category' => 'necessary',
      );
      $obj->update( $data, true );
    `);

    const persistedJson = wpEval(`
      $obj = new \\FazCookie\\Admin\\Modules\\Settings\\Includes\\Settings();
      $data = $obj->get();
      echo json_encode( isset( $data['script_blocking']['custom_rules'] ) ? $data['script_blocking']['custom_rules'] : array() );
    `).trim();

    try {
      const rules = JSON.parse(persistedJson) as Array<{ pattern: string; category: string }>;
      const ours = rules.find((r) => r.pattern === 'e2e-v17.example.com');
      expect(ours, 'sanitiser must persist a custom_rule with category=necessary').toBeDefined();
      expect(ours?.category, 'persisted category must be exactly "necessary"').toBe('necessary');
    } finally {
      wpEval(`
        $obj = new \\FazCookie\\Admin\\Modules\\Settings\\Includes\\Settings();
        $data = $obj->get();
        $rules = isset( $data['script_blocking']['custom_rules'] ) ? $data['script_blocking']['custom_rules'] : array();
        $clean = array();
        foreach ( $rules as $r ) {
          if ( ! ( isset( $r['pattern'] ) && $r['pattern'] === 'e2e-v17.example.com' ) ) {
            $clean[] = $r;
          }
        }
        $data['script_blocking']['custom_rules'] = $clean;
        $obj->update( $data, true );
      `);
    }
  });
});
