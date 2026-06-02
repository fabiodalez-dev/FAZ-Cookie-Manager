/**
 * Per-banner override of the Garante/EDPB close-button auto-hide (1.14.0+).
 *
 * Default behaviour (pre-1.14.0 and post-1.14.0): when a banner has both a
 * Reject button and a Close (X) button enabled, Template::prepare_html()
 * strips the X from the rendered HTML. The reason is regulatory — EDPB
 * Guidelines 03/2022 and Garante Privacy Provv. 10/06/2021 treat the
 * "neutral X + labelled Reject" combination on the same banner as a
 * recognised dark pattern.
 *
 * 1.14.0 adds a per-banner opt-out flag at `settings.allowCloseButtonWithReject`.
 * Use case: with multi-banner geo-routing in the same release, an admin
 * can serve a Reject-mandatory GDPR banner to EU visitors AND a separate
 * CCPA-style banner with the X visible to US visitors, without the dark-
 * pattern auto-hide stripping the X from the second one.
 *
 * All four cases below exercise Template directly (not the live frontend)
 * so the test is deterministic: it inspects the rendered HTML string for
 * the presence of `data-faz-tag="close-button"`.
 */

import { test, expect } from '../fixtures/wp-fixture';
import { resetDefaultBannerState } from '../utils/seed-defaults';
import { wpEval } from '../utils/wp-env';

test.beforeAll(() => {
  // Start from the canonical default banner regardless of what a prior
  // full-suite spec left behind (this spec's afterAll already restores it
  // on the way out; the beforeAll guarantees a clean entry too).
  resetDefaultBannerState();
});

