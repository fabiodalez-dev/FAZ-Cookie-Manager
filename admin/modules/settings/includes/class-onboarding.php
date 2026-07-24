<?php
/**
 * Guided setup wizard helper.
 *
 * Encapsulates the compliance-critical logic that turns a chosen jurisdiction
 * into concrete, lawful banner configuration, and persists the onboarding
 * completion flags. Kept deliberately small and free of REST/HTTP concerns so
 * the mapping can be unit-tested directly (see tests/unit/test-onboarding-php.php).
 *
 * @link       https://fabiodalez.it/
 * @since      1.24.0
 * @package    FazCookie\Admin\Modules\Settings\Includes
 */

namespace FazCookie\Admin\Modules\Settings\Includes;

use FazCookie\Admin\Modules\Banners\Includes\Controller as Banner_Controller;
use FazCookie\Admin\Modules\Banners\Includes\Banner;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Onboarding wizard operations.
 *
 * @class       Onboarding
 * @version     1.24.0
 * @package     FazCookie
 */
class Onboarding {

	/**
	 * The set of jurisdiction choices the wizard accepts.
	 *
	 * @var string[]
	 */
	const LAWS = array( 'gdpr', 'ccpa', 'both' );

	/**
	 * Geo-targeting region keys the wizard accepts — must stay in sync with the
	 * $region_labels list rendered on Settings → Geolocation (admin/views/settings.php).
	 *
	 * @var string[]
	 */
	const REGIONS = array( 'eu', 'uk', 'us', 'ca', 'br', 'au', 'jp', 'ch' );

	/**
	 * The banner_control switches the wizard is allowed to write. A strict
	 * allowlist so a forged payload can never toggle switches the wizard UI
	 * does not present (e.g. ab_test or status).
	 *
	 * @var string[]
	 */
	const BANNER_CONTROL_KEYS = array(
		'per_service_consent',
		'gtm_datalayer',
		'hide_from_bots',
		'cache_compatibility',
		'adblock_resilience',
	);

	/**
	 * Translate a chosen jurisdiction into the exact banner fields to write.
	 *
	 * The mapping is the single source of truth for the wizard's compliance
	 * posture and mirrors the encoding banner.js writes when the admin picks a
	 * law manually (admin/assets/js/pages/banner.js):
	 *
	 *  - gdpr : opt-in model, equal-weight Accept/Reject controls, no US
	 *           opt-out entry point, 180-day consent lifetime.
	 *  - ccpa : opt-out notice model, first-party "Do Not Sell or Share"
	 *           entry point + opt-out popup enabled, without GDPR Accept/Reject
	 *           controls, and a 365-day preference lifetime.
	 *  - both : mixed EU+US audience. Stored as applicableLaw='gdpr' (the MORE
	 *           protective opt-in model governs the banner, so EU visitors are
	 *           never downgraded to opt-out) WITH the US Do-Not-Sell entry point
	 *           also rendered. This is the plugin's established "gdpr_ccpa"
	 *           encoding (see Banner::apply_runtime_law_content_compatibility).
	 *
	 * These fields are explicit because the wizard changes the existing default
	 * GDPR row in place. Relying on the stored row's previous values would leave
	 * a CCPA selection with GDPR buttons and a 180-day expiry even though the
	 * review step promises the canonical 365-day opt-out configuration.
	 *
	 * @param string $law One of self::LAWS.
	 * @return array|null Banner field map, or null for an unknown law.
	 */
	public static function map_law_to_banner_fields( $law ) {
		switch ( $law ) {
			case 'gdpr':
				return array(
					'applicableLaw' => 'gdpr',
					'donotSell'     => false,
					'optoutPopup'   => false,
					'consentExpiry' => 180,
					'noticeButtons' => true,
				);
			case 'ccpa':
				return array(
					'applicableLaw' => 'ccpa',
					'donotSell'     => true,
					'optoutPopup'   => true,
					'consentExpiry' => 365,
					'noticeButtons' => false,
				);
			case 'both':
				return array(
					'applicableLaw' => 'gdpr',
					'donotSell'     => true,
					'optoutPopup'   => true,
					'consentExpiry' => 180,
					'noticeButtons' => true,
				);
			default:
				return null;
		}
	}

