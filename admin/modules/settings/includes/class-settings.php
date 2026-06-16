<?php
/**
 * Class Banner file.
 *
 * @package FazCookie
 */

namespace FazCookie\Admin\Modules\Settings\Includes;

use FazCookie\Includes\Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Handles Cookies Operation
 *
 * @class       Settings
 * @version     3.0.0
 * @package     FazCookie
 */
class Settings extends Store {
	/**
	 * Data array, with defaults.
	 *
	 * @var array
	 */
	protected $data = array();

	/**
	 * Instance of the current class
	 *
	 * @var object
	 */
	private static $instance;

	/**
	 * Return the current instance of the class
	 *
	 * @return object
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}
	/**
	 * Constructor
	 */
	public function __construct() {
		$this->data = $this->get_defaults();
	}

	/**
	 * Get default plugin settings
	 *
	 * @return array
	 */
	public function get_defaults() {
		return array(
			'site'         => array(
				'url'       => get_site_url(),
				'installed' => time(),
			),
			'consent_logs' => array(
				'status'    => true,
				'retention' => 12,
			),
			'languages'    => array(
				'selected' => array( 'en' ),
				'default'  => 'en',
			),
			'onboarding'   => array(
				'step' => 2,
			),
			'general'      => array(
				'remove_data_on_uninstall' => false,
				// Consent revision counter. Incremented manually by the admin
				// via the "Invalidate all consents" button. Returning visitors
				// whose stored consent has a lower revision will see the banner
				// again. Starts at 1 so any value >=1 is valid.
				'consent_revision'         => 1,
			),
			'scanner'      => array(
				'max_pages'       => 20,
				'last_scan'       => '',
				'static_ip'       => '',
				'auto_scan'       => false,
				'scan_frequency'  => 'weekly',
				'debug_mode'      => false,
			),
			'banner_control' => array(
				'status'                 => true,
				'excluded_pages'         => array(),
				'subdomain_sharing'      => false,
				'hide_from_bots'         => true,
				'gtm_datalayer'          => false,
				'alternative_asset_path' => false,
				'per_service_consent'    => false,
				'per_cookie_consent'     => false,
			),
			'microsoft'    => array(
				'uet_consent_mode' => false,
				'clarity_consent'  => false,
			),
			'site_links'   => array(
				'sites' => array(),
			),
			'iab'          => array(
				'enabled'               => false,
				'publisher_cc'          => '',
				'cmp_id'                => 0,
				'purpose_one_treatment' => false,
			),
			'geolocation'  => array(
				'maxmind_license_key' => '',
				'geo_targeting'       => false,
				'target_regions'      => array( 'eu', 'uk' ),
				'default_behavior'    => 'show_banner',
				// Which GeoLite2 edition the downloader fetches and the lookup
				// reads: 'country' (small, country-level only — the default and
				// the historical behaviour) or 'city' (larger, adds region /
				// subdivision detection needed by sub-national rulesets). User
				// choice surfaced in Settings → GeoIP Database.
				'geolite2_edition'    => 'country',
			),
			'script_blocking' => array(
				'custom_rules'       => array(),
				'excluded_pages'     => array(),
				// Default "never block before consent" list. Kept deliberately
				// narrow: only anti-abuse / security challenge endpoints that
				// are strictly necessary for a service the visitor actively
				// requested (CAPTCHA, bot challenge). Convenience/remote
				// resources that DO profile or set non-essential cookies —
				// Google Fonts, Google Maps, the YouTube/CustomSearch/Translation
				// APIs, OAuth, and generic CDNs (jsDelivr, unpkg) — are NOT
				// whitelisted by default: under GDPR/ePrivacy they must stay
				// blocked until consent (German courts have ruled CDN-hosted
				// Google Fonts unlawful without consent). Site owners can still
				// add any of them explicitly via Settings → Script Blocking if
				// their lawful basis warrants it.
				'whitelist_patterns' => array(
					'www.google.com/recaptcha/api',
					'www.gstatic.com/recaptcha/',
					'challenges.cloudflare.com/',
					'hcaptcha.com/',
				),
			),
			'pageview_tracking' => false,
			'consent_forwarding' => array(
				'enabled'        => false,
				'target_domains' => array(),
			),
			'age_gate'          => array(
				'enabled' => false,
				'min_age' => 16,
			),
			// Integrations with third-party plugins. Each integration is
			// effectively no-op if its host plugin is not active.
			'integrations'      => array(
				'paid_memberships_pro' => array(
					// Master toggle. Feature only activates when enabled AND
					// the PMP plugin is active on the site.
					'enabled'        => false,
					// Comma-separated list of PMP level IDs (stored as an
					// array of integers) whose members are exempted from the
					// cookie banner and whose consent is auto-granted across
					// all categories. This is the "Pay-or-Accept" (PUR)
					// branch: paying subscribers skip the banner, free
					// visitors keep the standard consent flow.
					'exempt_levels'  => array(),
				),
			),
		);

	}
	/**
	 * Get settings
	 *
	 * @param string $group Name of the group.
	 * @param string $key Name of the key.
	 * @return array
	 */
	private static $cached_settings = null;

