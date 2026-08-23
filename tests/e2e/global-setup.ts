import { request } from '@playwright/test';
import { assertPrerequisites } from './utils/preflight';
import { getWpLoginPath } from './utils/wp-auth';
import { wpEval } from './utils/wp-env';

async function globalSetup(): Promise<void> {
  const baseURL = process.env.WP_BASE_URL ?? 'http://127.0.0.1:9998';
  const adminUser = process.env.WP_ADMIN_USER ?? 'admin';
  const adminPass = process.env.WP_ADMIN_PASS ?? 'admin';

  const loginPath = getWpLoginPath();
  let loginVerified = false;
  let loginFailure = '';
  // A busy compatibility install can occasionally drop the first login POST
  // while dozens of plugin hooks initialise. Use a fresh cookie jar for one
  // retry, mirroring completeAdminLogin() instead of failing the whole suite
  // before any product assertion runs.
  for (let attempt = 1; attempt <= 2 && !loginVerified; attempt += 1) {
    const api = await request.newContext({
      baseURL,
      ignoreHTTPSErrors: true,
      timeout: 60_000,
    });
    try {
      const loginPage = await api.get(loginPath);
      if (!loginPage.ok()) {
        loginFailure = `login page status ${loginPage.status()}`;
        continue;
      }

      const loginResponse = await api.post(loginPath, {
        form: {
          log: adminUser,
          pwd: adminPass,
          'wp-submit': 'Log In',
          redirect_to: `${baseURL}/wp-admin/`,
          testcookie: '1',
        },
      });
      loginVerified = loginResponse.url().includes('/wp-admin');
      if (!loginVerified) {
        const body = await loginResponse.text().catch(() => '');
        const match = body.match(/<div[^>]+id=["']login_error["'][^>]*>([\s\S]*?)<\/div>/i);
        const detail = match ? match[1].replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim() : 'no login_error';
        loginFailure = `attempt ${attempt}: URL=${loginResponse.url()} status=${loginResponse.status()} ${detail}`;
      }
    } finally {
      await api.dispose();
    }
  }
  if (!loginVerified) {
    throw new Error(
      `WordPress login failed for user '${adminUser}' at ${baseURL}${loginPath}. ${loginFailure}. ` +
      'Check WP_ADMIN_USER/WP_ADMIN_PASS.',
    );
  }

  // Reachable, and the credentials work. Now hold the environment itself to
  // the suite's requirements — deployment freshness, server, permalinks,
  // WP_DEBUG, fixtures, and the plugin settings specs presume — so a dirty
  // or stale environment fails here, named, instead of surfacing later as a
  // handful of assertions that look like product regressions.
  await assertPrerequisites(baseURL);

  // Reset the active banner to a known clean shape and remove any secondary
  // banners left over by previous runs. Without this reset, specs that mutate
  // the active banner (CB-OV close-button override, multi-banner geo-routing)
  // can leave it in classic+pushdown across runs, which cascades into a
  // 14-fail run because later specs presuppose box+popup.
  //
  // WP_PATH is guaranteed by the preflight above, except when it was skipped
  // wholesale via FAZ_E2E_SKIP_PREFLIGHT — hence the guard remains.
  if (process.env.WP_PATH) {
    try {
      wpEval(`
        global $wpdb;
        $category_controller = \\FazCookie\\Admin\\Modules\\Cookies\\Includes\\Category_Controller::get_instance();
        $cookie_controller = \\FazCookie\\Admin\\Modules\\Cookies\\Includes\\Cookie_Controller::get_instance();
        $category_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}faz_cookie_categories" );
        if ( 0 === $category_count && method_exists( $category_controller, 'reinstall' ) ) {
          $category_controller->reinstall();
        }
        $fixture_categories = array(
          'necessary'     => 'faz_e2e_necessary_probe',
          'analytics'     => 'faz_e2e_analytics_probe',
          'functional'    => 'faz_e2e_functional_probe',
          'marketing'     => 'faz_e2e_marketing_probe',
          'performance'   => 'faz_e2e_performance_probe',
          'uncategorized' => 'faz_e2e_uncategorized_probe',
        );
        foreach ( $fixture_categories as $category_slug => $cookie_name ) {
          $category_id = (int) $wpdb->get_var(
            $wpdb->prepare(
              "SELECT category_id FROM {$wpdb->prefix}faz_cookie_categories WHERE slug = %s",
              $category_slug
            )
          );
          if ( $category_id <= 0 ) {
            continue;
          }
          $category_cookie_count = (int) $wpdb->get_var(
            $wpdb->prepare(
              "SELECT COUNT(*) FROM {$wpdb->prefix}faz_cookies WHERE category = %d",
              $category_id
            )
          );
          if ( 0 === $category_cookie_count ) {
            $now = current_time( 'mysql' );
            $wpdb->insert( $wpdb->prefix . 'faz_cookies', array(
              'name'          => $cookie_name,
              'slug'          => str_replace( '_', '-', $cookie_name ),
              'description'   => wp_json_encode( array( 'en' => 'E2E fixture cookie.' ) ),
              'duration'      => wp_json_encode( array( 'en' => 'Session' ) ),
              'domain'        => '127.0.0.1',
              'category'      => $category_id,
              'type'          => 'HTTP',
              'discovered'    => 0,
              'url_pattern'   => '',
              'meta'          => wp_json_encode( array() ),
              'date_created'  => $now,
              'date_modified' => $now,
            ) );
          }
        }
        $category_controller->delete_cache();
        $cookie_controller->delete_cache();

        $controller = \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance();
        $wpdb->query(
          $wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}faz_banners WHERE slug LIKE %s",
            'pr104-fu-%'
          )
        );
        $default_id = (int) $wpdb->get_var( "SELECT banner_id FROM {$wpdb->prefix}faz_banners WHERE banner_default = 1 ORDER BY banner_id ASC LIMIT 1" );
        if ( $default_id <= 0 ) {
          $controller->promote_fallback_default( 0 );
          $default_id = (int) $wpdb->get_var( "SELECT banner_id FROM {$wpdb->prefix}faz_banners WHERE banner_default = 1 ORDER BY banner_id ASC LIMIT 1" );
        }
        $banner = $default_id > 0 ? new \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Banner( $default_id ) : $controller->get_active_banner();
        if ( $banner ) {
          $s = $banner->get_settings();
          if ( ! is_array( $s ) ) { $s = array(); }
          if ( ! isset( $s['settings'] ) || ! is_array( $s['settings'] ) ) { $s['settings'] = array(); }
          // applicableLaw is the axis the ccpa specs flip to 'ccpa'; reset it
          // here too so a previous run's CCPA leak doesn't poison the first
          // GDPR-mode spec (mirrors utils/seed-defaults.ts).
          $s['settings']['applicableLaw'] = 'gdpr';
          $s['settings']['type'] = 'box';
          $s['settings']['preferenceCenterType'] = 'popup';
          $s['settings']['allowCloseButtonWithReject'] = false;
          if ( ! isset( $s['config'] ) || ! is_array( $s['config'] ) ) { $s['config'] = array(); }
          if ( ! isset( $s['config']['notice'] ) || ! is_array( $s['config']['notice'] ) ) { $s['config']['notice'] = array(); }
          if ( ! isset( $s['config']['notice']['elements'] ) || ! is_array( $s['config']['notice']['elements'] ) ) { $s['config']['notice']['elements'] = array(); }
          if ( ! isset( $s['config']['notice']['elements']['buttons'] ) || ! is_array( $s['config']['notice']['elements']['buttons'] ) ) { $s['config']['notice']['elements']['buttons'] = array(); }
          if ( ! isset( $s['config']['notice']['elements']['buttons']['elements'] ) || ! is_array( $s['config']['notice']['elements']['buttons']['elements'] ) ) { $s['config']['notice']['elements']['buttons']['elements'] = array(); }
          if ( ! isset( $s['config']['notice']['elements']['buttons']['elements']['donotSell'] ) || ! is_array( $s['config']['notice']['elements']['buttons']['elements']['donotSell'] ) ) {
            $s['config']['notice']['elements']['buttons']['elements']['donotSell'] = array();
          }
          $s['config']['notice']['elements']['buttons']['elements']['donotSell']['status'] = false;
            // The preference centre and its opener are gdpr.json defaults
            // (both true), but nothing restored them, so once a run left them
            // off every spec that opens the preference centre failed on a
            // banner that simply has no button to click — the accordion,
            // opt-out and per-cookie groups all died in a shared helper with
            // "a customize / settings button exists". Normalised here for the
            // same reason applicableLaw and type are.
            if ( ! isset( $s['config']['notice']['elements']['buttons']['elements']['settings'] ) || ! is_array( $s['config']['notice']['elements']['buttons']['elements']['settings'] ) ) {
            $s['config']['notice']['elements']['buttons']['elements']['settings'] = array();
            }
            $s['config']['notice']['elements']['buttons']['elements']['settings']['status'] = true;
            if ( ! isset( $s['config']['preferenceCenter'] ) || ! is_array( $s['config']['preferenceCenter'] ) ) { $s['config']['preferenceCenter'] = array(); }
            $s['config']['preferenceCenter']['status'] = true;
            // The accordion listeners in script.js are gated on auditTable.status
            // (an early return when config.auditTable.status is false), so with
            // it off the preference centre renders its accordions and NOTHING
            // binds to them: clicking a category does nothing at all. Another
            // gdpr.json default (true) that had drifted to false, unrestored.
            if ( ! isset( $s['config']['auditTable'] ) || ! is_array( $s['config']['auditTable'] ) ) { $s['config']['auditTable'] = array(); }
            $s['config']['auditTable']['status'] = true;
          if ( ! isset( $s['config']['optoutPopup'] ) || ! is_array( $s['config']['optoutPopup'] ) ) { $s['config']['optoutPopup'] = array(); }
          $s['config']['optoutPopup']['status'] = false;
          $banner->set_settings( $s );
          $banner->set_status( true );
          $banner->set_default( true );
          // Also reset row-level geo columns (target_countries / priority live
          // on the wp_faz_banners row, NOT inside settings — earlier code
          // unset them from settings, which was a no-op).
          if ( method_exists( $banner, 'set_target_countries' ) ) {
            $banner->set_target_countries( array() );
          }
          if ( method_exists( $banner, 'set_priority' ) ) {
            $banner->set_priority( 0 );
          }
          $banner->save();
        }
        // Do NOT delete non-active banner rows here. The multi-banner geo-
        // routing spec presupposes banner_id=2 exists (its tests mutate that
        // row to target US and assert on the picker output). A blanket DELETE
        // in global-setup wipes that fixture and the entire GEO suite fails.
        // Per-spec teardown (CB-OV-10) handles its own secondary banner
        // cleanup; cross-spec leakage is bounded by each spec's own
        // beforeAll/afterAll, not by a global blanket DELETE.
        // Reset the GLOBAL geo gate (faz_settings option, distinct from the
        // per-banner settings above). Specs like redundant-geo-routing-warning
        // turn geo_targeting on with default_behavior=no_banner; left behind,
        // Frontend::is_geo_banner_disabled() then suppresses the banner for
        // out-of-region visitors and breaks geo specs that assume a neutral
        // baseline (multi-banner-geo-routing GEO-19's US-visitor AMP path).
        $faz_settings = get_option( 'faz_settings', array() );
        if ( ! is_array( $faz_settings ) ) { $faz_settings = array(); }
        if ( ! isset( $faz_settings['geolocation'] ) || ! is_array( $faz_settings['geolocation'] ) ) {
          $faz_settings['geolocation'] = array();
        }
        $faz_settings['geolocation']['geo_targeting'] = false;
        // Reset the age gate for the same reason, and it is the more damaging
        // of the two. When enabled, an accept click is PARKED until the visitor
        // ticks the affirmation box, so no consent cookie is written — every
        // test that clicks Accept and waits for that cookie times out. It is
        // set by settings-options-behavior and v170-features F15, both of which
        // restore it correctly when they run to completion; an interrupted run
        // (a killed suite, a crashed worker) leaves { enabled: true } behind and
        // silently breaks accept-based specs across the whole instance from then
        // on. Found exactly that way: nine specs failing on main and on a
        // feature branch alike, all of them waiting for a cookie that a stale
        // age gate was suppressing.
        $faz_settings['age_gate'] = array( 'enabled' => false, 'min_age' => 16 );
        update_option( 'faz_settings', $faz_settings );
        delete_transient( 'faz_dismiss_redundant_geo_routing' );

        // Reset the GCM/GACM config once per run. The gcm/tcf specs enable GCM
        // and configure default signals; a spec that captures faz_gcm_settings
        // in beforeAll and "restores" it in afterAll otherwise restores the
        // polluted value the previous GCM spec left, propagating it down the
        // serial run. The plugin treats a missing option as GCM-disabled, which
        // is the shipped default and the baseline the non-GCM specs expect.
        delete_option( 'faz_gcm_settings' );

        delete_option( 'faz_banner_template' );
        if ( function_exists( 'faz_clear_banner_template_cache' ) ) {
          faz_clear_banner_template_cache();
        }
        $controller->delete_cache();
      `);
    } catch (error) {
      // Surface but don't abort — if the plugin isn't activated yet,
      // individual specs will fail with a clearer error.
      const msg = error instanceof Error ? error.message : String(error);
      console.warn(`[global-setup] banner reset skipped: ${msg.split('\n')[0]}`);
    }
  }
}

export default globalSetup;