	/**
	 * Apply the chosen jurisdiction to the site's default/active banner.
	 *
	 * Only the law-model fields are touched — applicableLaw, consent lifetime,
	 * canonical notice controls, Do-Not-Sell, and the opt-out popup container.
	 * Colours, copy, layout and every other customisation are preserved. The write goes through
	 * the normal Banner::set_settings()/save() path so cache invalidation and the
	 * standard sanitisation cascade run exactly as they do for a manual save.
	 *
	 * @param string $law One of self::LAWS.
	 * If no global/default banner is available (for example, an install contains
	 * only country-targeted banners), a new default banner is created instead of
	 * overwriting one of those specialised rows.
	 *
	 * @return true|WP_Error True on success; WP_Error when validation or creation fails.
	 */
	public function apply_law_to_default_banner( $law ) {
		$fields = self::map_law_to_banner_fields( $law );
		if ( null === $fields ) {
			return new WP_Error(
				'faz_invalid_onboarding_law',
				__( 'Choose a valid privacy law before finishing setup.', 'faz-cookie-manager' ),
				array( 'status' => 400 )
			);
		}

		$controller = Banner_Controller::get_instance();
		$banner     = $controller->get_active_banner();
		if ( $banner && ! $banner->get_default() && ! empty( $banner->get_target_countries() ) ) {
			// get_active_banner() deliberately falls back to an active targeted
			// row when no global/default row exists (legacy caller compatibility).
			// That fallback is not safe for onboarding: changing it would silently
			// rewrite a country-specific law. Create a new global banner below.
			$banner = false;
		}
		if ( ! $banner ) {
			// Do not repurpose a country/law-specific banner. A fresh Banner starts
			// from the bundled multilingual defaults and becomes the global fallback.
			$banner = new Banner();
			$name   = 'ccpa' === $law ? __( 'CCPA', 'faz-cookie-manager' ) : __( 'GDPR', 'faz-cookie-manager' );
			$banner->set_name( $name );
			$banner->set_default( true );
		}
		$banner->set_status( true );

		$properties = $banner->get_settings();
		if ( ! is_array( $properties ) ) {
			$properties = array();
		}
		if ( ! isset( $properties['settings'] ) || ! is_array( $properties['settings'] ) ) {
			$properties['settings'] = array();
		}
		$properties['settings']['applicableLaw'] = $fields['applicableLaw'];
		self::set_nested(
			$properties,
			array( 'settings', 'consentExpiry', 'status' ),
			true
		);
		self::set_nested(
			$properties,
			array( 'settings', 'consentExpiry', 'value' ),
			$fields['consentExpiry']
		);

		// A pure CCPA banner is an opt-out notice. Leaving the GDPR Accept/Reject
		// controls visible is not merely cosmetic: the CCPA runtime interprets its
		// opt-out checkbox, so a GDPR-style "Reject" click could be logged as a
		// rejection while optional categories remained allowed. GDPR and Both use
		// the normal equal-weight notice controls; CCPA exposes Do Not Sell only.
		foreach ( array( 'accept', 'reject', 'settings', 'readMore' ) as $button ) {
			self::set_nested(
				$properties,
				array( 'config', 'notice', 'elements', 'buttons', 'elements', $button, 'status' ),
				$fields['noticeButtons']
			);
		}

		// Canonical Do-Not-Sell flag: the nested buttons.elements.donotSell branch
		// is the one that survives sanitize_settings (it exists in the bundled
		// default config); the legacy notice.elements.donotSell key is dropped.
		self::set_nested(
			$properties,
			array( 'config', 'notice', 'elements', 'buttons', 'elements', 'donotSell', 'status' ),
			$fields['donotSell']
		);
		self::set_nested(
			$properties,
			array( 'config', 'notice', 'elements', 'buttons', 'elements', 'donotSell', 'tag' ),
			'donotsell-button'
		);
		// The US opt-out modal container. GDPR defaults keep it off; CCPA/Both
		// enable it so the Do-Not-Sell entry point never targets missing UI.
		self::set_nested(
			$properties,
			array( 'config', 'optoutPopup', 'status' ),
			$fields['optoutPopup']
		);

		$banner->set_settings( $properties );
		$saved_id = $banner->save();
		if ( ! $saved_id ) {
			return new WP_Error(
				'faz_onboarding_banner_save_failed',
				__( 'The cookie banner could not be created. Please try again.', 'faz-cookie-manager' ),
				array( 'status' => 500 )
			);
		}

		// Store::save() returns the object's ID for updates even if the underlying
		// database UPDATE fails. Re-read the row and verify every promised field so
		// onboarding cannot be marked complete on a half-persisted configuration.
		$persisted = new Banner( (int) $saved_id );
		if ( ! self::banner_matches_law_fields( $persisted, $fields ) ) {
			return new WP_Error(
				'faz_onboarding_banner_save_failed',
				__( 'Setup could not be saved. Please try again.', 'faz-cookie-manager' ),
				array( 'status' => 500 )
			);
		}

		return true;
	}

