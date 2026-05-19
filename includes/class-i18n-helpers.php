<?php
/**
 * Translation helper functions
 *
 * @link       https://fabiodalez.it/
 * @since      3.0.0
 * @package    FazCookie\Includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}
if ( ! function_exists( 'faz_default_language' ) ) {

	/**
	 * Check if a request is a rest request
	 *
	 * @return string
	 */
	function faz_default_language() {
		$settings = get_option( 'faz_settings' );
		if ( isset( $settings['languages']['default'] ) && is_string( $settings['languages']['default'] ) && '' !== $settings['languages']['default'] ) {
			return faz_sanitize_text( $settings['languages']['default'] );
		}
		// Fall back to WordPress site language (e.g. de_DE → de) instead of hardcoded 'en'.
		return function_exists( 'faz_set_default_language' ) ? faz_set_default_language() : 'en';
	}
}
if ( ! function_exists( 'faz_selected_languages' ) ) {

	/**
	 * Check if a request is a rest request
	 *
	 * @param string $language Language to add temporarily to the existing list.
	 * @return array
	 */
	function faz_selected_languages( $language = '' ) {
		$settings  = get_option( 'faz_settings' );
		$languages = isset( $settings['languages']['selected'] ) ? faz_sanitize_text( $settings['languages']['selected'] ) : array();
		if ( ! in_array( faz_default_language(), $languages, true ) ) {
			array_push( $languages, faz_default_language() );
		}
		if ( '' !== $language && ! in_array( $language, $languages, true ) ) {
			array_push( $languages, $language );
		}
		return $languages;
	}
}

if ( ! function_exists( 'faz_i18n_is_multilingual' ) ) {

	/**
	 * Return true if multilingual plugin is active
	 *
	 * @return boolean
	 */
	function faz_i18n_is_multilingual() {
		$status = false;

		if ( defined( 'ICL_LANGUAGE_CODE' ) || defined( 'POLYLANG_FILE' ) ) {
			$status = true;
		}

		// TranslatePress compatibility.
		if ( defined( 'TRP_PLUGIN_VERSION' ) || class_exists( 'TRP_Translate_Press' ) ) {
			$status = true;
		}

		// Weglot compatibility.
		if ( defined( 'WEGLOT_VERSION' ) || function_exists( 'weglot_get_current_language' ) ) {
			$status = true;
		}

		return $status;
	}
}

