<?php
/**
 * Custom autoloader
 *
 *  @package FazCookie
 */

namespace FazCookie;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Custom class autoloader class
 */
class Autoloader {

	/**
	 * Autoloader function
	 *
	 * @return void
	 */
	public function register() {
		spl_autoload_register( array( __CLASS__, 'load_class' ) );
	}
	/**
	 * Custom Class Loader For Boiler Plate
	 *
	 * @param string $class_name Class names.
	 * @return void
	 */
	public static function load_class( $class_name ) {
		if ( false === strpos( $class_name, 'FazCookie' ) ) {
			return;
		}
		$file_parts = explode( '\\', $class_name );
		$namespace  = '';
		$file_name  = '';
		for ( $i = count( $file_parts ) - 1; $i > 0; $i-- ) {

			$current = strtolower( $file_parts[ $i ] );
			$current = str_ireplace( '_', '-', $current );
			if ( count( $file_parts ) - 1 === $i ) {
				$file_name = "class-$current.php";
			} else {
				$namespace = '/' . $current . $namespace;
			}
		}
		$filepath = dirname( __FILE__ ) . $namespace . '/' . $file_name;
		if ( file_exists( $filepath ) ) {
			require $filepath;
		}
	}
}
