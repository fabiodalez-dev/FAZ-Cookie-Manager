<?php
/**
 * WordPress stubs for loading uninstall.php in a standalone PHP process.
 *
 * Driven by globals set by the test before the include:
 *   faz_t_multisite    bool              is_multisite() answer
 *   faz_t_blog_opt_in  array<int,bool>   blog_id => remove_data_on_uninstall
 *   faz_t_current_blog int               blog switch_to_blog() points at
 *
 * Records:
 *   faz_t_meta_deletes   list of delete_metadata() calls
 *   faz_t_option_deletes number of delete_option() calls
 *
 * @package FazCookie\Tests\Unit
 */

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
}

function is_multisite() {
	return ! empty( $GLOBALS['faz_t_multisite'] );
}

function get_current_blog_id() {
	return (int) $GLOBALS['faz_t_current_blog'];
}

function faz_t_settings_for_blog( $blog_id ) {
	$opt_in = $GLOBALS['faz_t_blog_opt_in'][ (int) $blog_id ] ?? false;
	return array( 'general' => array( 'remove_data_on_uninstall' => (bool) $opt_in ) );
}

function get_option( $name, $default = false ) {
	if ( 'faz_settings' === $name ) {
		return faz_t_settings_for_blog( $GLOBALS['faz_t_current_blog'] );
	}
	return $default;
}

function get_blog_option( $blog_id, $name, $default = false ) {
	if ( 'faz_settings' === $name ) {
		return faz_t_settings_for_blog( $blog_id );
	}
	return $default;
}

function get_sites( $args = array() ) {
	$ids    = array_keys( $GLOBALS['faz_t_blog_opt_in'] );
	$offset = (int) ( $args['offset'] ?? 0 );
	$number = (int) ( $args['number'] ?? 100 );
	return array_slice( $ids, $offset, $number );
}

function switch_to_blog( $blog_id ) {
	$GLOBALS['faz_t_current_blog'] = (int) $blog_id;
	return true;
}

function restore_current_blog() {
	$GLOBALS['faz_t_current_blog'] = 1;
	return true;
}

function delete_option( $name ) {
	unset( $name );
	$GLOBALS['faz_t_option_deletes']++;
	return true;
}

function delete_transient( $key ) {
	unset( $key );
	return true;
}

function delete_site_transient( $key ) {
	unset( $key );
	return true;
}

function get_posts( $args = array() ) {
	unset( $args );
	return array();
}

function wp_delete_post( $id, $force = false ) {
	unset( $id, $force );
	return true;
}

function absint( $v ) {
	return abs( (int) $v );
}

function trailingslashit( $s ) {
	return rtrim( $s, '/\\' ) . '/';
}

function wp_upload_dir( $time = null, $create_dir = true ) {
	unset( $time, $create_dir );
	return array( 'basedir' => sys_get_temp_dir() . '/faz-uninstall-test-no-such-dir-' . getmypid() );
}

function wp_delete_file( $file ) {
	unset( $file );
	return true;
}

function delete_metadata( $meta_type, $object_id, $meta_key, $meta_value = '', $delete_all = false ) {
	$GLOBALS['faz_t_meta_deletes'][] = array(
		'type'       => $meta_type,
		'object_id'  => $object_id,
		'key'        => $meta_key,
		'value'      => $meta_value,
		'delete_all' => $delete_all,
	);
	return true;
}

class Faz_T_Uninstall_DB {
	public $prefix     = 'wp_';
	public $options    = 'wp_options';
	public $sitemeta   = 'wp_sitemeta';
	public $usermeta   = 'wp_usermeta';
	public $last_error = '';

	public function prepare( $sql, ...$args ) {
		unset( $args );
		return $sql;
	}

	public function esc_like( $s ) {
		return $s;
	}

	public function query( $sql ) {
		unset( $sql );
		return 0;
	}

	public function get_results( $sql, $output = 'OBJECT' ) {
		unset( $sql, $output );
		return array();
	}

	public function get_col( $sql ) {
		unset( $sql );
		return array();
	}
}

$GLOBALS['wpdb'] = new Faz_T_Uninstall_DB();