if ( ! function_exists( 'faz_current_language' ) ) {
	/**
	 * Returns the current language code of the site.
	 *
	 * IMPORTANT: this function is cache-safe. When no URL-based multilingual
	 * plugin is active (WPML, Polylang, TranslatePress, Weglot), the function
	 * returns the site's default language rather than parsing the visitor's
	 * Accept-Language header. Accept-Language parsing on the server would
	 * contaminate full-page/CDN caches with the first visitor's language and
	 * serve it to everyone else (see GitHub issue #67).
	 *
	 * Browser-based language detection still happens, but client-side in
	 * script.js using `navigator.languages`. The JS reads
	 * `_fazStore._availableLanguages` and `_fazStore._browserDetect`, performs
	 * the match, and — if the detected language differs from the cacheable one
	 * the server picked — fetches the banner in the correct language through
	 * the REST API and swaps the DOM before the banner is shown.
	 *
	 * @return string
	 */
	function faz_current_language( $reset_cache = false ) {
		static $cached = null;
		if ( true === $reset_cache ) {
			$cached = null;
			return '';
		}
		if ( null !== $cached ) {
			return $cached;
		}
		$current_language = null;

		if ( faz_i18n_is_multilingual() ) {
			// If the plugin used is Polylang.
			if ( function_exists( 'pll_current_language' ) ) {

				$current_language = pll_current_language();
				// If current_language is still empty, we have to get the default language.
				if ( empty( $current_language ) ) {
					$current_language = pll_default_language();
				}
			} elseif ( defined( 'TRP_PLUGIN_VERSION' ) || class_exists( 'TRP_Translate_Press' ) ) {
				// TranslatePress: read the global language variable.
				global $TRP_LANGUAGE;
				if ( ! empty( $TRP_LANGUAGE ) ) {
					$current_language = substr( $TRP_LANGUAGE, 0, 2 );
				}
			} elseif ( function_exists( 'weglot_get_current_language' ) ) {
				// Weglot: use the helper function.
				$current_language = weglot_get_current_language();
			} else {
				// If the plugin used is WPML.
				$current_language = apply_filters( 'wpml_current_language', null );
			}

			// Fallback if neither WPML nor Polylang is used.
			if ( 'all' === $current_language || empty( $current_language ) ) {
				$current_language = faz_default_language();
			}
		} else {
			// No URL-based multilingual plugin — fall back to the site default
			// so that the rendered HTML stays cacheable. The browser-preferred
			// language is resolved client-side in script.js.
			//
			// Opt-in country→language fallback (1.14.0+): when the admin
			// has filtered `faz_use_country_language_fallback` to true,
			// derive the banner language from the visitor's detected
			// country before falling back to the site default. Lets
			// installs WITHOUT Polylang/WPML still serve country-localised
			// banner copy via multi-banner geo-routing — an Italian
			// visitor on a site with selected langs ["en","it"] sees the
			// `it` content even though the URL is /en/.
			$current_language = faz_default_language();
			if (
				class_exists( '\\FazCookie\\Includes\\Geolocation' )
				&& apply_filters( 'faz_use_country_language_fallback', false )
			) {
				$country = \FazCookie\Includes\Geolocation::get_visitor_country();
				if ( ! empty( $country ) ) {
					$selected = faz_selected_languages();
					// Issue #108: prefer the BCP-47 locale form when the
					// install actually has a regional-dialect translation
					// selected (es. `pt_BR` vs `pt_PT`). Falls back to the
					// language-only form when no regional variant is in
					// scope — preserves the pre-#108 behaviour on installs
					// that ship only `pt`.
					$country_locale = function_exists( 'faz_country_to_locale' ) ? faz_country_to_locale( $country ) : '';
					// Normalise WP-style locale (`pt_BR`) to the plugin-internal
					// hyphenated lowercase form (`pt-br`) — that's the shape
					// faz_selected_languages() returns. Without this the
					// regional-variant branch could never match (CodeRabbit
					// review, 1.14.2).
					if ( '' !== $country_locale ) {
						$country_locale = strtolower( str_replace( '_', '-', $country_locale ) );
					}
					if ( '' !== $country_locale && in_array( $country_locale, $selected, true ) ) {
						$current_language = $country_locale;
					} else {
						$country_lang = faz_country_to_language( $country );
						if ( ! empty( $country_lang ) && in_array( $country_lang, $selected, true ) ) {
							$current_language = $country_lang;
						}
					}
				}
			}
		}
		$map              = faz_get_lang_map();
		$current_language = isset( $map[ $current_language ] ) ? $map[ $current_language ] : $current_language;
		if ( in_array( $current_language, faz_selected_languages(), true ) === false ) {
			$current_language = faz_default_language();
		}
		$cached = apply_filters( 'faz_current_language', $current_language );
		return $cached;
	}
}