	/**
	 * Confirm that the persisted banner matches the configuration promised by
	 * the wizard review screen.
	 *
	 * @param Banner $banner Persisted banner instance.
	 * @param array  $fields Canonical law field map.
	 * @return bool
	 */
	private static function banner_matches_law_fields( $banner, $fields ) {
		if ( ! $banner->get_id() || ! $banner->get_status() ) {
			return false;
		}
		$properties = $banner->get_settings();
		if ( ! is_array( $properties ) ) {
			return false;
		}
		$buttons = $properties['config']['notice']['elements']['buttons']['elements'] ?? array();
		foreach ( array( 'accept', 'reject', 'settings', 'readMore' ) as $button ) {
			$status = ! empty( $buttons[ $button ]['status'] );
			if ( $status !== $fields['noticeButtons'] ) {
				return false;
			}
		}

		return ( $properties['settings']['applicableLaw'] ?? '' ) === $fields['applicableLaw']
			&& (int) ( $properties['settings']['consentExpiry']['value'] ?? 0 ) === $fields['consentExpiry']
			&& ! empty( $properties['settings']['consentExpiry']['status'] )
			&& ( ! empty( $buttons['donotSell']['status'] ) ) === $fields['donotSell']
			&& ( ! empty( $properties['config']['optoutPopup']['status'] ) ) === $fields['optoutPopup'];
	}

