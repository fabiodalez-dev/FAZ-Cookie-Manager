/**
 * Shared default-state provisioner for the E2E suite.
 *
 * The suite seeds a known-good baseline ONCE in global-setup.ts, but several
 * specs deliberately mutate that shared state mid-run (the close-button
 * override spec flips the banner to classic+pushdown, the multi-banner
 * geo-routing spec retargets it, the ccpa specs switch the banner's
 * applicableLaw to 'ccpa'). With a single worker and serial,
 * alphabetically-ordered execution those mutations leak into every later spec
 * that presupposes the default box+popup GDPR banner — which is why a
 * green-in-isolation spec like css-custom-properties / a11y / compliance-fixes
 * can still fail inside the full suite.
 *
 * Rather than chase every polluter's afterAll (fragile — a crashed teardown
 * re-leaks), the victims self-provision their precondition: call
 * resetDefaultBannerState() in a beforeAll. Idempotent and cheap.
 *
 * The banner-config reset mirrors the block global-setup.ts runs at suite
 * start (global-setup imports this helper) and ADDITIONALLY restores
 * applicableLaw='gdpr' — the one axis global-setup historically missed, which
 * let ccpa-revisit's law switch leak into later GDPR-mode specs.
 */
import { wpEval } from './wp-env';

/**
 * Restore the default banner to the canonical baseline the frontend-banner
 * specs presuppose: active, default, applicableLaw=gdpr, type=box,
 * preferenceCenterType=popup, allowCloseButtonWithReject=false, no geo
 * targeting/priority. Busts the cached banner template so the next frontend
 * render rebuilds from the reset config.
 *
 * No-op (silent) when WP_PATH is unset — wpEval throws a clear error from any
 * spec that genuinely needs WP-CLI, so we don't double-report here.
 */
export function resetDefaultBannerState(): void {
  if (!process.env.WP_PATH) return;
  wpEval(`
    global $wpdb;
    $controller = \\FazCookie\\Admin\\Modules\\Banners\\Includes\\Controller::get_instance();
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
      $s['settings']['applicableLaw'] = 'gdpr';
      $s['settings']['type'] = 'box';
      $s['settings']['preferenceCenterType'] = 'popup';
      $s['settings']['allowCloseButtonWithReject'] = false;
      $banner->set_settings( $s );
      $banner->set_status( true );
      $banner->set_default( true );
      if ( method_exists( $banner, 'set_target_countries' ) ) { $banner->set_target_countries( array() ); }
      if ( method_exists( $banner, 'set_priority' ) ) { $banner->set_priority( 0 ); }
      $banner->save();
    }
    delete_option( 'faz_banner_template' );
    if ( function_exists( 'faz_clear_banner_template_cache' ) ) { faz_clear_banner_template_cache(); }
    $controller->delete_cache();
  `);
}
