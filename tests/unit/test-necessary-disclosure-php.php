<?php
/**
 * Unit test — the shipped "Necessary" disclosure must not deny the consent
 * identifier the plugin itself stores.
 *
 * The bundled Necessary category description ended with "These cookies do not
 * store any personally identifiable data" (de: "speichern keine
 * personenbezogenen Daten"), in every language. The plugin's own
 * fazcookie-consent cookie, which sits in that category, carries a random
 * 32-character consentid that script.js keeps across sessions and that the
 * consent log stores next to a salted IP hash and a hashed user agent. A
 * stable per-browser identifier joined to a server-side record is an online
 * identifier (GDPR Recital 30, Art. 4(1)), so the sentence a visitor reads in
 * the preference centre and in the generated policy was inaccurate. The
 * consent cookie's own description ("Cookie consent preferences set by the
 * visitor."), the built-in scanner record it is seeded from and the suggested
 * privacy-policy paragraph all omitted the identifier as well.
 *
 * Three things are checked:
 *  1. the bundled text, in every language, no longer makes the claim and the
 *     English text names the identifier;
 *  2. the scanner's built-in record and the description catalogue agree, so a
 *     freshly seeded row is still recognised as stock text and translated;
 *  3. the upgrade migration rewrites stored text that is still the old stock
 *     wording (in any language slot) and leaves an administrator's own words
 *     alone, once per site;
 *  4. every language's Necessary text carries both statements the English one
 *     makes (not used for advertising or tracking; the consent cookie stores
 *     a random identifier), and a site that ran the migration before a
 *     language was corrected still gets the correction, without the earlier
 *     list being applied to it a second time. Turkish shipped without either
 *     sentence.
 *
 * Run: php tests/unit/test-necessary-disclosure-php.php
 */

namespace FazCookie\Admin\Modules\Cookies\Includes {
	class Cookie_Controller {
		public static $busts = 0;
		public static function get_instance() {
			return new self();
		}
		public function delete_cache() {
			self::$busts++;
		}
	}
	class Category_Controller {
		public static $busts = 0;
		public static function get_instance() {
			return new self();
		}
		public function delete_cache() {
			self::$busts++;
		}
	}
}

namespace {

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

$GLOBALS['faz_test_options'] = array();
$GLOBALS['faz_test_cache_deletes'] = array();

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) {
		return array_key_exists( $name, $GLOBALS['faz_test_options'] ) ? $GLOBALS['faz_test_options'][ $name ] : $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $name, $value, $autoload = null ) {
		$GLOBALS['faz_test_options'][ $name ] = $value;
		return true;
	}
}
if ( ! function_exists( 'esc_sql' ) ) {
	function esc_sql( $value ) {
		return $value;
	}
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $text ) {
		return trim( strip_tags( (string) $text ) );
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}
if ( ! function_exists( 'wp_cache_delete' ) ) {
	function wp_cache_delete( $key, $group = '' ) {
		$GLOBALS['faz_test_cache_deletes'][] = $group . ':' . $key;
		return true;
	}
}
if ( ! function_exists( 'faz_default_language' ) ) {
	function faz_default_language() {
		return 'en';
	}
}
if ( ! function_exists( 'faz_read_json_file' ) ) {
	function faz_read_json_file( $path = '' ) {
		if ( ! is_readable( $path ) ) {
			return array();
		}
		$data = json_decode( (string) file_get_contents( $path ), true );
		return is_array( $data ) ? $data : array();
	}
}

/**
 * The slice of $wpdb the migration uses: two in-memory tables.
 */
class Faz_Disclosure_Wpdb {
	public $prefix  = 'wp_';
	public $tables  = array();
	public $fail    = false;
	public $updates = 0;