	/**
	 * Finish the wizard: apply the law, ensure the accountability baseline, and
	 * persist the onboarding completion flags.
	 *
	 * The accountability baseline (banner visible + consent logging on) satisfies
	 * GDPR Art. 5(2)/7(1). On a fresh install these are already the defaults; the
	 * wizard only re-asserts them so a completed setup is demonstrably compliant.
	 *
	 * @param string $law     Chosen jurisdiction ('gdpr' | 'ccpa' | 'both').
	 * @param array  $options Optional wizard selections beyond the law step. Recognised
	 *                        keys (all optional; anything else is ignored):
	 *                        'language' (string), 'banner_control' (bool map limited to
	 *                        BANNER_CONTROL_KEYS), 'gcm' (['enabled'=>bool]),
	 *                        'microsoft' (['uet_consent_mode','clarity_consent']),
	 *                        'iab' (['enabled','cmp_id','publisher_cc']),
	 *                        'geolocation' (['geo_targeting','target_regions','default_behavior']),
	 *                        'payment_gateways' (string[] of catalog keys to opt in).
	 * @return array|WP_Error {
	 *     @type bool   $success        True after banner and settings are persisted.
	 *     @type bool   $banner_applied Whether the default banner was law-switched.
	 *     @type string $law            The persisted, validated jurisdiction.
	 *     @type string $warning        Advisory message(s); '' when there are none.
	 * }
	 */
	public function finish( $law, $options = array() ) {
		if ( ! in_array( $law, self::LAWS, true ) ) {
			return new WP_Error(
				'faz_invalid_onboarding_law',
				__( 'Choose a valid privacy law before finishing setup.', 'faz-cookie-manager' ),
				array( 'status' => 400 )
			);
		}
		if ( ! is_array( $options ) ) {
			$options = array();
		}

		$applied = $this->apply_law_to_default_banner( $law );
		if ( is_wp_error( $applied ) ) {
			// Never mark onboarding complete when no banner was configured. The
			// wizard's success state must mean the requested setup actually exists.
			return $applied;
		}

		$settings_obj = new Settings();
		$all          = $settings_obj->get();
		if ( ! is_array( $all ) ) {
			$all = array();
		}

		// Accountability baseline — banner shown, consent recorded.
		if ( ! isset( $all['banner_control'] ) || ! is_array( $all['banner_control'] ) ) {
			$all['banner_control'] = array();
		}
		$all['banner_control']['status'] = true;
		if ( ! isset( $all['consent_logs'] ) || ! is_array( $all['consent_logs'] ) ) {
			$all['consent_logs'] = array();
		}
		$all['consent_logs']['status'] = true;

		if ( ! isset( $all['onboarding'] ) || ! is_array( $all['onboarding'] ) ) {
			$all['onboarding'] = array();
		}
		$all['onboarding']['completed'] = true;
		$all['onboarding']['dismissed'] = false;
		$all['onboarding']['law']       = $law;

		// Fold the optional step selections into the same settings write so a
		// finished wizard is one atomic Settings::update (plus the separate GCM
		// option below). Warnings collect advisory, non-fatal notes.
		$warnings = $this->apply_options( $options, $all );

		$settings_obj->update( $all );

		// GCM lives in its own option (faz_gcm_settings) with its own sanitiser.
		if ( isset( $options['gcm']['enabled'] ) ) {
			$gcm = new \FazCookie\Admin\Modules\Gcm\Includes\Gcm_Settings();
			$gcm->update( array( 'status' => (bool) $options['gcm']['enabled'] ) );
		}

		// Regenerate the cached banner template so the law change reaches the
		// frontend on the next request (Settings::update already fires
		// faz_after_update_settings, but the banner save above changed the
		// banner row too — belt and braces for cache-compatibility mode).
		if ( function_exists( 'faz_clear_banner_template_cache' ) ) {
			faz_clear_banner_template_cache();
		}

		return array(
			'success'        => true,
			'banner_applied' => true,
			'law'            => $law,
			'warning'        => implode( ' ', $warnings ),
		);
	}

