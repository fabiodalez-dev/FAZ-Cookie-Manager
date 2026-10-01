<?php
/**
 * Standalone unit tests for reachable-ruleset coverage.
 *
 * The admin describes locked controls with a count: "every rule set that can
 * reach a visitor requires this". That sentence was answered against the whole
 * catalogue, so a site where exactly one rule set is ever applied was told that
 * 47 jurisdictions demanded the control. The lock was right; the number was
 * answering a different question.
 *
 * @package FazCookie\Tests\Unit
 */

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', sys_get_temp_dir() . '/faz-coverage-test/' );
	}
	require_once __DIR__ . '/../../admin/modules/geo-routing/includes/class-ruleset-loader.php';
}

namespace FazCookie\Admin\Modules\Geo_Routing\Includes {
	/**
	 * Loader double: a fixed catalogue, so the assertions describe the LOGIC and
	 * not whichever rule sets happen to ship today. The real catalogue is
	 * counted, never hardcoded, by the test below that uses it.
	 */
	class Loader_Double extends Ruleset_Loader {
		public $catalogue = array();
		public $index     = array();
		public function __construct() {}
		public function list_all() {
			return array_keys( $this->catalogue );
		}
		public function load_ruleset( $id ) {
			return isset( $this->catalogue[ $id ] ) ? $this->catalogue[ $id ] : null;
		}
		public function load_index() {
			return $this->index;
		}
		public $us_regions = array();
		public $regions    = array();
		public function load_us_regions() {
			return $this->us_regions;
		}
		public function load_regions() {
			return $this->regions;
		}
	}
}

namespace {
	use FazCookie\Admin\Modules\Geo_Routing\Includes\Loader_Double;

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

	if ( ! function_exists( 'faz_country_in_regions' ) ) {
		/**
		 * The real helper, reduced to the regions these cases use. Note that no
		 * EU ruleset id contains the string 'eu' — which is exactly why matching
		 * ids by substring was wrong and this map exists.
		 */
		function faz_country_in_regions( $country, $regions ) {
			$map = array(
				'eu' => array( 'IE', 'DE', 'FR' ),
				'uk' => array( 'GB' ),
				'us' => array( 'US' ),
				'jp' => array( 'JP' ),
			);
			foreach ( (array) $regions as $region ) {
				$region = strtolower( (string) $region );
				if ( isset( $map[ $region ] ) && in_array( strtoupper( $country ), $map[ $region ], true ) ) {
					return true;
				}
				if ( strtoupper( $region ) === strtoupper( $country ) ) {
					return true;
				}
			}
			return false;
		}
	}

	$req = array( 'ui' => array( 'revisit_widget_required' => true ) );
	$loader            = new Loader_Double();
	// load_index() returns the country map itself, not a wrapper around it.
	$loader->index     = array(
		'IE' => 'gdpr-ireland',
		'DE' => 'gdpr-germany',
		'GB' => 'gdpr-ireland',
		'US' => 'ccpa-california',
		'JP' => 'appi-japan',
		'CA' => 'pipeda-canada',
	);
	// Sub-national regimes: Quebec rides on Canada, California on the US. The
	// `_comment` key is the one the real index carries and the loader must skip.
	$loader->regions    = array(
		'_comment' => 'ignored',
		'CA-QC'    => 'law25-quebec',
	);
	$loader->us_regions = array(
		'_comment' => 'ignored',
		'US-CA'    => 'ccpa-california',
	);
	$loader->catalogue = array(
		'fallback-gdpr-most-protective' => $req,
		'gdpr-ireland'                  => $req,
		'gdpr-germany'                  => $req,
		'ccpa-california'               => $req,
		'appi-japan'                    => $req,
		'law25-quebec'                  => $req,
		'pipeda-canada'                 => $req,
	);

	echo "\n\033[1mreachable_ruleset_ids() — quali set possono raggiungere un visitatore\033[0m\n";

	faz_is( $loader->reachable_ruleset_ids( 'off' ), array(), 'spento -> nessun set' );

	// Il punto di tutto l'intervento: senza sorgente paese si applica un set
	// solo, quindi la copertura deve dire 1, non l'intero catalogo.
	faz_is(
		$loader->reachable_ruleset_ids( 'baseline' ),
		array( 'fallback-gdpr-most-protective' ),
		'baseline -> solo il fallback'
	);
	faz_is(
		$loader->reachable_ruleset_ids( 'degraded' ),
		array( 'fallback-gdpr-most-protective' ),
		'degraded si comporta come baseline'
	);

	// target_regions NON restringe quale set si applica: restringe quando il
	// banner si nasconde. Con show_banner un visitatore statunitense riceve
	// davvero il set statunitense, quindi l'intero catalogo è raggiungibile.
	$show = array( 'geolocation' => array( 'default_behavior' => 'show_banner', 'target_regions' => array( 'eu', 'uk' ) ) );
	$catalogue_size = count( $loader->catalogue );
	faz_is(
		count( $loader->reachable_ruleset_ids( 'routing', $show ) ),
		$catalogue_size,
		'routing + show_banner -> tutto il catalogo, anche con target eu/uk'
	);

