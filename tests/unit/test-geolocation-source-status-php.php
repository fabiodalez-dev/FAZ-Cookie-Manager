<?php
/**
 * Standalone unit tests for the REAL country-source predicate and probe.
 *
 * Every other suite that needs this predicate stubs the whole Geolocation class,
 * because they are testing what Frontend does with the answer. That leaves the
 * answer itself untested: a change to has_country_source() would keep all of
 * them green. This file loads includes/class-geolocation.php and drives it with
 * real files on disk, so the production logic is what fails when it breaks.
 *
 * The case that matters most is the one that caused the bug: a saved MaxMind
 * licence key is an INTENTION, not a capability, and the admin used to treat it
 * as proof.
 *
 * @package FazCookie\Tests\Unit
 */

namespace {
	$faz_tmp = sys_get_temp_dir() . '/faz-geo-source-' . getmypid() . '/';
	@mkdir( $faz_tmp, 0777, true );
	@mkdir( $faz_tmp . 'faz-cookie-manager/', 0777, true );

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', $faz_tmp );
	}

	$GLOBALS['__faz_filters'] = array();
	$GLOBALS['__faz_options'] = array();

	function apply_filters( $tag, $value ) {
		return isset( $GLOBALS['__faz_filters'][ $tag ] ) ? $GLOBALS['__faz_filters'][ $tag ] : $value;
	}
	// Presence of a callback, as WordPress answers it: any tag a test has set.
	function has_filter( $tag ) {
		return array_key_exists( $tag, $GLOBALS['__faz_filters'] );
	}
	function faz_set_filter( $tag, $value ) {
		$GLOBALS['__faz_filters'][ $tag ] = $value;
	}
	function get_option( $name, $default = false ) {
		return array_key_exists( $name, $GLOBALS['__faz_options'] ) ? $GLOBALS['__faz_options'][ $name ] : $default;
	}
	function update_option( $name, $value, $autoload = null ) {
		$GLOBALS['__faz_options'][ $name ] = $value;
		return true;
	}
	function delete_option( $name ) {
		unset( $GLOBALS['__faz_options'][ $name ] );
		return true;
	}
	function trailingslashit( $value ) {
		return rtrim( (string) $value, '/\\' ) . '/';
	}
	function wp_upload_dir() {
		return array( 'basedir' => rtrim( ABSPATH, '/' ), 'baseurl' => 'http://example.test' );
	}
	function get_transient( $key ) {
		return false;
	}
	function set_transient( $key, $value, $ttl = 0 ) {
		return true;
	}
	function faz_resolve_client_ip() {
		return '203.0.113.7';
	}
	function sanitize_text_field( $v ) {
		return is_string( $v ) ? trim( $v ) : '';
	}
	function wp_unslash( $v ) {
		return $v;
	}

	// Il reader vero, non uno stub: la sonda deve attraversare il parser MMDB
	// reale, altrimenti "il database non funziona" sarebbe un'affermazione del
	// test e non della produzione.
	require_once __DIR__ . '/../../includes/class-mmdb-reader.php';
	require_once __DIR__ . '/../../includes/class-geolocation.php';

	use FazCookie\Includes\Geolocation;

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
		echo "      atteso:   " . var_export( $expected, true ) . "\n";
		echo "      ottenuto: " . var_export( $actual, true ) . "\n";
	}

	function faz_reset() {
		$GLOBALS['__faz_filters'] = array();
		$GLOBALS['__faz_options'] = array();
		unset( $_SERVER['GEOIP_COUNTRY_CODE'] );
		Geolocation::reset_runtime_cache();
	}

	/**
	 * Replace the fixture database and drop PHP's stat cache for that path.
	 *
	 * is_valid_mmdb() sizes its tail read with filesize(), and filesize() is
	 * memoised per request for the whole path — file_put_contents() does not
	 * invalidate it. Without the clearstatcache() a replacement is read at the
	 * PREVIOUS file's length: after the 24-byte "not a database" fixture, the
	 * valid 2 KB one is measured as 24 bytes, falls under the marker length and
	 * is rejected. The test would fail on a correct implementation, which is
	 * the worst kind of failure to debug. reset_runtime_cache() cannot help —
	 * it clears Geolocation's own memos, not PHP's.
	 *
	 * @param string $path     Fixture path.
	 * @param string $contents New contents.
	 * @return void
	 */
	function faz_write_db( $path, $contents ) {
		file_put_contents( $path, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		clearstatcache( true, $path );
	}

	$db_dir   = ABSPATH . 'faz-cookie-manager/';
	$db_path  = $db_dir . 'GeoLite2-Country.mmdb';
	$marker   = "\xab\xcd\xefMaxMind.com";

	echo "\n\033[1mhas_country_source() — la classe reale, non un doppio\033[0m\n";

	faz_reset();
	@unlink( $db_path );
	Geolocation::reset_runtime_cache();
	faz_is( Geolocation::has_country_source(), false, 'nessun database, nessun filtro, nessun modulo -> nessuna sorgente' );

	faz_reset();
	faz_set_filter( 'faz_trust_cf_ipcountry_header', true );
	faz_is( Geolocation::has_country_source(), true, 'il filtro Cloudflare basta, anche senza intestazione nella richiesta' );

	faz_reset();
	faz_set_filter( 'faz_trust_geoip_country_code', true );
	faz_is( Geolocation::has_country_source(), true, 'il filtro mod_geoip basta, anche senza intestazione' );

	faz_reset();
	$_SERVER['GEOIP_COUNTRY_CODE'] = 'IT';
	faz_is( Geolocation::has_country_source(), true, "l'intestazione mod_geoip presente conta come sorgente" );
	unset( $_SERVER['GEOIP_COUNTRY_CODE'] );

	faz_reset();
	faz_set_filter( 'faz_has_country_signal_source', false );
	$_SERVER['GEOIP_COUNTRY_CODE'] = 'IT';
	faz_is( Geolocation::has_country_source(), false, 'il filtro di override vince anche quando una sorgente esiste' );
	unset( $_SERVER['GEOIP_COUNTRY_CODE'] );

	echo "\n\033[1mUn file che non è un MMDB non è una sorgente\033[0m\n";

	// Il controllo di formato è reale: is_valid_mmdb() cerca il marker nella coda
	// del file. Un file qualunque col nome giusto non deve contare.
	faz_reset();
	faz_write_db( $db_path, 'questo non è un database' );
	Geolocation::reset_runtime_cache();
	faz_is( Geolocation::has_country_source(), false, 'un file col nome giusto ma senza marker MMDB non conta' );

	faz_reset();
	faz_write_db( $db_path, str_repeat( 'x', 2048 ) . $marker );
	Geolocation::reset_runtime_cache();
	faz_is( Geolocation::has_country_source(), true, 'un file che supera il controllo di formato conta come sorgente' );

	echo "\n\033[1msource_status() — configurato NON vuole dire funzionante\033[0m\n";

	// IL caso che ha causato il difetto. L'admin leggeva la sola chiave e
	// dichiarava "sorgente configurata" a siti senza alcun database, mentre il
	// resolver restituiva stringa vuota per ogni visitatore.
	faz_reset();
	@unlink( $db_path );
	Geolocation::reset_runtime_cache();
	$GLOBALS['__faz_options']['faz_settings'] = array(
		'geolocation' => array( 'maxmind_license_key' => 'chiave-salvata-ma-mai-scaricata' ),
	);
	$status = Geolocation::source_status();
	faz_is( $status['configured'], true, 'chiave salvata -> configured true (è un intento)' );
	faz_is( $status['working'], false, 'chiave salvata senza database -> working FALSE' );
	faz_is( $status['reason'], 'key_without_database', 'la causa è distinguibile da "non configurato"' );

	faz_reset();
	@unlink( $db_path );
	Geolocation::reset_runtime_cache();
	$none = Geolocation::source_status();
	faz_is( $none['configured'], false, 'nessuna chiave e nessun database -> non configurato' );
	faz_is( $none['reason'], 'no_source', 'causa: nessuna sorgente' );

	faz_reset();
	faz_set_filter( 'faz_trust_cf_ipcountry_header', true );
	$cf = Geolocation::source_status();
	faz_is( $cf['source'], 'cloudflare', 'il filtro dichiara la sorgente' );
	faz_is( $cf['working'], true, "una dichiarazione dello sviluppatore non si sonda: l'intestazione arriva al visitatore" );

	echo "\n\033[1msource_status() — 'funzionante' vuol dire ciò che il resolver usa davvero\033[0m\n";

	// mod_geoip caricato ma senza il filtro di fiducia: detect_country() NON
	// legge GEOIP_COUNTRY_CODE. Dirlo "funzionante" mandava l'admin via convinto
	// di avere il routing per paese mentre ogni visitatore riceveva il fallback.
	faz_reset();
	@unlink( $db_path );
	Geolocation::reset_runtime_cache();
	$_SERVER['GEOIP_COUNTRY_CODE'] = 'IT';
	$untrusted = Geolocation::source_status();
	faz_is( $untrusted['source'], 'mod_geoip', 'mod_geoip presente -> la sorgente è riconosciuta' );
	faz_is( $untrusted['configured'], true, 'mod_geoip presente -> configurato' );
	faz_is( $untrusted['working'], false, 'mod_geoip SENZA filtro di fiducia -> NON funzionante: il resolver lo ignora' );
	faz_is( $untrusted['reason'], 'module_untrusted', 'causa propria, distinta da no_source e key_without_database' );

	// Anche con una chiave MaxMind salvata la causa più vicina resta il modulo:
	// basta una riga di filtro, non un download.
	$GLOBALS['__faz_options']['faz_settings'] = array(
		'geolocation' => array( 'maxmind_license_key' => 'chiave' ),
	);
	faz_is( Geolocation::source_status()['reason'], 'module_untrusted', 'mod_geoip non fidato + chiave senza database -> module_untrusted' );

	// Con il filtro di fiducia il modulo è usato davvero.
	faz_reset();
	$_SERVER['GEOIP_COUNTRY_CODE'] = 'IT';
	faz_set_filter( 'faz_trust_geoip_country_code', true );
	$trusted = Geolocation::source_status();
	faz_is( $trusted['working'], true, 'mod_geoip CON filtro di fiducia -> funzionante' );
	faz_is( $trusted['reason'], 'declared_by_filter', 'causa: dichiarato dallo sviluppatore' );
	unset( $_SERVER['GEOIP_COUNTRY_CODE'] );

	// faz_visitor_country è l'ultima parola di get_visitor_country(): un
	// callback lì è una sorgente anche se tutto il resto manca.
	faz_reset();
	@unlink( $db_path );
	Geolocation::reset_runtime_cache();
	faz_set_filter( 'faz_visitor_country', 'IT' );
	$by_filter = Geolocation::source_status();
	faz_is( $by_filter['working'], true, 'callback su faz_visitor_country -> funzionante, senza database né intestazioni' );
	faz_is( $by_filter['source'], 'filter', 'sorgente: filtro' );
	faz_is( $by_filter['reason'], 'country_filter', 'causa propria: country_filter' );

	// ...e vince anche sul modulo non fidato.
	$_SERVER['GEOIP_COUNTRY_CODE'] = 'IT';
	faz_is( Geolocation::source_status()['reason'], 'country_filter', 'faz_visitor_country + mod_geoip non fidato -> conta il filtro' );
	unset( $_SERVER['GEOIP_COUNTRY_CODE'] );

	// faz_has_country_signal_source forzato a true: "il paese lo inietta il mio edge".
	faz_reset();
	@unlink( $db_path );
	Geolocation::reset_runtime_cache();
	faz_set_filter( 'faz_has_country_signal_source', true );
	$forced_signal = Geolocation::source_status();
	faz_is( $forced_signal['working'], true, 'faz_has_country_signal_source forzato a true -> funzionante' );
	faz_is( $forced_signal['reason'], 'signal_forced_by_filter', 'causa propria: signal_forced_by_filter' );

	// Forzato a false non spegne nulla nel resolver: tiene solo la cache. Su
	// un sito senza sorgenti il verdetto resta quello naturale.
	faz_reset();
	@unlink( $db_path );
	Geolocation::reset_runtime_cache();
	faz_set_filter( 'faz_has_country_signal_source', false );
	faz_is( Geolocation::source_status()['reason'], 'no_source', 'faz_has_country_signal_source a false, nessuna sorgente -> no_source' );

	// Database presente ma inutilizzabile per il lookup: il file supera il
	// controllo di formato e la sonda fallisce. Deve essere 'probe_failed', NON
	// 'key_without_database' — le due cause mandano l'amministratore a fare
	// cose diverse.
	faz_reset();
	faz_write_db( $db_path, str_repeat( 'x', 2048 ) . $marker );
	Geolocation::reset_runtime_cache();
	$GLOBALS['__faz_options']['faz_settings'] = array(
		'geolocation' => array( 'maxmind_license_key' => 'chiave' ),
	);
	$broken = Geolocation::source_status();
	faz_is( $broken['configured'], true, 'database presente -> configurato' );
	faz_is( $broken['working'], false, 'un database che non risolve nulla -> working false' );
	faz_is( $broken['reason'], 'probe_failed', 'causa: la sonda è fallita, non il database mancante' );

	echo "\n\033[1mLa sonda è memorizzata e invalidata dal file\033[0m\n";

	$probe = get_option( 'faz_geo_source_probe', array() );
	faz_is( is_array( $probe ) && isset( $probe['fingerprint'] ), true, "l'esito della sonda viene memorizzato con un fingerprint" );
	$first = $probe['fingerprint'];

	// Lo stesso file deve dare lo stesso fingerprint, e il risultato memorizzato
	// deve essere RIUSATO invece di risondare.
	//
	// Confrontare solo il fingerprint non lo dimostrava: una sonda nuova
	// riscrive lo stesso fingerprint, quindi l'asserzione restava verde anche
	// se il riuso sparisse. L'unico modo di distinguere le due cose è rendere
	// il valore memorizzato DIVERSO da quello che una sonda produrrebbe: qui
	// il database non risolve nulla, quindi una sonda dà sempre false. Se la
	// chiamata restituisce true, quel true può venire solo dall'opzione.
	$probe['working'] = true;
	update_option( 'faz_geo_source_probe', $probe, false );
	Geolocation::reset_runtime_cache();
	$reused = Geolocation::source_status();
	faz_is( $reused['working'], true, 'file invariato -> il verdetto memorizzato viene riusato, nessuna nuova sonda' );
	faz_is( $reused['reason'], 'probe_ok', 'il motivo segue il verdetto riusato' );
	faz_is( get_option( 'faz_geo_source_probe' )['fingerprint'], $first, 'file invariato -> stesso fingerprint' );

	// $force salta il riuso: la sonda rigira e il database rotto torna false.
	// Senza questo caso il parametro non era coperto da nulla.
	$forced = Geolocation::source_status( true );
	faz_is( $forced['working'], false, 'force -> risonda il database non funzionante, il true memorizzato non vale' );
	faz_is( $forced['reason'], 'probe_failed', 'force -> la causa torna alla sonda fallita' );

	// ...e un file diverso uno nuovo, così una sostituzione del database non
	// lascia in piedi un verdetto vecchio.
	//
	// Nota su cosa questo NON prova: la chiamata a clearstatcache() in
	// database_fingerprint(). Qui il file cambia dimensione, quindi il
	// fingerprint cambierebbe anche leggendo la stat cache. Il caso che
	// clearstatcache difende — extract_mmdb() che sostituisce il file con
	// rename() DOPO che source_status() ha già letto i metadati nella stessa
	// richiesta — non è riproducibile in modo deterministico da qui, perché
	// servirebbe un file di dimensione identica e stesso mtime al secondo. La
	// seconda difesa è quella verificabile e la più robusta: extract_mmdb()
	// cancella l'opzione della sonda dopo un download riuscito.
	faz_write_db( $db_path, str_repeat( 'y', 4096 ) . $marker );
	Geolocation::reset_runtime_cache();
	Geolocation::source_status();
	faz_is( get_option( 'faz_geo_source_probe' )['fingerprint'] !== $first, true, 'database sostituito -> fingerprint diverso, il verdetto vecchio non sopravvive' );

	// Define the optional extension only after the absence tests above have
	// run: PHP registers a conditional function when this branch is reached.
	if ( ! function_exists( 'geoip_country_code_by_name' ) ) {
		function geoip_country_code_by_name( $ip ) {
			$GLOBALS['__faz_geoip_calls'][] = $ip;
			$result = $GLOBALS['__faz_geoip_results'][ $ip ] ?? false;
			if ( $result instanceof \Throwable ) {
				throw $result;
			}
			return $result;
		}
	}
	$GLOBALS['__faz_geoip_calls']   = array();
	$GLOBALS['__faz_geoip_results'] = array();
	faz_reset();
	@unlink( $db_path );
	Geolocation::reset_runtime_cache();
	$GLOBALS['__faz_options']['faz_settings'] = array( 'geolocation' => array( 'geo_targeting' => true ) );
	require_once __DIR__ . '/../../frontend/includes/class-geo-runtime.php';

	echo "\nPHP GeoIP: extension availability is not a successful lookup\n";
	faz_is( Geolocation::has_country_source(), true, 'the frontend still detects the installed extension conservatively' );
	faz_is( $GLOBALS['__faz_geoip_calls'], array(), 'the frontend existence check does not probe countries' );
	faz_is( Geolocation::get_visitor_country(), '', 'failed extension lookups resolve no visitor country' );
	$status = Geolocation::source_status();
	faz_is( $status['configured'], true, 'installed extension remains configured when its database does not work' );
	faz_is( $status['working'], false, 'failed extension lookups are not reported as working' );
	faz_is( $status['reason'], 'php_geoip_probe_failed', 'the extension failure has its own repair instruction' );
	faz_is( \FazCookie\Frontend\Includes\Geo_Runtime::current_state()['mode'], 'degraded', 'failed extension lookups report degraded rather than routing' );

	foreach ( array( 'XX', 'invalid', new \Error( 'database unavailable' ) ) as $result ) {
		$GLOBALS['__faz_geoip_results'] = array( '8.8.8.8' => $result, '1.1.1.1' => $result );
		faz_is( Geolocation::source_status()['working'], false, 'unknown, invalid and throwing lookups do not establish capability' );
	}

	$GLOBALS['__faz_geoip_results'] = array( '1.1.1.1' => 'it' );
	$GLOBALS['__faz_geoip_calls']   = array();
	$status = Geolocation::source_status();
	faz_is( $status['source'], 'php_geoip', 'a successful extension lookup selects the extension' );
	faz_is( $status['working'], true, 'the second probe can prove the extension works' );
	faz_is( $GLOBALS['__faz_geoip_calls'], array( '8.8.8.8', '1.1.1.1' ), 'try both public addresses when the first has no result' );
	faz_is( \FazCookie\Frontend\Includes\Geo_Runtime::current_state()['mode'], 'routing', 'a working extension enables the routing status' );

	$GLOBALS['__faz_geoip_results'] = array();
	faz_write_db( $db_path, str_repeat( 'z', 2048 ) . $marker );
	Geolocation::reset_runtime_cache();
	$status = Geolocation::source_status( true );
	faz_is( $status['source'], 'mmdb', 'failed extension lookups still reach the MMDB probe' );
	faz_is( $status['working'], false, 'a broken MMDB is not masked by an installed extension' );
	faz_is( $status['reason'], 'probe_failed', 'report the MMDB repair when that database also fails' );

	// The existing cache contract is independent of the extension: a saved
	// verdict for this same file must still be used after extension failure.
	$probe = get_option( 'faz_geo_source_probe', array() );
	$probe['working'] = true;
	update_option( 'faz_geo_source_probe', $probe, false );
	faz_is( Geolocation::source_status()['working'], true, 'a cached working MMDB verdict remains available after extension failure' );
	faz_is( Geolocation::source_status( true )['working'], false, 'force still rechecks the MMDB after extension failure' );

	@unlink( $db_path );
	@rmdir( $db_dir );
	@rmdir( rtrim( ABSPATH, '/' ) );

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