	public function prepare( $query, ...$args ) {
		$i = 0;
		return preg_replace_callback(
			'/%[ds]/',
			static function ( $m ) use ( &$i, $args ) {
				$v = $args[ $i++ ];
				return '%d' === $m[0] ? (string) (int) $v : "'" . addslashes( (string) $v ) . "'";
			},
			$query
		);
	}
	public function get_var( $query ) {
		if ( preg_match( "/^SHOW TABLES LIKE '(.+)'$/", $query, $m ) ) {
			return isset( $this->tables[ $m[1] ] ) ? $m[1] : null;
		}
		return null;
	}
	public function get_results( $query, $output = 'OBJECT' ) {
		if ( ! preg_match( '/FROM `([^`]+)` WHERE (slug|name) = \'([^\']*)\'/', $query, $m ) ) {
			return array();
		}
		$id_col = 'wp_faz_cookies' === $m[1] ? 'cookie_id' : 'category_id';
		$out    = array();
		foreach ( $this->tables[ $m[1] ] ?? array() as $id => $row ) {
			if ( $row[ $m[2] ] === $m[3] ) {
				$out[] = array( $id_col => $id, 'description' => $row['description'] );
			}
		}
		return $out;
	}
	public function update( $table, $data, $where, $format = null, $where_format = null ) {
		if ( $this->fail ) {
			return false;
		}
		$id = (int) reset( $where );
		$this->tables[ $table ][ $id ] = array_merge( $this->tables[ $table ][ $id ], $data );
		$this->updates++;
		return 1;
	}
}

$root     = dirname( __DIR__, 2 );
$contents = $root . '/admin/modules/cookies/includes/contents/';
require_once $root . '/includes/class-activator.php';
require_once $root . '/admin/modules/scanner/includes/class-cookie-database.php';

use FazCookie\Admin\Modules\Cookies\Includes\Category_Controller;
use FazCookie\Admin\Modules\Cookies\Includes\Cookie_Controller;
use FazCookie\Admin\Modules\Scanner\Includes\Cookie_Database;
use FazCookie\Includes\Activator;

$passed = 0;
$failed = 0;
/**
 * Whether $haystack contains $needle, ignoring case in any script.
 *
 * mbstring is not guaranteed where these standalone tests run (#342).
 * The /iu regex folds case on Unicode letters as mb_stripos() did.
 *
 * @param string $haystack Text to search.
 * @param string $needle   Literal text to find.
 * @return bool
 */
function nd_contains_ci( $haystack, $needle ) {
	return 1 === preg_match( '/' . preg_quote( (string) $needle, '/' ) . '/iu', (string) $haystack );
}

function nd_eq( $actual, $expected, $label ) {
	global $passed, $failed;
	if ( $actual === $expected ) {
		$passed++;
		echo "  \033[32mPASS\033[0m {$label}\n";
	} else {
		$failed++;
		echo "  \033[31mFAIL\033[0m {$label}\n";
		echo '        expected: ' . var_export( $expected, true ) . "\n";
		echo '        actual:   ' . var_export( $actual, true ) . "\n";
	}
}
function nd_fold( $text ) {
	return strtolower( trim( preg_replace( '/\s+/u', ' ', strip_tags( (string) $text ) ) ) );
}

// ---------------------------------------------------------------------------
// 1. Bundled text.
// ---------------------------------------------------------------------------
echo "\n== bundled Necessary and consent-cookie text ==\n";

$legacy = json_decode( (string) @file_get_contents( $contents . 'legacy-stock-text.json' ), true );
nd_eq( is_array( $legacy ), true, 'legacy-stock-text.json exists and decodes (the migration needs the old wording)' );
$legacy_cat = array_map( 'nd_fold', (array) ( $legacy['categories']['necessary']['description'] ?? array() ) );
$legacy_ck  = array_map( 'nd_fold', (array) ( $legacy['cookies']['fazcookie-consent']['description'] ?? array() ) );
nd_eq( in_array( nd_fold( '<p>Necessary cookies are required to enable the basic features of this site, such as providing secure log-in or adjusting your consent preferences. These cookies do not store any personally identifiable data.</p>' ), $legacy_cat, true ), true, 'the old English Necessary text is recorded as legacy stock wording' );
nd_eq( in_array( nd_fold( 'Cookie consent preferences set by the visitor.' ), $legacy_ck, true ), true, 'the old English consent-cookie text is recorded as legacy stock wording' );