	/**
	 * Fold the optional wizard selections into the settings array (mutated in
	 * place, persisted by the caller's single Settings::update). Every value is
	 * validated against a strict allowlist here AND re-sanitised downstream by
	 * Settings::sanitize, so a forged payload cannot write outside the wizard's
	 * surface.
	 *
	 * @param array $options Raw options from the REST layer.
	 * @param array $all     Full settings array to mutate.
	 * @return string[] Advisory warnings for the wizard's success toast.
	 */
	private function apply_options( array $options, array &$all ) {
		$warnings = array();

		// Banner default language — validated against the Languages catalogue.
		if ( isset( $options['language'] ) && is_string( $options['language'] ) && '' !== $options['language'] ) {
			$lang      = strtolower( sanitize_text_field( $options['language'] ) );
			$available = array();
			if ( class_exists( '\\FazCookie\\Admin\\Modules\\Languages\\Includes\\Controller' ) ) {
				$available = array_values( \FazCookie\Admin\Modules\Languages\Includes\Controller::get_instance()->get_languages() );
			}
			if ( in_array( $lang, $available, true ) ) {
				if ( ! isset( $all['languages'] ) || ! is_array( $all['languages'] ) ) {
					$all['languages'] = array();
				}
				$all['languages']['default'] = $lang;
				$selected                    = isset( $all['languages']['selected'] ) && is_array( $all['languages']['selected'] )
					? $all['languages']['selected']
					: array();
				if ( ! in_array( $lang, $selected, true ) ) {
					$selected[] = $lang;
				}
				$all['languages']['selected'] = array_values( array_unique( $selected ) );
			}
		}

		// Banner control switches — strict allowlist, boolean coercion.
		if ( isset( $options['banner_control'] ) && is_array( $options['banner_control'] ) ) {
			foreach ( self::BANNER_CONTROL_KEYS as $key ) {
				if ( array_key_exists( $key, $options['banner_control'] ) ) {
					$all['banner_control'][ $key ] = (bool) $options['banner_control'][ $key ];
				}
			}
			// Enabling Cache Compatibility Mode pauses the server-side A/B
			// banner split (Frontend::maybe_apply_ab_test skips under it). The
			// wizard has no A/B surface, so on re-entry an admin with a running
			// experiment would never learn why it stopped — say it.
			if ( ! empty( $all['banner_control']['cache_compatibility'] )
				&& ! empty( $all['banner_control']['ab_test']['status'] ) ) {
				$warnings[] = __( 'Cache Compatibility Mode pauses the running A/B banner test: variants are only split server-side, which this mode disables.', 'faz-cookie-manager' );
			}
		}

		// Microsoft consent signals (UET / Clarity).
		if ( isset( $options['microsoft'] ) && is_array( $options['microsoft'] ) ) {
			if ( ! isset( $all['microsoft'] ) || ! is_array( $all['microsoft'] ) ) {
				$all['microsoft'] = array();
			}
			foreach ( array( 'uet_consent_mode', 'clarity_consent' ) as $key ) {
				if ( array_key_exists( $key, $options['microsoft'] ) ) {
					$all['microsoft'][ $key ] = (bool) $options['microsoft'][ $key ];
				}
			}
		}

		// IAB TCF. The frontend only activates TCF with a registered CMP ID >= 2
		// (frontend/class-frontend.php), and the TC-string CmpId field is 12 bits
		// (max 4095) — silently clamping an out-of-range ID would sign TC strings
		// attributed to a DIFFERENT CMP. Refuse both cases and tell the admin why.
		if ( isset( $options['iab'] ) && is_array( $options['iab'] ) ) {
			if ( ! isset( $all['iab'] ) || ! is_array( $all['iab'] ) ) {
				$all['iab'] = array();
			}
			$iab_enabled = ! empty( $options['iab']['enabled'] );
			$cmp_id      = isset( $options['iab']['cmp_id'] ) ? absint( $options['iab']['cmp_id'] ) : 0;
			if ( $cmp_id > 0 && $cmp_id <= 4095 ) {
				$all['iab']['cmp_id'] = $cmp_id;
			}
			if ( isset( $options['iab']['publisher_cc'] ) ) {
				// Format enforcement (2-letter uppercase) happens in Settings::sanitize_option.
				$all['iab']['publisher_cc'] = (string) $options['iab']['publisher_cc'];
			}
			if ( $iab_enabled && ( $cmp_id < 2 || $cmp_id > 4095 ) ) {
				$iab_enabled = false;
				$warnings[]  = __( 'IAB TCF was not enabled: it requires a registered CMP ID between 2 and 4095. Add it under Settings and re-enable TCF there.', 'faz-cookie-manager' );
			}
			$all['iab']['enabled'] = $iab_enabled;
		}

		// Geo targeting.
		if ( isset( $options['geolocation'] ) && is_array( $options['geolocation'] ) ) {
			if ( ! isset( $all['geolocation'] ) || ! is_array( $all['geolocation'] ) ) {
				$all['geolocation'] = array();
			}
			if ( array_key_exists( 'geo_targeting', $options['geolocation'] ) ) {
				$all['geolocation']['geo_targeting'] = (bool) $options['geolocation']['geo_targeting'];
			}
			if ( isset( $options['geolocation']['target_regions'] ) && is_array( $options['geolocation']['target_regions'] ) ) {
				$regions = array_values( array_intersect( array_map( 'sanitize_key', $options['geolocation']['target_regions'] ), self::REGIONS ) );
				if ( ! empty( $regions ) ) {
					$all['geolocation']['target_regions'] = $regions;
				} elseif ( ! empty( $all['geolocation']['geo_targeting'] ) ) {
					// Geo targeting with zero regions would hide the banner for
					// everyone under 'no_banner' — keep the safe default set instead.
					$all['geolocation']['target_regions'] = array( 'eu', 'uk' );
				}
			}
			if ( isset( $options['geolocation']['default_behavior'] ) ) {
				$behavior = sanitize_key( $options['geolocation']['default_behavior'] );
				$all['geolocation']['default_behavior'] = in_array( $behavior, array( 'show_banner', 'no_banner' ), true ) ? $behavior : 'show_banner';
			}
		}

		// Per-gateway payment opt-ins. Two accepted shapes:
		//  - map  { key => bool }: the wizard's canonical form — the checkbox
		//    state of every gateway it SHOWED, so unticking a previously
		//    opted-in gateway genuinely disables it (the review must never
		//    show "off" while the stored value stays on);
		//  - list [ key, ... ]: legacy opt-in-only form, kept for
		//    backward compatibility (missing keys keep their state).
		// Gateways the wizard did not mention are never touched.
		if ( isset( $options['payment_gateways'] ) && is_array( $options['payment_gateways'] ) ) {
			$valid = self::payment_gateway_keys();
			if ( ! isset( $all['script_blocking'] ) || ! is_array( $all['script_blocking'] ) ) {
				$all['script_blocking'] = array();
			}
			if ( ! isset( $all['script_blocking']['payment_gateways'] ) || ! is_array( $all['script_blocking']['payment_gateways'] ) ) {
				$all['script_blocking']['payment_gateways'] = array();
			}
			foreach ( $options['payment_gateways'] as $key => $value ) {
				if ( is_int( $key ) ) {
					// Legacy list form: each entry is a gateway key to enable.
					$gateway = sanitize_key( $value );
					$enabled = true;
				} else {
					$gateway = sanitize_key( $key );
					$enabled = (bool) $value;
				}
				if ( in_array( $gateway, $valid, true ) ) {
					$all['script_blocking']['payment_gateways'][ $gateway ] = $enabled;
				}
			}
		}

		return $warnings;
	}

