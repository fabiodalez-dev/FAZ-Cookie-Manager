<?php
/**
 * Standalone unit tests for the four georouting states.
 *
 * The state exists because one switch governs two different jobs: applying a
 * jurisdiction's rules (which always works, because an unresolved country
 * resolves to the most-protective fallback) and routing by country (which needs
 * a country source). Every screen described the second. These tests pin the
 * distinction so it cannot quietly collapse back into one.
 *
 * @package FazCookie\Tests\Unit
 */

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', sys_get_temp_dir() . '/faz-geo-mode-test/' );
	}
	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( $tag, $value ) {
			return $value;
		}
	}
	if ( ! function_exists( 'get_option' ) ) {
		function get_option( $name, $default = false ) {
			return isset( $GLOBALS['__faz_options'][ $name ] ) ? $GLOBALS['__faz_options'][ $name ] : $default;
		}
	}
	if ( ! function_exists( 'update_option' ) ) {
		function update_option( $name, $value, $autoload = null ) {
			$GLOBALS['__faz_options'][ $name ] = $value;
			return true;
		}
	}
	$GLOBALS['__faz_options'] = array();

	require_once __DIR__ . '/../../frontend/includes/class-geo-runtime.php';

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
		echo "      atteso: " . var_export( $expected, true ) . "\n";
		echo "      ottenuto: " . var_export( $actual, true ) . "\n";
	}

	echo "\n\033[1mGeo_Runtime::mode() — la matrice completa\033[0m\n";

	// Spento vince su tutto: un integratore che disattiva col filtro non deve
	// vedere "routing" solo perché un database è installato.
	faz_is( Geo_Runtime::mode( false, true, true ), Geo_Runtime::MODE_OFF, 'spento con sorgente funzionante -> off' );
	faz_is( Geo_Runtime::mode( false, false, false ), Geo_Runtime::MODE_OFF, 'spento senza sorgente -> off' );

	// Il caso di gran lunga più diffuso: acceso per difetto, nessun database.
	faz_is( Geo_Runtime::mode( true, false, false ), Geo_Runtime::MODE_BASELINE, 'acceso senza alcuna sorgente -> baseline' );

	// Il caso insidioso: chiave salvata, database mai scaricato o cancellato.
	// Deve essere distinguibile da "non configurato", perché la via d'uscita è
	// diversa: riparare invece di configurare.
	faz_is( Geo_Runtime::mode( true, true, false ), Geo_Runtime::MODE_DEGRADED, 'configurato ma non funzionante -> degraded' );

	faz_is( Geo_Runtime::mode( true, true, true ), Geo_Runtime::MODE_ROUTING, 'configurato e funzionante -> routing' );

	// Combinazione impossibile ma non inesprimibile: "funziona" senza essere
	// configurato non deve mai essere letto come routing.
	faz_is( Geo_Runtime::mode( true, false, true ), Geo_Runtime::MODE_BASELINE, 'funzionante ma non configurato -> baseline, mai routing' );

	echo "\n\033[1mLe costanti sono distinte\033[0m\n";
	$modes = array( Geo_Runtime::MODE_OFF, Geo_Runtime::MODE_BASELINE, Geo_Runtime::MODE_ROUTING, Geo_Runtime::MODE_DEGRADED );
	faz_is( count( array_unique( $modes ) ), 4, 'i quattro stati hanno quattro valori diversi' );

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