// The denial, in each language the bundled files spoke it.
$denials = array(
	'personally identifiable', 'personenbezogenen Daten', 'dati identificativi personali',
	'donnée personnellement identifiable', 'dato de identificación personal', 'persoonlijk identificeerbare',
	'neukládají žádné osobní údaje', 'neukladajú žiadne osobné údaje', 'henkilökohtaisia tietoja', 'személyazonosításra alkalmas',
	'umożliwiających identyfikację', 'pessoalmente identificável', 'dado de identificação pessoal',
	'не хранят персональные данные', 'не зберігають персональних даних',
);
foreach ( glob( $contents . 'categories/*.json' ) as $file ) {
	$data = json_decode( (string) file_get_contents( $file ), true );
	$text = (string) ( $data['necessary']['description'] ?? '' );
	$hits = array();
	foreach ( $denials as $needle ) {
		if ( nd_contains_ci( $text, $needle ) ) {
			$hits[] = $needle;
		}
	}
	nd_eq( $hits, array(), 'categories/' . basename( $file ) . ': Necessary text no longer claims the cookies hold no personal data' );
	nd_eq( in_array( nd_fold( $text ), $legacy_cat, true ), false, 'categories/' . basename( $file ) . ': Necessary text is not a legacy wording' );
}
$en_cat = json_decode( (string) file_get_contents( $contents . 'categories/en.json' ), true );
$df_cat = json_decode( (string) file_get_contents( $contents . 'categories/default.json' ), true );
nd_eq( false !== stripos( (string) $en_cat['necessary']['description'], 'random identifier' ), true, 'categories/en.json: Necessary text names the random consent identifier' );
nd_eq( $df_cat['necessary']['description'], $en_cat['necessary']['description'], 'categories/default.json mirrors en.json for Necessary' );

foreach ( glob( $contents . 'cookies/*.json' ) as $file ) {
	$data = json_decode( (string) file_get_contents( $file ), true );
	$text = (string) ( $data['fazcookie-consent']['description'] ?? '' );
	nd_eq( '' !== $text && ! in_array( nd_fold( $text ), $legacy_ck, true ), true, 'cookies/' . basename( $file ) . ': consent-cookie text is the current wording' );
}
// A locale added after the fix can only carry the old wording translated
// afresh, which the legacy list cannot know about: Slovak arrived with "Tieto
// súbory cookie neukladajú žiadne osobné údaje" and a consent-cookie text that
// left the identifier out. Each locale that names the identifier must keep
// naming it; this pins the ones whose word for it is known.
$identifier_words = array( 'cs' => 'identifikátor', 'sk' => 'identifikátor', 'de' => 'Kennung', 'it' => 'identificativo', 'tr' => 'onay kimliği' );
foreach ( $identifier_words as $loc => $word ) {
	$cat = json_decode( (string) @file_get_contents( $contents . "categories/{$loc}.json" ), true );
	$ck  = json_decode( (string) @file_get_contents( $contents . "cookies/{$loc}.json" ), true );
	nd_eq( nd_contains_ci( (string) ( $cat['necessary']['description'] ?? '' ), $word ), true, "categories/{$loc}.json: Necessary text names the consent identifier" );
	nd_eq( nd_contains_ci( (string) ( $ck['fazcookie-consent']['description'] ?? '' ), $word ), true, "cookies/{$loc}.json: consent-cookie text names the identifier" );
}
$en_ck = json_decode( (string) file_get_contents( $contents . 'cookies/en.json' ), true );
nd_eq( false !== stripos( (string) $en_ck['fazcookie-consent']['description'], 'identifier' ), true, 'cookies/en.json: consent-cookie text names the identifier' );