	/**
	 * The payment gateway keys the wizard may opt in.
	 *
	 * @return string[]
	 */
	public static function payment_gateway_keys() {
		if ( class_exists( '\\FazCookie\\Frontend\\Frontend' ) ) {
			return array_keys( \FazCookie\Frontend\Frontend::payment_gateway_catalog() );
		}
		return array( 'paypal', 'stripe', 'square', 'braintree', 'klarna', 'mollie', 'amazon_pay' );
	}

	/**
	 * Environment-aware suggestions for the wizard: detected page-cache plugin,
	 * Google tag presence, WooCommerce, payment gateways (from active plugins
	 * and from cookies found by the scanner), and the site's language.
	 *
	 * Detection is read-only and conservative — a miss only means no suggestion
	 * badge; every switch remains available manually.
	 *
	 * @return array {
	 *     @type string $site_language Two-letter (or 'zh-hans'-style) code from the WP locale.
	 *     @type string $cache_plugin  Human label of the detected page-cache plugin, or ''.
	 *     @type bool   $google_tags   Whether Google tags (Site Kit / GA cookies) were detected.
	 *     @type bool   $woocommerce   Whether WooCommerce is active.
	 *     @type array  $gateways      List of ['key','label','source'] detected payment gateways.
	 * }
	 */
	public function get_recommendations() {
		return array(
			'site_language' => self::site_language(),
			'cache_plugin'  => self::detect_cache_plugin(),
			'google_tags'   => self::detect_google_tags(),
			'woocommerce'   => class_exists( 'WooCommerce' ),
			'gateways'      => self::detect_gateways(),
		);
	}

	/**
	 * Map the WP locale to a Languages-catalogue code ('it_IT' → 'it').
	 *
	 * Public because the wizard view uses it to pre-select the language step.
	 *
	 * @return string
	 */
	public static function site_language() {
		$locale = function_exists( 'get_user_locale' ) ? get_user_locale() : get_locale();
		$locale = strtolower( (string) $locale );
		// Chinese locales map onto the script-specific catalogue entries.
		if ( 0 === strpos( $locale, 'zh_tw' ) || 0 === strpos( $locale, 'zh_hk' ) ) {
			return 'zh-hant';
		}
		if ( 0 === strpos( $locale, 'zh' ) ) {
			return 'zh-hans';
		}
		// Brazilian Portuguese has its own catalogue entry (and its own bundled
		// banner translation) — plain 'pt' would serve the European variant.
		if ( 0 === strpos( $locale, 'pt_br' ) ) {
			return 'pt-br';
		}
		$parts = explode( '_', $locale );
		return preg_match( '/^[a-z]{2,3}$/', $parts[0] ) ? $parts[0] : 'en';
	}

