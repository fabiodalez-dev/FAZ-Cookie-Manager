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

	/**
	 * Default cap on accepted requests per client IP per 24 hours.
	 *
	 * The 60-second lock below only spaces submissions out; on its own it lets
	 * a bot submit once a minute forever, which is exactly the "chain of spam
	 * submissions" shape reported from the field. This is the cumulative
	 * ceiling. Filter: `faz_dsar_max_per_ip_per_day`.
	 */
	const MAX_PER_IP_PER_DAY = 5;

	/**
	 * Default cap on NOTIFICATION EMAILS sent site-wide per 24 hours.
	 *
	 * The per-IP ceiling is defeated by rotating addresses, so a site-wide
	 * ceiling is needed as well. What it bounds, though, is the outbound mail —
	 * not the right to submit. Refusing submissions site-wide would hand any
	 * botnet an off switch for every data subject's Art. 15-22 request, which is
	 * a worse outcome than the flood: GDPR Art. 12(2) obliges the controller to
	 * facilitate the exercise of rights. Past this ceiling requests are still
	 * accepted and recorded, no notification or confirmation email is sent for
	 * the rest of the window, and the admin is told once that it tripped.
	 * Filter: `faz_dsar_max_per_day`.
	 */
	const MAX_PER_DAY = 50;

	/**
	 * Default hard ceiling on accepted requests site-wide per 24 hours.
	 *
	 * Ten times the notification ceiling. Its only job is to bound what a flood
	 * can write to the database once the mail has already been silenced; by the
	 * time it is reached the site is plainly under attack and the admin has been
	 * emailed. Deliberately far above anything a real site produces, because
	 * this is the one ceiling that does deny a data subject.
	 * Filter: `faz_dsar_max_stored_per_day`.
	 */
	const MAX_STORED_PER_DAY = 500;

	public function __construct() {
		add_shortcode( 'faz_dsar_form', array( $this, 'render' ) );
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'handle_submit' ) );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, array( $this, 'handle_submit' ) );
		// The shortcode callback enqueues the handler wherever the form is
		// rendered server-side (posts, widgets, templates, builder elements);
		// this hook only covers forms injected client-side after page load.
		// The handler stays a separate file either way: an inline <script>
		// injected via innerHTML is silently ignored by browsers.
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_assets' ) );
		add_action( 'faz_after_activate', array( $this, 'assign_capabilities' ) );
		// Keep the pending count honest however a record appears or leaves —
		// the form, WP-CLI, an import, the trash. It is memoised for five
		// minutes because it is rendered on every admin page load, and a count
		// that lags is a request the admin does not know is waiting.
		add_action( 'transition_post_status', array( __CLASS__, 'flush_pending_count_on_transition' ), 10, 3 );
		add_action( 'before_delete_post', array( __CLASS__, 'flush_pending_count_for' ) );
	}

	/**
	 * Drop the memoised pending count.
	 *
	 * @since 1.34.1
	 */
	public static function flush_pending_count() {
		delete_transient( 'faz_dsar_pending_count' );
	}

	/**
	 * Drop the count when a DSAR record changes status.
	 *
	 * @since 1.34.1
	 * @param string   $new_status New status.
	 * @param string   $old_status Previous status.
	 * @param \WP_Post $post       The post.
	 */
	public static function flush_pending_count_on_transition( $new_status, $old_status, $post ) {
		if ( $post && self::POST_TYPE === $post->post_type ) {
			self::flush_pending_count();
		}
	}

	/**
	 * Drop the count when a DSAR record is about to be deleted for good.
	 *
	 * @since 1.34.1
	 * @param int $post_id Post being deleted.
	 */
	public static function flush_pending_count_for( $post_id ) {
		if ( self::POST_TYPE === get_post_type( $post_id ) ) {
			self::flush_pending_count();
		}
	}

	/**
	 * Grant custom DSAR capabilities to the administrator role.
	 *
	 * Hooked to faz_after_activate so it runs on initial activation
	 * and every version bump.
	 */
	public function assign_capabilities() {
		$role = get_role( 'administrator' );
		if ( ! $role ) {
			return;
		}

		$caps = array(
			'edit_faz_dsar',
			'read_faz_dsar',
			'delete_faz_dsar',
			'edit_faz_dsars',
			'edit_others_faz_dsars',
			'publish_faz_dsars',
			'read_private_faz_dsars',
			'delete_faz_dsars',
			'delete_private_faz_dsars',
			'delete_published_faz_dsars',
			'delete_others_faz_dsars',
			'edit_private_faz_dsars',
			'edit_published_faz_dsars',
		);

		foreach ( $caps as $cap ) {
			$role->add_cap( $cap );
		}
	}

	/**
	 * Load the DSAR form JS on pages that do not render the shortcode.
	 *
	 * render() enqueues the handler itself, and WordPress prints it in the
	 * footer, so every server-side placement — post content, widgets, block
	 * and builder templates — gets it without help. It used to be enqueued on
	 * every frontend page as well, for builders (e.g. Bricks) that inject the
	 * form HTML client-side after load, where the shortcode callback never
	 * runs: that cost a request and an inline config on every page of every
	 * site, form or not (#308). Such a site can opt back in through
	 * `faz_load_shortcode_assets_everywhere`.
	 */
	public function maybe_enqueue_assets() {
		if ( is_admin() ) {
			return;
		}
		if ( faz_load_shortcode_assets_everywhere( 'dsar' ) ) {
			$this->enqueue_dsar_assets();
		}
	}

	/**
	 * Register, localize, and enqueue the DSAR submit handler script.
	 * Safe to call multiple times — registration is guarded against duplicates.
	 */
	private function enqueue_dsar_assets() {
		if ( ! wp_script_is( 'faz-dsar-form', 'registered' ) ) {
			// Prefer the minified build (~3.6 KB vs ~8.6 KB).
			$suffix = faz_asset_suffix( 'frontend/js/faz-dsar' );
			wp_register_script(
				'faz-dsar-form',
				FAZ_PLUGIN_URL . 'frontend/js/faz-dsar' . $suffix . '.js',
				array(),
				FAZ_VERSION,
				true
			);
			wp_localize_script(
				'faz-dsar-form',
				'fazDsarConfig',
				array(
					'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
					'errMsg'     => __( 'An error occurred. Please try again.', 'faz-cookie-manager' ),
					'reqMsg'     => __( 'Please fill in all required fields.', 'faz-cookie-manager' ),
					'emailMsg'   => __( 'Please enter a valid email address.', 'faz-cookie-manager' ),
					'nameLabel'  => __( 'Name', 'faz-cookie-manager' ),
					'emailLabel' => __( 'Email', 'faz-cookie-manager' ),
					'typeLabel'  => __( 'Request type', 'faz-cookie-manager' ),
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
				'capability_type' => 'faz_dsar',
				// Override create-side caps to 'do_not_allow' so even an
				// administrator with edit_faz_dsars cannot manually craft a
				// DSAR record via /wp-admin/post-new.php?post_type=faz_dsar.
				// DSAR records are written exclusively by the public-facing
				// shortcode AJAX handler (handle_submit) after honeypot +
				// nonce + IP-rate-limit checks. Manual creation would bypass
				// that audit trail. The plural-form caps cover the
				// list-table "Add New" / bulk-edit paths; map_meta_cap will
				// route singular create/edit through edit_faz_dsar (which
				// administrators do hold).
				'capabilities'    => array(
					'create_posts'        => 'do_not_allow',
					'publish_posts'       => 'do_not_allow',
				),
				'map_meta_cap'    => true,
				// No 'custom-fields': every DSAR meta key is underscore-prefixed,
				// so is_protected_meta() hid the panel's contents anyway — the
				// edit screen showed a title and nothing else. The values are
				// rendered read-only by DSAR_Admin instead, which also keeps the
				// panel from being used to write arbitrary meta onto what is
				// meant to be an immutable audit record.
				'supports'        => array( 'title' ),
				'show_in_rest'    => false,
			)
		);
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
.faz-dsar-wrap .faz-dsar-btn:focus,
.faz-dsar-wrap .faz-dsar-btn:focus-visible { outline: 2px solid #ffffff; outline-offset: 2px; box-shadow: 0 0 0 4px #0d3a82; }
.faz-dsar-wrap .faz-dsar-btn:disabled { opacity: 0.6; cursor: not-allowed; }
.faz-dsar-notice { padding: 12px 16px; border-radius: 6px; margin-top: 12px; }
.faz-dsar-notice.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
.faz-dsar-notice.error   { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
.faz-dsar-wrap .faz-field-error { display: block; color: #721c24; font-size: 0.85em; margin-top: 3px; font-weight: 500; }
.faz-dsar-required-note { font-size: 13px; color: #545454; margin: 0 0 12px; }
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
			<p class="faz-dsar-required-note"><?php esc_html_e( 'Fields marked with * are required.', 'faz-cookie-manager' ); ?></p>
			<form
				class="faz-dsar-form"
				method="post"
				action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
				novalidate
			>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::AJAX_ACTION ); ?>">
				<input type="hidden" name="nonce"  value="<?php echo esc_attr( $nonce ); ?>">

				<!-- Honeypot field — bots fill it, humans don't -->
				<div class="faz-dsar-honeypot" aria-hidden="true">
					<input type="text" name="faz_hp_name" tabindex="-1" autocomplete="off" aria-hidden="true">
				</div>

				<div class="faz-dsar-field">
					<label for="<?php echo esc_attr( $id ); ?>-name"><?php esc_html_e( 'Full Name', 'faz-cookie-manager' ); ?> *</label>
					<input type="text" id="<?php echo esc_attr( $id ); ?>-name" name="dsar_name" required aria-required="true" autocomplete="name">
				</div>

				<div class="faz-dsar-field">
					<label for="<?php echo esc_attr( $id ); ?>-email"><?php esc_html_e( 'Email Address', 'faz-cookie-manager' ); ?> *</label>
					<input type="email" id="<?php echo esc_attr( $id ); ?>-email" name="dsar_email" required aria-required="true" autocomplete="email">
				</div>

				<div class="faz-dsar-field">
					<label for="<?php echo esc_attr( $id ); ?>-type"><?php esc_html_e( 'Request Type', 'faz-cookie-manager' ); ?> *</label>
					<select id="<?php echo esc_attr( $id ); ?>-type" name="dsar_type" required aria-required="true">
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
		// Defensive `return;` after every wp_send_json_*: wp_send_json_* calls
		// wp_die() which calls exit(), so flow does not continue under default
		// WP behaviour, but the contract is implicit. Sibling handlers in
		// Do_Not_Sell_Shortcode return explicitly after every send_json — and
		// some test harnesses / wp_die_handler filters override wp_die to
		// throw or return, where the implicit-exit assumption breaks. Be
		// symmetric and defensive everywhere.
		if ( ! check_ajax_referer( self::AJAX_ACTION, 'nonce', false ) ) {
			wp_send_json_error( __( 'Invalid security token. Please refresh the page and try again.', 'faz-cookie-manager' ) );
			return;
		}

		// Honeypot check.
		if ( ! empty( $_POST['faz_hp_name'] ) ) {
			wp_send_json_error( __( 'Submission rejected.', 'faz-cookie-manager' ) );
			return;
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
			return;
		}

		if ( ! is_email( $email ) ) {
			wp_send_json_error( __( 'Please enter a valid email address.', 'faz-cookie-manager' ) );
			return;
		}

		if ( ! in_array( $type, $valid_types, true ) ) {
			wp_send_json_error( __( 'Please select a valid request type.', 'faz-cookie-manager' ) );
			return;
		}

		$msg_len = function_exists( 'mb_strlen' ) ? mb_strlen( $message ) : strlen( $message );
		if ( $msg_len > self::MESSAGE_MAX_LENGTH ) {
			wp_send_json_error( __( 'Your message is too long. Please limit it to 5,000 characters.', 'faz-cookie-manager' ) );
			return;
		}

		// Spam gate and cumulative throttles. Runs BEFORE the 60-second lock is
		// acquired so a rejected submission does not burn the throttle window of
		// the next, possibly legitimate, visitor from the same address.
		$guard = $this->guard_submission(
			array(
				'name'    => $name,
				'email'   => $email,
				'type'    => $type,
				'message' => $message,
			)
		);
		if ( is_wp_error( $guard ) ) {
			wp_send_json_error( $guard->get_error_message() );
			return;
		}

		// Rate limiting: atomic DB-backed lock via add_option (MySQL INSERT IGNORE),
		// plus a transient for the 60-second durability window. On installs without
		// a persistent object cache (the majority), wp_cache_add is not inter-process
		// atomic, so add_option is required to prevent concurrent bypass.
		$rl_key   = 'faz_dsar_rl_'   . substr( $this->hash_ip(), 0, 16 );
		$lock_key = 'faz_dsar_lock_' . substr( $this->hash_ip(), 0, 16 );

		if ( false !== get_transient( $rl_key ) || ! add_option( $lock_key, 1, '', 'no' ) ) {
			wp_send_json_error( __( 'Too many requests. Please wait 1 minute before submitting again.', 'faz-cookie-manager' ) );
			return;
		}
		// Lock acquired — write durability transient, then process and release.
		set_transient( $rl_key, 1, 60 );

		// Claim the slot before the work (see record_submission()).
		$this->record_submission( $email );

		try {
			$post_id = $this->store_request( $name, $email, $type, $message );
			if ( ! $post_id ) {
				$this->release_submission( $email );
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

			// Past the ceiling the request is recorded and the per-request mail
			// is not sent — the right is honoured, the inbox is spared, and an
			// attacker cannot aim the confirmation email at a third party. The
			// admin is still told, hourly and in aggregate, and the pending
			// count on the menu does not depend on mail at all.
			if ( $this->per_request_mail_exhausted() ) {
				$this->announce_suppressed( $this->cap( 'faz_dsar_max_per_day', self::MAX_PER_DAY ) );
			} else {
				$this->notify_admin( $name, $email, $type, $message, $post_id, $admin_email );
				$this->send_confirmation( $name, $email, $type );
			}
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
	 * Decide whether a submission may be accepted.
	 *
	 * Three layers, cheapest first:
	 *
	 * 1. `faz_dsar_pre_submit` — the integration point for a third-party
	 *    protection plugin (ALTCHA / OpenPorte, Turnstile, hCaptcha, Akismet…).
	 *    Those plugins gate the forms they know — Contact Form 7, WPForms,
	 *    Gravity — and this form is a plugin-specific `wp_ajax` action, so
	 *    without a hook they cannot see it at all. Return a `WP_Error` to
	 *    reject; its message is shown to the visitor verbatim. The raw payload
	 *    (including any challenge token the protection plugin added to the
	 *    form) is in `$_POST`, so a gate can read its own field directly:
	 *
	 *        add_filter( 'faz_dsar_pre_submit', function ( $verdict ) {
	 *            return my_captcha_is_valid() ? $verdict : new WP_Error(
	 *                'captcha', __( 'Please complete the verification.' )
	 *            );
	 *        } );
	 *
	 * 2. One submission per email address per hour.
	 * 3. Cumulative 24-hour ceilings, per IP and site-wide.
	 *
	 * The 60-second per-IP lock is NOT here: it is acquired by the caller,
	 * which owns releasing it.
	 *
	 * @since 1.34.1
	 * @param array $context Sanitized submission fields (name, email, type, message).
	 * @return true|\WP_Error True to accept, WP_Error (message shown to the visitor) to reject.
	 */
	public function guard_submission( $context ) {
		$context = is_array( $context ) ? $context : array();
		$email   = isset( $context['email'] ) ? (string) $context['email'] : '';

		$verdict = apply_filters( 'faz_dsar_pre_submit', null, $context );
		if ( is_wp_error( $verdict ) ) {
			$message = $verdict->get_error_message();
			if ( '' === $message ) {
				$message = __( 'Submission rejected.', 'faz-cookie-manager' );
			}
			return $this->reject( 'faz_dsar_gate', $message, $context );
		}

		if ( '' !== $email && false !== get_transient( $this->email_rl_key( $email ) ) ) {
			return $this->reject(
				'faz_dsar_email_throttled',
				__( 'Too many requests. Please wait 1 hour before submitting again.', 'faz-cookie-manager' ),
				$context
			);
		}

		// The site-wide layer here is the HARD ceiling, not the notification one:
		// a flood must not be able to switch off everyone's right to submit.
		// Notifications are silenced separately, by notifications_exhausted(),
		// after the request has been safely recorded.
		$store_cap = $this->cap( 'faz_dsar_max_stored_per_day', self::MAX_STORED_PER_DAY );
		if ( $store_cap > 0 && $this->site_count() >= $store_cap ) {
			return $this->reject(
				'faz_dsar_site_throttled',
				__( 'This form has reached its daily limit for security reasons. Your request still matters: please email us directly to exercise your rights, and we will respond within the statutory deadline.', 'faz-cookie-manager' ),
				$context
			);
		}

		$ip_cap = $this->cap( 'faz_dsar_max_per_ip_per_day', self::MAX_PER_IP_PER_DAY );
		if ( $ip_cap > 0 && $this->ip_count() >= $ip_cap ) {
			return $this->reject(
				'faz_dsar_ip_throttled',
				__( 'Too many requests from this connection. Please try again tomorrow, or email us directly to exercise your rights.', 'faz-cookie-manager' ),
				$context
			);
		}

		return true;
	}

	/**
	 * Claim a slot in every throttle window.
	 *
	 * Called BEFORE the request is stored, deliberately. The counters are read
	 * in guard_submission() and written here, and between those two points two
	 * concurrent submissions can both read the pre-write value and both pass a
	 * ceiling — classic check-then-act. Claiming the slot before the work
	 * narrows that window from "store a post and send two emails" to a single
	 * read-modify-write, which is as far as it goes without serialising every
	 * submission behind one site-wide lock. That lock is deliberately not taken:
	 * a request that dies between acquiring and releasing it would leave the
	 * form shut for the whole site, which is precisely the failure these
	 * ceilings exist to prevent. The overshoot is bounded by how many requests
	 * the server runs at once, and these are flood ceilings, not quotas.
	 *
	 * @since 1.34.1
	 * @param string $email Requester's email address.
	 */
	public function record_submission( $email ) {
		set_transient( $this->email_rl_key( $email ), 1, HOUR_IN_SECONDS );
		$this->site_bump();
		$this->ip_bump();
	}

	/**
	 * Undo the per-address hold when the request could not be stored.
	 *
	 * The counters are left alone: one slot lost out of several hundred is
	 * harmless, while re-reading and rewriting them to give it back would widen
	 * exactly the race the claim above narrows.
	 *
	 * @since 1.34.1
	 * @param string $email Requester's email address.
	 */
	public function release_submission( $email ) {
		delete_transient( $this->email_rl_key( $email ) );
	}

	/**
	 * Whether per-request emails have been spent for this window.
	 *
	 * Checked after the request is recorded, so a flood costs the admin's inbox
	 * and nothing else. Past the ceiling both emails stop: the admin
	 * notification because one-per-submission is what a flood weaponises, and
	 * the requester's confirmation because that one goes to an address typed
	 * into a public form, which makes it the half an attacker can aim at
	 * somebody else.
	 *
	 * What does NOT stop is the admin finding out. Silence here would be worse
	 * than the flood it answers: an attacker who can switch off notifications
	 * can let a real request arrive unseen, and Art. 12(3) gives one month to
	 * answer it. So this is a change of channel, not a mute — see
	 * announce_suppressed(), and the pending count on the menu, which is the one
	 * channel no amount of traffic can touch.
	 *
	 * @since 1.34.1
	 * @return bool True when the per-request emails must be skipped.
	 */
	public function per_request_mail_exhausted() {
		$cap = $this->cap( 'faz_dsar_max_per_day', self::MAX_PER_DAY );
		if ( $cap <= 0 ) {
			return false;
		}
		return $this->site_count() > $cap;
	}

	/**
	 * Build a rejection, announcing it for logging / fail2ban-style tooling.
	 *
	 * @param string $code    Machine-readable reason.
	 * @param string $message Visitor-facing message.
	 * @param array  $context Sanitized submission fields.
	 * @return \WP_Error
	 */
	private function reject( $code, $message, $context ) {
		/**
		 * Fires when a DSAR submission is refused.
		 *
		 * @since 1.34.1
		 * @param string $code    One of faz_dsar_gate, faz_dsar_email_throttled,
		 *                        faz_dsar_site_throttled, faz_dsar_ip_throttled.
		 * @param array  $context Sanitized submission fields.
		 */
		do_action( 'faz_dsar_rejected', $code, $context );
		return new \WP_Error( $code, $message );
	}

	/**
	 * Resolve a filterable, non-negative cap.
	 *
	 * A filter returning 0 (or less) disables that ceiling, which is how a site
	 * that fronts the form with its own protection opts out of ours.
	 *
	 * @param string $filter  Filter name.
	 * @param int    $default Built-in value.
	 * @return int
	 */
	private function cap( $filter, $default ) {
		return max( 0, (int) apply_filters( $filter, $default ) );
	}

	/** Transient key for the per-email hourly throttle. */
	private function email_rl_key( $email ) {
		return 'faz_dsar_rl_em_' . substr( hash_hmac( 'sha256', strtolower( $email ), wp_salt() ), 0, 16 );
	}

	/**
	 * Transient key for the site-wide 24-hour counter.
	 *
	 * Every throttle key on this form shares the `faz_dsar_rl_` prefix on
	 * purpose: the test suites and the uninstall sweep purge the throttles by
	 * that prefix, and a counter named outside it would survive a reset and
	 * refuse submissions in a later run for reasons nothing had cleared.
	 */
	private static function day_key() {
		return 'faz_dsar_rl_cnt_all';
	}

	/** Transient key for this client's 24-hour counter. */
	private function ip_day_key() {
		return 'faz_dsar_rl_cnt_ip_' . substr( $this->hash_ip(), 0, 16 );
	}

	/**
	 * How many submissions the current window holds, from a stored payload.
	 *
	 * The window is enforced here rather than left to a storage expiry, because
	 * the site-wide counter is kept in an option and an option has no TTL.
	 *
	 * @param mixed $data Stored payload, or anything else.
	 * @return int
	 */
	private static function window_count( $data ) {
		if ( ! is_array( $data ) || ! isset( $data['n'], $data['start'] ) ) {
			return 0;
		}
		if ( time() - (int) $data['start'] >= DAY_IN_SECONDS ) {
			return 0;
		}
		return (int) $data['n'];
	}

	/**
	 * The payload for one more submission, without moving the window.
	 *
	 * Re-setting the window on every hit would turn a fixed 24-hour window into
	 * a rolling one that never closes while submissions keep arriving — the form
	 * would stay shut long after a flood stopped, which is worse than the flood.
	 * The window therefore closes 24 hours after the FIRST submission in it.
	 *
	 * @param mixed $data Stored payload, or anything else.
	 * @return array
	 */
	private static function window_bump( $data ) {
		$now   = time();
		$start = is_array( $data ) && isset( $data['start'] ) ? (int) $data['start'] : 0;
		$count = is_array( $data ) && isset( $data['n'] ) ? (int) $data['n'] : 0;

		if ( ! $start || $now - $start >= DAY_IN_SECONDS ) {
			$start = $now;
			$count = 0;
		}
		return array(
			'n'     => $count + 1,
			'start' => $start,
		);
	}

	/**
	 * Submissions recorded site-wide in the current window.
	 *
	 * Deliberately an option and not a transient. Where an external object
	 * cache is installed, a transient WITH an expiry never reaches the options
	 * table — it lives only in that cache, and a flush or an eviction takes it
	 * with it. The ceilings this feeds are what bound a flood for the whole
	 * site, and they must not be resettable by anything but time; the atomic
	 * lock a few lines up is an option for the same reason, stated in its own
	 * comment. The per-IP counter below stays a transient on purpose: its key
	 * space is unbounded (one per visitor), so it needs a storage that expires
	 * on its own, and losing one costs a single address's ceiling rather than
	 * the site's.
	 *
	 * @return int
	 */
	private function site_count() {
		return self::window_count( get_option( self::day_key() ) );
	}

	/** Claim one site-wide slot. */
	private function site_bump() {
		update_option( self::day_key(), self::window_bump( get_option( self::day_key() ) ), false );
	}

	/** Submissions recorded for this client in the current window. */
	private function ip_count() {
		return self::window_count( get_transient( $this->ip_day_key() ) );
	}

	/** Claim one slot for this client. */
	private function ip_bump() {
		$key  = $this->ip_day_key();
		$next = self::window_bump( get_transient( $key ) );
		// Keep the storage expiry aligned with the window the payload declares,
		// so the row disappears when the window does and never earlier.
		set_transient( $key, $next, max( 1, DAY_IN_SECONDS - ( time() - $next['start'] ) ) );
	}

	/**
	 * Tell the admin that requests are arriving without a mail each.
	 *
	 * Hourly, not once per window: the point is that the admin learns of a
	 * pending request while there is still time to answer it, and a single mail
	 * per 24 hours sent at the moment the ceiling tripped would be the easiest
	 * thing in the world to bury. It carries the counts, so one mail stands in
	 * for all the ones that were not sent, and it names the gate the site is
	 * missing.
	 *
	 * @since 1.34.1
	 * @param int $cap The per-request mail ceiling that was passed.
	 */
	public function announce_suppressed( $cap ) {
		$digest_key = 'faz_dsar_rl_digest';
		if ( false !== get_transient( $digest_key ) ) {
			return;
		}
		set_transient( $digest_key, 1, HOUR_IN_SECONDS );

		$admin_email = (string) get_option( 'admin_email' );
		if ( ! is_email( $admin_email ) ) {
			return;
		}
		$site_name = get_bloginfo( 'name' );
		$in_window = $this->site_count();
		$pending   = self::pending_count();

		wp_mail(
			$admin_email,
			/* translators: %s: site name */
			sprintf( __( '[%s] Data requests are arriving faster than they can be emailed', 'faz-cookie-manager' ), $site_name ),
			sprintf(
				/* translators: 1: site name, 2: requests in the last 24 hours, 3: the per-request mail ceiling, 4: requests awaiting action, 5: admin URL of the stored requests list */
				__( "%1\$s has received %2\$d data subject requests in the last 24 hours, past the %3\$d after which they are no longer emailed one by one. They are still being recorded: %4\$d are awaiting action.\n\nReview them here: %5\$s\n\nThis usually means the form is being targeted by automated submissions. Install a form-protection plugin (ALTCHA / OpenPorte, Turnstile, hCaptcha) and hook it into the faz_dsar_pre_submit filter, so the genuine requests are emailed individually again.\n\nYou will get this summary at most once an hour while it lasts.", 'faz-cookie-manager' ),
				$site_name,
				$in_window,
				$cap,
				$pending,
				admin_url( 'edit.php?post_type=' . self::POST_TYPE )
			)
		);
	}

	/**
	 * How many stored requests are awaiting action.
	 *
	 * Memoised for five minutes: it feeds the count on the admin menu, which is
	 * rendered on every admin page load, and the answer only has to be roughly
	 * current. `pending` covers records written before the status meta existed,
	 * which carry no value at all.
	 *
	 * @since 1.34.1
	 * @return int
	 */
	public static function pending_count() {
		$cached = get_transient( 'faz_dsar_pending_count' );
		if ( false !== $cached ) {
			return (int) $cached;
		}
		if ( ! post_type_exists( self::POST_TYPE ) ) {
			return 0;
		}
		$query = new \WP_Query(
			array(
				'post_type'        => self::POST_TYPE,
				'post_status'      => 'any',
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'no_found_rows'    => false,
				'suppress_filters' => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the count is memoised for five minutes and bounded by the daily ceiling; there is no other way to ask "awaiting action" without a status taxonomy.
				'meta_query'       => array(
					'relation' => 'OR',
					array(
						'key'     => '_dsar_status',
						'value'   => 'pending',
						'compare' => '=',
					),
					array(
						'key'     => '_dsar_status',
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);
		$count = (int) $query->found_posts;
		set_transient( 'faz_dsar_pending_count', $count, 5 * MINUTE_IN_SECONDS );
		return $count;
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
		$type_label = self::get_type_label( $type );

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
				__( 'Open the request to handle it (you can turn it into a WordPress erasure or export request from there): %s', 'faz-cookie-manager' ),
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
			array( 'Reply-To: "' . $safe_name . '" <' . $safe_email . '>' )
		);
	}

	/**
	 * Send a confirmation email to the requester.
	 */
	private function send_confirmation( $name, $email, $type ) {
		$site_name  = get_bloginfo( 'name' );
		$type_label = self::get_type_label( $type );

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
	public static function get_type_label( $type ) {
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
