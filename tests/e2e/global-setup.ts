import { request } from '@playwright/test';
import { getWpLoginPath } from './utils/wp-auth';
import { wpEval } from './utils/wp-env';

async function globalSetup(): Promise<void> {
  const baseURL = process.env.WP_BASE_URL ?? 'http://localhost:9998';
  const adminUser = process.env.WP_ADMIN_USER ?? 'admin';
  const adminPass = process.env.WP_ADMIN_PASS ?? 'admin';

  const api = await request.newContext({
    baseURL,
    ignoreHTTPSErrors: true,
  });

  const loginPath = getWpLoginPath();
  const loginPage = await api.get(loginPath);
  if (!loginPage.ok()) {
    await api.dispose();
    throw new Error(`WordPress login page not reachable at ${baseURL}${loginPath} (status ${loginPage.status()}).`);
  }

  // Verify credentials actually work before running the full suite.
  const loginResponse = await api.post(loginPath, {
    form: {
      log: adminUser,
      pwd: adminPass,
      'wp-submit': 'Log In',
      redirect_to: '/wp-admin/',
      testcookie: '1',
    },
  });
  if (!loginResponse.url().includes('/wp-admin')) {
    await api.dispose();
    throw new Error(`WordPress login failed for user '${adminUser}' at ${baseURL}${loginPath}. Check WP_ADMIN_USER/WP_ADMIN_PASS.`);
  }

  await api.dispose();

  // Reset the active banner to a known clean shape and remove any secondary
  // banners left over by previous runs. Without this reset, specs that mutate
  // the active banner (CB-OV close-button override, multi-banner geo-routing)
  // can leave it in classic+pushdown across runs, which cascades into a
  // 14-fail run because later specs presuppose box+popup.
  //
  // WP_PATH may be unset on certain environments (e.g., dev machines that
  // run only individual specs). When unset, skip silently — wp-env throws
  // a clear error from any spec that needs it.
  if (process.env.WP_PATH) {
    try {
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
          $banner->set_settings( $s );
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
