<?php
/**
 * Compatibility with 3rd-party translation plugins (TranslatePress, Weglot).
 *
 * These plugins translate by intercepting the final HTML output. The FAZ banner
 * is rendered in wp_footer and cached in the faz_banner_template option. To ensure
 * translations work:
 * 1. The banner HTML must be in the output buffer (already is — wp_footer)
 * 2. The banner cache must be invalidated when the language changes
 * 3. The current language must be detected correctly
 *
 * @link       https://fabiodalez.it/
 * @since      1.5.0
 * @package    FazCookie\Frontend
 */

namespace FazCookie\Frontend;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Translation compatibility layer for TranslatePress and Weglot.
 *
 * @package    FazCookie\Frontend
 */
class Translation_Compat {

	/**
	 * Initialize hooks for detected translation plugins.
	 */
	public function __construct() {
		// TranslatePress compatibility.
		if ( $this->is_translatepress_active() ) {
			add_filter( 'faz_current_language', array( $this, 'get_translatepress_language' ) );
			add_action( 'trp_language_change', array( $this, 'clear_banner_cache' ) );
		}

		// Weglot compatibility.
		if ( $this->is_weglot_active() ) {
			add_filter( 'faz_current_language', array( $this, 'get_weglot_language' ) );
		}

		// General: append language to the banner template cache key so each
		// language gets its own cached template variant.
		add_filter( 'faz_banner_template_cache_key', array( $this, 'add_language_to_cache_key' ) );
	}

	/**
	 * Check if TranslatePress is active.
	 *
	 * @return bool
	 */
	private function is_translatepress_active() {
		return defined( 'TRP_PLUGIN_VERSION' ) || class_exists( 'TRP_Translate_Press' );
	}

	/**
	 * Check if Weglot is active.
	 *
	 * @return bool
	 */
	private function is_weglot_active() {
		return defined( 'WEGLOT_VERSION' ) || function_exists( 'weglot_get_current_language' );
	}

	/**
	 * Get current language from TranslatePress.
	 *
	 * TranslatePress stores the active language in the global $TRP_LANGUAGE
	 * variable as a full locale (e.g. en_US). We normalise to a 2-letter code.
	 *
	 * @param string $language Current language code.
	 * @return string
	 */
	public function get_translatepress_language( $language ) {
		global $TRP_LANGUAGE;
		if ( ! empty( $TRP_LANGUAGE ) ) {
			// TranslatePress uses full locale (en_US) — convert to 2-letter code.
			return substr( $TRP_LANGUAGE, 0, 2 );
		}
		return $language;
	}

	/**
	 * Get current language from Weglot.
	 *
	 * @param string $language Current language code.
	 * @return string
	 */
	public function get_weglot_language( $language ) {
		if ( function_exists( 'weglot_get_current_language' ) ) {
			$weglot_lang = weglot_get_current_language();
			if ( ! empty( $weglot_lang ) ) {
				return $weglot_lang;
			}
		}
		return $language;
	}

	/**
	 * Clear the banner template cache (base + all language variants).
	 *
	 * Called when TranslatePress changes language context.
	 */
	public function clear_banner_cache() {
		faz_clear_banner_template_cache();
	}

	/**
	 * Add language suffix to the banner template cache key.
	 *
	 * This ensures each language gets its own cached template variant,
	 * preventing stale translations from being served.
	 *
	 * @param string $cache_key Current cache key.
	 * @return string Modified cache key with language suffix.
	 */
	public function add_language_to_cache_key( $cache_key ) {
		$lang = apply_filters( 'faz_current_language', '' );
		if ( ! empty( $lang ) ) {
			$cache_key .= '_' . sanitize_key( $lang );
		}
		return $cache_key;
	}
}