if ( ! function_exists( 'faz_country_to_language' ) ) {
	/**
	 * Map an ISO-3166 alpha-2 country code to its primary ISO-639-1 language
	 * code. Used by the opt-in country-based language fallback in
	 * `faz_current_language()` so installs without a URL-based multilingual
	 * plugin can still serve country-localised banner copy.
	 *
	 * The default map covers the regions the plugin's region presets list
	 * (EU / EEA, UK, US, Canada, Brazil, Switzerland, Japan, Australia) plus
	 * a handful of common destinations. Officially-multilingual countries
	 * (CH=de, CA=en, BE=nl, LU=fr, …) pick the most-spoken option; sites
	 * with a different default can override via the
	 * `faz_country_to_language` filter.
	 *
	 * @since 1.14.0
	 * @param string $country ISO-3166 alpha-2 country code.
	 * @return string ISO-639-1 language code, or '' when unmapped.
	 */
	function faz_country_to_language( $country ) {
		$country = is_string( $country ) ? strtoupper( trim( $country ) ) : '';
		if ( '' === $country ) {
			return '';
		}
		$map = array(
			// Italian
			'IT' => 'it', 'SM' => 'it', 'VA' => 'it',
			// German
			'DE' => 'de', 'AT' => 'de', 'LI' => 'de', 'CH' => 'de',
			// French
			// BE: Dutch (Vlaams) is the most-spoken language (~60%); French
			// (Wallonia, ~38%) and German (East Cantons, ~1%) are also
			// official. Override via the faz_country_to_language filter for
			// French- or German-primary Belgian sites.
			'FR' => 'fr', 'MC' => 'fr', 'BE' => 'nl', 'LU' => 'fr',
			// Spanish
			'ES' => 'es', 'MX' => 'es', 'AR' => 'es', 'CL' => 'es', 'CO' => 'es', 'PE' => 'es', 'VE' => 'es', 'UY' => 'es', 'PY' => 'es',
			// Portuguese
			'PT' => 'pt', 'BR' => 'pt',
			// Dutch
			'NL' => 'nl',
			// Polish
			'PL' => 'pl',
			// Czech / Slovak
			'CZ' => 'cs', 'SK' => 'sk',
			// Hungarian
			'HU' => 'hu',
			// Romanian
			'RO' => 'ro',
			// Greek
			'GR' => 'el', 'CY' => 'el',
			// Scandinavian
			'SE' => 'sv', 'NO' => 'no', 'DK' => 'da', 'FI' => 'fi', 'IS' => 'is',
			// Baltic
			'EE' => 'et', 'LV' => 'lv', 'LT' => 'lt',
			// Slovenian / Croatian / Bulgarian / Maltese
			'SI' => 'sl', 'HR' => 'hr', 'BG' => 'bg', 'MT' => 'mt',
			// Irish (English is also official)
			'IE' => 'en',
			// English-speaking
			'GB' => 'en', 'US' => 'en', 'CA' => 'en', 'AU' => 'en', 'NZ' => 'en', 'IN' => 'en', 'ZA' => 'en',
			// East Asia
			'JP' => 'ja', 'CN' => 'zh', 'TW' => 'zh', 'HK' => 'zh', 'KR' => 'ko',
			// Russian / Ukrainian / Turkish / Arabic
			'RU' => 'ru', 'UA' => 'uk', 'TR' => 'tr',
			'SA' => 'ar', 'AE' => 'ar', 'EG' => 'ar',
		);
		/**
		 * Filter the country→language map.
		 *
		 * Override for installs whose audience does not match the default
		 * mapping (e.g. a Swiss site whose audience is primarily French
		 * would return 'fr' for 'CH' instead of the default 'de').
		 *
		 * @since 1.14.0
		 * @param array  $map     Country code → language code map.
		 * @param string $country The country code being resolved.
		 */
		$map = (array) apply_filters( 'faz_country_to_language_map', $map, $country );
		$lang = isset( $map[ $country ] ) ? (string) $map[ $country ] : '';
		/**
		 * Filter the resolved language for a specific country.
		 *
		 * Lets callers override individual mappings without re-declaring
		 * the full map.
		 *
		 * @since 1.14.0
		 * @param string $lang    Resolved language code, '' when unmapped.
		 * @param string $country The country code being resolved.
		 */
		return (string) apply_filters( 'faz_country_to_language', $lang, $country );
	}
}

