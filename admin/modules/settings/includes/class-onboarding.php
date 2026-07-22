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
	 *  - gdpr : opt-in model, no US opt-out entry point.
	 *  - ccpa : opt-out (notice) model, first-party "Do Not Sell or Share"
	 *           entry point + opt-out popup enabled.
	 *  - both : mixed EU+US audience. Stored as applicableLaw='gdpr' (the MORE
	 *           protective opt-in model governs the banner, so EU visitors are
	 *           never downgraded to opt-out) WITH the US Do-Not-Sell entry point
	 *           also rendered. This is the plugin's established "gdpr_ccpa"
	 *           encoding (see Banner::apply_runtime_law_content_compatibility).
	 *
	 * Consent-expiry and equal-weight buttons are intentionally NOT part of the
	 * mapping: the bundled per-law defaults already carry lawful values
	 * (gdpr 180 days ≤ the Garante 182-day cap the frontend re-clamps to,
	 * ccpa 365) and the wizard must never raise expiry or weaken button weight.
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
				);
			case 'ccpa':
				return array(
					'applicableLaw' => 'ccpa',
					'donotSell'     => true,
					'optoutPopup'   => true,
				);
			case 'both':
				return array(
					'applicableLaw' => 'gdpr',
					'donotSell'     => true,
					'optoutPopup'   => true,
				);
			default:
				return null;
		}
	}

	/**
	 * Apply the chosen jurisdiction to the site's default/active banner.
	 *
	 * Only the law-related fields are touched — applicableLaw plus the canonical
	 * Do-Not-Sell button branch and the opt-out popup container. Colours, copy,
	 * layout and every other customisation are preserved. The write goes through
	 * the normal Banner::set_settings()/save() path so cache invalidation and the
	 * standard sanitisation cascade run exactly as they do for a manual save.
	 *
	 * @param string $law One of self::LAWS.
	 * @return true|WP_Error True on success; WP_Error (status 200, non-fatal)
	 *                       when no default banner row exists to update.
	 */
	public function apply_law_to_default_banner( $law ) {
		$fields = self::map_law_to_banner_fields( $law );
		if ( null === $fields ) {
			// Unknown law — nothing to apply, but not an error condition.
			return true;
		}

		$controller = Banner_Controller::get_instance();
		$banner     = $controller->get_active_banner();
		if ( ! $banner ) {
			// Rare corrupted install with no default banner. Non-fatal: the
			// caller still marks onboarding complete and surfaces a notice
			// pointing the admin at the Banner page.
			return new WP_Error(
				'faz_no_default_banner',
				__( 'No default cookie banner was found to configure. Open the Cookie Banner page to review your setup.', 'faz-cookie-manager' ),
				array( 'status' => 200 )
			);
		}

		$properties = $banner->get_settings();
		if ( ! is_array( $properties ) ) {
			$properties = array();
		}
		if ( ! isset( $properties['settings'] ) || ! is_array( $properties['settings'] ) ) {
			$properties['settings'] = array();
		}
		$properties['settings']['applicableLaw'] = $fields['applicableLaw'];

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
		$banner->save();

		return true;
	}

	/**
	 * Finish the wizard: apply the law, ensure the accountability baseline, and
	 * persist the onboarding completion flags.
	 *
	 * The accountability baseline (banner visible + consent logging on) satisfies
	 * GDPR Art. 5(2)/7(1). On a fresh install these are already the defaults; the
	 * wizard only re-asserts them so a completed setup is demonstrably compliant.
	 *
	 * @param string $law            Chosen jurisdiction ('' | 'gdpr' | 'ccpa' | 'both').
	 * @param bool   $enable_logging Whether to keep consent logging enabled (default true).
	 * @return array {
	 *     @type bool   $success        Always true (the settings write is the last step).
	 *     @type bool   $banner_applied Whether the default banner was law-switched.
	 *     @type string $law            The persisted, validated jurisdiction.
	 *     @type string $warning        Non-fatal message (e.g. no default banner), or ''.
	 * }
	 */
	public function finish( $law, $enable_logging = true ) {
		$law            = in_array( $law, self::LAWS, true ) ? $law : '';
		$banner_applied = false;
		$warning        = '';

		if ( '' !== $law ) {
			$applied = $this->apply_law_to_default_banner( $law );
			if ( is_wp_error( $applied ) ) {
				$warning = $applied->get_error_message();
			} else {
				$banner_applied = true;
			}
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
		if ( $enable_logging ) {
			if ( ! isset( $all['consent_logs'] ) || ! is_array( $all['consent_logs'] ) ) {
				$all['consent_logs'] = array();
			}
			$all['consent_logs']['status'] = true;
		}

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
			'banner_applied' => $banner_applied,
			'law'            => $law,
			'warning'        => $warning,
		);
	}

	/**
	 * Mark onboarding as dismissed without finishing (Dashboard card "×").
	 *
	 * Leaves `completed` untouched so the Setup submenu page stays reachable,
	 * but hides the Dashboard nag permanently.
	 *
	 * @return void
	 */
	public function dismiss() {
		$settings_obj = new Settings();
		$all          = $settings_obj->get();
		if ( ! is_array( $all ) ) {
			$all = array();
		}
		if ( ! isset( $all['onboarding'] ) || ! is_array( $all['onboarding'] ) ) {
			$all['onboarding'] = array();
		}
		$all['onboarding']['dismissed'] = true;
		$settings_obj->update( $all );
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
