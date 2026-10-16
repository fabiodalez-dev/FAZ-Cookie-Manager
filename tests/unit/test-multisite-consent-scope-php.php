<?php
/** Cross-site replay must fail without invalidating ordinary single-site cookies. */
$GLOBALS['settings'] = array( 'banner_control' => array( 'subdomain_sharing' => true ) );
$GLOBALS['multisite'] = false;
$GLOBALS['blog'] = 1;
$GLOBALS['network'] = 1;
function get_option( $key, $default = false ) { return $GLOBALS['settings']; }
function is_multisite() { return $GLOBALS['multisite']; }
function get_current_network_id() { return $GLOBALS['network']; }
function get_current_blog_id() { return $GLOBALS['blog']; }
function wp_hash( $value, $scheme ) { return hash_hmac( 'sha256', $value, 'shared-network-salt' ); }
function absint( $v ) { return abs( (int) $v ); }
require __DIR__ . '/helpers/load-consent-scope.php';
$checks = 0;
function scope_check( $value, $label ) { global $checks; ++$checks; if ( ! $value ) { throw new RuntimeException( $label ); } }
$legacy = substr( wp_hash( 'main|gdpr', 'auth' ), 0, 32 );
scope_check( faz_scope_fingerprint( 'main', 'gdpr' ) === $legacy, 'single-site fingerprint unchanged' );
scope_check( ! faz_is_consent_cookie_stale( 'action:yes,analytics:yes' ), 'single-site legacy consent survives' );
$GLOBALS['multisite'] = true;
$first = faz_scope_fingerprint( 'main', 'gdpr' );
$cookie = 'action:yes,analytics:yes,__scope.banner:main,__scope.law:gdpr,__scope.fp:' . $first;
scope_check( ! faz_is_consent_cookie_stale( $cookie ), 'own consent accepted' );
$GLOBALS['blog'] = 2;
scope_check( faz_is_consent_cookie_stale( $cookie ), 'other site cannot replay consent' );
scope_check( faz_get_valid_consent_cookie( $cookie ) === '', 'all server consumers discard foreign cookie' );
$GLOBALS['blog'] = 1;
$GLOBALS['network'] = 2;
scope_check( faz_is_consent_cookie_stale( $cookie ), 'network id also binds consent' );
scope_check( faz_is_consent_cookie_stale( 'action:yes,analytics:yes' ), 'legacy shared cookies need fresh consent' );
scope_check( ! faz_is_consent_cookie_stale( 'source:pmp,action:auto,consent:no,analytics:no' ), 'necessary-only PMP state survives' );
scope_check( faz_is_consent_cookie_stale( 'source:pmp,action:yes,consent:yes,analytics:yes' ), 'PMP marker cannot preserve a grant' );
foreach (array('analytics:yes','svc.maps:yes','ck.maps.x:yes','custom:yes:123') as $grant) {
 scope_check(faz_is_consent_cookie_stale('source:pmp,action:auto,consent:no,necessary:yes,'.$grant), 'PMP cannot exempt optional grant '.$grant);
}
$GLOBALS['settings']['banner_control']['subdomain_sharing'] = false;
scope_check( faz_scope_fingerprint( 'main', 'gdpr' ) !== $legacy, 'subdirectory multisite is bound even without domain sharing' );
scope_check( faz_consent_cookie_name() === 'fazcookie-consent-n2-b1', 'cookie names isolate the current site' );
echo "ALL PASS ($checks multisite scope checks)\n";
