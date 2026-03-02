<?php
/**
 * Utility functions class
 *
 * @link       https://fabiodalez.it/
 * @since      3.0.0
 *
 * @author     Sarath GP <sarath.gp@mozilor.com>
 * @package    FazCookie\Includes
 */

use FazCookie\Includes\Filesystem;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}
if ( ! function_exists( 'faz_parse_url' ) ) {
	/**
	 * Return parsed URL
	 *
	 * @param string $url URL string to be parsed.
	 * @return array URL parts.
	 */
	function faz_parse_url( $url ) {
		return function_exists( 'wp_parse_url' )
			? wp_parse_url( $url )
			: parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
	}
}
if ( ! function_exists( 'faz_read_json_file' ) ) {
	/**
	 * Processes a json file from the specified path
	 * and returns an array with its contents, or a void array if none found.
	 *
	 * @since 3.0.0
	 *
	 * @param string $file_path Path to file. Empty if no file.
	 * @return array Contents from json file.
	 */
	function faz_read_json_file( $file_path = '' ) {
		$config = array();

		$file_system = Filesystem::get_instance();
		$json        = $file_system->get_contents( $file_path );
		if ( ! $json ) {
			return $config;
		}
		$decoded_file        = json_decode(
			$json,
			true
		);
		$json_decoding_error = json_last_error();
		if ( JSON_ERROR_NONE !== $json_decoding_error ) {
			return $config;
		}
		if ( is_array( $decoded_file ) ) {
			$config = $decoded_file;
		}
		return $config;
	}
}

if ( ! function_exists( 'faz_i18n_date' ) ) {
	/**
	 * Get localized date.
	 *
	 * @param string $date Date in time stamped format.
	 * @return string
	 */
	function faz_i18n_date( $date = '' ) {
		return date_i18n( 'd/m/Y g:i:s', $date );
	}
}
if ( ! function_exists( 'faz_is_admin_request' ) ) {
	/**
	 * Get localized date.
	 *
	 * @return boolean
	 */
	function faz_is_admin_request() {
		return is_admin() && ! faz_is_ajax_request();
	}
}
if ( ! function_exists( 'faz_is_ajax_request' ) ) {
	/**
	 * Get localized date.
	 *
	 * @return boolean
	 */
	function faz_is_ajax_request() {
		if ( function_exists( 'wp_doing_ajax' ) ) {
			return wp_doing_ajax();
		} else {
			return ( defined( 'DOING_AJAX' ) && DOING_AJAX );
		}

	}
}
if ( ! function_exists( 'faz_is_rest_request' ) ) {

	/**
	 * Check if a request is a rest request
	 *
	 * @return boolean
	 */
	function faz_is_rest_request() {
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return false;
		}
		$rest_prefix = trailingslashit( rest_get_url_prefix() );
		$request     = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : false;
		if ( ! $request ) {
			return false;
		}
		$is_rest_api_request = ( false !== strpos( $request, $rest_prefix ) );

		return apply_filters( 'faz_is_rest_api_request', $is_rest_api_request );
	}
}
if ( ! function_exists( 'faz_is_cloud_request' ) ) {

	/**
	 * Check if a request is a rest request
	 *
	 * @return boolean
	 */
	function faz_is_cloud_request() {
		return false;
	}
}
if ( ! function_exists( 'faz_array_search' ) ) {

	/**
	 * Get settings of element from banner properties by using the tag "data-faz-tag"
	 *
	 * @param array  $array Array to be searched.
	 * @param string $key Tag to be used for searching.
	 * @param string $value  Tag name.
	 * @return array
	 */
	function faz_array_search( $array = array(), $key = '', $value = '' ) {

		$results = array();
		if ( is_array( $array ) ) {
			if ( isset( $array[ $key ] ) && $array[ $key ] === $value ) {
				$results = $array;
			}
			foreach ( $array as $sub_array ) {
				$results = array_merge( $results, faz_array_search( $sub_array, $key, $value ) );
			}
		}
		return $results;
	}
}
if ( ! function_exists( 'faz_first_time_install' ) ) {

	/**
	 * Check if the plugin is activated for the first time.
	 *
	 * @return boolean
	 */
	function faz_first_time_install() {
		return (bool) get_site_transient( '_faz_first_time_install' ) || (bool) get_option( 'faz_first_time_activated_plugin' );
	}
}

if ( ! function_exists( 'faz_is_admin_page' ) ) {

	/**
	 * Check if the plugin is activated for the first time.
	 *
	 * @return boolean
	 */
	function faz_is_admin_page() {
		if ( ! is_admin() ) {
			return false;
		}
		if ( function_exists( 'get_current_screen' ) && ! empty( get_current_screen() ) ) {
			$screen = get_current_screen();
			$page   = isset( $screen->id ) ? $screen->id : false;
			if ( false !== strpos( $page, 'toplevel_page_faz-cookie-manager' ) ) {
				return true;
			}
			if ( ! empty( $screen->parent_base ) && false !== strpos( $screen->parent_base, 'faz-cookie-manager' ) ) {
				return true;
			}
		} else {
			$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		return false !== strpos( $page, 'faz-cookie-manager' );
	}
}

if ( ! function_exists( 'faz_is_front_end_request' ) ) {

	/**
	 * Check if request coming from front-end.
	 *
	 * @return boolean
	 */
	function faz_is_front_end_request() {
		if ( is_admin() || faz_is_rest_request() || faz_is_ajax_request() ) {
			return false;
		}
		return true;
	}
}
if ( ! function_exists( 'faz_disable_banner' ) ) {

	/**
	 * Check if request coming from front-end.
	 *
	 * @return boolean
	 */
	function faz_disable_banner() {
		global $wp_customize;
		if ( isset( $_GET['et_fb'] ) || isset( $_GET['et_fb'] ) || ( defined( 'ET_FB_ENABLED' ) && ET_FB_ENABLED ) //phpcs:ignore WordPress.Security.NonceVerification.Recommended
		|| isset( $_GET['elementor-preview'] ) || isset( $_POST['cs_preview_state'] ) || isset( $wp_customize ) ) //phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
		{
			return true;
		}
		return false;
	}
}
if ( ! function_exists( 'faz_missing_tables' ) ) {

	/**
	 * Check if request coming from front-end.
	 *
	 * @return array
	 */
	function faz_missing_tables() {
		return get_option( 'faz_missing_tables', array() );
	}
}
if ( ! function_exists( 'faz_verify_nonce' ) ) {
	/**
	 * Verify nonce.
	 *
	 * @return WP_Error|boolean
	 */
	function faz_verify_nonce( $request ) {
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error( 'fazcookie_rest_invalid_nonce', __( 'Invalid nonce. Please refresh the page and try again.', 'faz-cookie-manager' ), array( 'status' => 403 ) );
		}
		return true;
	}
}
