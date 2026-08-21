<?php
/**
 * WordPress file sytstem API.
 *
 * @link       https://fabiodalez.it/
 * @since      3.0.0
 * @package    FazCookie\Includes
 */

namespace FazCookie\Includes;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * Handles admin notices for the plugin.
 */
class Notice {

	/**
	 * Instance of the current class
	 *
	 * @var object
	 */
	private static $instance;

	/**
	 * Return the current instance of the class
	 *
	 * @return object
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}
	/**
	 * Holds all dismissed notices
	 *
	 * @access public
	 * @since 3.0.0
	 * @var array $notices Array of dismissed notices.
	 */
	public $notices;
	/**
	 * Primary class constructor.
	 *
	 * @access public
	 * @since 3.0.0
	 */
	public function __construct() {

		// Populate $notices.
		$this->notices = $this->get_dismissed();
		foreach ( $this->notices as $notice => $timeout ) {
			if ( $timeout && $timeout < time() ) {
				$this->undismiss( $notice );
			}
		}
	}

	/**
	 * Checks if a given notice has been dismissed or not
	 *
	 * @since 6.0.0
	 * @param string $notice Programmatic Notice Name.
	 * @return boolean  Notice Dismissed
	 */
	public function is_dismissed( $notice ) {
		if ( ! isset( $this->notices[ $notice ] ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Marks the given notice as dismissed
	 *
	 * @since 3.0.0
	 * @param string  $notice Programmatic Notice Name.
	 * @param integer $expiry Notice expiry.
	 * @return void
	 */
	public function dismiss( $notice, $expiry = 0 ) {
		// Coerce before the strict 0-check: callers pass request input here, and
		// `time() + $expiry` below is a TypeError on a non-numeric value. The
		// cast also keeps the string '0' meaning "dismiss permanently" instead
		// of slipping past `0 !== $expiry` and expiring the dismissal instantly.
		$expiry    = is_scalar( $expiry ) ? (int) $expiry : 0;
		$dismissed = $this->get_dismissed();
		if ( 0 !== $expiry ) {
			$dismissed[ $notice ] = time() + $expiry;
		} else {
			$dismissed[ $notice ] = false;
		}
		update_option( 'faz_admin_notices', $dismissed, false );
	}


	/**
	 * Marks a notice as not dismissed
	 *
	 * @access public
	 * @since 6.0.0
	 *
	 * @param string $notice Programmatic Notice Name.
	 * @return void
	 */
	public function undismiss( $notice ) {
		unset( $this->notices[ $notice ] );
		update_option( 'faz_admin_notices', $this->notices, false );
	}

	/**
	 * Add notice
	 *
	 * @param string $notice Notice ID.
	 * @param array  $options Notice options.
	 * @return void
	 */
	public function add( $notice, $options = array() ) {
		$options = wp_parse_args(
			$options,
			array(
				'dismissible' => true,
				'type'        => 'default',
				'expiration'  => 0, // Default 0 (no expiration).
				'message'     => '',
			)
		);
		if ( isset( $this->notices[ $notice ] ) ) {
			unset( $this->notices[ $notice ] );
		} else {
			$this->notices[ $notice ] = $options;
		}
	}

	/**
	 * Get all the notices.
	 *
	 * @return array
	 */
	public function get() {
		return $this->notices;
	}

	/**
	 * Get dismissed notices
	 *
	 * @return array
	 */
	public function get_dismissed() {
		return get_option( 'faz_admin_notices', array() );
	}
}
