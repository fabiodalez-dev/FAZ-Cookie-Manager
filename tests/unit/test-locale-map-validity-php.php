<?php
/**
 * Every locale this plugin maps to must be one WordPress can actually resolve.
 *
 * A locale that does not exist does not fail loudly: switch_to_locale() finds
 * no catalogue and the string silently renders in English. That is how
 * faz-cookie-manager-hr_HR.mo shipped for several releases while Croatian
 * visitors read English (wordpress.org support topic "Croatian translation not
 * loading in FAZ 1.26.0"). The audit that followed corrected eleven mappings by
 * hand and still left three behind — es_PY, en_IN and en_IE, none of which
 * WordPress has ever shipped. Reading a table of locale codes is exactly the
 * kind of check a person performs badly and a machine performs perfectly.
 *
 * WP_LOCALES below is a snapshot of `wp core language list` (134 locales, WP
 * 7.x) plus the implicit en_US. Asserting our values are a SUBSET means new
 * WordPress locales never break this test; only a mapping to something that
 * does not exist does.
 *
 * @package FazCookie\Tests\Unit
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

// The helpers call these; the map lookups themselves need nothing more.
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value ) { return $value; }
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ) { return trim( strip_tags( is_scalar( $value ) ? (string) $value : '' ) ); }
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) { return $default; }
}
if ( ! function_exists( 'get_locale' ) ) {
	function get_locale() { return 'en_US'; }
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value ) { return json_encode( $value ); }
}

require_once __DIR__ . '/../../includes/class-i18n-helpers.php';

/** Snapshot of `wp core language list` (WP 7.x) + the implicit en_US. */
$wp_locales = array(
	'en_US', 'af', 'am', 'ar', 'arg', 'ary', 'as', 'az', 'azb', 'bel', 'bg_BG', 'bn_BD', 'bo',
	'bs_BA', 'ca', 'ceb', 'ckb', 'cs_CZ', 'cy', 'da_DK', 'de_AT', 'de_CH', 'de_CH_informal',
	'de_DE', 'de_DE_formal', 'dsb', 'dzo', 'el', 'en_AU', 'en_CA', 'en_GB', 'en_NZ', 'en_ZA',
	'eo', 'es_AR', 'es_CL', 'es_CO', 'es_CR', 'es_DO', 'es_EC', 'es_ES', 'es_GT', 'es_MX',
	'es_PE', 'es_PR', 'es_UY', 'es_VE', 'et', 'eu', 'fa_AF', 'fa_IR', 'fi', 'fr_BE', 'fr_CA',
	'fr_FR', 'fur', 'fy', 'gd', 'gl_ES', 'gu', 'haz', 'he_IL', 'hi_IN', 'hr', 'hsb', 'hu_HU',
	'hy', 'id_ID', 'is_IS', 'it_IT', 'ja', 'jv_ID', 'ka_GE', 'kab', 'kir', 'kk', 'km', 'kn',
	'ko_KR', 'lo', 'lt_LT', 'lv', 'mk_MK', 'ml_IN', 'mn', 'mr', 'ms_MY', 'my_MM', 'nb_NO',
	'ne_NP', 'nl_BE', 'nl_NL', 'nl_NL_formal', 'nn_NO', 'oci', 'pa_IN', 'pcm', 'pl_PL', 'ps',
	'pt_AO', 'pt_BR', 'pt_PT', 'pt_PT_ao90', 'rhg', 'ro_RO', 'ru_RU', 'sah', 'si_LK', 'sk_SK',
	'skr', 'sl_SI', 'snd', 'sq', 'sr_RS', 'sv_SE', 'sw', 'szl', 'ta_IN', 'ta_LK', 'tah', 'te',
	'th', 'tl', 'tr_TR', 'tt_RU', 'ug_CN', 'uk', 'ur', 'uz_UZ', 'vi', 'yor', 'zh_CN', 'zh_HK',
	'zh_TW'
);
$wp_locales = array_flip( $wp_locales );

$pass = 0;
$fail = 0;
function locale_check( $ok, $msg ) {
	global $pass, $fail;
	if ( $ok ) {
		$pass++;
	} else {
		$fail++;
		echo "  [FAIL] $msg\n";
	}
}

// --- faz_wp_locale(): plugin language code -> WordPress locale ---------------
$languages = array(
	'en', 'it', 'de', 'fr', 'es', 'pt', 'pt-br', 'nl', 'pl', 'ru', 'cs', 'sk', 'hu',
	'ro', 'bg', 'hr', 'el', 'tr', 'sv', 'no', 'da', 'fi', 'zh', 'ja', 'ko', 'ar',
	'he', 'uk', 'sr',
);
foreach ( $languages as $lang ) {
	$locale = faz_wp_locale( $lang );
	locale_check(
		isset( $wp_locales[ $locale ] ),
		"faz_wp_locale('$lang') returns '$locale', which WordPress does not ship — the catalogue can never load and the string falls back to English"
	);
}

// The specific regression the support topic was filed about.
locale_check( 'hr' === faz_wp_locale( 'hr' ), "Croatian must map to 'hr'; 'hr_HR' is not a WordPress locale" );

// --- faz_country_to_locale(): visitor country -> WordPress locale ------------
$countries = array(
	'PT', 'BR', 'ES', 'MX', 'AR', 'CL', 'CO', 'PE', 'VE', 'UY', 'PY',
	'GB', 'US', 'CA', 'AU', 'NZ', 'IN', 'ZA', 'IE',
	'FR', 'DE', 'AT', 'CH', 'IT', 'NL', 'BE', 'PL', 'CZ', 'SK', 'HU', 'RO',
	'BG', 'HR', 'SI', 'GR', 'TR', 'SE', 'NO', 'DK', 'FI', 'IS', 'EE', 'LV', 'LT',
	'MT', 'RU', 'UA', 'JP', 'CN', 'KR', 'IL', 'SA', 'AE', 'EG', 'RS',
);
foreach ( $countries as $cc ) {
	$locale = faz_country_to_locale( $cc );
	if ( '' === $locale ) {
		continue; // Deliberately unmapped: the caller falls back on its own.
	}
	locale_check(
		isset( $wp_locales[ $locale ] ),
		"faz_country_to_locale('$cc') returns '$locale', which WordPress does not ship — this visitor silently gets English"
	);
}

// The three the hand audit missed, named so a regression says which and why.
locale_check( 'es_MX' === faz_country_to_locale( 'PY' ), "Paraguay must not map to the non-existent es_PY (a Spanish speaker would get English)" );
locale_check( 'en_GB' === faz_country_to_locale( 'IN' ), "India must not map to the non-existent en_IN" );
locale_check( 'en_GB' === faz_country_to_locale( 'IE' ), "Ireland must not map to the non-existent en_IE" );

echo "locale map validity: {$pass} passed, {$fail} failed\n";
exit( $fail > 0 ? 1 : 0 );