	/**
	 * Detect an active full-page-cache plugin, using the same runtime signals as
	 * the cache-purge integrations (admin/modules/cache/services/*).
	 *
	 * @return string Human-readable plugin name, or '' when none detected.
	 */
	private static function detect_cache_plugin() {
		$signals = array(
			'WP Rocket'            => function_exists( 'rocket_clean_domain' ),
			'LiteSpeed Cache'      => class_exists( '\\LiteSpeed\\Purge' ),
			'W3 Total Cache'       => function_exists( 'w3tc_pgcache_flush' ),
			'WP Super Cache'       => function_exists( 'wp_cache_clean_cache' ),
			'WP Fastest Cache'     => function_exists( 'wpfc_clear_all_cache' ),
			'FlyingPress'          => class_exists( '\\FlyingPress\\Purge' ),
			'Hummingbird'          => class_exists( '\\Hummingbird\\WP_Hummingbird' ),
			'Breeze'               => class_exists( 'Breeze_Admin' ),
			'Cache Enabler'        => class_exists( 'Cache_Enabler' ),
			'SiteGround Optimizer' => function_exists( 'sg_cachepress_purge_cache' ),
		);
		foreach ( $signals as $label => $active ) {
			if ( $active ) {
				return $label;
			}
		}
		return '';
	}

