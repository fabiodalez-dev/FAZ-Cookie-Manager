<?php
/**
 * Scanner debug logger.
 *
 * When Scanner Debug Mode is enabled in settings, logs every categorization
 * decision during a scan for troubleshooting. Logs are stored in the
 * wp_options table and capped at 5 sessions.
 *
 * @package FazCookie
 */

namespace FazCookie\Admin\Modules\Scanner\Includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Scanner_Logger — singleton debug logger for the cookie scanner.
 *
 * @class       Scanner_Logger
 * @version     1.0.0
 * @package     FazCookie
 */
class Scanner_Logger {

	/**
	 * Singleton instance.
	 *
	 * @var Scanner_Logger|null
	 */
	private static $instance = null;

	/**
	 * Whether debug logging is enabled.
	 *
	 * @var bool
	 */
	private $enabled = false;

	/**
	 * Log entries for the current session.
	 *
	 * @var array
	 */
	private $log = array();

	/**
	 * Microtime when the session started.
	 *
	 * @var float
	 */
	private $start_time = 0;

	/**
	 * Option key for storing debug logs.
	 *
	 * @var string
	 */
	const LOG_OPTION = 'faz_scanner_debug_log';

	/**
	 * Maximum number of log sessions to keep.
	 *
	 * @var int
	 */
	const MAX_SESSIONS = 5;

	/**
	 * Get the singleton instance.
	 *
	 * @return Scanner_Logger
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor — use get_instance().
	 */
	private function __construct() {
		$this->enabled = $this->read_enabled_setting();
	}

	/**
	 * Check whether scanner debug mode is enabled in settings.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		return $this->enabled;
	}

	/**
	 * Read the debug_mode flag from faz_settings.
	 *
	 * @return bool
	 */
	private function read_enabled_setting() {
		$settings = \FazCookie\Admin\Modules\Settings\Includes\Settings::get_instance();
		return (bool) $settings->get( 'scanner', 'debug_mode' );
	}

	/**
	 * Start a new log session.
	 *
	 * @param string $context Human-readable context label (e.g. "Browser scan import").
	 * @return void
	 */
	public function start( $context ) {
		if ( ! $this->enabled ) {
			return;
		}

		$this->start_time = microtime( true );
		$this->log        = array();

		$this->log( '=== Scanner Debug Log ===' );
		$this->log( 'Context: ' . $context );
		$this->log( 'Time: ' . current_time( 'mysql' ) );
		$this->log( 'PHP: ' . PHP_VERSION . ' | WP: ' . get_bloginfo( 'version' ) );
		$this->log( 'Memory limit: ' . ini_get( 'memory_limit' ) );

		$default_lang = function_exists( 'faz_default_language' ) ? faz_default_language() : 'en';
		$this->log( 'Default language: ' . $default_lang );

		$selected = function_exists( 'faz_selected_languages' ) ? faz_selected_languages() : array( 'en' );
		$this->log( 'Selected languages: ' . implode( ', ', $selected ) );
	}

	/**
	 * Append a log entry.
	 *
	 * @param string     $message Log message.
	 * @param mixed|null $data    Optional data to append (arrays/objects are JSON-encoded).
	 * @return void
	 */
	public function log( $message, $data = null ) {
		if ( ! $this->enabled ) {
			return;
		}

		$elapsed = round( ( microtime( true ) - $this->start_time ) * 1000 );
		$entry   = sprintf( '[%dms] %s', $elapsed, $message );

		if ( null !== $data ) {
			if ( is_array( $data ) || is_object( $data ) ) {
				$entry .= ' → ' . wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
			} else {
				$entry .= ' → ' . (string) $data;
			}
		}

		$this->log[] = $entry;
	}

	/**
	 * Finish the current session and persist the log.
	 *
	 * @return void
	 */
	public function finish() {
		if ( ! $this->enabled ) {
			return;
		}

		$this->log( '=== Scan complete (' . round( microtime( true ) - $this->start_time, 2 ) . 's) ===' );

		$logs   = get_option( self::LOG_OPTION, array() );
		$logs[] = array(
			'date'    => current_time( 'mysql' ),
			'entries' => $this->log,
		);

		// Keep only the most recent sessions.
		if ( count( $logs ) > self::MAX_SESSIONS ) {
			$logs = array_slice( $logs, - self::MAX_SESSIONS );
		}

		update_option( self::LOG_OPTION, $logs, false );
	}

	/**
	 * Get all stored log sessions.
	 *
	 * @return array
	 */
	public function get_logs() {
		return get_option( self::LOG_OPTION, array() );
	}

	/**
	 * Delete all stored log sessions.
	 *
	 * @return void
	 */
	public function clear_logs() {
		delete_option( self::LOG_OPTION );
	}

	/**
	 * Get the latest log session as plain text.
	 *
	 * @return string
	 */
	public function get_latest_log_text() {
		$logs = $this->get_logs();
		if ( empty( $logs ) ) {
			return 'No scan logs available.';
		}
		$latest = end( $logs );
		return implode( "\n", $latest['entries'] );
	}

	/**
	 * Get all log sessions as plain text.
	 *
	 * @return string
	 */
	public function get_all_logs_text() {
		$logs = $this->get_logs();
		if ( empty( $logs ) ) {
			return 'No scan logs available.';
		}

		$output = array();
		foreach ( $logs as $i => $session ) {
			$output[] = sprintf( '--- Session %d (%s) ---', $i + 1, $session['date'] );
			$output[] = implode( "\n", $session['entries'] );
			$output[] = '';
		}
		return implode( "\n", $output );
	}
}