if ( ! function_exists( 'faz_country_to_locale' ) ) {
	/**
	 * Map an ISO-3166 alpha-2 country code to a BCP-47 / WordPress locale
	 * (e.g. `pt_BR`, `zh_TW`, `en_US`) — the regional-dialect-aware
	 * counterpart of {@see faz_country_to_language()}.
	 *
	 * Why this exists (issue #108): the bare ISO-639-1 form
	 * (\`pt\`, \`zh\`, \`es\`) cannot distinguish between Brazilian and
	 * European Portuguese, Mainland and Traditional Chinese, Mexican and
	 * Castilian Spanish, etc. Multilingual plugins (Polylang, WPML,
	 * TranslatePress, Weglot) match against full WP-style locales, so a
	 * Brazilian visitor on a site that ships both pt_BR and pt_PT would
	 * otherwise fall through to whichever the plugin defaults to.
	 *
	 * The default table covers the regional variants the multilingual
	 * ecosystem actually distinguishes. Officially-multilingual
	 * countries follow the same most-spoken pick used by
	 * faz_country_to_language(). Override per-country via
	 * `faz_country_to_locale` (single value) or `faz_country_to_locale_map`
	 * (full table). Compatible with ClassicPress 1.x — pure data + filters.
	 *
	 * @since 1.14.0
	 * @param string $country ISO-3166 alpha-2 country code.
	 * @return string Underscored WP-style locale (e.g. `pt_BR`), or '' when unmapped.
	 */
	function faz_country_to_locale( $country ) {
		$country = is_string( $country ) ? strtoupper( trim( $country ) ) : '';
		if ( '' === $country ) {
			return '';
		}
		$map = array(
			// Portuguese — Brazilian vs European
			'PT' => 'pt_PT', 'BR' => 'pt_BR',
			// Spanish — Spain / Mexico / South America (most ship es_MX as
			// the Latin-American baseline, hence the shared mapping).
			'ES' => 'es_ES', 'MX' => 'es_MX', 'AR' => 'es_AR',
			'CL' => 'es_CL', 'CO' => 'es_CO', 'PE' => 'es_PE',
			'VE' => 'es_VE', 'UY' => 'es_UY', 'PY' => 'es_PY',
			// English — preserve regional spelling/locale conventions.
			'GB' => 'en_GB', 'US' => 'en_US', 'CA' => 'en_CA',
			'AU' => 'en_AU', 'NZ' => 'en_NZ', 'IN' => 'en_IN',
			'ZA' => 'en_ZA', 'IE' => 'en_IE',
			// French — France / Monaco / Luxembourg.
			// (Canada returns 'en_CA' from this map; sites targeting
			// Quebec-French audiences should override via the
			// `faz_country_to_locale` filter to return 'fr_CA' for CA —
			// the 2-letter input contract makes a synthetic CA_FR key
			// here unreachable. F012 fix.)
			'FR' => 'fr_FR', 'MC' => 'fr_FR', 'LU' => 'fr_FR',
			// German — Germany / Austria / Switzerland
			'DE' => 'de_DE', 'AT' => 'de_AT', 'CH' => 'de_CH', 'LI' => 'de_DE',
			// Italian
			'IT' => 'it_IT', 'SM' => 'it_IT', 'VA' => 'it_IT',
			// Dutch / Flemish
			'NL' => 'nl_NL', 'BE' => 'nl_BE',
			// Chinese — Mainland / Taiwan / Hong Kong
			'CN' => 'zh_CN', 'TW' => 'zh_TW', 'HK' => 'zh_HK',
			// East Asia + others — most have a single canonical locale.
			'JP' => 'ja_JP', 'KR' => 'ko_KR',
			'RU' => 'ru_RU', 'UA' => 'uk_UA', 'TR' => 'tr_TR',
			'PL' => 'pl_PL', 'CZ' => 'cs_CZ', 'SK' => 'sk_SK',
			'HU' => 'hu_HU', 'RO' => 'ro_RO',
			'GR' => 'el_GR', 'CY' => 'el_GR',
			'SE' => 'sv_SE', 'NO' => 'nb_NO', 'DK' => 'da_DK',
			'FI' => 'fi_FI', 'IS' => 'is_IS',
			'EE' => 'et_EE', 'LV' => 'lv_LV', 'LT' => 'lt_LT',
			'SI' => 'sl_SI', 'HR' => 'hr_HR', 'BG' => 'bg_BG', 'MT' => 'mt_MT',
			// Arabic — most-deployed locale per country.
			'SA' => 'ar_SA', 'AE' => 'ar_AE', 'EG' => 'ar_EG',
		);
		/**
		 * Filter the country→locale map (full table override).
		 *
		 * @since 1.14.0
		 * @param array  $map     Country code → WP locale map.
		 * @param string $country The country code being resolved.
		 */
		$map    = (array) apply_filters( 'faz_country_to_locale_map', $map, $country );
		$locale = isset( $map[ $country ] ) ? (string) $map[ $country ] : '';
		/**
		 * Filter the resolved locale for a specific country (single override).
		 *
		 * Lets callers override individual mappings without re-declaring
		 * the full map — e.g. flip BE from Flemish (nl_BE) to French (fr_BE)
		 * for a Wallonian audience.
		 *
		 * @since 1.14.0
		 * @param string $locale  Resolved WP locale, '' when unmapped.
		 * @param string $country The country code being resolved.
		 */
		return (string) apply_filters( 'faz_country_to_locale', $locale, $country );
	}
}