// Both statements, in every language: "not used for advertising or to track
// you" and "the consent cookie stores a random identifier". Each is held here
// as the whole sentence the language uses, written out by hand and not read
// from the catalogue. A single word is not the statement: "used for
// advertising" contains the word, and an identifier that is no longer called
// random still contains "identifier". A sentence that is reworded has to be
// reworded here too, by someone who reads what it now says. A language file
// added later has to be listed here, which is the point: Turkish was merged
// with both sentences missing, and the checks above (written against the OLD
// claim) had nothing to say about it.
$statements = array(
	'cs'      => array( 'Nepoužívají se k reklamě ani ke sledování napříč jinými weby', 'Cookie souhlasu ukládá náhodný identifikátor' ),
	'de'      => array( 'Sie werden weder für Werbung noch zur websiteübergreifenden Nachverfolgung verwendet', 'Das Einwilligungs-Cookie speichert eine zufällige Kennung' ),
	'default' => array( 'They are not used for advertising or to track you across other sites', 'The consent cookie stores a random identifier' ),
	'en'      => array( 'They are not used for advertising or to track you across other sites', 'The consent cookie stores a random identifier' ),
	'es'      => array( 'No se utilizan con fines publicitarios ni para rastrearle en otros sitios', 'La cookie de consentimiento almacena un identificador aleatorio' ),
	'fi'      => array( 'Niitä ei käytetä mainontaan eikä sinun seuraamiseesi muilla sivustoilla', 'Suostumuseväste tallentaa satunnaisen tunnisteen' ),
	'fr'      => array( "Ils ne sont pas utilisés à des fins publicitaires ni pour vous suivre sur d'autres sites", 'Le cookie de consentement stocke un identifiant aléatoire' ),
	'hr'      => array( 'Ne koriste se za oglašavanje niti za praćenje na drugim stranicama', 'Kolačić privole pohranjuje nasumični identifikator' ),
	'hu'      => array( 'Nem használjuk őket hirdetésre, sem arra, hogy más webhelyeken nyomon kövessük Önt', 'A hozzájárulási süti egy véletlenszerű azonosítót tárol' ),
	'it'      => array( 'Non vengono usati per la pubblicità né per tracciarti su altri siti', 'Il cookie di consenso memorizza un identificativo casuale' ),
	'nl'      => array( 'Ze worden niet gebruikt voor advertenties of om je over andere sites te volgen', 'De toestemmingscookie slaat een willekeurige identificatiecode op' ),
	'pl'      => array( 'Nie są wykorzystywane do celów reklamowych ani do śledzenia Cię w innych witrynach', 'Plik cookie zgody przechowuje losowy identyfikator' ),
	'pt-br'   => array( 'Eles não são usados para publicidade nem para rastrear você em outros sites', 'O cookie de consentimento armazena um identificador aleatório' ),
	'pt'      => array( 'Não são utilizados para publicidade nem para o seguir noutros sites', 'O cookie de consentimento armazena um identificador aleatório' ),
	'ru'      => array( 'Они не используются для рекламы или для отслеживания вас на других сайтах', 'Файл cookie согласия хранит случайный идентификатор' ),
	'sk'      => array( 'Nepoužívajú sa na reklamu ani na sledovanie naprieč inými webovými stránkami', 'Súbor cookie súhlasu ukladá náhodný identifikátor' ),
	'tr'      => array( 'Bu çerezler reklam amacıyla veya sizi başka sitelerde izlemek için kullanılmaz', 'rastgele bir onay kimliği saklar' ),
	'uk'      => array( 'Вони не використовуються для реклами чи відстеження вас на інших сайтах', 'Файл cookie згоди зберігає випадковий ідентифікатор' ),
);
foreach ( glob( $contents . 'categories/*.json' ) as $file ) {
	$loc   = basename( $file, '.json' );
	$data  = json_decode( (string) file_get_contents( $file ), true );
	$text  = (string) ( $data['necessary']['description'] ?? '' );
	$known = $statements[ $loc ] ?? null;
	nd_eq( is_array( $known ), true, "categories/{$loc}.json: the language is listed in this test" );
	nd_eq( is_array( $known ) && nd_contains_ci( $text, $known[0] ), true, "categories/{$loc}.json: Necessary text says the cookies are not used for advertising or tracking" );
	nd_eq( is_array( $known ) && nd_contains_ci( $text, $known[1] ), true, "categories/{$loc}.json: Necessary text says the consent cookie stores a random identifier" );
}

