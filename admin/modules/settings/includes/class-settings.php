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
			'consent_logs' => array(
				'status'    => true,
				'retention' => 12,
			),
			// Pageview analytics retention, in months. Activator::run_retention_
			// cleanup() has read $settings['pageviews']['retention'] since the
			// purge was added, but the group was never declared here — and
			// sanitize() builds the stored option by walking THESE defaults, so
			// the key was unreachable through the settings API and every install
			// ran on the hardcoded 6. A destructive default that a site cannot
			// change except by writing PHP is not a setting; declaring the group
			// is what makes the documented one real.
			'pageviews'    => array(
				'retention' => 6,
			),
			// Same defect, same shape: cleanup_old_dsar_requests() has read
			// $settings['dsar']['retention'] since it was written, while the
			// group was never declared — so the documented 24-month window was
			// unreachable and every install ran on the hardcoded fallback. DSAR
			// records are the evidence that a data-subject request was answered,
			// which is exactly the kind of record a controller may need to
			// produce, so the floor below keeps it at accountability's side of
			// the line rather than allowing "never purge".
			'dsar'         => array(
				'retention' => 24,
			),
			'languages'    => array(
				'selected' => array( 'en' ),
				'default'  => 'en',
			),
			'onboarding'   => array(
				// Legacy CookieYes-fork leftover; preserved but unread.
				'step'      => 2,
				// Guided setup wizard state. `completed` DEFAULTS TO TRUE so any
				// install upgrading to this version (whose stored option predates
				// these keys) is treated as already-onboarded and is NEVER nagged.
				// The wizard is armed for exactly one path — a genuine fresh
				// install — where Activator::install() explicitly writes
				// completed=false through Settings::update(). See class-onboarding.php.
				'completed' => true,
				// Dashboard "Complete setup" card dismissed without finishing.
				'dismissed' => false,
				// Chosen jurisdiction, stored only for display / wizard re-entry
				// pre-selection: '' | 'gdpr' | 'ccpa' | 'both'. The law is APPLIED
				// to the default banner's applicableLaw / Do-Not-Sell fields — it
				// is not itself a runtime setting.
				'law'       => '',
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
				'cache_compatibility'    => false,
				// Anti-adblock banner resilience. When enabled, a single
				// deferred client-side check re-asserts the consent banner's
				// visibility if an ad-block cosmetic filter list (e.g. EasyList
				// Cookie) hides it. Purely keeps a legally required notice
				// visible — never forces interaction, never a cookie wall.
				// Distinct from 'alternative_asset_path' above, which only
				// renames the script handle so network-level filters do not
				// block the JavaScript itself. Default off.
				'adblock_resilience'     => false,
				// A/B testing of banner variants. When `status` is on and
				// `variants` lists two or more EXISTING active banner slugs,
				// each visitor is assigned one variant without a pre-consent
				// experiment cookie. After a choice, the banner slug already stored
				// in the consent record keeps the assignment stable. The accept-rate
				// per variant is reported on the Dashboard from the consent log.
				// The tool only ever chooses among banner rows the admin
				// already created — every variant stays independently
				// compliant (equal-weight buttons, opt-in categories); it is
				// NOT a way to author dark patterns. Default off, so existing
				// installs are unchanged. Server-side splitting is skipped
				// under Cache Compatibility Mode (see Frontend::maybe_apply_ab_test).
				'ab_test'                => array(
					'status'   => false,
					'variants' => array(),
				),
			),
			'microsoft'    => array(
				'uet_consent_mode' => false,
				'clarity_consent'  => false,
			),
			'site_links'   => array(
				'sites' => array(),
			),
			// Optional legal-links <nav> printed on wp_footer (Cookie Policy,
			// Privacy Policy, Imprint, …). Default OFF so no existing install
			// suddenly grows a footer element it never asked for. The rendered
			// markup depends ONLY on this option plus each page's publish
			// state — never on consent, login or geo — so it stays a single
			// cached variant under Cache Compatibility Mode.
			'legal_links'  => array(
				'enabled'    => false,
				// List of array( 'page_id' => int, 'label' => string ).
				// An empty label means "use the page title", so a renamed page
				// keeps the footer link in sync without an admin save.
				'link_items' => array(),
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
				'custom_rules'                => array(),
				'excluded_pages'              => array(),
				// Advanced runtime CSS-url blocking is intentionally opt-in.
				// The baseline blocker already handles server-rendered inline
				// <style> tags and direct HTMLStyleElement writes. This switch
				// enables broader client-side hooks for page builders/CSS-in-JS
				// channels such as Element.innerHTML, insertAdjacentHTML,
				// CharacterData edits inside <style>, and Constructable
				// Stylesheets. Those hooks improve block-first coverage for
				// inline CSS url()/@import leaks but touch global browser
				// prototypes, so they carry compatibility risk and stay off by
				// default.
				'aggressive_css_url_blocking' => false,
				// Compatibility-sensitive: filters Set-Cookie headers emitted by
				// PHP plugins. Explicit opt-in until the owner tests critical flows.
				'block_server_cookies'        => false,
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
				//
				// These four were briefly emptied. That reads as the stricter
				// choice, but the blocker DOES provider-match all four, so on a
				// fresh install every CAPTCHA-guarded contact, login and checkout
				// form silently stopped working for non-consented visitors — with
				// no error surfaced to the visitor or the admin. It also split the
				// install base in two, since upgrades keep their stored option
				// while new sites got the empty list, and it made the readme's
				// "nothing is whitelisted by default" claim false for everyone
				// already running the plugin. A challenge endpoint that gates a
				// form the visitor is actively trying to submit is the textbook
				// Art. 5(3) strictly-necessary case; the profiling resources above
				// are not, and stay out.
				'whitelist_patterns' => array(
					'www.google.com/recaptcha/api',
					'www.gstatic.com/recaptcha/',
					'challenges.cloudflare.com/',
					'hcaptcha.com/',
				),
				// Per-gateway payment-SDK opt-in. Each toggle, when the site owner
				// enables it, allows that gateway's payment scripts (PayPal SDK,
				// Stripe.js, …) to load BEFORE consent site-wide — for stores whose
				// payment forms live outside a WooCommerce checkout (Forminator,
				// Paid Memberships Pro, Easy Digital Downloads, Give, …). All OFF
				// by default: a payment SDK can set cookies / fingerprint, so
				// loading it before consent is an explicit, per-gateway,
				// admin-responsibility decision — never automatic. (A genuine
				// WooCommerce checkout/cart page is still exempt automatically as
				// "strictly necessary", regardless of these toggles.) Keys mirror
				// Frontend::payment_gateway_catalog().
				'payment_gateways' => array_fill_keys( self::payment_gateway_keys(), false ),
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
					// array of integers) whose members receive the paid,
					// privacy-preserving alternative: the banner is skipped,
					// necessary storage remains available, and every optional
					// purpose stays denied until the member explicitly changes
					// it through the preference centre.
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

	/**
	 * Drop the request-local settings cache after an external transaction is
	 * rolled back. Without this, a failed multi-store wizard save could expose
	 * the uncommitted value for the remainder of the request.
	 *
	 * @return void
	 */
	public static function clear_cache() {
		self::$cached_settings = null;
	}

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
			'payment_gateways',
			// A/B test variant slug list (banner_control.ab_test.variants).
			// Excluded so the recursive sanitiser hands the raw array to
			// sanitize_option() instead of recursing into it against an empty
			// default array (which would wipe every entry).
			'variants',
			// Footer legal links (legal_links.link_items): a list of
			// array( page_id, label ) structs. Same reason as 'variants' above —
			// without this entry the recursive sanitiser would walk each stored
			// row against the EMPTY default array and drop every one of them on
			// the next save of any setting. Deliberately named 'link_items'
			// rather than a generic 'items'/'pages': get_excludes() and
			// sanitize_option() match by bare key name across the WHOLE settings
			// tree, so a generic name would hijack any future group that happens
			// to use it.
			'link_items',
		);
	}

	/**
	 * Canonical list of payment-gateway keys. The single source of truth is
	 * Frontend::payment_gateway_catalog(); the literal list here is only a
	 * fallback for the rare context where that class can't be autoloaded. Used by
	 * BOTH the defaults and the sanitiser so the two never drift.
	 *
	 * @return string[]
	 */
	private static function payment_gateway_keys() {
		if ( class_exists( '\\FazCookie\\Frontend\\Frontend' ) ) {
			return array_keys( \FazCookie\Frontend\Frontend::payment_gateway_catalog() );
		}
		return array( 'paypal', 'stripe', 'square', 'braintree', 'klarna', 'mollie', 'amazon_pay' );
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
	 * @param array  $settings Input settings array.
	 * @param array  $defaults Default settings array.
	 * @param string $group    Key of the group being walked, i.e. the immediate
	 *                         parent of the leaves sanitised at this level (''
	 *                         at the top). sanitize_option() matches on the bare
	 *                         leaf name across the WHOLE tree — see the note in
	 *                         get_excludes() — so a leaf whose correct rule
	 *                         depends on which group it lives in has no other way
	 *                         to find out. Only 'retention' needs it today.
	 * @return array
	 */
	public static function sanitize( $settings, $defaults, $group = '' ) {
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
				$result[ $key ] = self::sanitize_option( $key, $value, $group );
				continue;
			}
			// If the default is an array but the stored value isn't, use the default.
			if ( is_array( $data ) && ! is_array( $value ) ) {
				$value = $data;
			}
			if ( is_array( $value ) ) {
				$result[ $key ] = self::sanitize( $value, $data, $key );
			} else {
				if ( is_string( $key ) ) {
					$result[ $key ] = self::sanitize_option( $key, $value, $group );
				}
			}
		}

		// Structural dependency between two banner_control flags. Enforced in the
		// server-side sanitiser rather than the admin JS so it also holds for REST
		// updates, imports and programmatic writes.
		//
		// NOTE: this method recurses into every nested array, so this block runs at
		// each level, not only at the top. The isset() guard is what keeps it a
		// no-op below the root — do not add an invariant here that could match a
		// nested key of the same name.
		//
		// Deliberately NOT enforced here: turning Cache Compatibility Mode off when
		// geo-targeting or IAB TCF is on. That combination is supported by design —
		// the frontend neutralises per-visitor resolution under cache mode instead
		// of failing (banner-rest and amp-consent skip country/ruleset lookup,
		// class-frontend forces the conservative gdpr_applies=true for TCF). Forcing
		// the flag off would silently revert an administrator's choice on every
		// save, including saves that never touched it, and would strip the setting
		// from installs already running the combination. The admin is warned in
		// settings.js instead, matching how Cache Compatibility Mode pausing the A/B
		// split is surfaced.
		if ( isset( $result['banner_control'] ) && is_array( $result['banner_control'] ) ) {
			// A per-cookie choice is nested below a service, so it is meaningless
			// without service-level consent — the admin help text says as much
			// ("Requires per-service consent"). The dependency is enforced in that
			// direction only: the parent switches the child off.
			//
			// It must NOT be enforced the other way round. Deriving
			// per_service_consent = true from per_cookie_consent looks equivalent
			// and is not: it makes per-service impossible to turn off while
			// per-cookie is on. The admin unticks it, saves, and finds it back on
			// with no explanation. Caught by
			// tests/e2e/specs/per-service-consent.spec.ts — "category-only mode
			// hides and disables per-service consent" — which switches per-service
			// off over a settings payload that still carries per_cookie_consent
			// from an earlier state, exactly as the settings screen does.
			if ( empty( $result['banner_control']['per_service_consent'] ) ) {
				$result['banner_control']['per_cookie_consent'] = false;
			}
		}
		return $result;
	}

	/**
	 * Sanitize the option values
	 *
	 * @param string $option The name of the option.
	 * @param string $value  The unsanitised value.
	 * @param string $group  Settings group the option belongs to, when the rule
	 *                       differs between groups that share a leaf name.
	 *                       Empty when called directly.
	 * @return string Sanitized value.
	 */
	public static function sanitize_option( $option, $value, $group = '' ) {
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
			case 'adblock_resilience':
			case 'per_service_consent':
			case 'cache_compatibility':
			case 'aggressive_css_url_blocking':
			case 'block_server_cookies':
				$value = faz_sanitize_bool( $value );
				break;
			case 'per_cookie_consent':
				// Nested per-cookie toggles within an accepted service. Only
				// meaningful alongside per_service_consent (the frontend store
				// and the server-side shredder both gate it on per-service), but
				// it is a plain boolean here.
				$value = faz_sanitize_bool( $value );
				break;
			case 'completed':
			case 'dismissed':
				// Guided setup wizard flags (onboarding group). Plain booleans:
				// `completed` gates the activation redirect + Dashboard card,
				// `dismissed` hides the card without finishing. Without these
				// explicit cases they would fall through to faz_sanitize_text and
				// a truthy string like 'false' would survive as a non-empty string.
				$value = faz_sanitize_bool( $value );
				break;
			case 'law':
				// Chosen jurisdiction for the guided setup wizard. Whitelisted so
				// a direct settings PUT cannot persist an arbitrary string; the
				// banner-apply logic only ever reads one of these four values.
				$allowed = array( '', 'gdpr', 'ccpa', 'both', 'popia' );
				$value   = in_array( $value, $allowed, true ) ? $value : '';
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
			case 'static_ip':
				$value = trim( (string) $value );
				$value = filter_var( $value, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ? $value : '';
				break;
			case 'step':
				$value = absint( $value );
				break;
			case 'max_pages':
				// Keep every entry point aligned with the browser scanner's
				// discover endpoint. An unbounded stored value can turn the cron
				// fallback into thousands of loopback requests, while zero makes
				// an enabled scheduled scan a silent no-op.
				$value = is_numeric( $value ) ? (int) $value : 0;
				$value = max( 1, min( 2000, $value ) );
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
				// Months to keep, capped at ten years. The floor differs by
				// group, which is the whole reason sanitize_option() now knows
				// one. Consent logs are the accountability record for consent
				// (GDPR Art. 7(1)) and their retention window is a promise made
				// to the visitor, so a stored 0 — read by the cleanup as "never
				// purge" — must not be reachable from the UI. Pageview analytics
				// carry no such duty, and their purge is documented as
				// opt-out-able: Activator::run_retention_cleanup() already
				// honours 0 via its `> 0` guard and the
				// faz_pageviews_retention_months filter, so refusing to persist
				// the 0 would leave that contract half-implemented.
				$floor = ( 'pageviews' === $group ) ? 0 : 1;
				// (int) rather than absint(): absint( -1 ) is 1, so a negative value
				// landed ABOVE the pageviews floor of 0 and was stored as one month
				// — the shortest possible retention — when the obvious reading of a
				// negative is "off". Clamping the signed value sends it to the floor,
				// which is 0 (never purge) for pageviews and 1 for the groups that owe
				// an accountability record.
				$value = max( $floor, min( 120, (int) $value ) );
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
			case 'variants':
				// A/B test variant list: an array of banner slugs. Each slug is
				// normalised with sanitize_title() (banner slugs are created via
				// sanitize_title in the Banners controller), blanks dropped and
				// duplicates removed. Storing a slug that no longer maps to a
				// banner is harmless — the selector and the stats reporter both
				// re-validate against the live banner rows at read time.
				if ( ! is_array( $value ) ) {
					$value = array();
					break;
				}
				$value = array_values( array_unique( array_filter( array_map( function ( $item ) {
					return is_scalar( $item ) ? sanitize_title( (string) $item ) : '';
				}, $value ), function ( $item ) {
					return '' !== $item;
				} ) ) );
				break;
			case 'link_items':
				// Footer legal links: a list of array( page_id, label ). Only the
				// two known keys survive, page IDs are deduplicated (the same page
				// twice would print the same link twice) and the whole list is
				// capped at 20 rows — a footer nav is a handful of legal pages, and
				// the cap keeps a crafted settings PUT from bloating every cached
				// page on the site. A blank label is legitimate: the renderer falls
				// back to the live page title.
				if ( ! is_array( $value ) ) {
					$value = array();
					break;
				}
				$clean = array();
				$seen  = array();
				foreach ( $value as $item ) {
					if ( count( $clean ) >= 20 ) {
						break;
					}
					if ( ! is_array( $item ) ) {
						continue;
					}
					// Deliberately (int) and not absint(): absint(-5) is 5, so a
					// negative ID would silently become a link to a DIFFERENT,
					// real page. A malformed ID must drop out, not be rounded
					// into a valid one.
					$page_id = (int) ( isset( $item['page_id'] ) ? $item['page_id'] : 0 );
					if ( $page_id < 1 || isset( $seen[ $page_id ] ) ) {
						continue;
					}
					$seen[ $page_id ] = true;
					$label            = sanitize_text_field( (string) ( isset( $item['label'] ) ? $item['label'] : '' ) );
					// mb_substr keeps a multibyte label from being cut mid-character;
					// mbstring is not guaranteed on every host, hence the fallback.
					if ( function_exists( 'mb_substr' ) ) {
						$label = mb_substr( $label, 0, 120 );
					} else {
						$label = substr( $label, 0, 120 );
					}
					$clean[] = array(
						'page_id' => $page_id,
						'label'   => $label,
					);
				}
				$value = $clean;
				break;
			case 'payment_gateways':
				// Map of gateway-key => bool. Only known catalogue keys survive,
				// each coerced to a strict boolean, so a settings PUT cannot smuggle
				// an unknown gateway or a non-bool into the whitelist decision.
				//
				// faz_sanitize_bool_strict(), not faz_sanitize_bool(): the general
				// coercion enumerates its NEGATIVES, so any string it does not
				// recognise — 'garbage', a truncated value, something a migration
				// mangled — came back true and was persisted as an enabled gateway.
				// True here removes a restriction, so only an explicit yes counts.
				// The two read sites use the same function; write and read agreeing
				// is what keeps a stored value from meaning one thing on save and
				// another on render.
				$gateway_keys = self::payment_gateway_keys();
				$clean = array();
				foreach ( $gateway_keys as $gw_key ) {
					$clean[ $gw_key ] = is_array( $value ) && array_key_exists( $gw_key, $value )
						? \faz_sanitize_bool_strict( $value[ $gw_key ] )
						: false;
				}
				$value = $clean;
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
				// Same finite vocabulary rendered by Settings and accepted by
				// onboarding. Unknown tokens used to survive direct REST/import
				// writes even though no runtime resolver could interpret them.
				$allowed = array( 'eu', 'uk', 'us', 'ca', 'br', 'au', 'jp', 'ch', 'za' );
				$value   = array_values( array_unique( array_intersect( array_map( function ( $region ) {
					if ( ! is_scalar( $region ) ) {
						return '';
					}
					return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( trim( (string) $region ) ) );
				}, $value ), $allowed ) ) );
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

}