	// Solo con no_banner i visitatori fuori target non vedono nulla, e allora
	// il sottoinsieme ha senso. Il fallback resta sempre dentro: paese
	// sconosciuto, VPN e lookup falliti ci cadono comunque.
	$hide = array( 'geolocation' => array( 'default_behavior' => 'no_banner', 'target_regions' => array( 'eu' ) ) );
	$sub  = $loader->reachable_ruleset_ids( 'routing', $hide );
	faz_is( in_array( 'fallback-gdpr-most-protective', $sub, true ), true, 'no_banner -> il fallback resta sempre raggiungibile' );
	faz_is( in_array( 'ccpa-california', $sub, true ), false, 'no_banner + target eu -> il set californiano non è raggiungibile' );
	// CONTROLLO CHIAVE: nessun id dei set GDPR contiene la stringa 'eu'. Un
	// confronto per sottostringa li perderebbe tutti e il conteggio
	// nasconderebbe un obbligo di legge invece di esagerarlo.
	faz_is( in_array( 'gdpr-ireland', $sub, true ), true, 'target eu -> i set GDPR sono raggiungibili benché nessun id contenga "eu"' );
	faz_is( in_array( 'gdpr-germany', $sub, true ), true, 'target eu -> anche il set tedesco' );

	// Targeting illeggibile: meglio sovrastimare un requisito che nasconderne uno.
	$empty = array( 'geolocation' => array( 'default_behavior' => 'no_banner', 'target_regions' => array() ) );
	faz_is( count( $loader->reachable_ruleset_ids( 'routing', $empty ) ), $catalogue_size, 'no_banner senza regioni -> tutto il catalogo (fail-safe)' );

	echo "\n\033[1mRegimi subnazionali: seguono il paese che li contiene\033[0m\n";

	// Il ramo subnazionale di reachable_ruleset_ids() non era coperto da nulla:
	// i due accessori restituivano sempre array vuoti, quindi il ciclo non
	// girava mai e rimuoverlo non avrebbe fatto fallire alcun test.
	$ca     = array( 'geolocation' => array( 'default_behavior' => 'no_banner', 'target_regions' => array( 'ca' ) ) );
	$ca_ids = $loader->reachable_ruleset_ids( 'routing', $ca );
	faz_is( in_array( 'pipeda-canada', $ca_ids, true ), true, 'target ca -> il set federale canadese è raggiungibile' );
	faz_is( in_array( 'law25-quebec', $ca_ids, true ), true, 'target ca -> anche il Quebec, regime subnazionale di CA' );
	faz_is( in_array( 'ccpa-california', $ca_ids, true ), false, 'target ca -> la California NON entra: US non è nel target' );

	// La direzione opposta, perché i casi sopra passerebbero anche se il ciclo
	// aggiungesse tutto indiscriminatamente.
	$eu_only = $loader->reachable_ruleset_ids( 'routing', $hide );
	faz_is( in_array( 'law25-quebec', $eu_only, true ), false, 'target eu -> il Quebec non è raggiungibile' );

	$us     = array( 'geolocation' => array( 'default_behavior' => 'no_banner', 'target_regions' => array( 'us' ) ) );
	$us_ids = $loader->reachable_ruleset_ids( 'routing', $us );
	faz_is( in_array( 'ccpa-california', $us_ids, true ), true, 'target us -> la California è raggiungibile' );
	faz_is( in_array( 'law25-quebec', $us_ids, true ), false, 'target us -> il Quebec no' );

	echo "\n\033[1mrequirement_coverage() rispetta l'insieme ricevuto\033[0m\n";

	$all = $loader->requirement_coverage( 'ui.revisit_widget_required' );
	faz_is( $all['total'], $catalogue_size, 'senza insieme -> conta tutto il catalogo' );
	faz_is( $all['required'], $catalogue_size, 'tutti i set del doppio pretendono il widget' );

	$base = $loader->requirement_coverage( 'ui.revisit_widget_required', $loader->reachable_ruleset_ids( 'baseline' ) );
	// CONTROLLO CHIAVE: se il restringimento sparisce, qui torna 5 e il test
	// diventa rosso. È l'unica asserzione che impedisce al messaggio dell'admin
	// di tornare a citare numeri che non riguardano l'utente.
	faz_is( $base['total'], 1, 'baseline -> total 1, non l\'intero catalogo' );
	faz_is( $base['required'], 1, 'baseline -> required 1' );
	faz_is( $base['required'] === $base['total'], true, 'baseline -> il lucchetto resta giustificato (all)' );

	// La memoizzazione non deve servire la risposta di un insieme a un altro.
	$again = $loader->requirement_coverage( 'ui.revisit_widget_required' );
	faz_is( $again['total'], $catalogue_size, 'la memo non contamina insiemi diversi' );

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