if ( ! function_exists( 'faz_browser_detect_enabled' ) ) {
	/**
	 * Whether the client-side JS should perform browser-language detection.
	 *
	 * Returns true when no URL-based multilingual plugin is active AND the
	 * admin has selected at least two languages. When this is true,
	 * `_fazStore._browserDetect` is exposed to the frontend and script.js
	 * reads `navigator.languages`, matches against the selected languages,
	 * and fetches the banner in the matching language if it differs from the
	 * server-rendered (cacheable) default.
	 *
	 * Site owners with aggressive CDN configurations can short-circuit
	 * detection entirely by returning false via the
	 * `faz_disable_browser_language_detection` filter.
	 *
	 * @return bool
	 */
	function faz_browser_detect_enabled() {
		if ( faz_i18n_is_multilingual() ) {
			return false;
		}
		if ( count( faz_selected_languages() ) < 2 ) {
			return false;
		}
		/**
		 * Filter to disable client-side browser-language detection.
		 *
		 * Returning true forces the banner to always use the default language.
		 *
		 * @param bool $disabled Defaults to false (detection enabled).
		 */
		if ( true === apply_filters( 'faz_disable_browser_language_detection', false ) ) {
			return false;
		}
		return true;
	}
}

if ( ! function_exists( 'faz_get_lang_map' ) ) {
	/**
	 * Returns the current language code of the site
	 *
	 * @return string
	 */
	function faz_get_lang_map() {
		$map = array(
			'pt-pt' => 'pt',
		);

		return apply_filters( 'faz_language_map', $map );
	}
}

if ( ! function_exists( 'faz_detect_browser_language' ) ) {
	/**
	 * Detect visitor's preferred language from the Accept-Language header.
	 * Returns a language code from faz_selected_languages() if matched,
	 * otherwise returns the default language.
	 *
	 * @return string
	 */
	function faz_detect_browser_language() {
		if ( empty( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ) {
			return faz_default_language();
		}

		$selected = faz_selected_languages();
		if ( count( $selected ) <= 1 ) {
			return faz_default_language();
		}

		$accept = sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) );
		$map    = faz_get_lang_map();

		// Parse Accept-Language: e.g. "it-IT,it;q=0.9,en-US;q=0.8,en;q=0.7"
		$langs = array();
		foreach ( explode( ',', $accept ) as $part ) {
			$part = trim( $part );
			if ( empty( $part ) ) {
				continue;
			}
			$pieces = explode( ';', $part );
			$code   = strtolower( trim( $pieces[0] ) );
			$q      = 1.0;
			if ( isset( $pieces[1] ) && preg_match( '/q\s*=\s*([\d.]+)/', $pieces[1], $m ) ) {
				$q = (float) $m[1];
			}
			$langs[ $code ] = $q;
		}
		arsort( $langs );

		foreach ( $langs as $code => $q ) {
			// Apply language map normalization.
			$normalized = isset( $map[ $code ] ) ? $map[ $code ] : $code;

			// Exact match (e.g. "pt-br").
			if ( in_array( $normalized, $selected, true ) ) {
				return $normalized;
			}

			// Try base language (e.g. "it-IT" → "it").
			$base = substr( $code, 0, 2 );
			$base = isset( $map[ $base ] ) ? $map[ $base ] : $base;
			if ( in_array( $base, $selected, true ) ) {
				return $base;
			}
		}

		return faz_default_language();
	}
}

