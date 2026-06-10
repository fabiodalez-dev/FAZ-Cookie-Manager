<?php
/**
 * Banner REST endpoint — per-language banner payload.
 *
 * Exposes a public GET /faz/v1/banner/(?P<lang>[a-z0-9-]+) endpoint that
 * returns the banner HTML, styles, shortcodes, categories, and i18n strings
 * resolved for a specific language. Used by the client-side browser-language
 * detection in script.js to swap the banner when the visitor's preferred
 * language differs from the server-rendered (cacheable) default.
 *
 * See GitHub issue #67 and includes/class-i18n-helpers.php for the
 * server-side rationale behind client-side language resolution.
 *
 * @package FazCookie\Frontend\Modules\Banner_Rest
 */

namespace FazCookie\Frontend\Modules\Banner_Rest;

use FazCookie\Admin\Modules\Banners\Includes\Controller as Banner_Controller;
use FazCookie\Admin\Modules\Banners\Includes\Template as Banner_Template;
use FazCookie\Frontend\Modules\Shortcodes\Shortcodes;
use FazCookie\Admin\Modules\Cookies\Includes\Category_Controller;
use FazCookie\Admin\Modules\Cookies\Includes\Cookie_Categories;
use FazCookie\Includes\Geolocation;
use FazCookie\Frontend\Includes\Geo_Runtime;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Banner REST controller.
 *
 * @class    Banner_Rest
 * @package  FazCookie
 */
class Banner_Rest {

