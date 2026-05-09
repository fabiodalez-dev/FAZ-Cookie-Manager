<?php
/**
 * CCPA "Do Not Sell My Personal Information" Shortcode — [faz_do_not_sell]
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

	const AJAX_ACTION  = 'faz_dnsmpi_optout';
	const COOKIE_NAME  = 'fazcookie-dnsmpi';
	const COOKIE_DAYS  = 365;
	const STATUS_FIELD = 'dnsmpi_optout';

	public function __construct() {
		add_shortcode( 'faz_do_not_sell', array( $this, 'render' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'handle_optout' ) );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, array( $this, 'handle_optout' ) );
	}

	/**
	 * Render the opt-out form.
	 */
	public function render( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'title'  => __( 'Do Not Sell My Personal Information', 'faz-cookie-manager' ),
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
.faz-dnsmpi-btn:disabled { opacity: 0.6; cursor: not-allowed; }
		' );

		$nonce = wp_create_nonce( self::AJAX_ACTION );
		$id    = 'faz-dnsmpi-' . wp_rand( 1000, 9999 );

		ob_start();
		?>
		<div class="faz-dnsmpi-wrap" id="<?php echo esc_attr( $id ); ?>">
			<h3><?php echo esc_html( $atts['title'] ); ?></h3>

			<?php if ( $already_opted_out ) : ?>
				<div class="faz-dnsmpi-notice success">
					<?php esc_html_e( 'You have already submitted an opt-out request. We will not sell your personal information.', 'faz-cookie-manager' ); ?>
				</div>
			<?php else : ?>
				<p><?php esc_html_e( 'As a California resident you have the right to opt out of the sale of your personal information. Submit the form below to exercise this right.', 'faz-cookie-manager' ); ?></p>
				<form class="faz-dnsmpi-form">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::AJAX_ACTION ); ?>">
					<input type="hidden" name="nonce"  value="<?php echo esc_attr( $nonce ); ?>">
					<button type="submit" class="faz-dnsmpi-btn"><?php echo esc_html( $atts['button'] ); ?></button>
				</form>
				<div class="faz-dnsmpi-notice" style="display:none;"></div>
			<?php endif; ?>
		</div>

		<script>
		(function(){
			var wrap = document.getElementById(<?php echo wp_json_encode( $id ); ?>);
			if ( ! wrap ) return;
			var form    = wrap.querySelector('.faz-dnsmpi-form');
			var notice  = wrap.querySelector('.faz-dnsmpi-notice');
			if ( ! form ) return;

			form.addEventListener('submit', function(e){
				e.preventDefault();
				var btn = form.querySelector('button');
				btn.disabled = true;
				var data = new FormData(form);
				fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
					method: 'POST',
					credentials: 'same-origin',
					body: data
				})
				.then(function(r){ return r.json(); })
				.then(function(res){
					form.style.display = 'none';
					notice.style.display = 'block';
					if ( res.success ) {
						notice.className = 'faz-dnsmpi-notice success';
						notice.textContent = res.data.message;
					} else {
						notice.className = 'faz-dnsmpi-notice error';
						notice.textContent = res.data || <?php echo wp_json_encode( __( 'An error occurred. Please try again.', 'faz-cookie-manager' ) ); ?>;
						btn.disabled = false;
						form.style.display = 'block';
					}
				})
				.catch(function(){
					btn.disabled = false;
					notice.className = 'faz-dnsmpi-notice error';
					notice.textContent = <?php echo wp_json_encode( __( 'Network error. Please try again.', 'faz-cookie-manager' ) ); ?>;
					notice.style.display = 'block';
				});
			});
		})();
		</script>
		<?php
		return ob_get_clean();
	}

	/**
	 * AJAX handler for the opt-out submission.
	 */
	public function handle_optout() {
		if ( ! check_ajax_referer( self::AJAX_ACTION, 'nonce', false ) ) {
			wp_send_json_error( __( 'Invalid security token. Please refresh the page and try again.', 'faz-cookie-manager' ) );
		}

		// Idempotency: if the browser already carries the opt-out cookie, skip
		// creating a duplicate log entry and re-sending the admin notification.
		if ( isset( $_COOKIE[ self::COOKIE_NAME ] ) && '1' === $_COOKIE[ self::COOKIE_NAME ] ) {
			wp_send_json_success(
				array( 'message' => __( 'You have already opted out. We will not sell your personal information.', 'faz-cookie-manager' ) )
			);
		}

		// Rate limiting: atomic DB-backed lock via add_option (MySQL INSERT IGNORE),
		// plus a transient for the 60-second durability window.
		$rl_key   = 'faz_dnsmpi_rl_'   . substr( $this->hash_ip(), 0, 16 );
		$lock_key = 'faz_dnsmpi_lock_' . substr( $this->hash_ip(), 0, 16 );

		if ( false !== get_transient( $rl_key ) || ! add_option( $lock_key, 1, '', 'no' ) ) {
			wp_send_json_error( __( 'Too many requests. Please wait before submitting again.', 'faz-cookie-manager' ) );
			return;
		}
		// Lock acquired — write durability transient, then process and release.
		set_transient( $rl_key, 1, 60 );

		$ip_hash = $this->hash_ip();
		$this->log_optout( $ip_hash );
		$this->set_optout_cookie();
		$this->notify_admin( $ip_hash );

		// Release DB lock; transient maintains the ongoing 60-second throttle.
		delete_option( $lock_key );

		wp_send_json_success(
			array(
				'message' => __( 'Your opt-out request has been received. We will not sell your personal information.', 'faz-cookie-manager' ),
			)
		);
	}

	/**
	 * Log the opt-out to the consent_logs table.
	 */
	private function log_optout( $ip_hash ) {
		global $wpdb;
		$table = $wpdb->prefix . 'faz_consent_logs';

		if ( ! $this->table_exists( $table ) ) {
			return;
		}

		$wpdb->insert(
			$table,
			array(
				'consent_id'      => 'dnsmpi-' . bin2hex( random_bytes( 8 ) ),
				'status'          => self::STATUS_FIELD,
				'categories'      => '',
				'ip_hash'         => $ip_hash,
				'user_agent'      => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
				'url'             => isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '',
				'banner_slug'     => '',
				'policy_revision' => 1,
				'created_at'      => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
		);
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
	 * Send a notification email to the admin.
	 */
	private function notify_admin( $ip_hash ) {
		$admin_email = get_option( 'admin_email' );
		$site_name   = get_bloginfo( 'name' );

		wp_mail(
			$admin_email,
			/* translators: %s: site name */
			sprintf( __( '[%s] New CCPA Do Not Sell Opt-Out Request', 'faz-cookie-manager' ), $site_name ),
			sprintf(
				/* translators: 1: site name, 2: date/time, 3: IP hash */
				__( "A visitor on %1\$s has submitted a Do Not Sell My Personal Information request.\n\nDate/Time: %2\$s\nIP Hash: %3\$s\n\nNo action is required unless you sell personal data, in which case you should ensure this user's data is excluded from any sale.", 'faz-cookie-manager' ),
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
