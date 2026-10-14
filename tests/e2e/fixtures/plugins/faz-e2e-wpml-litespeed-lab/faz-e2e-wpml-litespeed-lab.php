<?php
/**
 * Plugin Name: FAZ E2E WPML + LiteSpeed Lab
 * Description: Test-only. Emulates WPML's detection surface (ICL_LANGUAGE_CODE,
 *   SitePress, and the wpml_current_language / wpml_default_language /
 *   wpml_setting filters) so FAZ resolves the banner language exactly as it
 *   would with real WPML — real WPML is commercial and cannot ship in the repo.
 *   Also exposes FAZ's resolved language and WPML URL-safety as response headers,
 *   and counts LiteSpeed full-cache purges so the E2E suite can prove FAZ
 *   invalidates the LiteSpeed cache on a save. Never shipped; lives under
 *   tests/e2e/fixtures.
 *
 * @package FazCookieE2E
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * ---- WPML emulation ------------------------------------------------------
 * The "current" language is read from the ?wpmllang= query var (simulating a
 * visitor on WPML's /it/ or /en/ URL); the negotiation mode is read from the
 * faz_e2e_wpml_negotiation option (1 = directory, 2 = domain, 3 = parameter),
 * so a test can flip modes without reinstalling anything. FAZ never reads the
 * value of ICL_LANGUAGE_CODE — only that the constant / SitePress class exists
 * (faz_i18n_is_multilingual + faz_wpml_language_in_url) — and pulls the actual
 * language from the wpml_current_language filter.
 */

// OPT-IN: only emulate WPML when a spec asks for it. Specs that exercise a REAL
// multilingual plugin (e.g. TranslatePress) activate this fixture purely for its
// probe headers and must not have a phantom WPML in the mix — TRP/Weglot sit
// before WPML in faz_current_language()'s chain, so a stray emulation would
// muddy which branch is under test.
if ( 'yes' === get_option( 'faz_e2e_wpml_emulate', 'no' ) ) {

	if ( ! defined( 'ICL_LANGUAGE_CODE' ) ) {
		define( 'ICL_LANGUAGE_CODE', 'en' );
	}

	if ( ! class_exists( 'SitePress' ) ) {
		/** WPML core class — faz_wpml_language_in_url() also accepts its presence. */
		class SitePress {}
	}

	if ( ! function_exists( 'faz_e2e_wpml_current_lang' ) ) {
		/**
		 * The language WPML would report for this request.
		 *
		 * @return string
		 */
		function faz_e2e_wpml_current_lang() {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only test probe.
			$lang = isset( $_GET['wpmllang'] ) ? sanitize_key( wp_unslash( $_GET['wpmllang'] ) ) : 'en';
			return $lang ? $lang : 'en';
		}
	}

	add_filter(
		'wpml_current_language',
		static function () {
			return faz_e2e_wpml_current_lang();
		}
	);

	add_filter(
		'wpml_default_language',
		static function () {
			return 'en';
		}
	);

	// WPML exposes settings through apply_filters('wpml_setting', $default, $key).
	add_filter(
		'wpml_setting',
		static function ( $value, $key = '' ) {
			if ( 'language_negotiation_type' === $key ) {
				$mode = (int) get_option( 'faz_e2e_wpml_negotiation', 1 );
				return $mode > 0 ? $mode : 1;
			}
			return $value;
		},
		10,
		2
	);
}

/*
 * ---- Country source, opt-in ----------------------------------------------
 *
 * Geolocation::has_country_source() answers "is a country source CONFIGURED
 * for this site", and 02b needs it to answer yes in both the HTTP request and
 * the separate wpEval CLI process. On this machine the dev-only mu-plugin
 * faz-geo-dev-fake-cf happens to supply one, but a spec must not rest on a
 * fixture that is not in the repository: with it absent — or with a leftover
 * faz-e2e-force-no-geo — the test failed as though the stand-down path were
 * broken, which is the environment dependence it was written to remove.
 *
 * faz_trust_geoip_country_code rather than the Cloudflare header: it satisfies
 * has_country_source() without changing how a country is RESOLVED, because the
 * mod_geoip path behind it needs geoip_country_code_by_name(), which this stack
 * does not have. Gated on an option so only the spec that asks for it is
 * affected.
 */
if ( 'yes' === get_option( 'faz_e2e_force_country_source', 'no' ) ) {
	add_filter( 'faz_trust_geoip_country_code', '__return_true' );
}

/*
 * ---- Observability -------------------------------------------------------
 */

add_action(
	'send_headers',
	static function () {
		if ( is_admin() ) {
			return;
		}
		if ( function_exists( 'faz_current_language' ) ) {
			header( 'X-Faz-Current-Language: ' . faz_current_language() );
		}
		if ( function_exists( 'faz_wpml_language_in_url' ) ) {
			header( 'X-Faz-Wpml-Url-Safe: ' . ( faz_wpml_language_in_url() ? '1' : '0' ) );
		}
		if ( function_exists( 'faz_trp_language_in_url' ) ) {
			header( 'X-Faz-Trp-Url-Safe: ' . ( faz_trp_language_in_url() ? '1' : '0' ) );
		}
		if ( function_exists( 'faz_weglot_language_in_url' ) ) {
			header( 'X-Faz-Weglot-Url-Safe: ' . ( faz_weglot_language_in_url() ? '1' : '0' ) );
		}
	},
	99999
);

// LiteSpeed fires this action from \LiteSpeed\Purge::purge_all(); counting it
// proves FAZ's LiteSpeed adapter invalidated the whole cache on a save.
add_action(
	'litespeed_purged_all',
	static function () {
		$count = (int) get_option( 'faz_e2e_ls_purge_count', 0 );
		update_option( 'faz_e2e_ls_purge_count', $count + 1, false );
	}
);
