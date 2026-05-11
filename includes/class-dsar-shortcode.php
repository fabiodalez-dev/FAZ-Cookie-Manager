<?php
/**
 * Data Subject Access Request (DSAR) Shortcode — [faz_dsar_form]
 *
 * Renders a GDPR Article 15-17 compliant request form. On submission,
 * sends a notification email to the admin and stores the request as a
 * private post (post_type = 'faz_dsar') so requests survive email failures.
 *
 * Usage: [faz_dsar_form]
 *        [faz_dsar_form button="Send Request"]
 *
 * The notification recipient is always the WordPress admin email from Settings →
 * General. To customise the address update that setting — do not pass it as a
 * shortcode attribute (removed for security: any editor with page-edit access
 * could redirect DSAR notifications to an external address).
 *
 * @package FazCookie\Includes
 */

namespace FazCookie\Includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DSAR_Shortcode {

	use IP_Hasher;

	const AJAX_ACTION = 'faz_dsar_submit';
	const POST_TYPE   = 'faz_dsar';

	/** Maximum allowed length for the free-text message field. */
	const MESSAGE_MAX_LENGTH = 5000;

	public function __construct() {
		add_shortcode( 'faz_dsar_form', array( $this, 'render' ) );
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'handle_submit' ) );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, array( $this, 'handle_submit' ) );
		// Enqueue the submit handler early so it runs even when a page builder
		// (e.g. Bricks) renders the shortcode HTML client-side after page load.
		// Inline <script> tags injected via innerHTML are silently ignored by
		// browsers, so the handler must live in a separately-enqueued file.
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_assets' ) );
	}

	/**
	 * Enqueue the DSAR form JS on every frontend page.
	 *
	 * The submit handler uses a delegated listener and is a no-op when the
	 * page contains no .faz-dsar-form element, so the cost on non-DSAR pages
	 * is a single HTTP request for a ~1 KB file.
	 *
	 * Unconditional enqueue is intentional: page builders (e.g. Bricks) may
	 * render the shortcode HTML client-side after page load. In that scenario
	 * has_shortcode($post->post_content, ...) is unreliable because the current
	 * post may be a builder template rather than the actual page post.
	 */
	public function maybe_enqueue_assets() {
		if ( is_admin() ) {
			return;
		}
		$this->enqueue_dsar_assets();
	}

	/**
	 * Register, localize, and enqueue the DSAR submit handler script.
	 * Safe to call multiple times — registration is guarded against duplicates.
	 */
	private function enqueue_dsar_assets() {
		if ( ! wp_script_is( 'faz-dsar-form', 'registered' ) ) {
			wp_register_script(
				'faz-dsar-form',
				FAZ_PLUGIN_URL . 'frontend/js/faz-dsar.js',
				array(),
				FAZ_VERSION,
				true
			);
			wp_localize_script(
				'faz-dsar-form',
				'fazDsarConfig',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'errMsg'  => __( 'An error occurred. Please try again.', 'faz-cookie-manager' ),
					'reqMsg'  => __( 'Please fill in all required fields.', 'faz-cookie-manager' ),
				)
			);
		}
		wp_enqueue_script( 'faz-dsar-form' );
	}

	/**
	 * Register the private post type used to store DSAR requests.
	 */
	public function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'label'           => __( 'Data Requests', 'faz-cookie-manager' ),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => false,
				// Map all capabilities to manage_options so Editors (who have
				// read_private_posts by default) cannot access DSAR records
				// which contain personal data (name, email, request type).
				'capability_type' => 'post',
				'capabilities'    => array(
					'edit_post'          => 'manage_options',
					'read_post'          => 'manage_options',
					'delete_post'        => 'manage_options',
					'edit_posts'         => 'manage_options',
					'edit_others_posts'  => 'manage_options',
					'edit_private_posts' => 'manage_options',
					'delete_posts'       => 'manage_options',
					'publish_posts'      => 'manage_options',
					'read_private_posts' => 'manage_options',
					'create_posts'       => 'do_not_allow',
				),
				'map_meta_cap'    => true,
				'supports'        => array( 'title', 'custom-fields' ),
				'show_in_rest'    => false,
			)
		);

		// Mapping singular post capabilities (edit_post, delete_post, read_post) to
		// 'manage_options' with map_meta_cap => true causes WordPress to register
		// 'manage_options' in the global $post_type_meta_caps table. This makes
		// map_meta_cap() treat 'manage_options' as a meta-cap that requires a post ID,
		// returning 'do_not_allow' when no ID is supplied — breaking all admin pages
		// that check current_user_can('manage_options'). Remove the entry to restore
		// 'manage_options' as a primitive capability.
		global $post_type_meta_caps;
		unset( $post_type_meta_caps['manage_options'] );
	}

	/**
	 * Render the DSAR form.
	 */
	public function render( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'button' => __( 'Send Request', 'faz-cookie-manager' ),
			),
			$atts,
			'faz_dsar_form'
		);

		$this->enqueue_dsar_assets();
		wp_register_style( 'faz-dsar', false, array(), FAZ_VERSION );
		wp_enqueue_style( 'faz-dsar' );
		wp_add_inline_style( 'faz-dsar', '
.faz-dsar-wrap { max-width: 640px; }
.faz-dsar-wrap .faz-dsar-field { margin-bottom: 16px; }
.faz-dsar-wrap label { display: block; font-weight: 600; margin-bottom: 4px; }
.faz-dsar-wrap input[type=text],
.faz-dsar-wrap input[type=email],
.faz-dsar-wrap select,
.faz-dsar-wrap textarea { width: 100%; padding: 8px 12px; border: 1px solid #ccc; border-radius: 6px; font-size: 14px; box-sizing: border-box; }
.faz-dsar-wrap textarea { min-height: 100px; resize: vertical; }
.faz-dsar-wrap .faz-dsar-btn { background: #1863DC; color: #fff; border: none; padding: 10px 22px; border-radius: 6px; cursor: pointer; font-size: 14px; }
.faz-dsar-wrap .faz-dsar-btn:disabled { opacity: 0.6; cursor: not-allowed; }
.faz-dsar-notice { padding: 12px 16px; border-radius: 6px; margin-top: 12px; }
.faz-dsar-notice.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
.faz-dsar-notice.error   { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
.faz-dsar-honeypot { display: none !important; }
		' );

		$nonce = wp_create_nonce( self::AJAX_ACTION );
		$id    = 'faz-dsar-' . wp_rand( 1000, 9999 );

		$request_types = array(
			'access'      => __( 'Right of Access (Article 15) — receive a copy of my data', 'faz-cookie-manager' ),
			'erasure'     => __( 'Right to Erasure (Article 17) — delete my data', 'faz-cookie-manager' ),
			'portability' => __( 'Right to Data Portability (Article 20) — export my data', 'faz-cookie-manager' ),
			'rectify'     => __( 'Right to Rectification (Article 16) — correct my data', 'faz-cookie-manager' ),
			'restrict'    => __( 'Right to Restrict Processing (Article 18)', 'faz-cookie-manager' ),
			'object'      => __( 'Right to Object (Article 21) — object to processing', 'faz-cookie-manager' ),
		);

		ob_start();
		?>
		<div class="faz-dsar-wrap" id="<?php echo esc_attr( $id ); ?>">
			<form class="faz-dsar-form" novalidate>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::AJAX_ACTION ); ?>">
				<input type="hidden" name="nonce"  value="<?php echo esc_attr( $nonce ); ?>">

				<!-- Honeypot field — bots fill it, humans don't -->
				<div class="faz-dsar-honeypot" aria-hidden="true">
					<input type="text" name="faz_hp_name" tabindex="-1" autocomplete="off" aria-hidden="true">
				</div>

				<div class="faz-dsar-field">
					<label for="<?php echo esc_attr( $id ); ?>-name"><?php esc_html_e( 'Full Name', 'faz-cookie-manager' ); ?> *</label>
					<input type="text" id="<?php echo esc_attr( $id ); ?>-name" name="dsar_name" required autocomplete="name">
				</div>

				<div class="faz-dsar-field">
					<label for="<?php echo esc_attr( $id ); ?>-email"><?php esc_html_e( 'Email Address', 'faz-cookie-manager' ); ?> *</label>
					<input type="email" id="<?php echo esc_attr( $id ); ?>-email" name="dsar_email" required autocomplete="email">
				</div>

				<div class="faz-dsar-field">
					<label for="<?php echo esc_attr( $id ); ?>-type"><?php esc_html_e( 'Request Type', 'faz-cookie-manager' ); ?> *</label>
					<select id="<?php echo esc_attr( $id ); ?>-type" name="dsar_type" required>
						<option value=""><?php esc_html_e( '— Select a request type —', 'faz-cookie-manager' ); ?></option>
						<?php foreach ( $request_types as $val => $label ) : ?>
							<option value="<?php echo esc_attr( $val ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>

				<div class="faz-dsar-field">
					<label for="<?php echo esc_attr( $id ); ?>-msg"><?php esc_html_e( 'Additional Information', 'faz-cookie-manager' ); ?></label>
					<textarea id="<?php echo esc_attr( $id ); ?>-msg" name="dsar_message" maxlength="<?php echo esc_attr( self::MESSAGE_MAX_LENGTH ); ?>" placeholder="<?php esc_attr_e( 'Optional: provide any additional context to help us identify your data.', 'faz-cookie-manager' ); ?>"></textarea>
				</div>

				<button type="submit" class="faz-dsar-btn"><?php echo esc_html( $atts['button'] ); ?></button>
			</form>
			<div class="faz-dsar-notice" style="display:none;" role="status" aria-live="polite" aria-atomic="true" tabindex="-1"></div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * AJAX handler for form submission.
	 */
	public function handle_submit() {
		if ( ! check_ajax_referer( self::AJAX_ACTION, 'nonce', false ) ) {
			wp_send_json_error( __( 'Invalid security token. Please refresh the page and try again.', 'faz-cookie-manager' ) );
		}

		// Honeypot check.
		if ( ! empty( $_POST['faz_hp_name'] ) ) {
			wp_send_json_error( __( 'Submission rejected.', 'faz-cookie-manager' ) );
		}

		$name        = isset( $_POST['dsar_name'] ) ? sanitize_text_field( wp_unslash( $_POST['dsar_name'] ) ) : '';
		$email       = isset( $_POST['dsar_email'] ) ? sanitize_email( wp_unslash( $_POST['dsar_email'] ) ) : '';
		$type        = isset( $_POST['dsar_type'] ) ? sanitize_key( wp_unslash( $_POST['dsar_type'] ) ) : '';
		$message     = isset( $_POST['dsar_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['dsar_message'] ) ) : '';
		// Recipient is always the site admin — not sourced from client input.
		$admin_email = (string) get_option( 'admin_email' );

		$valid_types = array( 'access', 'erasure', 'portability', 'rectify', 'restrict', 'object' );

		if ( empty( $name ) ) {
			wp_send_json_error( __( 'Please enter your full name.', 'faz-cookie-manager' ) );
		}

		if ( ! is_email( $email ) ) {
			wp_send_json_error( __( 'Please enter a valid email address.', 'faz-cookie-manager' ) );
		}

		// Per-email rate limit: one submission per email per hour.
		$email_rl_key = 'faz_dsar_rl_em_' . substr( hash_hmac( 'sha256', strtolower( $email ), wp_salt() ), 0, 16 );
		if ( false !== get_transient( $email_rl_key ) ) {
			wp_send_json_error( __( 'Too many requests. Please wait before submitting again.', 'faz-cookie-manager' ) );
		}

		if ( ! in_array( $type, $valid_types, true ) ) {
			wp_send_json_error( __( 'Please select a valid request type.', 'faz-cookie-manager' ) );
		}

		$msg_len = function_exists( 'mb_strlen' ) ? mb_strlen( $message ) : strlen( $message );
		if ( $msg_len > self::MESSAGE_MAX_LENGTH ) {
			wp_send_json_error( __( 'Your message is too long. Please limit it to 5,000 characters.', 'faz-cookie-manager' ) );
		}

		// Rate limiting: atomic DB-backed lock via add_option (MySQL INSERT IGNORE),
		// plus a transient for the 60-second durability window. On installs without
		// a persistent object cache (the majority), wp_cache_add is not inter-process
		// atomic, so add_option is required to prevent concurrent bypass.
		$rl_key   = 'faz_dsar_rl_'   . substr( $this->hash_ip(), 0, 16 );
		$lock_key = 'faz_dsar_lock_' . substr( $this->hash_ip(), 0, 16 );

		if ( false !== get_transient( $rl_key ) || ! add_option( $lock_key, 1, '', 'no' ) ) {
			wp_send_json_error( __( 'Too many requests. Please wait before submitting again.', 'faz-cookie-manager' ) );
			return;
		}
		// Lock acquired — write durability transient, then process and release.
		set_transient( $rl_key, 1, 60 );

		try {
			$post_id = $this->store_request( $name, $email, $type, $message );
			if ( ! $post_id ) {
				// DB error — roll back rate-limit so the user can retry immediately.
				delete_transient( $rl_key );
				// Release the DB lock BEFORE wp_send_json_error(): wp_send_json_error()
				// calls wp_die() which calls exit(), and PHP `finally` blocks are NOT
				// executed when exit() terminates the script. Without this explicit
				// release, the lock would persist until manual admin intervention,
				// permanently locking out the originating IP from future submissions.
				delete_option( $lock_key );
				wp_send_json_error( __( 'We could not record your request due to a server error. Please try again.', 'faz-cookie-manager' ) );
				return;
			}

			set_transient( $email_rl_key, 1, HOUR_IN_SECONDS );
			$this->notify_admin( $name, $email, $type, $message, $post_id, $admin_email );
			$this->send_confirmation( $name, $email, $type );
		} finally {
			// Release DB lock regardless of success or exception; the transient
			// maintains the ongoing 60-second throttle window.
			delete_option( $lock_key );
		}

		wp_send_json_success(
			array(
				'message' => __( 'Your request has been received. We will respond within 30 days as required by law.', 'faz-cookie-manager' ),
			)
		);
	}

	/**
	 * Store the request as a private post so it survives email failures.
	 *
	 * @return int Post ID.
	 */
	private function store_request( $name, $email, $type, $message ) {
		$type_labels = array(
			'access'      => 'Access',
			'erasure'     => 'Erasure',
			'portability' => 'Portability',
			'rectify'     => 'Rectification',
			'restrict'    => 'Restriction',
			'object'      => 'Object',
		);
		$label = isset( $type_labels[ $type ] ) ? $type_labels[ $type ] : ucfirst( $type );

		$post_id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'private',
				'post_title'  => sprintf( '[%s] %s — %s', $label, $name, current_time( 'Y-m-d' ) ),
				'meta_input'  => array(
					'_dsar_name'    => $name,
					'_dsar_email'   => $email,
					'_dsar_type'    => $type,
					'_dsar_message' => $message,
					'_dsar_status'  => 'pending',
				),
			),
			true
		);

		return is_wp_error( $post_id ) ? 0 : $post_id;
	}

	/**
	 * Send notification email to the configured admin address.
	 *
	 * @param string $name        Requester's name (already sanitized).
	 * @param string $email       Requester's email.
	 * @param string $type        Request type key.
	 * @param string $message     Optional free-text message.
	 * @param int    $post_id     ID of the stored DSAR post (0 on failure).
	 * @param string $admin_email Destination address; falls back to site admin.
	 */
	private function notify_admin( $name, $email, $type, $message, $post_id, $admin_email = '' ) {
		if ( empty( $admin_email ) || ! is_email( $admin_email ) ) {
			$admin_email = get_option( 'admin_email' );
		}

		$site_name  = get_bloginfo( 'name' );
		$type_label = $this->get_type_label( $type );

		$body = sprintf(
			/* translators: 1: site name, 2: name, 3: email, 4: request type, 5: date, 6: message */
			__( "New data subject request received on %1\$s.\n\nName: %2\$s\nEmail: %3\$s\nRequest type: %4\$s\nSubmitted: %5\$s\n\nMessage:\n%6\$s\n\nYou must respond within 30 days (GDPR Art. 12). Reply directly to the requester's email address.", 'faz-cookie-manager' ),
			$site_name,
			$name,
			$email,
			$type_label,
			current_time( 'mysql' ),
			$message ?: __( '(none)', 'faz-cookie-manager' )
		);

		if ( $post_id ) {
			$body .= "\n\n" . sprintf(
				/* translators: %s: admin URL to the request post */
				__( 'View logged request: %s', 'faz-cookie-manager' ),
				admin_url( 'post.php?post=' . $post_id . '&action=edit' )
			);
		}

		// Strip CRLF and angle brackets to prevent SMTP header injection via name or email.
		$safe_name  = str_replace( array( "\r", "\n", '<', '>', '"' ), '', $name );
		$safe_email = str_replace( array( "\r", "\n" ), '', $email );

		wp_mail(
			$admin_email,
			/* translators: 1: site name, 2: request type */
			sprintf( __( '[%1$s] Data Subject Request: %2$s', 'faz-cookie-manager' ), $site_name, $type_label ),
			$body,
			array( 'Reply-To: ' . $safe_name . ' <' . $safe_email . '>' )
		);
	}

	/**
	 * Send a confirmation email to the requester.
	 */
	private function send_confirmation( $name, $email, $type ) {
		$site_name  = get_bloginfo( 'name' );
		$type_label = $this->get_type_label( $type );

		wp_mail(
			$email,
			/* translators: %s: site name */
			sprintf( __( '[%s] We received your data request', 'faz-cookie-manager' ), $site_name ),
			sprintf(
				/* translators: 1: first name, 2: site name, 3: human-readable request type (e.g. "Right of Access (Art. 15 GDPR)") */
				__( "Dear %1\$s,\n\nWe have received your data subject request (%3\$s) on %2\$s.\n\nWe will process your request and respond within 30 days as required by GDPR Article 12.\n\nIf you have any questions, please reply to this email.\n\nThank you,\nThe %2\$s team", 'faz-cookie-manager' ),
				$name,
				$site_name,
				$type_label
			)
		);
	}

	/**
	 * Map a DSAR type key to a human-readable label.
	 *
	 * @param string $type One of: access, erasure, portability, rectify, restrict, object.
	 * @return string Translated label, or the raw key as fallback.
	 */
	private function get_type_label( $type ) {
		$labels = array(
			'access'      => __( 'Right of Access (Art. 15 GDPR)', 'faz-cookie-manager' ),
			'erasure'     => __( 'Right to Erasure (Art. 17 GDPR)', 'faz-cookie-manager' ),
			'portability' => __( 'Right to Data Portability (Art. 20 GDPR)', 'faz-cookie-manager' ),
			'rectify'     => __( 'Right to Rectification (Art. 16 GDPR)', 'faz-cookie-manager' ),
			'restrict'    => __( 'Right to Restrict Processing (Art. 18 GDPR)', 'faz-cookie-manager' ),
			'object'      => __( 'Right to Object (Art. 21 GDPR)', 'faz-cookie-manager' ),
		);
		return isset( $labels[ $type ] ) ? $labels[ $type ] : $type;
	}
}