	/**
	 * Whether Google tags are plausibly present: a Google integration plugin is
	 * active, or the scanner already found Google Analytics / Ads cookies.
	 *
	 * @return bool
	 */
	private static function detect_google_tags() {
		if ( defined( 'GOOGLESITEKIT_VERSION' ) || class_exists( 'MonsterInsights' ) || function_exists( 'monsterinsights' ) ) {
			return true;
		}
		foreach ( self::discovered_cookies() as $cookie ) {
			$name = strtolower( $cookie['name'] );
			if ( 0 === strpos( $name, '_ga' ) || '_gid' === $name || 0 === strpos( $name, '_gcl' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Detect payment gateways from active plugins and scanner-discovered cookies.
	 *
	 * @return array[] Each entry: ['key' => catalog key, 'label' => human name,
	 *                 'source' => 'plugin'|'scan'].
	 */
	private static function detect_gateways() {
		$catalog = array();
		if ( class_exists( '\\FazCookie\\Frontend\\Frontend' ) ) {
			$catalog = \FazCookie\Frontend\Frontend::payment_gateway_catalog();
		}

		// Active-plugin signals. Checked against the active_plugins option
		// directly so no wp-admin/includes/plugin.php load is needed in REST.
		$active_plugins = (array) get_option( 'active_plugins', array() );
		$plugin_map     = array(
			'stripe'     => array( 'woocommerce-gateway-stripe/woocommerce-gateway-stripe.php' ),
			'paypal'     => array( 'woocommerce-paypal-payments/woocommerce-paypal-payments.php' ),
			'mollie'     => array( 'mollie-payments-for-woocommerce/mollie-payments-for-woocommerce.php' ),
			'square'     => array( 'woocommerce-square/woocommerce-square.php' ),
			'braintree'  => array( 'woocommerce-paypal-powered-by-braintree/woocommerce-paypal-powered-by-braintree.php' ),
			'klarna'     => array( 'klarna-payments-for-woocommerce/klarna-payments-for-woocommerce.php', 'klarna-checkout-for-woocommerce/klarna-checkout-for-woocommerce.php' ),
			'amazon_pay' => array( 'woocommerce-gateway-amazon-payments-advanced/woocommerce-gateway-amazon-payments-advanced.php' ),
		);

		// Cookie/domain signals from the scanner's discoveries.
		$scan_map = array(
			'stripe'     => array( '__stripe', 'stripe.com' ),
			'paypal'     => array( 'paypal' ),
			'klarna'     => array( 'klarna' ),
			'mollie'     => array( 'mollie' ),
			'braintree'  => array( 'braintree' ),
			'square'     => array( 'squareup.com' ),
			'amazon_pay' => array( 'payments-amazon' ),
		);
		$discovered = self::discovered_cookies();

		// Current opt-in state, so the wizard can pre-tick already-enabled
		// gateways instead of showing them off while they stay always-allowed.
		$settings_obj = new Settings();
		$current      = $settings_obj->get( 'script_blocking' );
		$current_map  = isset( $current['payment_gateways'] ) && is_array( $current['payment_gateways'] )
			? $current['payment_gateways']
			: array();

		$found = array();
		foreach ( self::payment_gateway_keys() as $key ) {
			$label  = isset( $catalog[ $key ]['label'] ) ? $catalog[ $key ]['label'] : ucfirst( $key );
			$source = '';
			foreach ( isset( $plugin_map[ $key ] ) ? $plugin_map[ $key ] : array() as $plugin_file ) {
				if ( in_array( $plugin_file, $active_plugins, true ) ) {
					$source = 'plugin';
					break;
				}
			}
			if ( '' === $source && isset( $scan_map[ $key ] ) ) {
				foreach ( $discovered as $cookie ) {
					$haystack = strtolower( $cookie['name'] . ' ' . $cookie['domain'] );
					foreach ( $scan_map[ $key ] as $needle ) {
						if ( false !== strpos( $haystack, $needle ) ) {
							$source = 'scan';
							break 2;
						}
					}
				}
			}
			// An already-enabled gateway is always listed (even without a fresh
			// detection signal) so the wizard shows — and lets the admin change —
			// its true stored state.
			$enabled = ! empty( $current_map[ $key ] );
			if ( '' !== $source || $enabled ) {
				$found[] = array(
					'key'     => $key,
					'label'   => $label,
					'source'  => '' !== $source ? $source : 'enabled',
					'enabled' => $enabled,
				);
			}
		}
		return $found;
	}

	/**
	 * Scanner-discovered cookies (name + domain), cached per request.
	 *
	 * @return array[] Each entry: ['name' => string, 'domain' => string].
	 */
	private static function discovered_cookies() {
		static $rows = null;
		if ( null !== $rows ) {
			return $rows;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'faz_cookies';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- read-only detection over the plugin's own prefix+literal table; result is memoised for the request; no user input in the query.
		$results = $wpdb->get_results( "SELECT name, domain FROM {$table} WHERE discovered = 1 LIMIT 500", ARRAY_A );
		$rows    = array();
		foreach ( is_array( $results ) ? $results : array() as $row ) {
			$rows[] = array(
				'name'   => isset( $row['name'] ) ? (string) $row['name'] : '',
				'domain' => isset( $row['domain'] ) ? (string) $row['domain'] : '',
			);
		}
		return $rows;
	}

	/**
	 * Whether onboarding has been completed (or the install predates the wizard).
	 *
	 * @return bool
	 */
	public function is_complete() {
		$settings_obj = new Settings();
		$onboarding   = $settings_obj->get( 'onboarding' );
		return ! empty( $onboarding['completed'] );
	}

	/**
	 * The jurisdiction stored from a previous run, for wizard re-entry.
	 *
	 * @return string One of '' | 'gdpr' | 'ccpa' | 'both'.
	 */
	public function get_law() {
		$settings_obj = new Settings();
		$onboarding   = $settings_obj->get( 'onboarding' );
		$law          = isset( $onboarding['law'] ) ? $onboarding['law'] : '';
		return in_array( $law, self::LAWS, true ) ? $law : '';
	}

	/**
	 * Assign a value at a nested array path, creating intermediate arrays.
	 *
	 * @param array    $arr   Array to mutate (by reference).
	 * @param string[] $path  Ordered list of keys.
	 * @param mixed    $value Value to set at the leaf.
	 * @return void
	 */
	private static function set_nested( array &$arr, array $path, $value ) {
		$ref   = &$arr;
		$last  = count( $path ) - 1;
		foreach ( $path as $i => $key ) {
			if ( $i === $last ) {
				$ref[ $key ] = $value;
				break;
			}
			if ( ! isset( $ref[ $key ] ) || ! is_array( $ref[ $key ] ) ) {
				$ref[ $key ] = array();
			}
			$ref = &$ref[ $key ];
		}
		unset( $ref );
	}
}
