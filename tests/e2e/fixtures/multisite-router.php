<?php
/**
 * PHP built-in-server router for a subdirectory WordPress multisite.
 *
 * Apache/nginx rewrite `/child/wp-admin/...` to the shared core file while
 * preserving REQUEST_URI so WordPress can resolve the child blog. php -S has
 * no .htaccess support, so the isolated E2E network needs that small mapping.
 */

$root = (string) $_SERVER['DOCUMENT_ROOT'];
$path = (string) parse_url( isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH );

if ( is_file( $root . $path ) ) {
	return false;
}

// Map child-site requests for shared WordPress core/assets to their physical
// root path. Keep REQUEST_URI untouched: multisite derives the blog path from
// it, while SCRIPT_NAME/PHP_SELF must name the PHP file actually executed.
if ( preg_match( '#^/[^/]+/(wp-(?:admin|includes|content)/.+|wp-login\.php|wp-cron\.php)$#', $path, $matches ) ) {
	$mapped = '/' . $matches[1];
	if ( is_file( $root . $mapped ) ) {
		if ( '.php' === strtolower( substr( $mapped, -4 ) ) ) {
			$_SERVER['SCRIPT_NAME']     = $mapped;
			$_SERVER['PHP_SELF']       = $mapped;
			$_SERVER['SCRIPT_FILENAME'] = $root . $mapped;
			require $root . $mapped;
			return true;
		}
		readfile( $root . $mapped );
		return true;
	}
}

require $root . '/index.php';
return true;
