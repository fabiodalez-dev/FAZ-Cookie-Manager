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
	 * @param string $law Chosen jurisdiction ('gdpr' | 'ccpa' | 'both').
	 * @return array|WP_Error {
	 *     @type bool   $success        True after banner and settings are persisted.
	 *     @type bool   $banner_applied Whether the default banner was law-switched.
	 *     @type string $law            The persisted, validated jurisdiction.
	 *     @type string $warning        Reserved advisory message; currently ''.
	 * }
	 */
	public function finish( $law ) {
		if ( ! in_array( $law, self::LAWS, true ) ) {
			return new WP_Error(
				'faz_invalid_onboarding_law',
				__( 'Choose a valid privacy law before finishing setup.', 'faz-cookie-manager' ),
				array( 'status' => 400 )
			);
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

		$settings_obj->update( $all );

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
			'warning'        => '',
		);
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
