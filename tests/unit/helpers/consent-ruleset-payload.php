<?php
/** Export real PHP runtime decisions for the cross-language consent matrix. */
define( 'ABSPATH', __DIR__ );
require dirname( __DIR__, 3 ) . '/frontend/includes/class-geo-runtime.php';
use FazCookie\Frontend\Includes\Geo_Runtime;
$payloads = array();
foreach ( glob( dirname( __DIR__, 3 ) . '/admin/modules/geo-routing/rulesets/*.json' ) as $path ) {
    if ( '_' === basename( $path )[0] ) { continue; }
    $ruleset = json_decode( file_get_contents( $path ), true, 512, JSON_THROW_ON_ERROR );
    $categories = array();
    foreach ( $ruleset['ui']['default_categories'] as $slug => $state ) {
        $categories[] = array(
            'slug' => $slug,
            'isNecessary' => 'necessary' === $slug,
            'ccpaDoNotSell' => 'marketing' === $slug,
            'defaultFromRuleset' => Geo_Runtime::is_ruleset_default( $ruleset, $slug ),
            'requiresSeparateOptIn' => Geo_Runtime::requires_separate_optin( $ruleset, $slug ),
            'defaultConsent' => Geo_Runtime::default_consent( $ruleset, $slug, false, 'marketing' === $slug, false ),
            'cookies' => array(),
        );
    }
    $payloads[] = array( 'ruleset' => $ruleset, 'law' => Geo_Runtime::model_to_law( $ruleset ), 'categories' => $categories );
}
echo json_encode( $payloads, JSON_THROW_ON_ERROR );
