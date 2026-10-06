<?php
/** Banner readiness uses the real selector without resolving language. */
namespace FazCookie\Includes {
	class Base_Controller {}
}
namespace FazCookie\Admin\Modules\Banners\Includes {
	class Banner {
		public $id;
		public $language;
		public function __construct( $id ) { $this->id = $id; }
		public function set_language( $language ) { $this->language = $language; }
	}
}
namespace {
	define( 'ABSPATH', __DIR__ );
	$GLOBALS['faz_language_reads'] = 0;
	function faz_current_language() { ++$GLOBALS['faz_language_reads']; return 'it'; }
	require_once dirname( __DIR__, 2 ) . '/admin/modules/banners/includes/class-controller.php';
	class Faz_Readiness_Controller extends \FazCookie\Admin\Modules\Banners\Includes\Controller {
		public $items = array();
		public function get_items() { return $this->items; }
	}
	$passed = $failed = 0;
	function readiness_same( $actual, $expected, $label ) {
		global $passed, $failed;
		if ( $actual === $expected ) { ++$passed; echo "PASS $label\n"; }
		else { ++$failed; echo "FAIL $label\n"; }
	}
	function readiness_row( $id, $law, $targets = array(), $active = 1, $priority = 0, $default = 0 ) {
		return (object) array( 'banner_id' => $id, 'status' => $active, 'target_countries' => $targets,
			'priority' => $priority, 'banner_default' => $default, 'settings' => array( 'settings' => array( 'applicableLaw' => $law ) ) );
	}
	$controller = new Faz_Readiness_Controller();
	readiness_same( $controller->has_active_banner_for_law( 'gdpr' ), false, 'no banners cannot satisfy readiness' );
	$controller->items = array( readiness_row( 1, 'gdpr', array(), 0 ), readiness_row( 2, 'ccpa' ) );
	readiness_same( $controller->has_active_banner_for_law( 'gdpr' ), false, 'disabled GDPR and active CCPA do not satisfy GDPR readiness' );
	$controller->items[] = readiness_row( 3, 'gdpr', array( 'IT' ), 1, 100, 1 );
	readiness_same( $controller->has_active_banner_for_law( 'gdpr' ), false, 'a targeted default is not a globally applicable GDPR banner' );
	readiness_same( $controller->has_active_banner_for_law( 'gdpr', 'IT' ), true, 'the targeted banner remains available in its country' );
	$controller->items[] = readiness_row( 4, 'gdpr', array(), 1, 10 );
	$controller->items[] = readiness_row( 5, 'gdpr', array(), 1, 20 );
	readiness_same( $controller->has_active_banner_for_law( 'gdpr' ), true, 'an active global GDPR banner satisfies readiness' );
	readiness_same( $GLOBALS['faz_language_reads'], 0, 'all readiness checks avoid language lookup and bootstrap recursion' );
	$global = $controller->get_active_banner_for_law( 'gdpr' );
	readiness_same( $global->id, 5, 'normal rendering uses the same priority selection' );
	readiness_same( $global->language, 'it', 'normal rendering still resolves language' );
	$targeted = $controller->get_active_banner_for_law( 'gdpr', 'IT' );
	readiness_same( $targeted->id, 3, 'country-targeted selection still outranks the global fallback' );
	echo "{$passed} passed, {$failed} failed\n";
	exit( $failed ? 1 : 0 );
}