	public function get( $group = '', $key = '' ) {
		if ( null === self::$cached_settings ) {
			$settings = get_option( 'faz_settings', $this->data );
			self::$cached_settings = self::sanitize( $settings, $this->data );
		}
		$settings = self::$cached_settings;
		if ( empty( $key ) && empty( $group ) ) {
			return $settings;
		} elseif ( ! empty( $key ) && ! empty( $group ) ) {
			$settings = isset( $settings[ $group ] ) ? $settings[ $group ] : array();
			return isset( $settings[ $key ] ) ? $settings[ $key ] : array();
		} else {
			return isset( $settings[ $group ] ) ? $settings[ $group ] : array();
		}
	}

	/**
	 * Excludes a key from sanitizing multiple times.
	 *
	 * @return array
	 */
	public static function get_excludes() {
		return array(
			'selected',
			'excluded_pages',
			'sites',
			'custom_rules',
			'target_regions',
			'target_domains',
			'whitelist_patterns',
			'exempt_levels',
		);
	}
	/**
	 * Update settings to database.
	 *
	 * @param array $data Array of settings data.
	 * @return void
	 */
	public function update( $data, $clear = true ) {
		$defaults = $this->get_defaults();
		$settings = self::sanitize( $data, $defaults );
		update_option( 'faz_settings', $settings );
		self::$cached_settings = null;
		do_action( 'faz_after_update_settings', $clear );
	}

	/**
	 * Sanitize options
	 *
	 * @param array $settings Input settings array.
	 * @param array $defaults Default settings array.
	 * @return array
	 */
	public static function sanitize( $settings, $defaults ) {
		$result  = array();
		$excludes = self::get_excludes();
		foreach ( $defaults as $key => $data ) {
			$value = isset( $settings[ $key ] ) ? $settings[ $key ] : $data;
			// Excluded keys handle their own coercion in sanitize_option() —
			// e.g. `exempt_levels` accepts a comma-separated string from the
			// admin UI and normalizes to an array of IDs. Running the
			// "array default but non-array value" override below would wipe
			// the string before sanitize_option() ever sees it.
			if ( in_array( $key, $excludes, true ) ) {
				$result[ $key ] = self::sanitize_option( $key, $value );
				continue;
			}
			// If the default is an array but the stored value isn't, use the default.
			if ( is_array( $data ) && ! is_array( $value ) ) {
				$value = $data;
			}
			if ( is_array( $value ) ) {
				$result[ $key ] = self::sanitize( $value, $data );
			} else {
				if ( is_string( $key ) ) {
					$result[ $key ] = self::sanitize_option( $key, $value );
				}
			}
		}
		return $result;
	}