test.describe.serial('Close button per-banner override vs Garante/EDPB dark-pattern auto-hide', () => {
  test.afterAll(() => {
    // Reset the active banner to the known clean shape applied by
    // global-setup.ts. Earlier this hook snapshotted whatever state the
    // banner happened to be in at test start and restored it verbatim — but
    // if a previous spec already mutated the banner into classic+pushdown,
    // the snapshot froze the wrong state and later specs in the run
    // inherited it, cascading into double-digit fail counts. Forcing the
    // canonical default at teardown keeps the rest of the suite isolated.
    wpEval(`
      global $wpdb;
      $controller = \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance();
      $banner = $controller->get_active_banner();
      if ( $banner ) {
        $s = $banner->get_settings();
        if ( ! is_array( $s ) ) { $s = array(); }
        if ( ! isset( $s['settings'] ) || ! is_array( $s['settings'] ) ) { $s['settings'] = array(); }
        $s['settings']['type'] = 'box';
        $s['settings']['preferenceCenterType'] = 'popup';
        $s['settings']['allowCloseButtonWithReject'] = false;
        // Re-enable the close button explicitly so default-on assertions
        // still hold for downstream specs.
        if ( ! isset( $s['config']['notice']['elements']['closeButton'] ) || ! is_array( $s['config']['notice']['elements']['closeButton'] ) ) {
          $s['config']['notice']['elements']['closeButton'] = array();
        }
        $s['config']['notice']['elements']['closeButton']['status'] = true;
        $banner->set_settings( $s );
        $banner->save();
      }
      // Remove any secondary banners created by CB-OV-10 / GEO multi-banner
      // tests so the next spec sees a single-banner install.
      // Column is banner_id (not id) — PK from class-activator.
      $table = $wpdb->prefix . 'faz_banners';
      $active_id = $banner ? (int) $banner->get_id() : 0;
      if ( $active_id > 0 ) {
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE banner_id <> %d", $active_id ) );
      }
      delete_option( 'faz_banner_template' );
      if ( function_exists( 'faz_clear_banner_template_cache' ) ) {
        faz_clear_banner_template_cache();
      }
      $controller->delete_cache();
    `);
  });

  // Helper: configure the active banner with the given button visibilities and
  // override flag, then render the template and return the HTML string.
  function renderActiveBannerHtml(opts: {
    rejectStatus: boolean;
    closeStatus: boolean;
    allowOverride: boolean;
  }): string {
    return wpEval(`
      $banner = \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->get_active_banner();
      $settings = $banner->get_settings();
      if ( ! is_array( $settings ) ) { $settings = array(); }
      if ( ! isset( $settings['settings'] ) || ! is_array( $settings['settings'] ) ) { $settings['settings'] = array(); }
      $settings['settings']['allowCloseButtonWithReject'] = ${opts.allowOverride ? 'true' : 'false'};

      // Force the banner shape that exposes both Reject and Close buttons in
      // the rendered HTML. Use the notice elements layer (where the rest of
      // the codebase already keeps button visibility for the picker).
      if ( ! isset( $settings['config'] ) || ! is_array( $settings['config'] ) ) { $settings['config'] = array(); }
      if ( ! isset( $settings['config']['notice'] ) || ! is_array( $settings['config']['notice'] ) ) { $settings['config']['notice'] = array(); }
      if ( ! isset( $settings['config']['notice']['elements'] ) || ! is_array( $settings['config']['notice']['elements'] ) ) { $settings['config']['notice']['elements'] = array(); }

      $els =& $settings['config']['notice']['elements'];
      if ( ! isset( $els['closeButton'] ) || ! is_array( $els['closeButton'] ) ) { $els['closeButton'] = array(); }
      $els['closeButton']['status'] = ${opts.closeStatus ? 'true' : 'false'};

      // Reject button lives nested under notice.elements.buttons.elements.reject in the runtime config.
      if ( ! isset( $els['buttons'] ) || ! is_array( $els['buttons'] ) ) { $els['buttons'] = array(); }
      if ( ! isset( $els['buttons']['elements'] ) || ! is_array( $els['buttons']['elements'] ) ) { $els['buttons']['elements'] = array(); }
      if ( ! isset( $els['buttons']['elements']['reject'] ) || ! is_array( $els['buttons']['elements']['reject'] ) ) { $els['buttons']['elements']['reject'] = array(); }
      $els['buttons']['elements']['reject']['status'] = ${opts.rejectStatus ? 'true' : 'false'};

      $banner->set_settings( $settings );
      $banner->save();
      delete_option( 'faz_banner_template' );

      // Re-load the banner to get the post-sanitize settings, then render.
      $reread = new \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Banner( $banner->get_id() );
      $template = new \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Template( $reread, 'en' );
      echo base64_encode( (string) $template->get_html() );
    `).trim();
  }

  function htmlContainsCloseButton(b64Html: string): boolean {
    const html = Buffer.from(b64Html, 'base64').toString('utf8');
    return /data-faz-tag=["']close-button["']/.test(html);
  }

  test('CB-OV-01: default behaviour preserved — reject ON + close ON + override OFF → close removed from HTML', () => {
    const html = renderActiveBannerHtml({ rejectStatus: true, closeStatus: true, allowOverride: false });
    expect(htmlContainsCloseButton(html), 'Compliance auto-hide must still fire on the default banner').toBe(false);
  });

  test('CB-OV-02: per-banner override ON — reject ON + close ON + override ON → close kept in HTML', () => {
    const html = renderActiveBannerHtml({ rejectStatus: true, closeStatus: true, allowOverride: true });
    expect(htmlContainsCloseButton(html), 'allowCloseButtonWithReject=true keeps the X alongside Reject').toBe(true);
  });

  test('CB-OV-03: reject OFF + close ON + override OFF → close kept (no dark-pattern conflict)', () => {
    const html = renderActiveBannerHtml({ rejectStatus: false, closeStatus: true, allowOverride: false });
    expect(htmlContainsCloseButton(html), 'no Reject = no dark-pattern conflict, X stays without needing the override').toBe(true);
  });

  test('CB-OV-04: close OFF (admin disabled X explicitly) — override flag has no effect', () => {
    const html = renderActiveBannerHtml({ rejectStatus: true, closeStatus: false, allowOverride: true });
    // The "Show Close Button" admin toggle is the authoritative signal: if
    // the admin disabled the X, the override flag must not resurrect it.
    expect(htmlContainsCloseButton(html), 'closeButton.status=false trumps the override flag').toBe(false);
  });

  // ──────────────────────────────────────────────────────────────────────
  // CB-OV-05 → CB-OV-10: higher-level coverage (REST round-trip, live
  // frontend DOM, click-as-reject semantics, multi-banner integration).
  // ──────────────────────────────────────────────────────────────────────

  test('CB-OV-05: REST GET /banners/{id}?context=edit exposes settings.allowCloseButtonWithReject', () => {
    const result = wpEval(`
      $banner = \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->get_active_banner();
      $s = $banner->get_settings();
      if ( ! is_array( $s['settings'] ) ) { $s['settings'] = array(); }
      $s['settings']['allowCloseButtonWithReject'] = true;
      $banner->set_settings( $s );
      $banner->save();

      // Resolve the first administrator dynamically — don't hardcode
      // user_id=1, which can be brittle on fixtures that start IDs
      // elsewhere (multisite, imported DB).
      $admin_ids = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ) );
      wp_set_current_user( ! empty( $admin_ids ) ? (int) $admin_ids[0] : 0 );
      $req = new WP_REST_Request( 'GET', '/faz/v1/banners/' . $banner->get_id() );
      $req->set_param( 'context', 'edit' );
      $res = rest_do_request( $req );
      $data = $res->get_data();
      $props = isset( $data['properties'] ) ? $data['properties'] : array();
      $inner = isset( $props['settings'] ) ? $props['settings'] : array();
      echo wp_json_encode( array(
        'status' => $res->get_status(),
        'flag'   => isset( $inner['allowCloseButtonWithReject'] ) ? $inner['allowCloseButtonWithReject'] : null,
      ) );
    `).trim();

    const data = JSON.parse(result);
    expect(data.status, 'REST GET responds 200').toBe(200);
    // Banner::set_settings stores booleans as PHP true → after sanitize this
    // round-trips as truthy ("1" string or true). Accept either.
    expect(Boolean(data.flag), 'admin GET exposes the persisted override flag').toBe(true);
  });

  test('CB-OV-06: REST PUT persists settings.allowCloseButtonWithReject end-to-end', () => {
    const result = wpEval(`
      $banner = \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->get_active_banner();
      $bid = $banner->get_id();
      $current = $banner->get_settings();

      // Resolve administrator dynamically (see CB-OV-05 above).
      $admin_ids = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ) );
      wp_set_current_user( ! empty( $admin_ids ) ? (int) $admin_ids[0] : 0 );
      $req = new WP_REST_Request( 'PUT', '/faz/v1/banners/' . $bid );
      $req->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
      $req->set_param( 'name', $banner->get_name() ?: 'CB-OV-06' );
      $req->set_param( 'status', true );
      $req->set_param( 'default', true );
      $merged = $current;
      if ( ! isset( $merged['settings'] ) || ! is_array( $merged['settings'] ) ) { $merged['settings'] = array(); }
      $merged['settings']['allowCloseButtonWithReject'] = true;
      $req->set_param( 'properties', $merged );
      $req->set_param( 'contents', $banner->get_contents() );
      $res = rest_do_request( $req );

      // Re-read from DB to confirm the persisted state, not just the response.
      $reread = new \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Banner( $bid );
      $persisted = $reread->get_settings();
      echo wp_json_encode( array(
        'put_status'    => $res->get_status(),
        'persisted'     => isset( $persisted['settings']['allowCloseButtonWithReject'] ) ? (string) $persisted['settings']['allowCloseButtonWithReject'] : null,
      ) );
    `).trim();

    const data = JSON.parse(result);
    expect(data.put_status, 'PUT responds 200').toBe(200);
    expect(['1', 'true'].includes(String(data.persisted)), 'override flag persisted in DB after REST PUT').toBe(true);
  });

  test('CB-OV-07: live frontend — override=true keeps the X visible in the rendered DOM next to Reject', async ({ browser }) => {
    // Configure the active banner: classic type (so the X is meaningful in
    // the layout), reject + close enabled, override ON.
    wpEval(`
      $banner = \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->get_active_banner();
      $s = $banner->get_settings();
      if ( ! is_array( $s['settings'] ) ) { $s['settings'] = array(); }
      $s['settings']['type'] = 'classic';
      $s['settings']['preferenceCenterType'] = 'pushdown';
      $s['settings']['allowCloseButtonWithReject'] = true;
      if ( ! isset( $s['config']['notice']['elements']['closeButton'] ) || ! is_array( $s['config']['notice']['elements']['closeButton'] ) ) {
        $s['config']['notice']['elements']['closeButton'] = array();
      }
      $s['config']['notice']['elements']['closeButton']['status'] = true;
      if ( ! isset( $s['config']['notice']['elements']['buttons']['elements']['reject'] ) || ! is_array( $s['config']['notice']['elements']['buttons']['elements']['reject'] ) ) {
        $s['config']['notice']['elements']['buttons']['elements']['reject'] = array();
      }
      $s['config']['notice']['elements']['buttons']['elements']['reject']['status'] = true;
      $banner->set_settings( $s );
      $banner->save();
      delete_option( 'faz_banner_template' );
      if ( function_exists( 'faz_clear_banner_template_cache' ) ) {
        faz_clear_banner_template_cache();
      }
      \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();
    `);

    const ctx = await browser.newContext();
    try {
      const page = await ctx.newPage();
      await page.goto(`${process.env.WP_BASE_URL ?? 'http://localhost:9998'}/`, { waitUntil: 'domcontentloaded' });
      await expect(page.locator('[data-faz-tag="notice"]')).toBeVisible();
      await expect(
        page.locator('[data-faz-tag="close-button"]').first(),
        'X stays in the DOM when override is on AND reject is on',
      ).toBeAttached();
      await expect(
        page.locator('[data-faz-tag="reject-button"]').first(),
        'Reject must also be present (precondition for the override to matter)',
      ).toBeAttached();
    } finally {
      await ctx.close();
    }
  });

  test('CB-OV-08: live frontend — override=false (default) hides the X next to Reject', async ({ browser }) => {
    wpEval(`
      $banner = \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->get_active_banner();
      $s = $banner->get_settings();
      if ( ! is_array( $s['settings'] ) ) { $s['settings'] = array(); }
      $s['settings']['allowCloseButtonWithReject'] = false;
      if ( ! isset( $s['config']['notice']['elements']['closeButton'] ) || ! is_array( $s['config']['notice']['elements']['closeButton'] ) ) {
        $s['config']['notice']['elements']['closeButton'] = array();
      }
      $s['config']['notice']['elements']['closeButton']['status'] = true;
      if ( ! isset( $s['config']['notice']['elements']['buttons']['elements']['reject'] ) || ! is_array( $s['config']['notice']['elements']['buttons']['elements']['reject'] ) ) {
        $s['config']['notice']['elements']['buttons']['elements']['reject'] = array();
      }
      $s['config']['notice']['elements']['buttons']['elements']['reject']['status'] = true;
      $banner->set_settings( $s );
      $banner->save();
      delete_option( 'faz_banner_template' );
      if ( function_exists( 'faz_clear_banner_template_cache' ) ) {
        faz_clear_banner_template_cache();
      }
      \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();
    `);

    const ctx = await browser.newContext();
    try {
      const page = await ctx.newPage();
      await page.goto(`${process.env.WP_BASE_URL ?? 'http://localhost:9998'}/`, { waitUntil: 'domcontentloaded' });
      await expect(page.locator('[data-faz-tag="notice"]')).toBeVisible();
      await expect(
        page.locator('[data-faz-tag="reject-button"]').first(),
        'Reject must be visible',
      ).toBeAttached();
      await expect(
        page.locator('[data-faz-tag="close-button"]'),
        'X must be stripped by the Garante/EDPB auto-hide when override is off',
      ).toHaveCount(0);
    } finally {
      await ctx.close();
    }
  });

  test('CB-OV-09: clicking the X (when override=true) records consent as Reject, never as Accept', async ({ browser }) => {
    // X click goes through _fazActionClose() → _fazAcceptCookies("reject") in
    // frontend/js/script.js — the consent semantics must be Reject, not
    // Accept. Otherwise the override would silently downgrade compliance
    // (an X that looks neutral but ends up granting consent is a worse
    // dark pattern than the original).
    wpEval(`
      $banner = \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->get_active_banner();
      $s = $banner->get_settings();
      if ( ! is_array( $s['settings'] ) ) { $s['settings'] = array(); }
      $s['settings']['type'] = 'classic';
      $s['settings']['preferenceCenterType'] = 'pushdown';
      $s['settings']['applicableLaw'] = 'gdpr';
      $s['settings']['allowCloseButtonWithReject'] = true;
      if ( ! isset( $s['config']['notice']['elements']['closeButton'] ) || ! is_array( $s['config']['notice']['elements']['closeButton'] ) ) {
        $s['config']['notice']['elements']['closeButton'] = array();
      }
      $s['config']['notice']['elements']['closeButton']['status'] = true;
      if ( ! isset( $s['config']['notice']['elements']['buttons']['elements']['reject'] ) || ! is_array( $s['config']['notice']['elements']['buttons']['elements']['reject'] ) ) {
        $s['config']['notice']['elements']['buttons']['elements']['reject'] = array();
      }
      $s['config']['notice']['elements']['buttons']['elements']['reject']['status'] = true;
      $banner->set_settings( $s );
      $banner->save();
      delete_option( 'faz_banner_template' );
      if ( function_exists( 'faz_clear_banner_template_cache' ) ) {
        faz_clear_banner_template_cache();
      }
      \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->delete_cache();
    `);

    const ctx = await browser.newContext();
    try {
      const page = await ctx.newPage();
      await page.goto(`${process.env.WP_BASE_URL ?? 'http://localhost:9998'}/`, { waitUntil: 'domcontentloaded' });
      await expect(page.locator('[data-faz-tag="notice"]')).toBeVisible();
      await expect(page.locator('[data-faz-tag="close-button"]').first()).toBeAttached();

      await page.locator('[data-faz-tag="close-button"]').first().click();
      await page.waitForFunction(
        () => document.cookie.split(';').some((c) => c.trim().startsWith('fazcookie-consent=')),
        undefined,
        { timeout: 5_000 },
      );

      const consent = await page.evaluate(() => {
        const raw = document.cookie.split(';').find((c) => c.trim().startsWith('fazcookie-consent='));
        return raw ? decodeURIComponent(raw.split('=').slice(1).join('=')) : '';
      });

      expect(consent, 'consent cookie must be present after X click').not.toBe('');
      expect(consent, 'X click must record action:yes (a decision was taken)').toContain('action:yes');
      expect(consent, 'X click under GDPR law must record consent:no (Reject, not Accept)').toContain('consent:no');
      expect(consent, 'no analytics opt-in must leak from the X click').not.toContain('analytics:yes');
      expect(consent, 'no marketing opt-in must leak from the X click').not.toContain('marketing:yes');
    } finally {
      await ctx.close();
    }
  });

  test('CB-OV-10: per-banner — flag on banner A does not bleed into banner B', () => {
    // Two-banner sanity check: setting the override on banner_id=1 must not
    // affect banner_id=2 (and vice-versa). The flag lives in the banner row,
    // not in a global option.
    const result = wpEval(`
      global $wpdb;
      // Pick A explicitly as the active banner so the test is deterministic
      // regardless of MySQL row order or auto_increment drift. The legacy
      // "ORDER BY banner_id ASC LIMIT 2" form could pick an unrelated row
      // as A on dirty DBs and mutate the wrong banner.
      $active = \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance()->get_active_banner();
      if ( ! $active ) { echo 'NO_ACTIVE_BANNER'; return; }
      $id_a = (int) $active->get_id();

      // B is "any banner that is NOT A" — query explicitly to avoid the
      // ORDER BY trap. Create one on the fly when none exists.
      $id_b = (int) $wpdb->get_var(
        $wpdb->prepare(
          "SELECT banner_id FROM {$wpdb->prefix}faz_banners WHERE banner_id <> %d ORDER BY banner_id ASC LIMIT 1",
          $id_a
        )
      );
      if ( $id_b <= 0 ) {
        $now = current_time( 'mysql' );
        $wpdb->insert(
          $wpdb->prefix . 'faz_banners',
          array(
            'name'             => 'CB-OV-10 secondary',
            'slug'             => 'cb-ov-10-secondary',
            'status'           => 0,
            'settings'         => wp_json_encode( $active->get_settings() ),
            'contents'         => wp_json_encode( $active->get_contents() ),
            'banner_default'   => 0,
            'target_countries' => wp_json_encode( array() ),
            'priority'         => 0,
            'date_created'     => $now,
            'date_modified'    => $now,
          )
        );
        $id_b = (int) $wpdb->insert_id;
      }

      $a = new \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Banner( $id_a );
      $b = new \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Banner( $id_b );

      $sa = $a->get_settings();
      if ( ! is_array( $sa['settings'] ) ) { $sa['settings'] = array(); }
      $sa['settings']['allowCloseButtonWithReject'] = true;
      $a->set_settings( $sa );
      $a->save();

      $sb = $b->get_settings();
      if ( ! is_array( $sb['settings'] ) ) { $sb['settings'] = array(); }
      $sb['settings']['allowCloseButtonWithReject'] = false;
      $b->set_settings( $sb );
      $b->save();

      $a_after = new \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Banner( $id_a );
      $b_after = new \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Banner( $id_b );

      $a_flag = $a_after->get_settings();
      $b_flag = $b_after->get_settings();

      echo wp_json_encode( array(
        'a' => isset( $a_flag['settings']['allowCloseButtonWithReject'] ) ? (string) $a_flag['settings']['allowCloseButtonWithReject'] : null,
        'b' => isset( $b_flag['settings']['allowCloseButtonWithReject'] ) ? (string) $b_flag['settings']['allowCloseButtonWithReject'] : null,
      ) );
    `).trim();

    if (result === 'NO_ACTIVE_BANNER') {
      test.skip(true, 'Need an active banner to seed the secondary for the isolation check');
      return;
    }

    const data = JSON.parse(result);
    expect(['1', 'true'].includes(String(data.a)), 'banner A keeps its own override = true').toBe(true);
    expect(['', '0', 'false', null].includes(data.b), 'banner B keeps its own override = false (no bleed from banner A)').toBe(true);
  });
});