	/**
	 * Constructor — register the REST route.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the /faz/v1/banner/(lang) route.
	 *
	 * @return void
	 */
	public function register_routes() {
		// `permission_callback => __return_true` is intentional and required.
		// This endpoint serves the cached, language-localised consent banner
		// HTML/JSON to anonymous frontend visitors — they need it BEFORE they
		// can grant or deny consent, so any capability check would prevent
		// the banner from ever rendering for new visitors. The endpoint is
		// strictly read-only (GET), returns no PII, and the `lang` parameter
		// is doubly validated (sanitize_callback + validate_callback) against
		// a strict regex of lowercase ASCII / digits / dashes.
		register_rest_route(
			'faz/v1',
			'/banner/(?P<lang>[a-z0-9-]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_banner' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'lang' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => array( $this, 'sanitize_language' ),
						'validate_callback' => array( $this, 'validate_language' ),
					),
				),
			)
		);
	}

	/**
	 * Sanitize the language path parameter to lowercase letters/digits/dashes only.
	 *
	 * @param string $value Raw value from the URL.
	 * @return string
	 */
	public function sanitize_language( $value ) {
		return strtolower( preg_replace( '/[^a-z0-9-]/i', '', (string) $value ) );
	}

	/**
	 * Validate that the requested language is part of the admin's selected set.
	 *
	 * @param string $value Sanitised language code.
	 * @return bool
	 */
	public function validate_language( $value ) {
		if ( ! function_exists( 'faz_selected_languages' ) ) {
			return false;
		}
		return in_array( $value, faz_selected_languages(), true );
	}

	/**
	 * Return the banner payload for the requested language.
	 *
	 * Response body:
	 *   {
	 *     language:   "it",
	 *     html:       "<div class=...",
	 *     styles:     ".faz-consent-container { ... }",
	 *     shortCodes: [{ key, content, tag, status, attributes }, ...],
	 *     categories: [{ slug, name, description, ... }, ...],
	 *     i18n:       { privacy_region_label: "...", ... }
	 *   }
	 *
	 * The template for the requested language is lazily generated on first
	 * request and cached in the `faz_banner_template` option, so subsequent
	 * fetches are served from a single DB read.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_banner( WP_REST_Request $request ) {
		$lang = $request->get_param( 'lang' );

		// Validate again defensively (the args callback should have already
		// rejected invalid values).
		if ( empty( $lang ) || ! in_array( $lang, faz_selected_languages(), true ) ) {
			return new WP_Error(
				'faz_invalid_language',
				__( 'The requested language is not configured for this site.', 'faz-cookie-manager' ),
				array( 'status' => 404 )
			);
		}

		$controller = Banner_Controller::get_instance();
		$country    = Geolocation::get_visitor_country();
		$banner     = $controller->get_active_banner_for_country( $country );

		// Runtime geo-routing parity with the initial render (load_banner):
		// when the resolved ruleset's model maps to a different law than the
		// country-selected banner, prefer the active banner carrying that law so
		// the language-swapped banner enforces the same model as the first paint.
		$runtime_ruleset = Geo_Runtime::resolve_for_country( $country );
		if ( null !== $runtime_ruleset ) {
			$wanted_law  = Geo_Runtime::model_to_law( $runtime_ruleset );
			$current_law = ( $banner ) ? $banner->get_law() : '';
			if ( $wanted_law !== $current_law ) {
				$law_banner = $controller->get_active_banner_for_law( $wanted_law, $country );
				if ( $law_banner ) {
					$banner = $law_banner;
				}
			}
		}

		if ( ! $banner ) {
			return new WP_Error(
				'faz_no_banner',
				__( 'No active banner found.', 'faz-cookie-manager' ),
				array( 'status' => 404 )
			);
		}

		// Force the language context for downstream helpers. The static cache
		// inside faz_current_language() is reset so the added filter actually
		// influences subsequent calls within this request.
		$filter = static function () use ( $lang ) {
			return $lang;
		};
		add_filter( 'faz_current_language', $filter, 1 );
		faz_current_language( true ); // reset static cache.

		// Switch WordPress translations so __( '...', 'faz-cookie-manager' )
		// returns strings in the target language. Best-effort: if the locale
		// is not installed, WP falls back to en_US gracefully.
		$target_locale   = $this->language_to_wp_locale( $lang );
		$locale_switched = false;
		if ( function_exists( 'switch_to_locale' ) && $target_locale ) {
			$locale_switched = switch_to_locale( $target_locale );
		}

		$orig_banner_lang = $banner->get_language();
		$banner->set_language( $lang );

		// Build the banner-scoped, language-specific template.
		$template = new Banner_Template( $banner, $lang );
		$html     = $template->get_html();
		$styles   = $template->get_styles();

		// Prepare shortcodes with a fresh instance bound to the language-
		// switched banner.
		$settings   = $banner->get_settings();
		$version_id = isset( $settings['settings']['versionID'] ) ? $settings['settings']['versionID'] : 'default';
		$shortcodes_instance = new Shortcodes( $banner, $version_id ); // registers add_shortcode with this instance.

		$short_codes = $this->build_shortcodes_payload( $banner, $shortcodes_instance );

		// Categories with names/descriptions resolved in the target language.
		// Pass the runtime ruleset so the language swap preserves the
		// jurisdiction-authoritative defaultConsent instead of resetting it to
		// the catalogue values (finding #4).
		$categories = $this->build_categories_payload( $lang, $runtime_ruleset );

		// Build the i18n payload BEFORE restoring the locale — otherwise
		// __( '...', 'faz-cookie-manager' ) would resolve against the
		// original locale and the REST response would mix languages.
		$i18n = $this->build_i18n_payload();

		// Restore original state before responding.
		$banner->set_language( $orig_banner_lang );
		remove_filter( 'faz_current_language', $filter, 1 );
		faz_current_language( true );
		if ( $locale_switched && function_exists( 'restore_previous_locale' ) ) {
			restore_previous_locale();
		}

		// Under the runtime flag the enforced law follows the ruleset model, so
		// the swapped banner runs the same regime as the initial render.
		$active_law = ( null !== $runtime_ruleset )
			? Geo_Runtime::model_to_law( $runtime_ruleset )
			: $banner->get_law();

		$payload = array(
			'language'   => $lang,
			'bannerSlug' => $banner->get_slug(),
			'activeLaw'  => $active_law,
			'html'       => $html,
			'styles'     => $styles,
			'shortCodes' => $short_codes,
			'categories' => $categories,
			'i18n'       => $i18n,
		);

		$response = new WP_REST_Response( $payload, 200 );
		// The runtime ruleset varies the payload (defaultConsent, activeLaw,
		// banner) by the visitor's jurisdiction, so it must never be cached
		// across visitors — force no-store while the flag is on.
		if ( $controller->has_country_dependent_banners() || Geo_Runtime::is_enabled() ) {
			$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
			$response->header( 'Pragma', 'no-cache' );
			$response->header( 'X-LiteSpeed-Cache-Control', 'no-cache' );
			if ( apply_filters( 'faz_trust_cf_ipcountry_header', false ) ) {
				$response->header( 'Vary', 'CF-IPCountry' );
			}
		} else {
			// Allow CDNs to cache per-language responses for a short TTL. The
			// payload is deterministic when the selected banner is not country-dependent.
			//
			// F111 fix (1.14.3): add Vary: CF-IPCountry when CF
			// integration is on so the CDN keys cache entries per
			// country. has_country_dependent_banners() can return a
			// stale-false during cache-epoch propagation delay (or
			// between admin sessions that touch country targeting and
			// the next request); without Vary the CDN could serve a
			// stale answer composed for one country to a visitor in
			// another for the full 5-minute TTL. With Vary, a
			// publisher who toggles country-dependent state mid-window
			// at worst gets per-country cache entries that all match
			// the same payload — at best gets isolation when a banner
			// becomes country-dependent. Cheap insurance.
			$response->header( 'Cache-Control', 'public, max-age=300' );
			if ( apply_filters( 'faz_trust_cf_ipcountry_header', false ) ) {
				$response->header( 'Vary', 'CF-IPCountry' );
			}
		}
		return $response;
	}

	/**
	 * Build the `shortCodes` payload for the REST banner response.
	 *
	 * Must stay in sync with Frontend::prepare_shortcodes(): script.js looks
	 * up entries by `key` (faz_readmore, faz_show_desc, faz_category_toggle_
	 * label, faz_optout_option_title, …) and a partial list would leave some
	 * labels stuck in the server-default language after a swap.
	 *
	 * @param object     $banner              Active banner.
	 * @param Shortcodes $shortcodes_instance Fresh shortcodes instance.
	 * @return array
	 */
	protected function build_shortcodes_payload( $banner, Shortcodes $shortcodes_instance ) {
		// The Shortcodes class re-registers itself via add_shortcode inside
		// init() (called in its constructor), so do_shortcode('[faz_*]') will
		// now use the fresh instance.
		$settings   = $banner->get_settings();
		$configs    = ( isset( $settings['config'] ) && is_array( $settings['config'] ) ) ? $settings['config'] : array();
		$readmore   = faz_array_search( $configs, 'tag', 'readmore-button' );
		$attributes = array();
		if ( isset( $readmore['meta']['noFollow'] ) && true === $readmore['meta']['noFollow'] ) {
			$attributes['rel'] = 'nofollow';
		}
		if ( isset( $readmore['meta']['newTab'] ) && true === $readmore['meta']['newTab'] ) {
			$attributes['target'] = '_blank';
		}

		$simple_entry = static function ( $key, $tag = '' ) {
			return array(
				'key'        => $key,
				'content'    => do_shortcode( '[' . $key . ']' ),
				'tag'        => $tag,
				'status'     => true,
				'attributes' => array(),
			);
		};

		$codes = array(
			array(
				'key'        => 'faz_readmore',
				'content'    => do_shortcode( '[faz_readmore]' ),
				'tag'        => 'readmore-button',
				'status'     => isset( $readmore['status'] ) && true === $readmore['status'],
				'attributes' => $attributes,
			),
			$simple_entry( 'faz_show_desc', 'show-desc-button' ),
			$simple_entry( 'faz_hide_desc', 'hide-desc-button' ),
			$simple_entry( 'faz_optout_show_desc', 'optout-show-desc-button' ),
			$simple_entry( 'faz_optout_hide_desc', 'optout-hide-desc-button' ),
			$simple_entry( 'faz_category_toggle_label' ),
			$simple_entry( 'faz_enable_category_label' ),
			$simple_entry( 'faz_disable_category_label' ),
			$simple_entry( 'faz_video_placeholder' ),
			$simple_entry( 'faz_enable_optout_label' ),
			$simple_entry( 'faz_disable_optout_label' ),
			$simple_entry( 'faz_optout_toggle_label' ),
			$simple_entry( 'faz_optout_option_title' ),
			$simple_entry( 'faz_optout_close_label' ),
			$simple_entry( 'faz_preference_close_label' ),
		);

		unset( $shortcodes_instance ); // keep the variable referenced so lints do not complain.

		/**
		 * Filter the banner REST shortcodes payload.
		 *
		 * @param array  $codes  Shortcode entries.
		 * @param object $banner Active banner.
		 */
		return apply_filters( 'faz_banner_rest_shortcodes', $codes, $banner );
	}

	/**
	 * Build the `categories` payload for the requested language.
	 *
	 * Mirrors Frontend::get_cookie_groups() but parametrised on $lang so the
	 * REST response matches what the client would have rendered on a fresh
	 * request in that language.
	 *
	 * @param string     $lang    Language code.
	 * @param array|null $ruleset Resolved runtime ruleset, or null for catalogue.
	 * @return array
	 */
	protected function build_categories_payload( $lang, $ruleset = null ) {
		$categories = Category_Controller::get_instance()->get_items();
		$out        = array();
		if ( empty( $categories ) || ! is_array( $categories ) ) {
			return $out;
		}
		foreach ( $categories as $category_data ) {
			if ( ! is_object( $category_data ) ) {
				continue;
			}
			$category = new Cookie_Categories( $category_data );
			if ( false === $category->get_visibility() ) {
				continue;
			}
			$slug = $category->get_slug();
			if ( 'wordpress-internal' === $slug ) {
				continue;
			}

			$out[] = array(
				'id'             => $category->get_id(),
				'name'           => $category->get_name( $lang ),
				'slug'           => $slug,
				'description'    => $category->get_description( $lang ),
				'isNecessary'    => 'necessary' === $slug,
				// SOLD or SHARED → subject to the combined CPRA opt-out. The
				// always-exempt `necessary` category is never opt-out-able,
				// regardless of its stored flags (kept in sync with defaultConsent.ccpa).
				'ccpaDoNotSell'  => 'necessary' !== $slug && ( $category->get_sell_personal_data() || $category->get_share_personal_data() ),
				'cookies'        => $this->build_category_cookies_payload( $category->get_cookies() ),
				'active'         => true,
				'defaultConsent' => Geo_Runtime::default_consent(
					$ruleset,
					$slug,
					(bool) $category->get_prior_consent(),
					(bool) $category->get_sell_personal_data(),
					(bool) $category->get_share_personal_data()
				),
			);
		}
		/**
		 * Filter the banner REST categories payload.
		 *
		 * @param array  $out  Category entries.
		 * @param string $lang Language code.
		 */
		return apply_filters( 'faz_banner_rest_categories', $out, $lang );
	}

	/**
	 * Build the category cookie payload consumed by script.js.
	 *
	 * @param array $items Raw/prepared cookie rows.
	 * @return array
	 */
	protected function build_category_cookies_payload( $items ) {
		$cookies = array();
		foreach ( (array) $items as $item ) {
			if ( is_array( $item ) ) {
				$item = (object) $item;
			}
			if ( ! is_object( $item ) ) {
				continue;
			}
			$name = isset( $item->name ) ? sanitize_text_field( (string) $item->name ) : '';
			if ( \FazCookie\Frontend\Frontend::is_wp_internal_cookie( $name ) ) {
				continue;
			}
			$cookies[] = array(
				'cookieID' => $name,
				'domain'   => isset( $item->domain ) ? sanitize_text_field( (string) $item->domain ) : '',
				'provider' => isset( $item->url_pattern ) ? sanitize_text_field( (string) $item->url_pattern ) : '',
			);
		}
		return $cookies;
	}

	/**
	 * Build the `i18n` payload — the same WordPress strings Frontend
	 * exposes in `_fazStore._i18n`. When switch_to_locale has successfully
	 * swapped the translation, __() returns strings in the target language.
	 *
	 * @return array
	 */
	protected function build_i18n_payload() {
		return array(
			'privacy_region_label'                => __( 'We value your privacy', 'faz-cookie-manager' ),
			'consent_saved'                       => __( 'Your cookie preferences have been saved.', 'faz-cookie-manager' ),
			'optout_preferences_label'            => __( 'Opt-out Preferences', 'faz-cookie-manager' ),
			'customise_consent_preferences_label' => __( 'Customise Consent Preferences', 'faz-cookie-manager' ),
			'service_consent_label'               => __( 'Service consent', 'faz-cookie-manager' ),
			'vendor_consent_label'                => __( 'Vendor consent', 'faz-cookie-manager' ),
		);
	}

	/**
	 * Convert a plugin language code (e.g. "it", "pt-br") to a WordPress locale
	 * (e.g. "it_IT", "pt_BR"). Thin wrapper over the shared `faz_wp_locale()`
	 * helper so both the REST endpoint and the initial server-side banner
	 * render resolve locales from a single source of truth.
	 *
	 * @param string $lang Plugin language code.
	 * @return string
	 */
	protected function language_to_wp_locale( $lang ) {
		return faz_wp_locale( $lang );
	}
}