	/**
	 * Sanitize the option values
	 *
	 * @param string $option The name of the option.
	 * @param string $value  The unsanitised value.
	 * @return string Sanitized value.
	 */
	public static function sanitize_option( $option, $value ) {
		switch ( $option ) {
			case 'status':
			case 'subdomain_sharing':
			case 'uet_consent_mode':
			case 'clarity_consent':
			case 'enabled':
			case 'purpose_one_treatment':
			case 'pageview_tracking':
			case 'auto_scan':
			case 'remove_data_on_uninstall':
			case 'debug_mode':
			case 'geo_targeting':
			case 'hide_from_bots':
			case 'gtm_datalayer':
			case 'alternative_asset_path':
			case 'per_service_consent':
				$value = faz_sanitize_bool( $value );
				break;
			case 'per_cookie_consent':
				// Hidden experimental sub-feature: reject direct REST/import
				// attempts to enable it until the per-cookie enforcement rework
				// is complete.
				$value = false;
				break;
			case 'scan_frequency':
				$allowed = array( 'daily', 'weekly', 'monthly' );
				$value   = in_array( $value, $allowed, true ) ? $value : 'weekly';
				break;
			case 'geolite2_edition':
				// Whitelist the GeoLite2 edition so a direct settings PUT cannot
				// persist an arbitrary string. The runtime reader already falls
				// back to Country on unknown values, but the stored value should
				// never hold anything but the two valid editions.
				$allowed = array( 'country', 'city' );
				$value   = in_array( $value, $allowed, true ) ? $value : 'country';
				break;
			case 'installed':
			case 'step':
			case 'max_pages':
				$value = absint( $value );
				break;
			case 'consent_revision':
				// Revision counter: always >= 1. Bounded upper limit to avoid
				// accidental huge values from corrupted input. The
				// "Invalidate all consents" button is meant to be one-way,
				// so we also refuse to LOWER the persisted revision —
				// otherwise a power user editing the readonly input via
				// DevTools could downgrade the counter and re-validate
				// already-revoked consents.
				$incoming  = max( 1, min( 999999, absint( $value ) ) );
				$persisted = isset( self::$cached_settings['general']['consent_revision'] )
					? absint( self::$cached_settings['general']['consent_revision'] )
					: 0;
				if ( 0 === $persisted ) {
					// First read — pull from DB to avoid bootstrapping issues.
					$db_settings = get_option( 'faz_settings', array() );
					$persisted   = isset( $db_settings['general']['consent_revision'] )
						? absint( $db_settings['general']['consent_revision'] )
						: 1;
				}
				$value = max( $incoming, $persisted );
				break;
			case 'retention':
				$value = max( 1, min( 120, absint( $value ) ) );
				break;
			case 'min_age':
				$value = max( 13, min( 18, absint( $value ) ) );
				break;
			case 'cmp_id':
				$value = min( 4095, absint( $value ) );
				break;
			case 'target_domains':
				// Cross-domain consent forwarding receivers MUST be HTTP(S)
				// URLs — anything else (`javascript:`, `data:`, malformed
				// strings) is rejected. We use `esc_url_raw()` to produce
				// a safe stored value and parse the host to enforce schemes.
				if ( ! is_array( $value ) ) {
					$value = array();
					break;
				}
				$value = array_values( array_filter( array_map( function ( $item ) {
					$raw = trim( (string) $item );
					if ( '' === $raw ) {
						return '';
					}
					$url    = esc_url_raw( $raw );
					$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
					$host   = wp_parse_url( $url, PHP_URL_HOST );
					if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || empty( $host ) ) {
						return '';
					}
					return $url;
				}, $value ), function ( $item ) {
					return '' !== $item;
				} ) );
				break;
			case 'excluded_pages':
			case 'sites':
			case 'whitelist_patterns':
				if ( ! is_array( $value ) ) {
					$value = array();
					break;
				}
				$value = array_values( array_filter( array_map( function ( $item ) {
					return trim( sanitize_text_field( (string) $item ) );
				}, $value ), function ( $item ) {
					return '' !== $item;
				} ) );
				break;
			case 'exempt_levels':
				// Accept either an array of IDs or a comma-separated string
				// (admin UI submits the latter). Normalize to a deduplicated
				// array of positive integers.
				if ( is_string( $value ) ) {
					$value = array_map( 'trim', explode( ',', $value ) );
				}
				if ( ! is_array( $value ) ) {
					$value = array();
					break;
				}
				$value = array_values( array_unique( array_filter( array_map( 'absint', $value ) ) ) );
				break;
			case 'custom_rules':
				// Allowed categories must include all built-in non-removable
				// slugs (`necessary`, `uncategorized`) plus the user-facing
				// runtime categories. Without `necessary`, the 8 blocker
				// templates (Cloudflare Turnstile, Gravatar, reCAPTCHA,
				// hCaptcha, Wordfence, WPForms, Ninja Forms reCAPTCHA,
				// WooCommerce Attribution) silently lose their custom_rule
				// rows on save — admin sees the green toast, DB stays empty.
				// `performance` retained for backward compat with v1.13.x
				// installs even though no real category currently uses it.
				$allowed_categories = array( 'necessary', 'uncategorized', 'analytics', 'marketing', 'functional', 'performance' );
				if ( ! is_array( $value ) ) {
					$value = array();
				}
				$value = array_values( array_filter( array_map( function ( $rule ) use ( $allowed_categories ) {
					if ( ! is_array( $rule ) ) {
						return null;
					}
					$pattern  = isset( $rule['pattern'] ) ? sanitize_text_field( $rule['pattern'] ) : '';
					$category = isset( $rule['category'] ) ? sanitize_text_field( $rule['category'] ) : '';
					if ( empty( $pattern ) || empty( $category ) || ! in_array( $category, $allowed_categories, true ) ) {
						return null;
					}
					return array( 'pattern' => $pattern, 'category' => $category );
				}, $value ) ) );
				// Deduplicate by pattern+category to prevent the admin click-
				// the-same-template-twice failure mode.
				$seen  = array();
				$value = array_values( array_filter( $value, function ( $rule ) use ( &$seen ) {
					$key = $rule['pattern'] . '|' . $rule['category'];
					if ( isset( $seen[ $key ] ) ) {
						return false;
					}
					$seen[ $key ] = true;
					return true;
				} ) );
				break;
			case 'default_behavior':
				$allowed = array( 'show_banner', 'no_banner' );
				$value   = in_array( $value, $allowed, true ) ? $value : 'show_banner';
				break;
			case 'target_regions':
				if ( ! is_array( $value ) ) {
					$value = array();
				}
				$value = array_values( array_unique( array_map( 'sanitize_text_field', $value ) ) );
				break;
			case 'publisher_cc':
				$value = strtoupper( sanitize_text_field( (string) $value ) );
				$value = preg_match( '/^[A-Z]{2}$/', $value ) ? $value : '';
				break;
			default:
				$value = faz_sanitize_text( $value );
				break;
		}
		return $value;
	}

	// Getter Functions.

	/**
	 * Get current site URL.
	 *
	 * @return mixed
	 */
	public function get_url() {
		return $this->get( 'site', 'url' );
	}


	/**
	 * Get consent log status
	 *
	 * @return boolean
	 */
	public function get_consent_log_status() {
		return (bool) $this->get( 'consent_logs', 'status' );

	}

	/**
	 * Returns the default language code
	 *
	 * @return string
	 */
	public function get_default_language() {
		$default = $this->get( 'languages', 'default' );
		return is_string( $default ) ? sanitize_text_field( $default ) : 'en';
	}

	/**
	 * Returns the selected languages.
	 *
	 * @return array
	 */
	public function get_selected_languages() {
		return faz_sanitize_text( $this->get( 'languages', 'selected' ) );
	}

	/**
	 * First installed date of the plugin.
	 *
	 * @return mixed
	 */
	public function get_installed_date() {
		return $this->get( 'site', 'installed' );
	}
}