if ( ! function_exists( 'faz_i18n_default_language' ) ) {
	/**
	 * Returns the current language code of the site
	 *
	 * @return string
	 */
	function faz_i18n_default_language() {
		if ( faz_i18n_is_multilingual() ) {
			if ( function_exists( 'pll_default_language' ) ) {
				$default = pll_default_language();
			} else {
				$null    = null;
				$default = apply_filters( 'wpml_default_language', $null );
			}
		} else {
			$default = faz_default_language();
		}
		return $default;
	}
}
if ( ! function_exists( 'faz_i18n_term_by_language' ) ) {
	/**
	 * Returns the current language code of the site
	 *
	 * @param integer $term_id Original term id.
	 * @param string  $language Language code.
	 * @return object
	 */
	function faz_i18n_term_by_language( $term_id, $language ) {
		$term = false;
		if ( faz_i18n_is_multilingual() ) {
			if ( function_exists( 'pll_get_term_translations' ) ) {
				$terms = pll_get_term_translations( $term_id );
				if ( isset( $terms[ $language ] ) ) {
					$original_term_id = $terms[ $language ];
					$term             = get_term_by( 'id', $original_term_id, 'cookielawinfo-category' );
				}
			} else {
				if ( function_exists( 'icl_object_id' ) ) {
					global $sitepress;
					if ( $sitepress ) {
						if ( version_compare( ICL_SITEPRESS_VERSION, '3.2.0' ) >= 0 ) {
							$original_term_id = apply_filters( 'wpml_object_id', $term_id, 'category', true, $language );
						} else {
							$original_term_id = icl_object_id( $term_id, 'category', true, $language );
						}
						remove_filter( 'get_term', array( $sitepress, 'get_term_adjust_id' ), 1 );
						$term = get_term_by( 'id', $original_term_id, 'cookielawinfo-category' );
						add_filter( 'get_term', array( $sitepress, 'get_term_adjust_id' ), 1, 1 );
					}
				}
			}
		}
		return $term;
	}
}

if ( ! function_exists( 'faz_i18n_post_by_language' ) ) {
	/**
	 * Returns the current language code of the site
	 *
	 * @param integer $post_id Original post id.
	 * @param string  $language Language code.
	 * @return object|false
	 */
	function faz_i18n_post_by_language( $post_id, $language ) {
		$post = false;
		if ( faz_i18n_is_multilingual() ) {
			if ( function_exists( 'pll_get_post_translations' ) ) {
				$posts = pll_get_post_translations( $post_id );
				if ( isset( $posts[ $language ] ) ) {
					$original_post_id = $posts[ $language ];
					$post             = get_post( $original_post_id );
				}
			} else {
				if ( function_exists( 'icl_object_id' ) ) {
					$type = apply_filters( 'wpml_element_type', get_post_type( $post_id ) );
					$trid = apply_filters( 'wpml_element_trid', false, $post_id, $type );

					$translations = apply_filters( 'wpml_get_element_translations', array(), $trid, $type );
					if ( isset( $translations[ $language ] ) ) {
						$original_post_id = isset( $translations[ $language ]->element_id ) ? $translations[ $language ]->element_id : false;
						if ( $original_post_id ) {
							$post = get_post( $original_post_id );
						}
					}
				}
			}
		}
		return $post;
	}
}

