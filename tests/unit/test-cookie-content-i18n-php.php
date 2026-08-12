<?php
/**
 * Regression tests for issue #214: legacy single-language cookie rows.
 *
 * @package FazCookie\Tests\Unit
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $value ) { return abs( (int) $value ); }
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ) { return trim( strip_tags( is_scalar( $value ) ? (string) $value : '' ) ); }
}
if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $value ) { return is_scalar( $value ) ? trim( strip_tags( (string) $value ) ) : ''; }
}
if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $value ) {
		$value = strtolower( trim( (string) $value ) );
		$value = preg_replace( '/[^a-z0-9_\-]+/', '-', $value );
		return trim( $value, '-' );
	}
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $value ) { return strip_tags( (string) $value ); }
}
if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( $value ) { return (string) $value; }
}
if ( ! function_exists( 'wp_filter_post_kses' ) ) {
	function wp_filter_post_kses( $value ) { return (string) $value; }
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value ) { return json_encode( $value ); }
}
if ( ! function_exists( 'faz_default_language' ) ) {
	function faz_default_language() { return 'en'; }
}
if ( ! function_exists( 'faz_selected_languages' ) ) {
	function faz_selected_languages( $language = '' ) {
		if ( '' !== $language ) {
			return array( $language );
		}
		return isset( $GLOBALS['cookie_i18n_selected_languages'] )
			? $GLOBALS['cookie_i18n_selected_languages']
			: array( 'en', 'it' );
	}
}
if ( ! function_exists( 'wp_cache_delete' ) ) {
	function wp_cache_delete( $key, $group = '' ) {
		$GLOBALS['cookie_i18n_cache_deletes'][] = $group . ':' . $key;
		return true;
	}
}
if ( ! function_exists( 'faz_clear_banner_template_cache' ) ) {
	function faz_clear_banner_template_cache() {
		$GLOBALS['cookie_i18n_template_cache_clears'] = 1 + ( $GLOBALS['cookie_i18n_template_cache_clears'] ?? 0 );
	}
}
// Minimal hook dispatcher: enough to prove the resolver's two escape hatches
// actually reach a subscriber, in the order and with the arguments documented.
$GLOBALS['cookie_i18n_filters'] = array();
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $callback ) {
		$GLOBALS['cookie_i18n_filters'][ $hook ][] = $callback;
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value, ...$args ) {
		foreach ( ( $GLOBALS['cookie_i18n_filters'][ $hook ] ?? array() ) as $callback ) {
			$value = $callback( $value, ...$args );
		}
		return $value;
	}
}
function cookie_i18n_reset_filters() {
	$GLOBALS['cookie_i18n_filters'] = array();
}

// Lightweight controller doubles let the upgrade migration be exercised
// without bootstrapping WordPress or touching a database.
eval( 'namespace FazCookie\Admin\Modules\Cookies\Includes; class Cookie_Controller { public static function get_instance(){ return new self(); } public function delete_cache(){ $GLOBALS["cookie_i18n_controller_clears"][] = "cookies"; } }' );
eval( 'namespace FazCookie\Admin\Modules\Cookies\Includes; class Category_Controller { public static function get_instance(){ return new self(); } public function delete_cache(){ $GLOBALS["cookie_i18n_controller_clears"][] = "categories"; } }' );

require_once dirname( __DIR__, 2 ) . '/includes/class-store.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-cookie-content-i18n.php';
require_once dirname( __DIR__, 2 ) . '/admin/modules/cookies/includes/class-cookie.php';
require_once dirname( __DIR__, 2 ) . '/admin/modules/cookie-policy-generator/includes/class-renderer.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-cookie-table-shortcode.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-activator.php';

use FazCookie\Admin\Modules\Cookies\Includes\Cookie;
use FazCookie\Admin\Modules\Cookie_Policy_Generator\Includes\Generator;
use FazCookie\Admin\Modules\Cookie_Policy_Generator\Includes\Renderer;
use FazCookie\Includes\Activator;
use FazCookie\Includes\Cookie_Content_I18n;
use FazCookie\Includes\Cookie_Table_Shortcode;

$tests_run = 0;
$failed    = 0;

function cookie_i18n_eq( $actual, $expected, $label ) {
	global $tests_run, $failed;
	$tests_run++;
	if ( $actual === $expected ) {
		echo "  PASS  {$label}\n";
		return;
	}
	$failed++;
	echo "  FAIL  {$label}\n        expected: " . var_export( $expected, true ) . "\n        actual:   " . var_export( $actual, true ) . "\n";
}

function cookie_i18n_row( $description = array( 'en' => 'Google Analytics cookie used to distinguish users.' ), $duration = array( 'en' => '2 years' ) ) {
	$row                = new stdClass();
	$row->cookie_id     = 214;
	$row->name          = '_ga';
	$row->slug          = '_ga';
	$row->description   = wp_json_encode( $description );
	$row->duration      = wp_json_encode( $duration );
	$row->domain        = '.example.test';
	$row->category      = 3;
	$row->type          = 0;
	$row->discovered    = 1;
	$row->url_pattern   = '';
	$row->meta          = '{}';
	$row->date_created  = '2026-08-06 00:00:00';
	$row->date_modified = '2026-08-06 00:00:00';
	return $row;
}

echo "\n== Cookie content i18n (#214) ==\n\n";

cookie_i18n_eq( Cookie_Content_I18n::duration( '2 years', 'it' ), '2 anni', 'duration plural is translated to Italian' );
cookie_i18n_eq( Cookie_Content_I18n::duration( '1 hour', 'it_IT' ), '1 ora', 'WordPress locale variant is normalized' );
cookie_i18n_eq( Cookie_Content_I18n::duration( 'session', 'it' ), 'sessione', 'session duration is translated' );
cookie_i18n_eq( Cookie_Content_I18n::duration( '2 years', 'cs' ), '2 roky', 'Czech few plural form is selected' );
cookie_i18n_eq( Cookie_Content_I18n::duration( '30 minutes', 'cs' ), '30 minut', 'Czech many plural form is selected' );
cookie_i18n_eq( Cookie_Content_I18n::duration( '22 minutes', 'pl' ), '22 minuty', 'Polish few plural form is selected' );
cookie_i18n_eq( Cookie_Content_I18n::duration( '12 minutes', 'pl' ), '12 minut', 'Polish teen plural form is selected' );
cookie_i18n_eq( Cookie_Content_I18n::duration( '1 year or longer', 'it' ), '', 'ambiguous free-form duration is left to stored fallback' );
cookie_i18n_eq( Cookie_Content_I18n::description( '_ga', 'it' ), 'Cookie di Google Analytics utilizzato per distinguere gli utenti.', 'curated _ga description is translated' );
cookie_i18n_eq( Cookie_Content_I18n::description( '_ga_G-ABC123', 'it' ), 'Cookie di Google Analytics 4 utilizzato per mantenere lo stato della sessione.', 'prefix cookie definition covers concrete names' );

$legacy      = new Cookie( cookie_i18n_row() );
$durations   = $legacy->get_duration();
$descriptions = $legacy->get_description();
cookie_i18n_eq( $durations['en'], '2 years', 'legacy English duration remains unchanged' );
cookie_i18n_eq( $durations['it'], '2 anni', 'legacy row receives Italian duration without DB migration' );
cookie_i18n_eq( $descriptions['en'], 'Google Analytics cookie used to distinguish users.', 'legacy English description remains unchanged' );
cookie_i18n_eq( $descriptions['it'], 'Cookie di Google Analytics utilizzato per distinguere gli utenti.', 'legacy row receives Italian description without DB migration' );

$custom = new Cookie( cookie_i18n_row(
	array( 'en' => 'English custom text', 'it' => 'Testo italiano personalizzato' ),
	array( 'en' => '2 years', 'it' => 'Durata personalizzata' )
) );
cookie_i18n_eq( $custom->get_description( 'it' ), 'Testo italiano personalizzato', 'manual Italian description wins over bundled fallback' );
cookie_i18n_eq( $custom->get_duration()['it'], 'Durata personalizzata', 'manual Italian duration wins over bundled fallback' );

$method = new ReflectionMethod( Renderer::class, 'decode_cookie_i18n_text' );
$method->setAccessible( true );
cookie_i18n_eq(
	$method->invoke( null, wp_json_encode( array( 'en' => '2 years' ) ), 'it', '_ga', 'duration' ),
	'2 anni',
	'cookie-policy renderer uses shared duration fallback'
);
// A value that is non-empty but NOT localised. Scanners and imports write the
// same English string into every language slot, so the Italian column can hold
// "2 years" — and the old "only translate when empty" rule skipped it for being
// non-empty, leaving the mixed-language declaration issue #214 is about intact
// inside the fix for it.
$stock_dur = new Cookie( cookie_i18n_row( array( 'en' => 'x' ), array( 'en' => '2 years', 'it' => '2 years' ) ) );
cookie_i18n_eq( $stock_dur->get_duration()['it'], '2 anni', 'an English duration sitting in the Italian slot is translated' );

// And the resolver's strictness is what protects everything else: it parses a
// strict "<number> <english unit>" or a named special and returns '' otherwise.
$free_dur = new Cookie( cookie_i18n_row( array( 'en' => 'x' ), array( 'en' => '1 year or longer', 'it' => '1 year or longer' ) ) );
cookie_i18n_eq( $free_dur->get_duration()['it'], '1 year or longer', 'a free-form duration is left exactly as entered' );

$already_it = new Cookie( cookie_i18n_row( array( 'en' => 'x' ), array( 'en' => '2 years', 'it' => '2 anni' ) ) );
cookie_i18n_eq( $already_it->get_duration()['it'], '2 anni', 'an already-Italian duration is not re-translated into nonsense' );

// STOCK text still translates: this is the feature working, and asserting it
// first keeps the guard below from being satisfied by simply never translating.
cookie_i18n_eq(
	$method->invoke( null, wp_json_encode( array( 'en' => 'Google Analytics cookie used to distinguish users.' ) ), 'it', '_ga', 'description' ),
	'Cookie di Google Analytics utilizzato per distinguere gli utenti.',
	'cookie-policy renderer translates the plugin\'s own English description'
);
// Administrator-authored text does NOT. This assertion previously demanded the
// opposite -- that "English source" be replaced by the generic Italian sentence
// -- which pinned the defect rather than the behaviour: the description column
// carries reviewed legal copy naming a site's own processors and retention, and
// the next save persisted the generic replacement over it. Showing the author's
// English to an Italian visitor is the honest fallback; putting words in their
// mouth in a language they never reviewed is not.
cookie_i18n_eq(
	$method->invoke( null, wp_json_encode( array( 'en' => 'English source' ) ), 'it', '_ga', 'description' ),
	'English source',
	'cookie-policy renderer keeps administrator wording in every language'
);

// Legacy/plain strings belong to the default language. They are often custom
// administrator wording, not stock catalogue text, so both parallel renderers
// must preserve them in that language and only consult the catalogue when a
// different requested language is missing.
$plain_custom = 'Custom analytics wording written by the administrator.';
cookie_i18n_eq(
	$method->invoke( null, $plain_custom, 'en', '_ga', 'description' ),
	$plain_custom,
	'cookie-policy renderer preserves a plain custom description in the default language'
);

$shortcode        = ( new ReflectionClass( Cookie_Table_Shortcode::class ) )->newInstanceWithoutConstructor();
$shortcode_method = new ReflectionMethod( Cookie_Table_Shortcode::class, 'localize_cookie_field' );
$shortcode_method->setAccessible( true );
cookie_i18n_eq(
	$shortcode_method->invoke( $shortcode, $legacy, $plain_custom, 'description', 'en', 'en', 'en' ),
	$plain_custom,
	'cookie-table shortcode preserves a plain custom description in the default language'
);
// This call site hands the value in separately from the Cookie object, so the
// resolver never saw the string it was about to replace and substituted it
// anyway. Both halves are asserted: stock text translates, custom text survives.
cookie_i18n_eq(
	$shortcode_method->invoke( $shortcode, $legacy, 'Google Analytics cookie used to distinguish users.', 'description', 'it', 'en', 'it' ),
	'Cookie di Google Analytics utilizzato per distinguere gli utenti.',
	'cookie-table shortcode translates a stock default-language description for another language'
);
cookie_i18n_eq(
	$shortcode_method->invoke( $shortcode, $legacy, $plain_custom, 'description', 'it', 'en', 'it' ),
	$plain_custom,
	'cookie-table shortcode keeps administrator wording for another language'
);

$GLOBALS['cookie_i18n_controller_clears']      = array();
$GLOBALS['cookie_i18n_cache_deletes']          = array();
$GLOBALS['cookie_i18n_template_cache_clears']  = 0;
Activator::refresh_cookie_translation_caches();
cookie_i18n_eq( $GLOBALS['cookie_i18n_controller_clears'], array( 'cookies', 'categories' ), 'upgrade migration rotates prepared cookie/category caches' );
$cookie_i18n_missing_frags = array_values( array_diff(
	array( 'faz_cookie_policy:faz_cookie_policy_list_en', 'faz_cookie_policy:faz_cookie_policy_list_it' ),
	$GLOBALS['cookie_i18n_cache_deletes']
) );
cookie_i18n_eq( $cookie_i18n_missing_frags, array(), 'upgrade migration clears language-specific policy fragments' );

cookie_i18n_eq( $GLOBALS['cookie_i18n_template_cache_clears'], 1, 'upgrade migration clears banner templates' );

// The policy is not rendered only in the SELECTED languages: resolve_lang()
// takes the shortcode `lang` attribute, the page default or the WordPress
// locale, none of which is bound to faz_selected_languages(). Clearing only the
// selected set left an English fragment serving pre-translation rows on a site
// whose selected language is Italian alone.
$GLOBALS['cookie_i18n_selected_languages'] = array( 'it' );
$GLOBALS['cookie_i18n_cache_deletes']      = array();
Activator::refresh_cookie_translation_caches();
cookie_i18n_eq(
	in_array( 'faz_cookie_policy:faz_cookie_policy_list_en', $GLOBALS['cookie_i18n_cache_deletes'], true ),
	true,
	'the English policy fragment is cleared even when English is not selected'
);
unset( $GLOBALS['cookie_i18n_selected_languages'] );

// --- Croatian, and the guard that would have caught it being absent ---
//
// hr_HR ships as a plugin locale but had no entry in duration-units.json, so a
// Croatian banner silently kept its English durations. Nothing failed: the
// fallback is deliberately non-destructive, which is exactly what makes a
// missing language invisible. The coverage assertion below is the real fix —
// the four cases after it only pin the grammar.
// The .po glob alone was the wrong yardstick, and measuring it is what let a
// second language slip through unnoticed: Bulgarian has no .po but IS a
// cookie-policy language (Generator::LANGUAGES), and it reached this branch with
// no duration entry at all. The authoritative set is the union — a language the
// policy renders in, or one whose interface is translated, must not fall back to
// English retention periods in either case.
$faz_i18n_shipped = array();
foreach ( Generator::LANGUAGES as $faz_i18n_lang ) {
	// pt-BR is the one catalogue key that keeps its region.
	$faz_i18n_shipped[ strtolower( $faz_i18n_lang ) ] = true;
}
foreach ( glob( dirname( __DIR__, 2 ) . '/languages/faz-cookie-manager-*.po' ) as $faz_i18n_po ) {
	if ( preg_match( '/faz-cookie-manager-([a-z]{2})_/', basename( $faz_i18n_po ), $faz_i18n_m ) ) {
		$faz_i18n_shipped[ $faz_i18n_m[1] ] = true;
	}
}
$faz_i18n_units   = json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/admin/modules/cookies/includes/contents/duration-units.json' ), true );
$faz_i18n_missing = array_values( array_diff( array_keys( $faz_i18n_shipped ), array_keys( (array) $faz_i18n_units ) ) );
cookie_i18n_eq( $faz_i18n_missing, array(), 'every language the plugin renders in has a duration catalogue' );

// Bulgarian has one/other only (CLDR: `one` is exactly 1), so these two cases
// are the whole grammar — but they are also the proof that the entry added for
// the guard above is wired to the resolver and not merely present in the file.
cookie_i18n_eq( Cookie_Content_I18n::duration( '1 year', 'bg' ), '1 година', 'Bulgarian singular is selected' );
cookie_i18n_eq( Cookie_Content_I18n::duration( '2 years', 'bg' ), '2 години', 'Bulgarian plural is selected' );
cookie_i18n_eq( Cookie_Content_I18n::duration( 'session', 'bg' ), 'сесия', 'Bulgarian session duration is translated' );

// Capitalisation follows the source: inventories store "Session" more often
// than "session", and a lowercase translation next to a capitalised English
// value is the mixed look issue #214 reports.
cookie_i18n_eq( Cookie_Content_I18n::duration( 'Session', 'it' ), 'Sessione', 'capitalised source yields a capitalised translation' );
cookie_i18n_eq( Cookie_Content_I18n::duration( 'session', 'it' ), 'sessione', 'lowercase source stays lowercase' );
cookie_i18n_eq( Cookie_Content_I18n::duration( 'Persistent', 'it' ), 'Persistente', 'the same holds for persistent' );
cookie_i18n_eq( Cookie_Content_I18n::duration( '2 years', 'it' ), '2 anni', 'numeric durations are unaffected by the case rule' );

cookie_i18n_eq( Cookie_Content_I18n::duration( '1 year', 'hr' ), '1 godina', 'Croatian singular form is selected' );
cookie_i18n_eq( Cookie_Content_I18n::duration( '2 years', 'hr' ), '2 godine', 'Croatian few plural form is selected' );
cookie_i18n_eq( Cookie_Content_I18n::duration( '5 years', 'hr' ), '5 godina', 'Croatian many plural form is selected' );
cookie_i18n_eq( Cookie_Content_I18n::duration( '12 minutes', 'hr' ), '12 minuta', 'Croatian teens take the many form, not few' );
cookie_i18n_eq( Cookie_Content_I18n::duration( '21 hours', 'hr' ), '21 sat', 'Croatian numbers ending in 1 take the ONE form (21 sat, not 21 sati)' );
cookie_i18n_eq( Cookie_Content_I18n::duration( '11 hours', 'hr' ), '11 sati', 'except the 11 teen, which stays many' );
// Polish does NOT share that rule: CLDR gives it `one` for exactly 1, so 21 is
// "21 lat" and never "21 rok". Pinned because the obvious generalisation from
// Croatian to "all Slavic locales" produces exactly that error.
cookie_i18n_eq( Cookie_Content_I18n::duration( '21 years', 'pl' ), '21 lat', 'Polish 21 takes the many form, NOT one' );
cookie_i18n_eq( Cookie_Content_I18n::duration( '1 year', 'pl' ), '1 rok', 'Polish one applies to exactly 1' );
cookie_i18n_eq( Cookie_Content_I18n::duration( 'session', 'hr' ), 'sesija', 'Croatian session duration is translated' );

// --- The two CLDR classes the selector used to collapse into `other` ---
//
// A fractional retention period is not exotic here: "1.5 years" is what a
// scanner writes for an 18-month cookie, and it is the exact case Czech and
// Polish give their own word for. Both were rendering the big-integer plural.
cookie_i18n_eq( Cookie_Content_I18n::duration( '1.5 years', 'cs' ), '1.5 roku', 'Czech decimals take the many form (roku), not the plural let' );
cookie_i18n_eq( Cookie_Content_I18n::duration( '1,5 days', 'cs' ), '1,5 dne', 'a comma decimal reaches the same Czech class' );
cookie_i18n_eq( Cookie_Content_I18n::duration( '1.5 years', 'pl' ), '1.5 roku', 'Polish decimals take other (roku), which is NOT the integer plural' );
cookie_i18n_eq( Cookie_Content_I18n::duration( '5 years', 'pl' ), '5 lat', 'Polish 5 stays in the many class' );
// …and the neighbours the two rules must not disturb.
cookie_i18n_eq( Cookie_Content_I18n::duration( '2 years', 'cs' ), '2 roky', 'Czech few is untouched by the decimal rule' );
cookie_i18n_eq( Cookie_Content_I18n::duration( '1.5 years', 'it' ), '1.5 anni', 'Italian decimals stay plural' );
cookie_i18n_eq( Cookie_Content_I18n::duration( '1.5 years', 'hr' ), '1.5 godina', 'Croatian decimals are left in other' );

// French and Portuguese count 0 and every 1.x as singular (CLDR: i = 0,1). The
// Romance locales do NOT agree on this — Italian and Spanish pluralise both —
// so the rule is per-language, not "Romance".
cookie_i18n_eq( Cookie_Content_I18n::duration( '0 days', 'fr' ), '0 jour', 'French 0 is singular' );
cookie_i18n_eq( Cookie_Content_I18n::duration( '1.5 years', 'fr' ), '1.5 an', 'French 1.5 is singular' );
cookie_i18n_eq( Cookie_Content_I18n::duration( '0 days', 'pt' ), '0 dia', 'Portuguese 0 is singular' );
cookie_i18n_eq( Cookie_Content_I18n::duration( '1.5 years', 'pt-BR' ), '1.5 ano', 'Brazilian Portuguese follows the same rule' );
cookie_i18n_eq( Cookie_Content_I18n::duration( '2 years', 'fr' ), '2 ans', 'French keeps the plural from 2 up' );
cookie_i18n_eq( Cookie_Content_I18n::duration( '0 days', 'it' ), '0 giorni', 'Italian 0 stays plural — the rule is not applied across Romance' );
cookie_i18n_eq( Cookie_Content_I18n::duration( '0 days', 'es' ), '0 días', 'Spanish 0 stays plural too' );

// --- A short generic name must not collect a third party's description ---
//
// Matching on the cookie NAME alone made `fr` "Facebook advertising cookie"
// whatever set it — and `fr` is an ordinary name for a site's own language
// preference, so the declaration asserted a transfer to Meta that never
// happened. The cookie declaration is the document a visitor consents to and a
// regulator reads; a wrong attribution there is not a cosmetic defect.
cookie_i18n_eq(
	Cookie_Content_I18n::description( 'fr', 'en', '.facebook.com' ),
	'Facebook advertising cookie.',
	'fr on facebook.com is still described'
);
cookie_i18n_eq(
	Cookie_Content_I18n::description( 'fr', 'en', 'connect.facebook.com' ),
	'Facebook advertising cookie.',
	'and on a subdomain of it'
);
cookie_i18n_eq(
	Cookie_Content_I18n::description( 'fr', 'en', 'example.test' ),
	'',
	'a first-party fr — a language preference, say — gets NO third-party description'
);
cookie_i18n_eq(
	Cookie_Content_I18n::description( 'fr', 'en', 'notfacebook.com' ),
	'',
	'and a look-alike domain cannot borrow the entry'
);
// An unattributable cookie must not be assigned to somebody. For a legal
// statement "we could not tell" has to stay "we could not tell".
cookie_i18n_eq(
	Cookie_Content_I18n::description( 'fr', 'en', '' ),
	'',
	'an unknown domain is refused, not assumed'
);
// The same rule for the other genuinely third-party-set names.
cookie_i18n_eq( Cookie_Content_I18n::description( 'ide', 'en', '.doubleclick.net' ), 'DoubleClick/Google cookie used for targeted advertising.', 'ide on doubleclick.net resolves' );
cookie_i18n_eq( Cookie_Content_I18n::description( 'ide', 'en', 'shop.example.test' ), '', 'ide set first-party does not' );
cookie_i18n_eq( Cookie_Content_I18n::description( 'ct0', 'en', '.x.com' ), 'Twitter cookie used for security and spam prevention on embedded content.', 'ct0 resolves on x.com as well as twitter.com' );
// And names written FIRST-PARTY by a third party's script keep working without
// a domain, because constraining them would reject every real one.
cookie_i18n_eq( Cookie_Content_I18n::description( '_ga', 'en', 'example.test' ), 'Google Analytics cookie used to distinguish users.', '_ga is first-party and stays unconstrained' );
cookie_i18n_eq( Cookie_Content_I18n::description( '_fbp', 'en', 'example.test' ), 'Facebook Pixel cookie used for advertising and analytics.', 'and so is _fbp' );

// --- Prefix collisions resolve to the LONGEST match, not the first one ---
//
// `wordpress_` and `wordpress_logged_in_` are both prefix keys, and one is a
// prefix of the other. Returning the first hit made the answer depend on the
// order of keys in a JSON file: right today only because the specific keys sit
// above the general ones. Reorder the file and a session cookie acquires the
// wrong description in a document a visitor consents to — silently.
cookie_i18n_eq(
	Cookie_Content_I18n::description( 'wordpress_logged_in_a1b2c3', 'en' ),
	'Indicates logged-in status and user identity.',
	'wordpress_logged_in_* takes the specific entry, not the generic wordpress_ one'
);
cookie_i18n_eq(
	Cookie_Content_I18n::description( 'wordpress_ab12cd34', 'en' ),
	'WordPress authentication cookie for the admin area.',
	'and a plain wordpress_* cookie still gets the generic one'
);
cookie_i18n_eq(
	Cookie_Content_I18n::description( 'comment_author_email_9f', 'en' ),
	'Stores the commenter email for convenience.',
	'comment_author_email_ beats the shorter comment_author_'
);
cookie_i18n_eq(
	Cookie_Content_I18n::description( 'comment_author_url_9f', 'en' ),
	'Stores the commenter website URL for convenience.',
	'and so does comment_author_url_'
);

// Reversing the catalogue order must change nothing. This is the assertion that
// makes the rule an invariant rather than a property of the current file.
$faz_i18n_rev = new ReflectionMethod( Cookie_Content_I18n::class, 'catalogue_prefix' );
$faz_i18n_rev->setAccessible( true );
cookie_i18n_eq(
	$faz_i18n_rev->invoke( null, 'wordpress_logged_in_' ),
	'wordpress_logged_in_',
	'a trailing-underscore key is its own prefix'
);

// Lookup is case-insensitive: catalogue keys are lowercase by convention and a
// cookie called IDE must still resolve.
cookie_i18n_eq(
	Cookie_Content_I18n::description( 'IDE', 'en', '.doubleclick.net' ),
	'DoubleClick/Google cookie used for targeted advertising.',
	'an uppercase cookie name resolves against the lowercase key'
);

// Every locale must carry the SAME key set. Twelve catalogues were added at
// once; without this, one of them silently falls behind as entries are added
// and that language quietly loses descriptions.
$faz_i18n_en_keys = array_keys( (array) json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/admin/modules/cookies/includes/contents/cookies/en.json' ), true ) );
sort( $faz_i18n_en_keys );
foreach ( (array) glob( dirname( __DIR__, 2 ) . '/admin/modules/cookies/includes/contents/cookies/*.json' ) as $faz_i18n_f ) {
	$faz_i18n_lang = basename( $faz_i18n_f, '.json' );
	$faz_i18n_k    = array_keys( (array) json_decode( (string) file_get_contents( $faz_i18n_f ), true ) );
	sort( $faz_i18n_k );
	cookie_i18n_eq( $faz_i18n_k, $faz_i18n_en_keys, "{$faz_i18n_lang}.json carries exactly the English key set" );
}

// --- Both content surfaces must cover the same languages ---
//
// The descriptions shipped in en + it while durations shipped in fourteen, so a
// German site got "2 Jahre" next to an English sentence — the mixed-language
// declaration this feature exists to remove, produced by the feature itself.
// The two are asserted against each other rather than against a hardcoded list,
// because a list is a third thing to keep in step.
$faz_i18n_dir      = dirname( __DIR__, 2 ) . '/admin/modules/cookies/includes/contents';
$faz_i18n_dur      = array_keys( (array) json_decode( (string) file_get_contents( $faz_i18n_dir . '/duration-units.json' ), true ) );
$faz_i18n_desc     = array();
foreach ( (array) glob( $faz_i18n_dir . '/cookies/*.json' ) as $faz_i18n_file ) {
	$faz_i18n_desc[] = basename( $faz_i18n_file, '.json' );
}
sort( $faz_i18n_dur );
sort( $faz_i18n_desc );
cookie_i18n_eq( array_values( array_diff( $faz_i18n_dur, $faz_i18n_desc ) ), array(), 'every language with durations also has descriptions' );
cookie_i18n_eq( array_values( array_diff( $faz_i18n_desc, $faz_i18n_dur ) ), array(), 'and the reverse, so neither surface can drift ahead' );

// Every jurisdiction the policy generator can render must be covered by both,
// since that is the set a site can actually select.
$faz_i18n_gen = array();
if ( preg_match( "#const LANGUAGES\s*=\s*array\(([^)]*)\)#", (string) file_get_contents( dirname( __DIR__, 2 ) . '/admin/modules/cookie-policy-generator/includes/class-generator.php' ), $faz_i18n_m ) ) {
	preg_match_all( "#'([^']+)'#", $faz_i18n_m[1], $faz_i18n_g );
	$faz_i18n_gen = array_map( 'strtolower', $faz_i18n_g[1] );
}
cookie_i18n_eq( count( $faz_i18n_gen ) > 0, true, 'the policy generator language list could be read' );
cookie_i18n_eq( array_values( array_diff( $faz_i18n_gen, $faz_i18n_desc ) ), array(), 'every policy-generator language has a description catalogue' );

// And each one actually resolves, rather than merely having a file.
foreach ( $faz_i18n_desc as $faz_i18n_lang ) {
	cookie_i18n_eq(
		'' !== Cookie_Content_I18n::description( '_ga', $faz_i18n_lang ) && '' !== Cookie_Content_I18n::duration( '2 years', $faz_i18n_lang ),
		true,
		"{$faz_i18n_lang}: both a description and a duration resolve"
	);
}

// --- Every catalogue key must be able to match something ---
//
// `wp-settings` and `_hj` sat in both catalogues unreachable: the prefix branch
// only fires for a key that says it is a prefix, and neither did, so the entries
// could only have matched cookies literally named "wp-settings" or "_hj" — which
// do not exist. The keys now carry a marker (`wp-settings-`, `_hj*`).
$faz_i18n_meta = json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/admin/modules/cookies/includes/contents/cookies/en.json' ), true );
foreach ( array( 'en', 'it' ) as $faz_i18n_catalogue ) {
	$faz_i18n_entries     = json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/admin/modules/cookies/includes/contents/cookies/' . $faz_i18n_catalogue . '.json' ), true );
	$faz_i18n_unreachable = array();
	foreach ( (array) $faz_i18n_entries as $faz_i18n_key => $faz_i18n_entry ) {
		$faz_i18n_last  = substr( $faz_i18n_key, -1 );
		$faz_i18n_probe = $faz_i18n_key;
		if ( '*' === $faz_i18n_last ) {
			$faz_i18n_probe = substr( $faz_i18n_key, 0, -1 ) . 'x';
		} elseif ( in_array( $faz_i18n_last, array( '_', '-' ), true ) ) {
			$faz_i18n_probe = $faz_i18n_key . 'x';
		}
		// An entry that declares the third party which SETS the cookie is only
		// reachable from that domain — that is the point of the constraint — so
		// the probe supplies it. Entries without one are unconstrained.
		// Read from the ENGLISH catalogue whichever language is being probed: the
		// constraint is metadata about who sets the cookie, not translated prose,
		// so it is declared once and would only have somewhere to drift if it
		// were repeated per locale.
		$faz_i18n_domain = isset( $faz_i18n_meta[ $faz_i18n_key ]['domains'][0] ) ? $faz_i18n_meta[ $faz_i18n_key ]['domains'][0] : '';
		if ( Cookie_Content_I18n::description( $faz_i18n_probe, $faz_i18n_catalogue, $faz_i18n_domain ) !== $faz_i18n_entry['description'] ) {
			$faz_i18n_unreachable[] = $faz_i18n_key;
		}
	}
	cookie_i18n_eq( $faz_i18n_unreachable, array(), "every {$faz_i18n_catalogue} catalogue key resolves for a cookie name shaped like it" );
}

// The loop above can only see whether a key is reachable in principle; it cannot
// know that no browser has ever set a cookie called "wp-settings". These are the
// names the two entries exist to serve, taken from the WordPress source and from
// includes/data/known-providers.json.
$faz_i18n_real_names = array(
	'wp-settings-1'         => 'Customizes the admin interface for each user.',
	'wp-settings-time-1'    => 'Customizes the admin interface for each user.',
	'_hjSessionUser_123456' => 'Hotjar analytics cookie.',
	'_hjFirstSeen'          => 'Hotjar analytics cookie.',
);
foreach ( $faz_i18n_real_names as $faz_i18n_name => $faz_i18n_text ) {
	cookie_i18n_eq( Cookie_Content_I18n::description( $faz_i18n_name, 'en' ), $faz_i18n_text, "the real cookie {$faz_i18n_name} finds its catalogue entry" );
}
// The wildcard is additive: it must not have turned exact keys into prefixes.
cookie_i18n_eq( Cookie_Content_I18n::description( '_gac_UA-1234', 'en' ), 'Google Analytics cookie containing campaign information.', '_gac_ still wins over the shorter _ga key' );
cookie_i18n_eq( Cookie_Content_I18n::description( '_gargantuan', 'en' ), '', 'an exact key like _ga does not swallow unrelated names' );
cookie_i18n_eq( Cookie_Content_I18n::description( 'frobnicator', 'en' ), '', 'nor does the two-letter fr key' );

// --- The escape hatch ---
//
// Bundled text cannot be right everywhere: a site may run its own translation
// workflow, or need a processor named. Both resolvers are filterable, and ''
// means "stand down", which is the same signal the stock-text guard uses.
cookie_i18n_reset_filters();
add_filter(
	'faz_cookie_content_i18n_description',
	function ( $description, $slug, $lang ) {
		return '_ga' === $slug && 'it' === $lang ? 'Testo imposto dal filtro.' : $description;
	}
);
cookie_i18n_eq( Cookie_Content_I18n::description( '_ga', 'it' ), 'Testo imposto dal filtro.', 'a filter can substitute a resolved description' );
cookie_i18n_eq( Cookie_Content_I18n::description( '_ga', 'en' ), 'Google Analytics cookie used to distinguish users.', 'the filter receives the slug and language, so it can scope itself' );
cookie_i18n_reset_filters();

add_filter( 'faz_cookie_content_i18n_description', '__return_empty_catalogue_entry' );
function __return_empty_catalogue_entry( $description ) {
	return '';
}
cookie_i18n_eq( Cookie_Content_I18n::description( '_ga', 'it' ), '', 'returning an empty string switches the description fallback off' );
// …and switching it off must leave the stored row visible rather than blank it:
// this is the whole point of the empty-string contract.
cookie_i18n_eq(
	$method->invoke( null, wp_json_encode( array( 'en' => 'Google Analytics cookie used to distinguish users.' ) ), 'it', '_ga', 'description' ),
	'Google Analytics cookie used to distinguish users.',
	'with the fallback disabled the renderer shows the stored value untouched'
);
cookie_i18n_reset_filters();

add_filter(
	'faz_cookie_content_i18n_duration',
	function ( $duration, $source, $lang ) {
		// The parser declines free-form periods on purpose; a site that wants
		// them rendered can say so here.
		return '' === $duration && 'up to 13 months' === $source && 'it' === $lang ? 'fino a 13 mesi' : $duration;
	}
);
cookie_i18n_eq( Cookie_Content_I18n::duration( 'up to 13 months', 'it' ), 'fino a 13 mesi', 'a filter can render a period the parser declines' );
cookie_i18n_eq( Cookie_Content_I18n::duration( '2 years', 'it' ), '2 anni', 'a scoped duration filter leaves everything else alone' );
cookie_i18n_reset_filters();

add_filter(
	'faz_cookie_content_i18n_duration',
	function () {
		return null; // A filter that forgets to return a string.
	}
);
cookie_i18n_eq( Cookie_Content_I18n::duration( '2 years', 'it' ), '', 'a non-string filter return is read as "stand down", not propagated' );
cookie_i18n_reset_filters();
cookie_i18n_eq( Cookie_Content_I18n::duration( '2 years', 'it' ), '2 anni', 'and removing the filter restores the bundled answer' );

echo "\n" . ( $tests_run - $failed ) . "/{$tests_run} passed\n";
exit( 0 === $failed ? 0 : 1 );
