<?php
/** Load the production helpers without booting the WordPress plugin. */
$source = file_get_contents( dirname( __DIR__, 3 ) . '/faz-cookie-manager.php' );
foreach ( array( 'faz_consent_site_bound', 'faz_consent_cookie_name', 'faz_consent_cookie_names', 'faz_pmp_necessary_only', 'faz_scope_fingerprint', 'faz_parse_consent_cookie', 'faz_get_consent_revision', 'faz_is_consent_cookie_stale', 'faz_read_scoped_cookie_value', 'faz_consent_cookie_scope_changed', 'faz_get_valid_consent_cookie' ) as $name ) {
	if ( ! function_exists( $name ) ) {
		if ( ! preg_match( '/^function ' . $name . '\(.*?^}/ms', $source, $match ) ) {
			throw new RuntimeException( 'Missing production helper: ' . $name );
		}
		eval( $match[0] );
	}
}
