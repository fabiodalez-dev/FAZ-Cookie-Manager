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
/**
 * Full known-good baseline reset for a spec file's beforeAll.
 *
 * resetDefaultBannerState() only covers the default banner row. Several specs
 * also mutate GLOBAL options that leak the same way: faz_gcm_settings (the
 * gcm/gacm specs turn GCM on and configure default signals) and
 * faz_settings.geolocation.geo_targeting (the geo specs flip the runtime gate).
 * A spec that captures the option at beforeAll and "restores" it in afterAll
 * actually restores whatever polluted value the PREVIOUS spec left, so the
 * pollution propagates down the alphabetical run. resetBaseline() restores the
 * option axes to the same known-good baseline global-setup.ts establishes once,
 * so every file that calls it starts from a clean slate regardless of run order.
 *
 * Idempotent, cheap, and a silent no-op when WP_PATH is unset. Call it in a
 * beforeAll (before the spec's own setup) in any spec that presupposes the
 * default GDPR box banner with GCM off and geo-routing off.
 */
export function resetBaseline(): void {
  if ( ! process.env.WP_PATH ) {
    return;
  }
  resetDefaultBannerState();
  wpEval( `
    // GCM off — the plugin treats a missing/empty faz_gcm_settings as disabled,
    // which is the shipped default and the baseline the non-GCM specs expect.
    delete_option( 'faz_gcm_settings' );

    // Geo runtime gate off (mirrors global-setup.ts). Preserve the rest of
    // faz_settings — only the geolocation.geo_targeting axis is a known polluter.
    $faz_settings = get_option( 'faz_settings', array() );
    if ( ! is_array( $faz_settings ) ) { $faz_settings = array(); }
    if ( ! isset( $faz_settings['geolocation'] ) || ! is_array( $faz_settings['geolocation'] ) ) {
      $faz_settings['geolocation'] = array();
    }
    $faz_settings['geolocation']['geo_targeting'] = false;

    // Language baseline. Specs that need another language set their own and
    // restore it (banner-i18n-non-english, multilingual-edge), but a run that is
    // interrupted — or a spec that fails before its afterAll — leaves the site
    // on whatever it had configured. Everything that asserts banner COPY then
    // reads Italian where it expects English, and reports it as a content bug:
    // reads the Italian banner title on a test that never mentions language.
    // Normalising here costs nothing for the specs that override it.
    // Only the DEFAULT is normalised, and 'en' is ensured present in the
    // selection. Replacing the whole languages block with English-only was the
    // first attempt and was too blunt: specs assert the settings payload and
    // several need other locales selected, so dropping them traded one class of
    // failure for another. The polluting axis is the default — it decides which
    // copy the banner renders — not the size of the selection.
    if ( ! isset( $faz_settings['languages'] ) || ! is_array( $faz_settings['languages'] ) ) {
      $faz_settings['languages'] = array();
    }
    $selected = $faz_settings['languages']['selected'] ?? array();
    if ( ! is_array( $selected ) ) { $selected = array(); }
    if ( ! in_array( 'en', $selected, true ) ) { array_unshift( $selected, 'en' ); }
    $faz_settings['languages']['selected'] = array_values( $selected );
    $faz_settings['languages']['default']  = 'en';

    update_option( 'faz_settings', $faz_settings );

    delete_option( 'faz_banner_template' );
    if ( function_exists( 'faz_clear_banner_template_cache' ) ) { faz_clear_banner_template_cache(); }

    // resetBaseline() runs through WP-CLI, where admin modules are normally
    // deferred. Bootstrap their REST hook and mirror the production settings
    // save event so active page caches cannot keep serving the pre-reset GCM
    // or geo configuration to the next spec.
    do_action( 'rest_api_init' );
    do_action( 'faz_after_update_settings', $faz_settings );
  ` );
}

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
      if ( method_exists( $banner, 'set_target_countries' ) ) { $banner->set_target_countries( array() ); }
      if ( method_exists( $banner, 'set_priority' ) ) { $banner->set_priority( 0 ); }
      $banner->save();
    }
    delete_option( 'faz_banner_template' );
    if ( function_exists( 'faz_clear_banner_template_cache' ) ) { faz_clear_banner_template_cache(); }
    $controller->delete_cache();
  `);
}