if ( ! function_exists( 'faz_wpml_active' ) ) {
	function faz_wpml_active() {
		return class_exists( 'SitePress' );
	}
}

if ( ! function_exists( 'faz_i18n_selected_languages' ) ) {
	function faz_i18n_selected_languages() {
		$languages = array( faz_i18n_default_language() );
		if ( faz_i18n_is_multilingual() ) {
			if ( faz_wpml_active() ) {
				return faz_i18n_wpml_languages();
			} else {
				return faz_i18n_pll_languages();
			}
		}
		return $languages;
	}
}

if ( ! function_exists( 'faz_i18n_pll_languages' ) ) {
	function faz_i18n_pll_languages() {
		$languages = array();
		if ( function_exists( 'pll_languages_list' ) ) {
			$configured = pll_languages_list();
			if ( empty( $configured ) ) {
				return $languages;
			}
			foreach ( $configured as $language ) {
				$languages[] = $language;
			}
		}
		return $languages;
	}
}
if ( ! function_exists( 'faz_i18n_wpml_languages' ) ) {
	function faz_i18n_wpml_languages() {
		$languages  = array();
		$configured = apply_filters( 'wpml_active_languages', null );
		if ( empty( $configured ) ) {
			return $languages;
		}
		foreach ( $configured as $key => $language ) {
			$languages[] = $key;
		}
		return $languages;
	}
}

if ( ! function_exists( 'faz_i18n_translate_string' ) ) {
	function faz_i18n_translate_string( $string, $key, $language, $context = 'CookieLawInfo-0.9' ) {
		if ( function_exists( 'pll_translate_string' ) ) {
			return pll_translate_string( $string, $language );
		} else {
			return apply_filters( 'wpml_translate_single_string', $string, "admin_texts_{$context}", "[{$context}]" . $key, $language );
		}
	}
}

if ( ! function_exists( 'faz_i18n_term_language' ) ) {
	function faz_i18n_term_language( $term ) {
		$language = faz_i18n_default_language();
		if ( faz_i18n_is_multilingual() ) {
			if ( function_exists( 'pll_get_term_language' ) ) {
				$language = pll_get_term_language( $term );
			}
		}
		return $language;
	}
}

if ( ! function_exists( 'faz_wp_locale' ) ) {
	/**
	 * Map a plugin language code (e.g. "de", "pt-br") to a WordPress locale
	 * (e.g. "de_DE", "pt_BR"). Falls back to the input when no mapping exists.
	 *
	 * Single source of truth used both by the REST banner endpoint and the
	 * initial server-side banner render. Without it the initial render would
	 * call `__( '...', 'faz-cookie-manager' )` against the WP-installed
	 * locale (e.g. en_US) even when the plugin's configured default is a
	 * different language — producing a cached banner template with English
	 * strings under a `[de]` cache key.
	 *
	 * Override via the `faz_wp_locale_from_language` filter.
	 *
	 * @param string $lang Plugin language code.
	 * @return string WordPress locale code.
	 */
	function faz_wp_locale( $lang ) {
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
			'hr'    => 'hr_HR',
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

if ( ! function_exists( 'faz_clear_banner_template_cache' ) ) {
	/**
	 * Clear all banner template cache variants.
	 *
	 * Deletes the base option and any language-suffixed variants created by
	 * the faz_banner_template_cache_key filter (e.g. faz_banner_template_en,
	 * faz_banner_template_it). Used whenever the banner needs full regeneration.
	 *
	 * @return void
	 */
	function faz_clear_banner_template_cache() {
		global $wpdb;

		// Delete the base option.
		delete_option( 'faz_banner_template' );

		// Delete any language-suffixed variants.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name != %s",
				$wpdb->esc_like( 'faz_banner_template_' ) . '%',
				'faz_banner_template'
			)
		);
		foreach ( $rows as $option_name ) {
			delete_option( $option_name );
		}
	}
}
