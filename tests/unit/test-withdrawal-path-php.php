<?php
/**
 * Standalone unit tests for the verified footer withdrawal path.
 *
 * Subsystem: withdrawal-path-php
 *
 * A jurisdiction rule set asserting `ui.revisit_widget_required` requires a
 * standing way to reopen and withdraw consent. The plugin could only see one
 * implementation of that — its own floating widget — so it locked that widget
 * on, and an administrator who had deliberately put a consent-preferences link
 * in the footer could not turn the floating one off. The requirement was met;
 * the screen had no way to tell.
 *
 * Every assertion here is about the direction of failure. The footer link is
 * evidence the plugin does not own: it lives in a theme or block template, it
 * can be removed by an edit nobody reports, and the only thing that can be
 * said about it is what a fetch of the site's own pages shows. So never
 * checked, checked and failed, checked too long ago, and a site that cannot be
 * reached at all must ALL leave the widget forced on. The one state that may
 * lift the lock is a recent, successful check.
 *
 * Runs the real class against stubbed WordPress HTTP and option functions —
 * no live site, no network.
 *
 * Run from project root:
 *   php tests/unit/test-withdrawal-path-php.php
 *
 * @package FazCookie\Tests\Unit
 */

namespace {

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', sys_get_temp_dir() . '/faz-withdrawal-test/' );
	}

	$GLOBALS['__faz_options']  = array();
	$GLOBALS['__faz_requests'] = array();
	// Keyed by URL: array( 'body' => string, 'code' => int ) or 'error'.
	$GLOBALS['__faz_responses'] = array();
	$GLOBALS['__faz_pages']     = array();

	function get_option( $name, $default = false ) {
		return array_key_exists( $name, $GLOBALS['__faz_options'] ) ? $GLOBALS['__faz_options'][ $name ] : $default;
	}
	function update_option( $name, $value, $autoload = null ) {
		$GLOBALS['__faz_options'][ $name ] = $value;
		return true;
	}
	function home_url( $path = '/' ) {
		return 'https://example.test' . $path;
	}
	function get_permalink( $id ) {
		return isset( $GLOBALS['__faz_pages'][ (int) $id ] ) ? $GLOBALS['__faz_pages'][ (int) $id ] : '';
	}
	function get_posts( $args = array() ) {
		$exclude = isset( $args['exclude'] ) ? array_map( 'intval', (array) $args['exclude'] ) : array();
		$ids     = array();
		foreach ( array_keys( $GLOBALS['__faz_pages'] ) as $id ) {
			if ( ! in_array( (int) $id, $exclude, true ) ) {
				$ids[] = (int) $id;
			}
		}
		return array_slice( $ids, 0, isset( $args['numberposts'] ) ? (int) $args['numberposts'] : 5 );
	}

	class WP_Error {
		public $message;
		public function __construct( $code = '', $message = '' ) {
			$this->message = $message;
		}
	}
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
	function wp_remote_get( $url, $args = array() ) {
		$GLOBALS['__faz_requests'][] = $url;
		if ( ! isset( $GLOBALS['__faz_responses'][ $url ] ) ) {
			return new WP_Error( 'none', 'no stub for ' . $url );
		}
		$stub = $GLOBALS['__faz_responses'][ $url ];
		if ( 'error' === $stub ) {
			return new WP_Error( 'http_request_failed', 'cURL error 7' );
		}
		return $stub;
	}
	function wp_remote_retrieve_response_code( $response ) {
		return isset( $response['code'] ) ? $response['code'] : 0;
	}
	function wp_remote_retrieve_body( $response ) {
		return isset( $response['body'] ) ? $response['body'] : '';
	}

	require_once __DIR__ . '/../../includes/class-withdrawal-path.php';
	require_once __DIR__ . '/../../frontend/includes/class-geo-runtime.php';

	use FazCookie\Includes\Withdrawal_Path;
	use FazCookie\Frontend\Includes\Geo_Runtime;

	$run = $pass = $fail = 0;
	function faz_is( $actual, $expected, $label ) {
		global $run, $pass, $fail;
		$run++;
		if ( $actual === $expected ) {
			$pass++;
			echo "  \033[32m✓\033[0m $label\n";
			return;
		}
		$fail++;
		echo "  \033[31m✗\033[0m $label\n";
		echo '      atteso:   ' . var_export( $expected, true ) . "\n";
		echo '      ottenuto: ' . var_export( $actual, true ) . "\n";
	}

	/** Settings with the given withdrawal path. */
	function faz_settings_with( $path ) {
		return array( 'banner_control' => array( 'withdrawal_path' => $path ) );
	}
	/** Store a probe record directly, to test the trust rules in isolation. */
	function faz_seed_probe( $status, $checked, $found = array( 'https://example.test/' ) ) {
		$GLOBALS['__faz_options'][ Withdrawal_Path::PROBE_OPTION ] = array(
			'status'  => $status,
			'reason'  => 'seeded',
			'checked' => $checked,
			'urls'    => $found,
			'found'   => $found,
		);
	}
	/** A page body carrying the marker the shortcode emits. */
	function faz_page_with_link() {
		return '<html><body><footer><a href="#faz-consent" data-faz-open-preferences="1">Cookie preferences</a></footer></body></html>';
	}
	function faz_page_without_link() {
		return '<html><body><footer><a href="/privacy">Privacy</a></footer></body></html>';
	}

	echo "\n\033[1mconfigured_path() — whitelist, con ricaduta sulla rotta sempre presente\033[0m\n";

	faz_is( Withdrawal_Path::configured_path( array() ), 'widget', 'nessuna impostazione -> widget' );
	faz_is( Withdrawal_Path::configured_path( faz_settings_with( 'footer_link' ) ), 'footer_link', 'footer_link viene riconosciuto' );
	faz_is( Withdrawal_Path::configured_path( faz_settings_with( 'widget' ) ), 'widget', 'widget viene riconosciuto' );
	// Fail-closed: qualunque altra cosa ricade sulla rotta che il plugin
	// disegna da sé, non su "esiste un'altra rotta".
	faz_is( Withdrawal_Path::configured_path( faz_settings_with( 'qualcosa' ) ), 'widget', 'valore ignoto -> widget, non footer_link' );
	faz_is( Withdrawal_Path::configured_path( faz_settings_with( array( 'footer_link' ) ) ), 'widget', 'valore non stringa -> widget' );

	echo "\n\033[1msatisfies_revisit_requirement() — ogni ramo incerto lascia il lucchetto\033[0m\n";

	$GLOBALS['__faz_options'] = array();
	faz_is( Withdrawal_Path::satisfies_revisit_requirement( faz_settings_with( 'widget' ) ), false, 'rotta widget -> il requisito è soddisfatto DAL widget, quindi il lucchetto resta' );
	faz_is( Withdrawal_Path::satisfies_revisit_requirement( faz_settings_with( 'footer_link' ) ), false, 'footer_link mai verificato -> non soddisfatto' );

	faz_seed_probe( 'failed', time() - 60 );
	faz_is( Withdrawal_Path::satisfies_revisit_requirement( faz_settings_with( 'footer_link' ) ), false, 'verifica fallita -> non soddisfatto' );

	faz_seed_probe( 'verified', time() - 3600 );
	faz_is( Withdrawal_Path::satisfies_revisit_requirement( faz_settings_with( 'footer_link' ) ), true, 'verifica recente e riuscita -> soddisfatto, il lucchetto si apre' );

	// CONTROLLO CHIAVE: la prova invecchia. Un link nel footer può essere
	// rimosso con una modifica del template che nessuno segnala, quindi una
	// verifica vecchia non è più una prova.
	faz_seed_probe( 'verified', time() - Withdrawal_Path::VALID_FOR - 10 );
	faz_is( Withdrawal_Path::satisfies_revisit_requirement( faz_settings_with( 'footer_link' ) ), false, 'verifica più vecchia della finestra di fiducia -> torna non soddisfatto' );
	faz_is( Withdrawal_Path::state( faz_settings_with( 'footer_link' ) )['stale'], true, "lo stato distingue 'scaduta' da 'fallita': l'azione da fare è diversa" );

	// Orologio spostato indietro: 'checked' sembra nel futuro. Non è una prova
	// più fresca, è una prova di cui non si sa l'età.
	faz_seed_probe( 'verified', time() + 86400 );
	faz_is( Withdrawal_Path::satisfies_revisit_requirement( faz_settings_with( 'footer_link' ) ), false, 'timestamp nel futuro -> trattato come inaffidabile, non come recentissimo' );

	// Anche con una verifica perfetta, tornare al widget richiude tutto.
	faz_seed_probe( 'verified', time() - 10 );
	faz_is( Withdrawal_Path::satisfies_revisit_requirement( faz_settings_with( 'widget' ) ), false, 'verifica valida ma rotta riportata a widget -> il lucchetto torna' );

	echo "\n\033[1mverify() — il marker deve stare su OGNI pagina, non su una\033[0m\n";

	$GLOBALS['__faz_options'] = array();
	$GLOBALS['__faz_pages']   = array( 12 => 'https://example.test/chi-siamo/', 34 => 'https://example.test/contatti/' );
	$urls                     = Withdrawal_Path::probe_urls();
	faz_is( in_array( 'https://example.test/', $urls, true ), true, 'la home è sempre fra le pagine controllate' );
	faz_is( count( $urls ) <= Withdrawal_Path::MAX_PROBE_URLS, true, 'il numero di richieste è limitato' );

	$GLOBALS['__faz_responses'] = array();
	foreach ( $urls as $u ) {
		$GLOBALS['__faz_responses'][ $u ] = array( 'code' => 200, 'body' => faz_page_with_link() );
	}
	$GLOBALS['__faz_requests'] = array();
	$state                    = Withdrawal_Path::verify();
	faz_is( $state['status'], 'verified', 'marker su ogni pagina -> verificato' );
	faz_is( count( $state['found'] ), count( $urls ), 'tutte le pagine controllate hanno dato esito positivo' );
	faz_is( count( $GLOBALS['__faz_requests'] ), count( $urls ), 'una richiesta per pagina, nessuna in più' );

	// IL caso che "any" avrebbe accettato: il link incollato nel contenuto di
	// una pagina sola invece che nel template. Non è una rotta permanente.
	$GLOBALS['__faz_responses'][ $urls[ count( $urls ) - 1 ] ] = array( 'code' => 200, 'body' => faz_page_without_link() );
	$state = Withdrawal_Path::verify();
	faz_is( $state['status'], 'failed', 'marker su alcune pagine ma non su tutte -> fallito' );
	faz_is( $state['reason'], 'marker_missing', 'causa: il marker manca, non la rete' );
	faz_is( Withdrawal_Path::satisfies_revisit_requirement( faz_settings_with( 'footer_link' ) ), false, 'un link su una pagina sola non apre il lucchetto' );

	// Sito irraggiungibile da sé stesso (loopback bloccato, firewall, staging
	// con autenticazione). Non dice NULLA sul link: la causa deve restare
	// distinguibile, perché la cosa da riparare è un'altra.
	$GLOBALS['__faz_responses'][ $urls[0] ] = 'error';
	$state                                  = Withdrawal_Path::verify();
	faz_is( $state['status'], 'failed', 'richiesta in errore -> fallito (fail-closed)' );
	faz_is( $state['reason'], 'request_failed', "causa distinta da 'marker_missing': mandare l'admin a cercare un link presente sarebbe un falso indizio" );

	// Una pagina che non carica affatto.
	$GLOBALS['__faz_responses'][ $urls[0] ] = array( 'code' => 503, 'body' => '' );
	$state                                  = Withdrawal_Path::verify();
	faz_is( $state['reason'], 'http_503', 'il codice HTTP finisce nella causa, così il messaggio può dirlo' );

	echo "\n\033[1mbody_has_marker() — conta solo l'attributo su un elemento vero\033[0m\n";

	// IL difetto: con "alternative asset path" il bundle script.min.js viene
	// stampato inline, e contiene il selettore come TESTO. Una ricerca per
	// sottostringa lo prendeva per un link, e su una pagina senza alcun link
	// il lucchetto del widget si apriva.
	$inline_bundle = '<html><head><script id="faz-inline">var s="[data-faz-open-preferences],.faz-cookie-settings-btn";document.addEventListener("click",function(e){e.target.closest(s);});</script></head><body><footer><a href="/privacy">Privacy</a></footer></body></html>';
	faz_is( Withdrawal_Path::body_has_marker( $inline_bundle ), false, 'marker solo dentro uno <script> inline -> NON verificato' );
	faz_is( Withdrawal_Path::body_has_marker( '<script>"data-faz-open-preferences"</script>' ), false, 'only raw text leaves no document to parse' );
	faz_is( Withdrawal_Path::body_has_marker( '<body><!-- <a data-faz-open-preferences="1">Cookie</a> --><p>x</p></body>' ), false, 'marker solo in un commento HTML -> NON verificato' );
	faz_is( Withdrawal_Path::body_has_marker( '<body><noscript><a href="#faz-consent" data-faz-open-preferences="1">Cookie</a></noscript></body>' ), false, 'marker solo in <noscript> -> NON verificato: senza script.js il link non fa nulla' );
	faz_is( Withdrawal_Path::body_has_marker( '<body><template><button data-faz-open-preferences="1">Cookie</button></template></body>' ), false, 'marker solo in un <template> inerte -> NON verificato' );
	// Raw-text regions must end where the element's name ends, not at the first
	// string that merely starts with it. `</titlex>` does not close <title>, and
	// a browser renders everything after it as the title's text — so a marker
	// there is inert. Closing the region early would hand that inert text back
	// as live markup, verify a footer route that does not exist, and leave the
	// visitor with no way to withdraw consent: fail-open, in the one class whose
	// contract is to fail closed.
	// Each case puts a REAL element carrying the marker after the fake closing
	// tag: that is what makes the assertion bite. With the marker only in loose
	// text the DOM parser finds no attribute and the test would pass either way
	// — green, and worthless. Confirmed by running all four against the old
	// substring search: every one of them goes red.
	$raw_text_boundaries = array(
		'<title>Writing </titlex> more <a data-faz-open-preferences>fake</a></title><footer>none</footer>',
		'<style>#a{color:red} /* </stylex> */ <a data-faz-open-preferences>fake</a></style><footer>none</footer>',
		'<script>var s = "</scriptx>"; <a data-faz-open-preferences>fake</a></script><footer>none</footer>',
		'<textarea>paste </textareax> here <a data-faz-open-preferences>fake</a></textarea><footer>none</footer>',
		// Vertical tab. PCRE's \s matches it, HTML's whitespace set does not,
		// so `</title\x0B>` must NOT close the region: a browser keeps reading
		// title text, and anything after it has to stay inert here too.
		"<title>t </title\x0B> <a data-faz-open-preferences>fake</a></title><footer>none</footer>",
	);
	foreach ( $raw_text_boundaries as $markup ) {
		faz_is( Withdrawal_Path::body_has_marker( $markup ), false, 'marker oltre una finta chiusura di testo grezzo -> NON verificato' );
	}
	// E il tag di chiusura vero continua a chiudere: la correzione non deve
	// rendere la regione infinita, o ogni marker reale dopo di essa sparirebbe.
	faz_is(
		Withdrawal_Path::body_has_marker( '<title>t</title><footer><a href="#faz-consent" data-faz-open-preferences>Cookie</a></footer>' ),
		true,
		'dopo la chiusura vera il markup torna a contare'
	);

	$unusable_controls = array(
		'<button disabled data-faz-open-preferences>Preferences</button>',
		'<button data-faz-open-preferences disabled="false">Preferences</button>',
		'<footer hidden><button data-faz-open-preferences>Preferences</button></footer>',
		'<footer inert><a data-faz-open-preferences>Preferences</a></footer>',
		'<fieldset disabled><button data-faz-open-preferences>Preferences</button></fieldset>',
		'<template><template><p>inner</p></template><button data-faz-open-preferences>Preferences</button></template>',
		'<footer style="display: none"><a data-faz-open-preferences>Preferences</a></footer>',
		'<footer><a style="visibility:hidden" data-faz-open-preferences>Preferences</a></footer>',
	);
	// `disabled` where the HTML spec gives it no effect. These are USABLE: the
	// attribute is defined only on form controls and never disables a link, so
	// the element still renders and script.js still binds to it. Rejecting them
	// reported a perfectly good footer route as `marker_missing`, and the
	// administrator saw verification fail with nothing visible to fix. The
	// browser side asks `:disabled`, which agrees with every case below.
	$disabled_without_effect = array(
		'<footer disabled><a data-faz-open-preferences>Preferences</a></footer>',
		'<div disabled><a data-faz-open-preferences>Preferences</a></div>',
		'<form disabled><a data-faz-open-preferences>Preferences</a></form>',
		// `disabled` on the link itself is just as meaningless.
		'<footer><a disabled data-faz-open-preferences>Preferences</a></footer>',
		// A fieldset's disabled state exempts its FIRST <legend>.
		'<fieldset disabled><legend><button data-faz-open-preferences>Preferences</button></legend></fieldset>',
		// A form control outside any disabled fieldset is unaffected.
		'<fieldset disabled><button>Other</button></fieldset><button data-faz-open-preferences>Preferences</button>',
	);
	foreach ( $disabled_without_effect as $html ) {
		faz_is( Withdrawal_Path::body_has_marker( $html ), true, 'disabled with no effect keeps the route usable: ' . $html );
	}
	// ...but a fieldset DOES disable a form control outside its first legend,
	// including one nested deeper, and a second <legend> is not exempt.
	$disabled_with_effect = array(
		'<fieldset disabled><legend>Title</legend><button data-faz-open-preferences>Preferences</button></fieldset>',
		'<fieldset disabled><div><button data-faz-open-preferences>Preferences</button></div></fieldset>',
		'<fieldset disabled><legend>First</legend><legend><button data-faz-open-preferences>Preferences</button></legend></fieldset>',
	);
	foreach ( $disabled_with_effect as $html ) {
		faz_is( Withdrawal_Path::body_has_marker( $html ), false, 'a disabled fieldset does disable its form controls: ' . $html );
	}

	foreach ( $unusable_controls as $html ) {
		faz_is( Withdrawal_Path::body_has_marker( $html ), false, 'unusable withdrawal control: ' . $html );
		foreach ( $urls as $u ) {
			$GLOBALS['__faz_responses'][ $u ] = array( 'code' => 200, 'body' => $html );
		}
		$unusable_probe = Withdrawal_Path::verify();
		faz_is( $unusable_probe['status'], 'failed', 'unusable control cannot verify the footer route' );
		$overlay = Geo_Runtime::apply_ui_requirements(
			array( 'ui' => array( 'revisit_widget_required' => true ) ),
			array( 'config' => array( 'revisitConsent' => array( 'status' => false ) ) ),
			Withdrawal_Path::satisfies_revisit_requirement( faz_settings_with( 'footer_link' ) )
		);
		faz_is( $overlay['config']['revisitConsent']['status'], true, 'unusable control leaves the revisit widget on' );
	}
	faz_is( Withdrawal_Path::body_has_marker( '<footer hidden><a data-faz-open-preferences>Hidden</a></footer><footer><a data-faz-open-preferences>Visible</a></footer>' ), true, 'a usable sibling is not hidden by the earlier hidden ancestor' );
	faz_is( Withdrawal_Path::body_has_marker( '<body><style>[data-faz-open-preferences]{color:red}</style></body>' ), false, 'marker solo in un selettore CSS -> NON verificato' );
	faz_is( Withdrawal_Path::body_has_marker( '<body><code>&lt;a data-faz-open-preferences="1"&gt;</code></body>' ), false, 'snippet mostrato come testo con entità -> NON verificato' );
	faz_is( Withdrawal_Path::body_has_marker( '<body><a title="usa data-faz-open-preferences nel footer" href="/x">x</a></body>' ), false, 'marker dentro il VALORE di un altro attributo -> NON verificato' );
	faz_is( Withdrawal_Path::body_has_marker( '<body><a data-faz-open-preferences-extra="1">x</a></body>' ), false, 'attributo con nome più lungo -> NON verificato' );
	// Uno <script> che non si chiude inghiotte il resto del documento, come
	// fa il browser: il link dopo non è markup.
	faz_is( Withdrawal_Path::body_has_marker( '<body><script>var a=1;<a data-faz-open-preferences="1">x</a></body>' ), false, '<script> mai chiuso -> il resto è testo, NON verificato' );

	faz_is( Withdrawal_Path::body_has_marker( faz_page_with_link() ), true, '<a data-faz-open-preferences="1"> vero -> verificato' );
	faz_is( Withdrawal_Path::body_has_marker( '<footer><button type="button" class="faz-cookie-settings-btn" data-faz-open-preferences="1" aria-haspopup="dialog">Manage consent preferences</button></footer>' ), true, 'il <button> emesso dallo shortcode -> verificato' );
	faz_is( Withdrawal_Path::body_has_marker( "<footer><a href='#faz-consent' data-faz-open-preferences='1'>x</a></footer>" ), true, 'valore tra apici singoli -> verificato' );
	faz_is( Withdrawal_Path::body_has_marker( '<footer><a href="#faz-consent" data-faz-open-preferences>x</a></footer>' ), true, 'attributo booleano senza valore -> verificato' );
	faz_is( Withdrawal_Path::body_has_marker( '<FOOTER><A HREF="#faz-consent" DATA-FAZ-OPEN-PREFERENCES="1">x</A></FOOTER>' ), true, 'tag e attributo in maiuscolo -> verificato (HTML non distingue)' );
	faz_is( Withdrawal_Path::body_has_marker( "<footer><li\n  class=\"x\"\n  data-faz-open-preferences=\"1\">x</li></footer>" ), true, 'qualunque elemento, attributo su un\'altra riga -> verificato (script.js usa closest())' );
	faz_is( Withdrawal_Path::body_has_marker( '<a title="a > b" data-faz-open-preferences="1">x</a>' ), true, 'un ">" dentro un valore tra virgolette non spezza il tag' );
	faz_is( Withdrawal_Path::body_has_marker( '<span data-faz-open-preferences/>' ), true, 'elemento auto-chiuso -> verificato' );
	faz_is( Withdrawal_Path::body_has_marker( $inline_bundle . '<footer><a data-faz-open-preferences="1">x</a></footer>' ), true, 'bundle inline PIÙ un link vero -> verificato: lo script non nasconde il link' );

	// E attraverso verify(): una pagina il cui unico "marker" è il bundle inline
	// deve far fallire la verifica, con la causa giusta.
	$GLOBALS['__faz_options']   = array();
	$GLOBALS['__faz_responses'] = array();
	foreach ( Withdrawal_Path::probe_urls() as $u ) {
		$GLOBALS['__faz_responses'][ $u ] = array( 'code' => 200, 'body' => $inline_bundle );
	}
	$state = Withdrawal_Path::verify();
	faz_is( $state['status'], 'failed', 'verify(): solo bundle inline su ogni pagina -> fallito' );
	faz_is( $state['reason'], 'marker_missing', 'verify(): causa marker_missing' );
	faz_is( Withdrawal_Path::satisfies_revisit_requirement( faz_settings_with( 'footer_link' ) ), false, 'verify(): il lucchetto del widget resta chiuso' );

	echo "\n\033[1mapply_ui_requirements() — il widget si forza solo se nient'altro lo fa\033[0m\n";

	$ruleset = array( 'ui' => array( 'revisit_widget_required' => true ) );

	$forced = Geo_Runtime::apply_ui_requirements( $ruleset, array() );
	faz_is( $forced['config']['revisitConsent']['status'], true, 'nessuna rotta verificata -> il runtime accende il widget' );

	// Stesso ruleset, stessa chiamata: l'unica differenza è che una rotta
	// alternativa è stata PROVATA. Non si accende un secondo controllo sopra
	// una scelta legittima dell'amministratore.
	$not_forced = Geo_Runtime::apply_ui_requirements( $ruleset, array(), true );
	faz_is( isset( $not_forced['config']['revisitConsent']['status'] ), false, 'rotta verificata -> il runtime NON tocca il widget' );
	$footer_fallback = Geo_Runtime::apply_ui_requirements( $ruleset, array( 'config' => array( 'revisitConsent' => array( 'status' => false ) ) ), true );
	faz_is( $footer_fallback['config']['revisitConsent']['status'], false, 'verified footer preserves the disabled widget baseline' );
	faz_is( $footer_fallback['config']['revisitConsent']['verifiedAlternative'], true, 'verified footer retains a hidden browser fallback' );
	$amp_fallback = Geo_Runtime::apply_ui_requirements( $ruleset, $footer_fallback );
	faz_is( $amp_fallback['config']['revisitConsent']['status'], true, 'without an alternative the native widget is enabled' );
	faz_is( $amp_fallback['config']['revisitConsent']['verifiedAlternative'], false, 'the normal-page alternative cannot leak into AMP' );

	// Exercise the real PHP template filter: a disabled widget normally
	// disappears, but a verified footer must retain its hidden fallback.
	require_once dirname( __DIR__, 2 ) . '/includes/class-utils.php';
	require_once dirname( __DIR__, 2 ) . '/includes/class-store.php';
	require_once dirname( __DIR__, 2 ) . '/admin/modules/banners/includes/class-banner.php';
	require_once dirname( __DIR__, 2 ) . '/admin/modules/banners/includes/class-template.php';
	function do_shortcode( $html ) { return $html; }
	function faz_sanitize_bool( $value ) { return filter_var( $value, FILTER_VALIDATE_BOOLEAN ); }
	function faz_sanitize_text( $value ) { return is_array( $value ) ? array_map( 'faz_sanitize_text', $value ) : trim( (string) $value ); }
	$model = ( new \ReflectionClass( '\FazCookie\Admin\Modules\Banners\Includes\Banner' ) )->newInstanceWithoutConstructor();
	$controller_property = new \ReflectionProperty( $model, 'controller' );
	$controller_property->setAccessible( true );
	$controller_property->setValue( $model, new class {
		public function get_default_configs( $type ) {
			return array( 'config' => array( 'revisitConsent' => array( 'tag' => 'revisit-consent', 'status' => false ) ) );
		}
	} );
	$model->set_settings( $footer_fallback );
	faz_is( $model->get_settings()['config']['revisitConsent']['verifiedAlternative'], true, 'real Banner sanitization preserves the runtime fallback flag' );
	$model_data = new \ReflectionProperty( $model, 'data' );
	$model_data->setAccessible( true );
	faz_is( isset( $model_data->getValue( $model )['settings']['config']['revisitConsent']['verifiedAlternative'] ), false, 'the runtime flag is excluded from data that is persisted' );
	$model->set_settings( array( 'config' => array( 'revisitConsent' => array( 'status' => true ) ) ) );
	faz_is( isset( $model->get_settings()['config']['revisitConsent']['verifiedAlternative'] ), false, 'a later overlay can clear the runtime fallback flag' );
	$prepare_html = new \ReflectionMethod( '\FazCookie\Admin\Modules\Banners\Includes\Template', 'prepare_html' );
	$prepare_html->setAccessible( true );
	foreach ( array( false, true ) as $retain_fallback ) {
		$template = ( new \ReflectionClass( '\FazCookie\Admin\Modules\Banners\Includes\Template' ) )->newInstanceWithoutConstructor();
		foreach ( array(
			'template' => array( 'html' => '<div class="faz-btn-revisit-wrapper faz-revisit-hide" data-faz-tag="revisit-consent"><button>Preferences</button></div><div class="faz-consent-container">Banner</div>' ),
			'properties' => array( 'config' => array( 'revisitConsent' => array( 'tag' => 'revisit-consent', 'status' => false, 'verifiedAlternative' => $retain_fallback ) ) ),
		) as $property_name => $value ) {
			$property = new \ReflectionProperty( $template, $property_name );
			$property->setAccessible( true );
			$property->setValue( $template, $value );
		}
		$html = $prepare_html->invoke( $template );
		faz_is( false !== strpos( $html, 'data-faz-tag="revisit-consent"' ), $retain_fallback, 'PHP template retains the disabled widget only as a verified-footer fallback' );
	}

	// E il resto dell'overlay non cambia: il terzo parametro riguarda solo
	// questo requisito.
	$other = array( 'ui' => array( 'revisit_widget_required' => true, 'donotsell_link_required' => true ) );
	$out   = Geo_Runtime::apply_ui_requirements( $other, array(), true );
	faz_is( $out['config']['optoutPopup']['status'], true, 'gli altri requisiti del ruleset restano applicati' );

	echo "\n--\n";
	echo "Tests:  $run\n";
	echo "Passed: $pass\n";
	echo "Failed: $fail\n\n";
	if ( $fail > 0 ) {
		echo "\033[31mFAIL\033[0m\n";
		exit( 1 );
	}
	echo "\033[32mPASS\033[0m\n";
	exit( 0 );
}