// The seed source and the catalogue must agree, or a freshly seeded row is
// not recognised as stock wording and never gets translated.
$builtin = Cookie_Database::lookup( 'fazcookie-consent' );
nd_eq( is_array( $builtin ) ? $builtin['description'] : null, $en_ck['fazcookie-consent']['description'], 'Cookie_Database built-in record matches the English catalogue entry' );

// The suggested privacy-policy paragraph lists what the consent log stores.
$privacy_text = (string) file_get_contents( $root . '/includes/class-privacy-policy-text.php' );
nd_eq( false !== strpos( $privacy_text, 'persistent consent ID' ), true, 'suggested privacy-policy text mentions the consent identifier' );
nd_eq( false !== strpos( $privacy_text, 'browser user agent' ) && false !== strpos( $privacy_text, 'salted SHA-256 hashes' ), true, 'suggested privacy-policy text says the user agent is hashed, as it is stored' );

// ---------------------------------------------------------------------------
// 2. Upgrade migration.
// ---------------------------------------------------------------------------
echo "\n== upgrade migration ==\n";

$migrate = array( Activator::class, 'refresh_necessary_disclosure_text' );
nd_eq( is_callable( $migrate ), true, 'Activator::refresh_necessary_disclosure_text() exists' );

if ( is_callable( $migrate ) ) {
	$it_cat     = json_decode( (string) file_get_contents( $contents . 'categories/it.json' ), true );
	$it_ck      = json_decode( (string) file_get_contents( $contents . 'cookies/it.json' ), true );
	$legacy_en  = $legacy['categories']['necessary']['description'][ array_search( nd_fold( '<p>Necessary cookies are required to enable the basic features of this site, such as providing secure log-in or adjusting your consent preferences. These cookies do not store any personally identifiable data.</p>' ), $legacy_cat, true ) ];
	$legacy_it  = '<p>I cookie necessari sono fondamentali per le funzioni di base del sito Web e il sito Web non funzionerà nel modo previsto senza di essi. Questi cookie non memorizzano dati identificativi personali.</p>';
	$custom_de  = '<p>Unsere eigene, juristisch geprüfte Beschreibung.</p>';

	$db = new Faz_Disclosure_Wpdb();
	$db->tables = array(
		'wp_faz_cookie_categories' => array(
			1 => array( 'slug' => 'necessary', 'description' => wp_json_encode( array(
				// A WYSIWYG round-trip: tags dropped, spacing changed. Still stock.
				'en' => "  Necessary cookies are required to enable the basic features of this site, such as providing secure log-in or adjusting your consent preferences.\nThese cookies do not store any personally identifiable data.",
				'it' => $legacy_it,
				'de' => $custom_de,
			) ) ),
			2 => array( 'slug' => 'analytics', 'description' => wp_json_encode( array( 'en' => $legacy_en ) ) ),
		),
		'wp_faz_cookies' => array(
			10 => array( 'name' => 'fazcookie-consent', 'description' => wp_json_encode( array( 'en' => 'Cookie consent preferences set by the visitor.', 'it' => 'Cookie consent preferences set by the visitor.' ) ) ),
			11 => array( 'name' => 'fazcookie-consent', 'description' => 'Our own words about the consent cookie.' ),
		),
	);
	$GLOBALS['wpdb']              = $db;
	$GLOBALS['faz_test_options']  = array();
	$GLOBALS['faz_test_cache_deletes'] = array();
	Cookie_Controller::$busts     = 0;
	Category_Controller::$busts   = 0;
	$done_now = call_user_func( $migrate );
	nd_eq( $done_now, true, 'a completed run reports itself done' );

	$nec = json_decode( $db->tables['wp_faz_cookie_categories'][1]['description'], true );
	nd_eq( $nec['en'], $en_cat['necessary']['description'], 'stored English stock text (even reflowed, tags lost) becomes the current English text' );
	nd_eq( $nec['it'], $it_cat['necessary']['description'], 'stored Italian stock text becomes the current Italian text' );
	nd_eq( $nec['de'], $custom_de, "an administrator's own wording is left alone" );
	nd_eq( json_decode( $db->tables['wp_faz_cookie_categories'][2]['description'], true )['en'], $legacy_en, 'only the Necessary category is touched' );
	$ck = json_decode( $db->tables['wp_faz_cookies'][10]['description'], true );
	nd_eq( $ck['en'], $en_ck['fazcookie-consent']['description'], 'consent-cookie English stock text becomes the current text' );
	nd_eq( $ck['it'], $it_ck['fazcookie-consent']['description'], 'English stock text in the Italian slot becomes the current Italian text' );
	nd_eq( $db->tables['wp_faz_cookies'][11]['description'], 'Our own words about the consent cookie.', 'a plain-string custom consent-cookie description is left alone' );
	nd_eq( get_option( 'faz_necessary_disclosure_text_done' ), Activator::DISCLOSURE_TEXT_REVISION, 'the once-per-site marker is written, with the revision applied' );
	nd_eq( Category_Controller::$busts > 0 && Cookie_Controller::$busts > 0, true, 'category and cookie caches are busted after the rewrite' );
	// The bundled catalogues the preference centre merges sit in the object
	// cache for twelve hours: without these deletes the old Necessary text
	// stays on screen for that long.
	nd_eq( in_array( 'faz_category_contents:faz_category_contents_v2_it', $GLOBALS['faz_test_cache_deletes'], true ), true, 'the merged bundled catalogue cache is busted per language' );
	nd_eq( in_array( 'faz_category_contents:faz_category_contents_en', $GLOBALS['faz_test_cache_deletes'], true ), true, 'and the English bundled catalogue cache too' );

	// Once per site: an administrator who later restores the old sentence keeps it.
	$db->tables['wp_faz_cookie_categories'][1]['description'] = wp_json_encode( array( 'en' => $legacy_en ) );
	$before = $db->updates;
	$done_again = call_user_func( $migrate );
	nd_eq( $db->updates, $before, 'a second run writes nothing' );
	nd_eq( $done_again, true, 'and still reports done, so the batch can write its version marker' );

	// A plain-string legacy value is rewritten in the default language.
	$db = new Faz_Disclosure_Wpdb();
	$db->tables = array(
		'wp_faz_cookie_categories' => array(),
		'wp_faz_cookies'           => array( 20 => array( 'name' => 'fazcookie-consent', 'description' => 'Cookie consent preferences set by the visitor.' ) ),
	);
	$GLOBALS['wpdb']             = $db;
	$GLOBALS['faz_test_options'] = array();
	call_user_func( $migrate );
	nd_eq( $db->tables['wp_faz_cookies'][20]['description'], $en_ck['fazcookie-consent']['description'], 'a plain-string legacy consent-cookie description is rewritten' );

	// Tables missing: retried later, marker withheld.
	$db = new Faz_Disclosure_Wpdb();
	$GLOBALS['wpdb']             = $db;
	$GLOBALS['faz_test_options'] = array();
	$declined = call_user_func( $migrate );
	nd_eq( get_option( 'faz_necessary_disclosure_text_done', 0 ), 0, 'no tables yet: marker withheld so the migration retries' );
	// The step's own marker is not enough: run_pending_migrations() returns
	// before calling anything once the BATCH marker is current, so a decline
	// that does not reach the batch was retried only by the next release.
	nd_eq( $declined, false, 'and the decline is reported, so the batch withholds its version marker' );

	// A failed write throws so the batch withholds its version marker.
	$db = new Faz_Disclosure_Wpdb();
	$db->tables = array(
		'wp_faz_cookie_categories' => array( 1 => array( 'slug' => 'necessary', 'description' => wp_json_encode( array( 'en' => $legacy_en ) ) ) ),
		'wp_faz_cookies'           => array(),
	);
	$db->fail                    = true;
	$GLOBALS['wpdb']             = $db;
	$GLOBALS['faz_test_options'] = array();
	$threw = false;
	try {
		call_user_func( $migrate );
	} catch ( \RuntimeException $e ) {
		$threw = true;
	}
	nd_eq( $threw && ! get_option( 'faz_necessary_disclosure_text_done' ), true, 'a failed write throws and leaves the marker unset' );

	// Revision 2: Turkish. A site whose marker is the plain 1 of the first
	// pass has the shipped Turkish text stored (Turkish arrived after that
	// pass existed), and gets the corrected one; the first pass's own list is
	// not applied to it again.
	$tr_cat    = json_decode( (string) file_get_contents( $contents . 'categories/tr.json' ), true );
	$legacy_tr = '<p>Web sitesinin güvenli ve düzgün şekilde çalışması için zorunlu olan çerezlerdir. Bu çerezler, oturum yönetimi, güvenlik ve temel işlevlerin sağlanması amacıyla kullanılır. Bu çerezlerin işlevleri olmadan site düzgün çalışmayabilir.</p>';
	nd_eq( $tr_cat['necessary']['description'] !== $legacy_tr, true, 'categories/tr.json no longer ships the text without the two sentences' );
	foreach ( array( 1 => 'a site that ran the first pass', 0 => 'a site that never ran it' ) as $marker => $who ) {
		$db = new Faz_Disclosure_Wpdb();
		$db->tables = array(
			'wp_faz_cookie_categories' => array(
				1 => array( 'slug' => 'necessary', 'description' => wp_json_encode( array( 'tr' => $legacy_tr, 'en' => $legacy_en, 'de' => $custom_de ) ) ),
			),
			'wp_faz_cookies'           => array(
				10 => array( 'name' => 'fazcookie-consent', 'description' => wp_json_encode( array( 'en' => 'Cookie consent preferences set by the visitor.' ) ) ),
			),
		);
		$GLOBALS['wpdb']             = $db;
		$GLOBALS['faz_test_options'] = $marker ? array( 'faz_necessary_disclosure_text_done' => $marker ) : array();
		$done = call_user_func( $migrate );
		$nec  = json_decode( $db->tables['wp_faz_cookie_categories'][1]['description'], true );
		$ck   = json_decode( $db->tables['wp_faz_cookies'][10]['description'], true );
		nd_eq( $done, true, "{$who}: the step reports done" );
		nd_eq( $nec['tr'], $tr_cat['necessary']['description'], "{$who}: the stored Turkish stock text becomes the current Turkish text" );
		nd_eq( $nec['de'], $custom_de, "{$who}: an administrator's own wording is left alone" );
		if ( $marker ) {
			nd_eq( $nec['en'], $legacy_en, "{$who}: an old sentence the administrator put back after it is not replaced a second time" );
			nd_eq( $ck['en'], 'Cookie consent preferences set by the visitor.', "{$who}: nor is the consent-cookie text" );
		} else {
			nd_eq( $nec['en'], $en_cat['necessary']['description'], "{$who}: the first pass's wordings are applied too" );
			nd_eq( $ck['en'], $en_ck['fazcookie-consent']['description'], "{$who}: and the consent-cookie text" );
		}
		nd_eq( get_option( 'faz_necessary_disclosure_text_done' ), Activator::DISCLOSURE_TEXT_REVISION, "{$who}: the marker holds the current revision" );
		$before = $db->updates;
		$db->tables['wp_faz_cookie_categories'][1]['description'] = wp_json_encode( array( 'tr' => $legacy_tr ) );
		call_user_func( $migrate );
		nd_eq( $db->updates, $before, "{$who}: a later run writes nothing" );
	}

	// Registered in the batch.
	$src = (string) file_get_contents( $root . '/includes/class-activator.php' );
	nd_eq( 1 === preg_match( '/function run_pending_migrations\(\).*?self::refresh_necessary_disclosure_text\(\).*?self::refresh_cookie_translation_caches\(\);/s', $src ), true, 'run_pending_migrations() runs it, before the translation caches are refreshed' );
	nd_eq( 1 === preg_match( '/\$all_complete = self::refresh_necessary_disclosure_text\(\) && \$all_complete;/', $src ), true, 'and its result gates the batch version marker' );
}

echo "\n{$passed} passed, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );
}
