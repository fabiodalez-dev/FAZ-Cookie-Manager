<?php
/**
 * Pure runtime probe for the regulatory compliance matrix E2E suite.
 *
 * Reads scenarios from STDIN and exercises the production resolver and
 * Geo_Runtime helpers without booting WordPress or mutating a test database.
 *
 * @package FazCookie\Tests\E2E
 */

$root = dirname( __DIR__, 3 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', $root . '/' );
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) { // phpcs:ignore WordPress.NamingConventions
		unset( $tag );
		return $value;
	}
}

require_once $root . '/admin/modules/geo-routing/includes/class-ruleset-resolver.php';
require_once $root . '/frontend/includes/class-geo-runtime.php';

use FazCookie\Admin\Modules\Geo_Routing\Includes\Ruleset_Resolver;
use FazCookie\Frontend\Includes\Geo_Runtime;

$scenarios = json_decode( (string) stream_get_contents( STDIN ), true );
if ( ! is_array( $scenarios ) ) {
	fwrite( STDERR, "Expected a JSON scenario array on STDIN.\n" );
	exit( 1 );
}

$rulesets_dir = $root . '/admin/modules/geo-routing/rulesets';
$index         = json_decode( (string) file_get_contents( $rulesets_dir . '/_index.json' ), true );
$rulesets      = array();

foreach ( glob( $rulesets_dir . '/*.json' ) ?: array() as $file ) {
	if ( '_index.json' === basename( $file ) ) {
		continue;
	}
	$ruleset = json_decode( (string) file_get_contents( $file ), true );
	if ( is_array( $ruleset ) && isset( $ruleset['id'] ) ) {
		$rulesets[ $ruleset['id'] ] = $ruleset;
	}
}

$gcm_baseline = array(
	'default_settings' => array(
		array(
			'ad_storage'              => 'granted',
			'analytics_storage'       => 'granted',
			'ad_user_data'            => 'granted',
			'ad_personalization'      => 'granted',
			'functionality_storage'   => 'granted',
			'personalization_storage' => 'granted',
			'security_storage'        => 'granted',
			'marketing'               => 'granted',
			'analytics'               => 'granted',
			'functional'              => 'granted',
			'necessary'               => 'granted',
			'regions'                 => 'All',
		),
	),
);

$result = array();
foreach ( $scenarios as $scenario ) {
	$id      = isset( $scenario['id'] ) ? (string) $scenario['id'] : '';
	$country = isset( $scenario['country'] ) ? (string) $scenario['country'] : '';
	$region  = isset( $scenario['region'] ) ? (string) $scenario['region'] : '';
	$vpn     = ! empty( $scenario['vpn'] );
	$ruleset = isset( $rulesets[ $id ] ) ? $rulesets[ $id ] : null;

	if ( null === $ruleset ) {
		$result[ $id ] = array( 'error' => 'ruleset_not_found' );
		continue;
	}

	$resolved = Ruleset_Resolver::resolve(
		$country,
		$region,
		$vpn,
		array(),
		isset( $index['countries'] ) ? $index['countries'] : array(),
		isset( $index['_us_regions'] ) ? $index['_us_regions'] : array(),
		isset( $index['_default_fallback'] ) ? $index['_default_fallback'] : 'fallback-gdpr-most-protective',
		array_keys( $rulesets ),
		isset( $index['_regions'] ) ? $index['_regions'] : array()
	);

	$categories = array( 'necessary', 'functional', 'analytics', 'marketing', 'profiling' );
	$defaults   = array();
	$consent    = array();
	foreach ( $categories as $slug ) {
		$defaults[ $slug ] = Geo_Runtime::category_default( $ruleset, $slug );
		$consent[ $slug ]  = Geo_Runtime::default_consent(
			$ruleset,
			$slug,
			false,
			true,
			true
		);
	}

	$mapped_gcm = Geo_Runtime::apply_cmv2_to_gcm( $ruleset, $gcm_baseline );

	$result[ $id ] = array(
		'resolved'       => $resolved,
		'model_to_law'   => Geo_Runtime::model_to_law( $ruleset ),
		'category_bool'  => $defaults,
		'default_consent' => $consent,
		'gcm_row'        => isset( $mapped_gcm['default_settings'][0] )
			? $mapped_gcm['default_settings'][0]
			: null,
	);
}

echo json_encode( $result, JSON_UNESCAPED_SLASHES );
