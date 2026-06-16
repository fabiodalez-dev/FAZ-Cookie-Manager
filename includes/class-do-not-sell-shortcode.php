<?php
/**
 * CCPA/CPRA "Do Not Sell or Share My Personal Information" Shortcode — [faz_do_not_sell]
 *
 * Renders a CCPA opt-out form. On submission, logs the opt-out in the
 * consent_logs table (status = 'dnsmpi_optout') with a hashed IP address
 * and notifies the site admin via email.
 *
 * Usage: [faz_do_not_sell]
 *        [faz_do_not_sell title="Opt Out" button="Submit Request"]
 *
 * @package FazCookie\Includes
 */

namespace FazCookie\Includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Do_Not_Sell_Shortcode {

	use IP_Hasher;

	const AJAX_ACTION    = 'faz_dnsmpi_optout';
	const RESCIND_ACTION = 'faz_dnsmpi_rescind';
	const COOKIE_NAME    = 'fazcookie-dnsmpi';
	const COOKIE_DAYS    = 365;
	const STATUS_FIELD   = 'dnsmpi_optout';
	const STATUS_RESCIND = 'dns_rescinded';

	public function __construct() {
		add_shortcode( 'faz_do_not_sell', array( $this, 'render' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'handle_optout' ) );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, array( $this, 'handle_optout' ) );
		add_action( 'wp_ajax_' . self::RESCIND_ACTION, array( $this, 'handle_rescind' ) );
		add_action( 'wp_ajax_nopriv_' . self::RESCIND_ACTION, array( $this, 'handle_rescind' ) );
		// Enqueue the submit handler unconditionally: page builders may inject
		// shortcode HTML client-side, so has_shortcode() is unreliable.
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_assets' ) );
	}

	/**
	 * Enqueue DNSMPI assets on every frontend page.
	 */
	public function maybe_enqueue_assets() {
		if ( is_admin() ) {
			return;
		}
		$this->enqueue_dnsmpi_assets();
	}

	/**
	 * Register and enqueue the DNSMPI form handler script.
	 */
	private function enqueue_dnsmpi_assets() {
		if ( ! wp_script_is( 'faz-dnsmpi-form', 'registered' ) ) {
			wp_register_script(
				'faz-dnsmpi-form',
				FAZ_PLUGIN_URL . 'frontend/js/faz-dnsmpi.js',
				array(),
				FAZ_VERSION,
				true
			);
			wp_localize_script(
				'faz-dnsmpi-form',
				'fazDnsmpiConfig',
				array(
					'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
					'rescindAction'  => self::RESCIND_ACTION,
					'successMsg'     => __( 'Your opt-out request has been received. We will not sell or share your personal information.', 'faz-cookie-manager' ),
					'rescindSuccess' => __( 'Your opt-out has been withdrawn. You may submit a new request at any time.', 'faz-cookie-manager' ),
					'errMsg'         => __( 'An error occurred. Please try again.', 'faz-cookie-manager' ),
					'netMsg'         => __( 'Network error. Please try again.', 'faz-cookie-manager' ),
				)
			);
		}
		wp_enqueue_script( 'faz-dnsmpi-form' );
	}

	/**
	 * Render the opt-out form.
	 */
	public function render( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'title'  => __( 'Do Not Sell or Share My Personal Information', 'faz-cookie-manager' ),
				'button' => __( 'Submit Opt-Out Request', 'faz-cookie-manager' ),
			),
			$atts,
			'faz_do_not_sell'
		);

		$already_opted_out = isset( $_COOKIE[ self::COOKIE_NAME ] ) && '1' === $_COOKIE[ self::COOKIE_NAME ];

		wp_register_style( 'faz-dnsmpi', false, array(), FAZ_VERSION );
		wp_enqueue_style( 'faz-dnsmpi' );
		wp_add_inline_style( 'faz-dnsmpi', '
.faz-dnsmpi-wrap { max-width: 600px; padding: 20px; border: 1px solid #ddd; border-radius: 8px; }
.faz-dnsmpi-wrap h3 { margin-top: 0; }
.faz-dnsmpi-notice { padding: 12px 16px; border-radius: 6px; margin-top: 12px; }
.faz-dnsmpi-notice.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
.faz-dnsmpi-notice.error   { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
.faz-dnsmpi-btn { background: #1863DC; color: #fff; border: none; padding: 10px 22px; border-radius: 6px; cursor: pointer; font-size: 14px; }
.faz-dnsmpi-btn:focus,
.faz-dnsmpi-btn:focus-visible { outline: 2px solid #ffffff; outline-offset: 2px; box-shadow: 0 0 0 4px #0d3a82; }
.faz-dnsmpi-btn:disabled { opacity: 0.6; cursor: not-allowed; }
		' );

		$nonce         = wp_create_nonce( self::AJAX_ACTION );
		$rescind_nonce = wp_create_nonce( self::RESCIND_ACTION );

		$this->enqueue_dnsmpi_assets();

		ob_start();
		?>
		<div class="faz-dnsmpi-wrap">
			<h3><?php echo esc_html( $atts['title'] ); ?></h3>

			<?php if ( $already_opted_out ) : ?>
				<div class="faz-dnsmpi-notice success">
					<?php esc_html_e( 'You have already submitted an opt-out request. We will not sell or share your personal information.', 'faz-cookie-manager' ); ?>
				</div>
				<p><?php esc_html_e( 'Changed your mind? You can withdraw your opt-out at any time.', 'faz-cookie-manager' ); ?></p>
				<form class="faz-dnsmpi-rescind-form">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::RESCIND_ACTION ); ?>">
					<input type="hidden" name="nonce"  value="<?php echo esc_attr( $rescind_nonce ); ?>">
					<button type="submit" class="faz-dnsmpi-btn faz-dnsmpi-rescind-btn"><?php esc_html_e( 'Withdraw opt-out / opt back in', 'faz-cookie-manager' ); ?></button>
				</form>
				<div class="faz-dnsmpi-notice" role="status" aria-live="polite" aria-atomic="true" style="display:none;"></div>
			<?php else : ?>
				<p><?php esc_html_e( 'As a US resident in a state with applicable privacy laws, you have the right to opt out of the sale of your personal information. Submit the form below to exercise this right.', 'faz-cookie-manager' ); ?></p>
				<form class="faz-dnsmpi-form">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::AJAX_ACTION ); ?>">
					<input type="hidden" name="nonce"  value="<?php echo esc_attr( $nonce ); ?>">
					<button type="submit" class="faz-dnsmpi-btn"><?php echo esc_html( $atts['button'] ); ?></button>
				</form>
				<div class="faz-dnsmpi-notice" role="status" aria-live="polite" aria-atomic="true" style="display:none;"></div>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * AJAX handler for the opt-out submission.
	 */
	public function handle_optout() {
		if ( ! check_ajax_referer( self::AJAX_ACTION, 'nonce', false ) ) {
			wp_send_json_error( __( 'Invalid security token. Please refresh the page and try again.', 'faz-cookie-manager' ) );
			return;
		}

		// Idempotency: if the browser already carries the opt-out cookie, skip
		// creating a duplicate log entry and re-sending the admin notification.
		if ( isset( $_COOKIE[ self::COOKIE_NAME ] ) && '1' === $_COOKIE[ self::COOKIE_NAME ] ) {
			wp_send_json_success(
				array( 'message' => __( 'You have already opted out. We will not sell or share your personal information.', 'faz-cookie-manager' ) )
			);
			return;
		}

		// Rate limiting: atomic DB-backed lock via add_option (MySQL INSERT IGNORE),
		// plus a transient for the 60-second durability window.
		$rl_key   = 'faz_dnsmpi_rl_'   . substr( $this->hash_ip(), 0, 16 );
		$lock_key = 'faz_dnsmpi_lock_' . substr( $this->hash_ip(), 0, 16 );

		if ( false !== get_transient( $rl_key ) || ! add_option( $lock_key, 1, '', 'no' ) ) {
			wp_send_json_error( __( 'Too many requests. Please wait 1 minute before submitting again.', 'faz-cookie-manager' ) );
			return;
		}
		// Lock acquired — write durability transient, then process and release.
		set_transient( $rl_key, 1, 60 );

		try {
			$ip_hash = $this->hash_ip();
			$this->log_optout( $ip_hash );
			$this->set_optout_cookie();
			$this->notify_admin( $ip_hash );
		} finally {
			// Release DB lock regardless of success or exception; the transient
			// maintains the ongoing 60-second throttle window.
			delete_option( $lock_key );
		}

		wp_send_json_success(
			array(
				'message' => __( 'Your opt-out request has been received. We will not sell or share your personal information.', 'faz-cookie-manager' ),
			)
		);
	}

	/**
	 * AJAX handler to rescind a previous CCPA opt-out (opt back in).
	 *
	 * Required by CCPA 1798.135(c): consumers must be able to opt back in
	 * after exercising their right to opt out of the sale of personal data.
	 */
	public function handle_rescind() {
		if ( ! check_ajax_referer( self::RESCIND_ACTION, 'nonce', false ) ) {
			wp_send_json_error( __( 'Invalid security token. Please refresh the page and try again.', 'faz-cookie-manager' ) );
			return;
		}

		// Idempotency: if the opt-out cookie is already absent, nothing to do.
		$has_optout_cookie = isset( $_COOKIE[ self::COOKIE_NAME ] ) && '1' === $_COOKIE[ self::COOKIE_NAME ];

		// Rate limiting: atomic DB-backed lock plus 60-second transient window.
		$rl_key   = 'faz_dnsmpi_rsc_rl_'   . substr( $this->hash_ip(), 0, 16 );
		$lock_key = 'faz_dnsmpi_rsc_lock_' . substr( $this->hash_ip(), 0, 16 );

		if ( false !== get_transient( $rl_key ) || ! add_option( $lock_key, 1, '', 'no' ) ) {
			wp_send_json_error( __( 'Too many requests. Please wait 1 minute before submitting again.', 'faz-cookie-manager' ) );
			return;
		}
		set_transient( $rl_key, 1, 60 );

		try {
			$this->clear_optout_cookie();
			if ( $has_optout_cookie ) {
				$ip_hash = $this->hash_ip();
				$this->log_rescind( $ip_hash );
			}
		} finally {
			delete_option( $lock_key );
		}

		wp_send_json_success(
			array(
				'message' => __( 'Your opt-out has been withdrawn. You may submit a new request at any time.', 'faz-cookie-manager' ),
			)
		);
	}

	/**
	 * Log a DNSMPI event through the central consent logger.
	 *
	 * Routes the opt-out / rescind record through the same pipeline every other
	 * consent record uses, so the User-Agent is HASHED and the URL is stripped
	 * of query strings/fragments. The previous direct $wpdb->insert stored the
	 * full raw User-Agent and the raw Referer URL (potentially with PII in the
	 * query string) — a data-minimisation gap the central logger already solves
	 * via hash_user_agent() + sanitize_log_url(). `$ip_hash` is no longer used
	 * here (the central logger derives its own hashed IP from the same request);
	 * the parameter is kept for call-site stability.
	 *
	 * @param string $consent_prefix consent_id prefix (e.g. 'dnsmpi', 'dnsmpi-rsc').
	 * @param string $status         Log status (STATUS_FIELD / STATUS_RESCIND).
	 * @return void
	 */
	private function log_dnsmpi_event( $consent_prefix, $status ) {
		$controller_class = '\\FazCookie\\Admin\\Modules\\Consentlogs\\Includes\\Controller';
		if ( ! class_exists( $controller_class ) ) {
			return;
		}
		$controller_class::get_instance()->log_consent(
			array(
				'consent_id' => $consent_prefix . '-' . bin2hex( random_bytes( 8 ) ),
				'status'     => $status,
				// Empty STRING, not array(): log_consent() json-encodes an array
				// (array() → "[]"), but a DNSMPI opt-out carries no category map,
				// and the audit/export contract expects "" for this column.
				'categories' => '',
				// Pass the Referer as `url`; log_consent() runs it through
				// sanitize_log_url() which drops the query string and fragment.
				'url'        => isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '',
			)
		);
	}

	/**
	 * Log the opt-out to the consent_logs table (pseudonymised pipeline).
	 */
	private function log_optout( $ip_hash ) {
		unset( $ip_hash ); // Central logger derives its own hashed IP.
		$this->log_dnsmpi_event( 'dnsmpi', self::STATUS_FIELD );
	}

	/**
	 * Set a long-lived opt-out cookie.
	 */
	private function set_optout_cookie() {
		$expires = time() + ( self::COOKIE_DAYS * DAY_IN_SECONDS );
		// Use '/' for path and '' for domain (lets the browser derive the domain
		// from the request) instead of COOKIEPATH/COOKIE_DOMAIN which are not
		// defined in all WordPress contexts (e.g. REST routes, AJAX) and cause
		// PHPStan errors when those constants are absent from the stub set.
		setcookie( self::COOKIE_NAME, '1', array(
			'expires'  => $expires,
			'path'     => '/',
			'domain'   => '',
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		) );
	}

	/**
	 * Clear the opt-out cookie by setting an expired empty value.
	 *
	 * Used when the visitor exercises their right to rescind the CCPA
	 * opt-out (1798.135(c)). Also unsets the live $_COOKIE entry so that
	 * subsequent calls within the same PHP process see the cleared state.
	 */
	private function clear_optout_cookie() {
		setcookie( self::COOKIE_NAME, '', array(
			'expires'  => time() - 3600,
			'path'     => '/',
			'domain'   => '',
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		) );
		if ( isset( $_COOKIE[ self::COOKIE_NAME ] ) ) {
			unset( $_COOKIE[ self::COOKIE_NAME ] );
		}
	}

	/**
	 * Log the rescind (opt-back-in) to the consent_logs table.
	 *
	 * Mirrors log_optout() but uses STATUS_RESCIND so admins can audit
	 * both sides of the CCPA opt-out / opt-back-in lifecycle.
	 */
	private function log_rescind( $ip_hash ) {
		unset( $ip_hash ); // Central logger derives its own hashed IP.
		$this->log_dnsmpi_event( 'dnsmpi-rsc', self::STATUS_RESCIND );
	}

	/**
	 * Send a notification email to the admin.
	 */
	private function notify_admin( $ip_hash ) {
		$admin_email = get_option( 'admin_email' );
		$site_name   = get_bloginfo( 'name' );

		wp_mail(
			$admin_email,
			/* translators: %s: site name */
			sprintf( __( '[%s] New CCPA/CPRA Do Not Sell or Share Opt-Out Request', 'faz-cookie-manager' ), $site_name ),
			sprintf(
				/* translators: 1: site name, 2: date/time, 3: IP hash */
				__( "A visitor on %1\$s has submitted a Do Not Sell or Share My Personal Information request.\n\nDate/Time: %2\$s\nIP Hash: %3\$s\n\nNo action is required unless you sell or share personal data, in which case you should ensure this user's data is excluded from any sale or sharing.", 'faz-cookie-manager' ),
				$site_name,
				current_time( 'mysql' ),
				$ip_hash
			)
		);
	}

	/**
	 * Check if a DB table exists.
	 */
	private function table_exists( $table ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- table existence check; $table is composed from $wpdb->prefix + literal string.
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}
}
