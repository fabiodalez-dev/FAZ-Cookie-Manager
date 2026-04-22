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

		$banner = Banner_Controller::get_instance()->get_active_banner();
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

		// Build the (possibly cached) template in the requested language.
		// Template::__construct triggers load() which either generates or
		// populates the language-specific slot in the faz_banner_template
		// option. We then read the stored payload directly, avoiding access
		// to protected props on the Template instance.
		new Banner_Template( $banner, $lang );
		$cache_key = apply_filters( 'faz_banner_template_cache_key', 'faz_banner_template' );
		$stored    = get_option( $cache_key, array() );
		$entry     = ( is_array( $stored ) && isset( $stored[ $lang ] ) && is_array( $stored[ $lang ] ) ) ? $stored[ $lang ] : array();
		$html      = isset( $entry['html'] ) ? (string) $entry['html'] : '';
		$styles    = isset( $entry['styles'] ) ? (string) $entry['styles'] : '';

		// Prepare shortcodes with a fresh instance bound to the language-
		// switched banner.
		$settings   = $banner->get_settings();
		$version_id = isset( $settings['settings']['versionID'] ) ? $settings['settings']['versionID'] : 'default';
		$shortcodes_instance = new Shortcodes( $banner, $version_id ); // registers add_shortcode with this instance.

		$short_codes = $this->build_shortcodes_payload( $banner, $shortcodes_instance );

		// Categories with names/descriptions resolved in the target language.
		$categories = $this->build_categories_payload( $lang );

		// Restore original state before responding.
		$banner->set_language( $orig_banner_lang );
		remove_filter( 'faz_current_language', $filter, 1 );
		faz_current_language( true );
		if ( $locale_switched && function_exists( 'restore_previous_locale' ) ) {
			restore_previous_locale();
		}

		$payload = array(
			'language'   => $lang,
			'html'       => $html,
			'styles'     => $styles,
			'shortCodes' => $short_codes,
			'categories' => $categories,
			'i18n'       => $this->build_i18n_payload(),
		);

		$response = new WP_REST_Response( $payload, 200 );
		// Allow CDNs to cache per-language responses for a short TTL. The
		// payload is deterministic for a given (lang, plugin version) pair.
		$response->header( 'Cache-Control', 'public, max-age=300' );
		return $response;
	}

	/**
	 * Build the `shortCodes` payload reproducing the subset used by script.js.
	 *
	 * Mirrors the minimal set Frontend::prepare_shortcodes exposes. Only the
	 * keys the client actually reads are included to keep the payload small.
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

		$codes = array(
			array(
				'key'        => 'faz_readmore',
				'content'    => do_shortcode( '[faz_readmore]' ),
				'tag'        => 'readmore-button',
				'status'     => isset( $readmore['status'] ) && true === $readmore['status'],
				'attributes' => $attributes,
			),
			array(
				'key'        => 'faz_show_desc',
				'content'    => do_shortcode( '[faz_show_desc]' ),
				'tag'        => 'show-desc-button',
				'status'     => true,
				'attributes' => array(),
			),
			array(
				'key'        => 'faz_hide_desc',
				'content'    => do_shortcode( '[faz_hide_desc]' ),
				'tag'        => 'hide-desc-button',
				'status'     => true,
				'attributes' => array(),
			),
			array(
				'key'        => 'faz_optout_show_desc',
				'content'    => do_shortcode( '[faz_optout_show_desc]' ),
				'tag'        => 'optout-show-desc-button',
				'status'     => true,
				'attributes' => array(),
			),
			array(
				'key'        => 'faz_optout_hide_desc',
				'content'    => do_shortcode( '[faz_optout_hide_desc]' ),
				'tag'        => 'optout-hide-desc-button',
				'status'     => true,
				'attributes' => array(),
			),
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
	 * @param string $lang Language code.
	 * @return array
	 */
	protected function build_categories_payload( $lang ) {
		$categories = Category_Controller::get_instance()->get_items();
		$out        = array();
		if ( empty( $categories ) || ! is_array( $categories ) ) {
			return $out;
		}
		foreach ( $categories as $category ) {
			if ( ! is_object( $category ) ) {
				continue;
			}
			$name = method_exists( $category, 'get_name' ) ? $category->get_name( $lang ) : '';
			$desc = method_exists( $category, 'get_description' ) ? $category->get_description( $lang ) : '';
			$slug = method_exists( $category, 'get_slug' ) ? $category->get_slug() : '';

			$entry = array(
				'slug'        => $slug,
				'name'        => $name,
				'description' => $desc,
			);
			if ( method_exists( $category, 'get_id' ) ) {
				$entry['id'] = $category->get_id();
			}
			if ( method_exists( $category, 'is_necessary' ) ) {
				$entry['isNecessary'] = (bool) $category->is_necessary();
			}
			$out[] = $entry;
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
	 * Build the `i18n` payload — the same WordPress strings Frontend
	 * exposes in `_fazStore._i18n`. When switch_to_locale has successfully
	 * swapped the translation, __() returns strings in the target language.
	 *
	 * @return array
	 */
	protected function build_i18n_payload() {
		return array(
			'privacy_region_label'                => __( 'We value your privacy', 'faz-cookie-manager' ),
			'optout_preferences_label'            => __( 'Opt-out Preferences', 'faz-cookie-manager' ),
			'customise_consent_preferences_label' => __( 'Customise Consent Preferences', 'faz-cookie-manager' ),
			'service_consent_label'               => __( 'Service consent', 'faz-cookie-manager' ),
			'vendor_consent_label'                => __( 'Vendor consent', 'faz-cookie-manager' ),
		);
	}

	/**
	 * Convert a plugin language code (e.g. "it", "pt-br") to a WordPress locale
	 * (e.g. "it_IT", "pt_BR"). Falls back to the input when no mapping exists.
	 *
	 * Users can override or extend the mapping via the
	 * `faz_wp_locale_from_language` filter.
	 *
	 * @param string $lang Plugin language code.
	 * @return string
	 */
	protected function language_to_wp_locale( $lang ) {
		$map = array(
			'en'    => 'en_US',
			'it'    => 'it_IT',
			'de'    => 'de_DE',
			'fr'    => 'fr_FR',
			'es'    => 'es_ES',
			'pt'    => 'pt_PT',
			'pt-br' => 'pt_BR',
			'nl'    => 'nl_NL',
			'pl'    => 'pl_PL',
			'ru'    => 'ru_RU',
			'cs'    => 'cs_CZ',
			'sk'    => 'sk_SK',
			'hu'    => 'hu_HU',
			'ro'    => 'ro_RO',
			'bg'    => 'bg_BG',
			'hr'    => 'hr',
			'el'    => 'el',
			'tr'    => 'tr_TR',
			'sv'    => 'sv_SE',
			'no'    => 'nb_NO',
			'da'    => 'da_DK',
			'fi'    => 'fi',
			'zh'    => 'zh_CN',
			'ja'    => 'ja',
			'ko'    => 'ko_KR',
			'ar'    => 'ar',
			'he'    => 'he_IL',
			'uk'    => 'uk',
			'sr'    => 'sr_RS',
		);
		$locale = isset( $map[ $lang ] ) ? $map[ $lang ] : $lang;
		return apply_filters( 'faz_wp_locale_from_language', $locale, $lang );
	}
}
