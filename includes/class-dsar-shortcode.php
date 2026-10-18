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

	/**
	 * Read-only AJAX action that hands out a fresh nonce and signed stamp.
	 *
	 * frontend/js/faz-dsar.js calls it once, when the visitor starts filling
	 * the form, and writes the result over the values the (possibly cached)
	 * markup carried. See handle_refresh().
	 *
	 * @since 1.34.2
	 */
	const REFRESH_ACTION = 'faz_dsar_refresh';
	const POST_TYPE   = 'faz_dsar';

	/** Maximum allowed length for the free-text message field. */
	const MESSAGE_MAX_LENGTH = 5000;

	/**
	 * Default minimum seconds between a form being rendered and submitted.
	 *
	 * The nonce is not a per-submission cost. For an anonymous visitor
	 * WordPress derives it from uid 0 and an empty session token, so a single
	 * nonce scraped once from a public page is valid site-wide, from every
	 * source address, for 12 to 24 hours; a script that posts straight to
	 * `admin-ajax.php` pays for it once and then submits as fast as the server
	 * will answer. The dwell time is the cost the nonce is not: the form is
	 * stamped with the moment it was issued, the stamp is signed, and a
	 * submission that arrives sooner than this is refused.
	 *
	 * Three seconds, because the only thing that must never happen is refusing
	 * a data subject. A person typing a name, an email address and picking a
	 * request type cannot beat it; a person whose browser autofills all three
	 * and who clicks immediately might, and is told to submit again in a
	 * moment, which the next attempt satisfies by the passage of time alone.
	 * A script posting straight to the endpoint beats it always.
	 *
	 * Filter: `faz_dsar_min_dwell_seconds` (0 disables it).
	 */
	const MIN_DWELL_SECONDS = 3;

	/**
	 * Default leading hex zeros required of the proof-of-work hash.
	 *
	 * Four, i.e. one accepted hash in 65,536 — about 71,000 attempts on average.
	 * Measured with the shipped solver in headless Chromium: mean 254 ms from
	 * click to request, median 268 ms, worst of eight runs 495 ms. On an
	 * emulated low-end phone (Pixel 5 viewport, 4x CPU throttling) the same
	 * runs give mean 890 ms, worst 1,879 ms. The numbers come from
	 * `tests/unit/js/dsar-antibot-pow.test.mjs`, which runs the real
	 * `frontend/js/faz-dsar.js` and verifies every answer it produces.
	 *
	 * The count of attempts is geometrically distributed, so the tail is long:
	 * the client therefore carries a wall-clock budget and GIVES UP rather than
	 * freezing a form, and giving up means the submission is sent without an
	 * answer — which the server accepts. The worst case of the cost is a bot
	 * that got in for free, never a data subject who could not submit.
	 *
	 * Filter: `faz_dsar_pow_difficulty`, clamped to 1-6. Six is about 16
	 * million hashes and would take a slow phone the better part of a minute:
	 * the clamp is there so a well-meant "make it harder" cannot turn into a
	 * denial.
	 */
	const POW_DIFFICULTY = 4;

	/**
	 * Default cap on accepted requests per client IP per 24 hours.
	 *
	 * The 60-second lock below only spaces submissions out; on its own it lets
	 * a bot submit once a minute forever, which is exactly the "chain of spam
	 * submissions" shape reported from the field. This is the cumulative
	 * ceiling. Filter: `faz_dsar_max_per_ip_per_day` (0 disables it).
	 *
	 * IT ASSUMES THE CLIENT ADDRESS IDENTIFIES ONE VISITOR, and that assumption
	 * is not free. Behind a CDN or a reverse proxy whose forwarded header
	 * cannot be read, `faz_resolve_client_ip()` returns the proxy's own
	 * address: every visitor of the site then shares one counter and five
	 * submissions close the form for everybody for 24 hours — the site-wide
	 * denial of Art. 15-22 requests that MAX_PER_DAY below is written to avoid,
	 * reproduced at 5 instead of 500. So when the address cannot be shown to
	 * identify a visitor (`faz_client_ip_identifies_visitor()`), this layer is
	 * SKIPPED rather than applied: the site-wide ceilings and the
	 * one-per-email-per-hour limit still bound a flood, which costs the
	 * database rows the hard ceiling allows instead of everybody's rights. NOT
	 * the 60-second lock — claim_connection_lock() skips on the same predicate,
	 * for the same reason, so naming it here claimed a bound that is not there. The
	 * skip is specific to ceilings that deny a right: `faz_throttle_request()`
	 * keeps keying on the address even when it is shared, because the windows
	 * it keeps bound writes rather than refuse people, and a bounded site-wide
	 * window beats an unbounded one.
	 *
	 * Since 1.34.1 the resolver recognises a Cloudflare edge by itself, and
	 * every other topology once the site declares it — one filter each, and
	 * which one depends on the topology, not on taste. Another CDN: its
	 * published edge ranges in `faz_trusted_proxy_cidrs`, plus
	 * `faz_proxy_forwarded_header` when that edge overwrites a header of its
	 * own rather than appending to `X-Forwarded-For`. A reverse proxy on the
	 * same host: `faz_loopback_proxy_header`, naming the one header that proxy
	 * writes — unset by default, so a loopback peer reads nothing until it is
	 * named, which is why an unconfigured one lands here. A load balancer of
	 * one's own: `faz_trust_proxy_headers` scoped with `faz_trusted_proxies`.
	 * Adding a range alone does NOT give this ceiling a key when the edge
	 * writes some other header, which is the mistake the notices below are
	 * careful not to suggest. A skipped layer is reported in the hourly digest,
	 * and a refusal sends its own notice: a ceiling that denies a right must
	 * not deny it unseen.
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

	/**
	 * Whether this request has already been kept out of the page cache.
	 *
	 * One veto per request, however many forms the page renders.
	 *
	 * @since 1.34.2
	 * @var bool
	 */
	private $page_cache_vetoed = false;

	public function __construct() {
		add_shortcode( 'faz_dsar_form', array( $this, 'render' ) );
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'handle_submit' ) );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, array( $this, 'handle_submit' ) );
		add_action( 'wp_ajax_' . self::REFRESH_ACTION, array( $this, 'handle_refresh' ) );
		add_action( 'wp_ajax_nopriv_' . self::REFRESH_ACTION, array( $this, 'handle_refresh' ) );
		// The shortcode callback enqueues the handler wherever the form is
		// rendered server-side (posts, widgets, templates, builder elements);
		// this hook only covers forms injected client-side after page load.
		// The handler stays a separate file either way: an inline <script>
		// injected via innerHTML is silently ignored by browsers.
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_assets' ) );
		add_action( 'template_redirect', array( $this, 'maybe_veto_page_cache_early' ) );
		add_action( 'faz_after_activate', array( $this, 'assign_capabilities' ) );
		// Starts the clock the unstamped-submission grace window is measured
		// against (see stampless_still_possible()).
		add_action( 'faz_after_activate', array( $this, 'record_stamp_epoch' ) );
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
					// Where the form fetches a current nonce and stamp before it
					// is sent, so a copy a cache kept past their life still
					// submits. See handle_refresh().
					'refreshAction' => self::REFRESH_ACTION,
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
		$this->veto_page_cache();
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
.faz-dsar-wrap .faz-dsar-field-hint { font-size: 13px; color: #545454; margin: 4px 0 0; }
.faz-dsar-honeypot { display: none !important; }
		' );

		$nonce = wp_create_nonce( self::AJAX_ACTION );
		$id    = 'faz-dsar-' . wp_rand( 1000, 9999 );
		// Everything the server needs to price this submission, signed so the
		// client cannot rewrite it: the issue time (dwell), the randomised
		// honeypot field name, and the proof-of-work challenge when it is on.
		// Nothing is stored server-side — the signature is what makes the
		// values trustworthy when they come back.
		$stamp = self::issue_stamp();

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
				<?php if ( '' !== $stamp['sig'] ) : ?>
					<input type="hidden" name="faz_dsar_t" value="<?php echo esc_attr( $stamp['t'] ); ?>">
					<input type="hidden" name="faz_dsar_s" value="<?php echo esc_attr( $stamp['sig'] ); ?>">
				<?php endif; ?>
				<?php if ( '' !== $stamp['salt'] ) : ?>
					<?php
					// Proof-of-work challenge (opt-in, see pow_enabled()). The
					// difficulty travels in the open because it travels signed:
					// lowering it invalidates the stamp. `faz_pow_n` is filled
					// in by frontend/js/faz-dsar.js and stays empty when
					// JavaScript is off, which the server accepts — a puzzle
					// that can refuse a data subject is not an option here.
					?>
					<input type="hidden" name="faz_pow_salt" value="<?php echo esc_attr( $stamp['salt'] ); ?>">
					<input type="hidden" name="faz_pow_diff" value="<?php echo esc_attr( $stamp['diff'] ); ?>">
					<input type="hidden" name="faz_pow_n" value="">
				<?php endif; ?>

				<?php
				// Honeypot — bots fill it, humans don't. The field NAME is
				// per-render and derived from the signed stamp above
				// (honeypot_field()), because a field called `faz_hp_name` on
				// every site forever is a field a scripted client simply omits,
				// at zero cost. The server recomputes the name from the stamp,
				// so there is no per-visitor state to keep, and a submission
				// that carries a verified stamp but not this field did not come
				// from the markup that stamp was issued with.
				?>
				<div class="faz-dsar-honeypot" aria-hidden="true">
					<input type="text" name="<?php echo esc_attr( $stamp['hp'] ); ?>" tabindex="-1" autocomplete="off" aria-hidden="true">
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
					<select id="<?php echo esc_attr( $id ); ?>-type" name="dsar_type" required aria-required="true" aria-describedby="<?php echo esc_attr( $id ); ?>-type-hint">
						<option value=""><?php esc_html_e( '— Select a request type —', 'faz-cookie-manager' ); ?></option>
						<?php foreach ( $request_types as $val => $label ) : ?>
							<option value="<?php echo esc_attr( $val ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<?php
					// The select takes one right and the per-email throttle
					// holds a second submission for an hour, so a person who
					// wants two rights needs to be told, here, that one
					// message carries them all — not after the refusal.
					?>
					<p class="faz-dsar-field-hint" id="<?php echo esc_attr( $id ); ?>-type-hint"><?php esc_html_e( 'Exercising more than one right? Choose one here and name the others in the message below — a single submission covers them all.', 'faz-cookie-manager' ); ?></p>
				</div>

				<div class="faz-dsar-field">
					<label for="<?php echo esc_attr( $id ); ?>-consent-id"><?php esc_html_e( 'Consent ID (optional)', 'faz-cookie-manager' ); ?></label>
					<input type="text" id="<?php echo esc_attr( $id ); ?>-consent-id" name="dsar_consent_id" maxlength="190" pattern="[A-Za-z0-9=+\/]{1,190}" autocomplete="off" aria-describedby="<?php echo esc_attr( $id ); ?>-consent-help">
					<small id="<?php echo esc_attr( $id ); ?>-consent-help"><?php esc_html_e( 'Find this ID in your cookie preferences to help us locate your consent history.', 'faz-cookie-manager' ); ?></small>
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
	 * AJAX handler that hands out a fresh nonce and signed stamp for the form.
	 *
	 * Why it exists. The form bakes an anonymous nonce (12 to 24 hours) and a
	 * signed stamp (max_form_age(), 24 hours) into the page body. The page
	 * cache veto (veto_page_cache()) keeps the page out of caches only where it
	 * can see the form: a singular post whose content names the shortcode is
	 * vetoed before output, and everything else only from render(), through
	 * signals a cache honours only if it has not already decided — so a form
	 * in a widget, a builder template (Bricks, Oxygen and Elementor keep theirs
	 * in post meta), a block theme template or a synced pattern can still be
	 * served from a cache, and an edge cache the plugin cannot reach ignores
	 * the veto anyway. After a day every submission from such a copy was
	 * refused, and "reload the page" returned the same copy: an Art. 12(2)
	 * failure on the channel the site relies on. frontend/js/faz-dsar.js now
	 * fetches current values from here when the visitor starts filling the
	 * form and writes them over the cached ones, wherever the form was placed.
	 *
	 * Why it is safe. Read-only and unauthenticated, like the DNSMPI refresh
	 * (Do_Not_Sell_Shortcode::handle_nonce_refresh()). The nonce and the stamp
	 * are exactly what render() prints into a public page, so this hands out
	 * nothing a GET of that page does not. None of the anti-bot costs is
	 * weakened: the stamp's issue time is now, so the minimum dwell is counted
	 * from this call (the response says how long, and the script waits it out
	 * before sending); a proof-of-work challenge, when the gate is on, is a new
	 * unpredictable salt the client must solve; and the honeypot name is derived
	 * from the new stamp as always. The stamp is deliberately NOT single-use,
	 * for the reason check_antibot() gives; the per-email, per-IP and site-wide
	 * ceilings are what bound how many requests a client can file.
	 *
	 * admin-ajax.php sends nocache_headers() itself and every page cache
	 * excludes it; the explicit no-store keeps the contract in this file.
	 *
	 * @since 1.34.2
	 * @return void
	 */
	public function handle_refresh() {
		Cache_Compatibility::send_no_store_headers();
		$stamp = self::issue_stamp();
		wp_send_json_success(
			array(
				'nonce' => wp_create_nonce( self::AJAX_ACTION ),
				't'     => (int) $stamp['t'],
				'sig'   => (string) $stamp['sig'],
				'hp'    => (string) $stamp['hp'],
				'salt'  => (string) $stamp['salt'],
				'diff'  => (int) $stamp['diff'],
				// Seconds the script must let pass before sending, so the dwell
				// check measured from this fresh stamp is met by a real person
				// who submits straight away, instead of refused as "too fast".
				'wait'  => $this->cap( 'faz_dsar_min_dwell_seconds', self::MIN_DWELL_SECONDS ),
			)
		);
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
			// "Refresh" is the remedy on a live page. On a page a cache kept
			// past the nonce's life — which veto_page_cache() prevents, but a
			// site can filter the veto off, and a cache it cannot reach may
			// exist — a refresh returns the same markup, so the message also
			// names the one channel that always works.
			// Through reject() like every other refusal, so faz_dsar_rejected
			// fires for the one a stale cache produces most. Nothing from the
			// request is trusted yet, so no field travels with it.
			$refused = $this->reject( 'faz_dsar_nonce', __( 'Invalid security token. Please refresh the page and try again, or email us directly to exercise your rights.', 'faz-cookie-manager' ), array() );
			wp_send_json_error( $refused->get_error_message() );
			return;
		}

		// The honeypot used to be checked here, against a field name hardcoded
		// into every copy of the plugin. It now lives in check_antibot(),
		// reached through guard_submission() below, together with the dwell
		// time and the proof-of-work answer: all three read the same signed
		// stamp, and a check that reads half a stamp is a check that can be
		// told half a story.

		$name     = isset( $_POST['dsar_name'] ) ? sanitize_text_field( wp_unslash( $_POST['dsar_name'] ) ) : '';
		$email       = isset( $_POST['dsar_email'] ) ? sanitize_email( wp_unslash( $_POST['dsar_email'] ) ) : '';
		$type        = isset( $_POST['dsar_type'] ) ? sanitize_key( wp_unslash( $_POST['dsar_type'] ) ) : '';
		$message     = isset( $_POST['dsar_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['dsar_message'] ) ) : '';
		$consent_id  = isset( $_POST['dsar_consent_id'] ) && is_string( $_POST['dsar_consent_id'] ) ? wp_unslash( $_POST['dsar_consent_id'] ) : '';
		if ( ( isset( $_POST['dsar_consent_id'] ) && ! is_string( $_POST['dsar_consent_id'] ) ) || ! self::valid_consent_id( $consent_id ) ) {
			// The field is optional, so the way out is to leave it empty; the
			// email fallback is named as on every other refusal. The raw value
			// failed validation and is not passed to the action.
			$refused = $this->reject(
				'faz_dsar_consent_id',
				__( 'Please enter a valid consent ID or leave the field empty. You can also email us directly to exercise your rights.', 'faz-cookie-manager' ),
				array(
					'name'       => $name,
					'email'      => $email,
					'type'       => $type,
					'message'    => $message,
					'consent_id' => '',
				)
			);
			wp_send_json_error( $refused->get_error_message() );
			return;
		}
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
				'consent_id' => $consent_id,
			)
		);
		if ( is_wp_error( $guard ) ) {
			wp_send_json_error( $guard->get_error_message() );
			return;
		}

		// The 60-second per-connection lock (see claim_connection_lock()).
		if ( ! $this->claim_connection_lock() ) {
			wp_send_json_error( __( 'Too many requests. Please wait 1 minute before submitting again.', 'faz-cookie-manager' ) );
			return;
		}

		// Claim the slot before the work (see record_submission()).
		$this->record_submission( $email );

		try {
			$post_id = $this->store_request( $name, $email, $type, $message, $consent_id );
			if ( ! $post_id ) {
				$this->release_submission( $email );
				// DB error — roll back the lock AND its window so the user can
				// retry immediately. Released BEFORE wp_send_json_error(): it
				// calls wp_die() which calls exit(), and PHP `finally` blocks are
				// NOT executed when exit() terminates the script. Without this
				// explicit release the lock would persist until manual admin
				// intervention, permanently locking out the originating IP.
				$this->release_connection_lock( false );
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
			// Release the DB lock regardless of success or exception; the
			// transient keeps the 60-second window running.
			$this->release_connection_lock( true );
		}

		wp_send_json_success(
			array(
				'message' => __( 'Your request has been received. We will respond within 30 days as required by law.', 'faz-cookie-manager' ),
			)
		);
	}

	/**
	 * Claim the 60-second per-connection lock, or report that it is held.
	 *
	 * Atomic DB-backed lock via add_option (MySQL INSERT IGNORE) plus a
	 * transient for the 60-second durability window. On installs without a
	 * persistent object cache (the majority) wp_cache_add is not inter-process
	 * atomic, so add_option is what prevents a concurrent bypass.
	 *
	 * Keyed on the connection, so it applies only where the connection is one
	 * visitor's. Behind a proxy whose forwarded header cannot be read every
	 * visitor resolves to the proxy's own address, and a lock on that address
	 * is not "one submission a minute per visitor" but "one submission a
	 * minute for the whole site": under a flood from one source every
	 * legitimate data subject is told to wait, which is the Art. 12(2) denial
	 * the per-IP ceiling already steps aside to avoid. Same rule here, same
	 * predicate (`faz_client_ip_identifies_visitor()`): where the address does
	 * not identify a visitor there is no lock, and the per-email limit and the
	 * cumulative ceilings keep bounding the flood.
	 *
	 * @since 1.34.1
	 * @return bool True when the submission may proceed (lock claimed, or no
	 *              lock applies); false when another submission from this
	 *              connection holds it.
	 */
	public function claim_connection_lock() {
		if ( ! $this->ip_identifies_visitor() ) {
			return true;
		}
		list( $rl_key, $lock_key ) = $this->connection_lock_keys();
		if ( false !== get_transient( $rl_key ) || ! add_option( $lock_key, 1, '', 'no' ) ) {
			return false;
		}
		// Lock acquired — arm the durability window.
		set_transient( $rl_key, 1, 60 );
		return true;
	}

	/**
	 * Release the per-connection lock claimed by claim_connection_lock().
	 *
	 * @since 1.34.1
	 * @param bool $keep_window True after a stored submission, so the 60-second
	 *                          window keeps running; false to roll the window
	 *                          back too, so a visitor whose submission failed
	 *                          on the server can retry at once.
	 * @return void
	 */
	public function release_connection_lock( $keep_window = true ) {
		if ( ! $this->ip_identifies_visitor() ) {
			return;
		}
		list( $rl_key, $lock_key ) = $this->connection_lock_keys();
		if ( ! $keep_window ) {
			delete_transient( $rl_key );
		}
		delete_option( $lock_key );
	}

	/**
	 * The window transient key and the lock option key for this connection.
	 *
	 * @return array{0:string,1:string}
	 */
	private function connection_lock_keys() {
		$hash = substr( $this->hash_ip(), 0, 16 );
		return array( 'faz_dsar_rl_' . $hash, 'faz_dsar_lock_' . $hash );
	}

	/**
	 * Decide whether a submission may be accepted.
	 *
	 * Four layers, cheapest first:
	 *
	 * 1. The built-in anti-bot checks — randomised honeypot, signed minimum
	 *    dwell time, optional proof of work. See check_antibot(), which also
	 *    states why every one of them fails OPEN.
	 * 2. `faz_dsar_pre_submit` — the integration point for a third-party
	 *    protection plugin (ALTCHA / OpenPorte, Turnstile, hCaptcha). Those
	 *    plugins gate the forms they know — Contact Form 7, WPForms, Gravity —
	 *    and this form is a plugin-specific `wp_ajax` action, so without a hook
	 *    they cannot see it at all. Recipes are in the filter's own docblock a
	 *    few lines below.
	 * 3. One submission per email address per hour.
	 * 4. Cumulative 24-hour ceilings, per IP and site-wide.
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

		$antibot = $this->check_antibot( $context );
		if ( is_wp_error( $antibot ) ) {
			return $antibot;
		}

		/**
		 * Gate a DSAR submission with your own verification.
		 *
		 * Return a `WP_Error` to refuse it — its message is shown to the
		 * visitor verbatim — or leave the incoming value alone to accept. The
		 * raw payload is still in `$_POST`, so a gate reads its own challenge
		 * field directly; `$context` carries the sanitized name, email, type
		 * and message, for a gate that scores the content.
		 *
		 * This exists because a cookie-consent plugin has no business shipping
		 * three vendor integrations: three APIs would be three maintenance
		 * treadmills and three entries in the readme's External Services
		 * section, and the plugin itself must make no outbound call. The
		 * verification request therefore belongs in YOUR snippet (a
		 * site-specific plugin, or your theme's functions.php), which is also
		 * where the vendor's secret key belongs. Five lines each:
		 *
		 * Cloudflare Turnstile — add the widget to the page with
		 * `<div class="cf-turnstile" data-sitekey="…"></div>` plus Cloudflare's
		 * script, and verify the token it writes into `cf-turnstile-response`:
		 *
		 *     add_filter( 'faz_dsar_pre_submit', function ( $verdict ) {
		 *         $token = isset( $_POST['cf-turnstile-response'] ) ? sanitize_text_field( wp_unslash( $_POST['cf-turnstile-response'] ) ) : '';
		 *         $res   = wp_remote_post( 'https://challenges.cloudflare.com/turnstile/v0/siteverify', array( 'body' => array( 'secret' => TURNSTILE_SECRET, 'response' => $token, 'remoteip' => faz_resolve_client_ip() ) ) );
		 *         if ( is_wp_error( $res ) ) { return $verdict; } // verification unreachable: let the data subject through
		 *         $ok = ! empty( json_decode( wp_remote_retrieve_body( $res ), true )['success'] );
		 *         return $ok ? $verdict : new WP_Error( 'turnstile', __( 'Please complete the verification and submit again.' ) );
		 *     } );
		 *
		 * hCaptcha — same shape, different endpoint and field:
		 *
		 *     add_filter( 'faz_dsar_pre_submit', function ( $verdict ) {
		 *         $token = isset( $_POST['h-captcha-response'] ) ? sanitize_text_field( wp_unslash( $_POST['h-captcha-response'] ) ) : '';
		 *         $res   = wp_remote_post( 'https://api.hcaptcha.com/siteverify', array( 'body' => array( 'secret' => HCAPTCHA_SECRET, 'response' => $token ) ) );
		 *         if ( is_wp_error( $res ) ) { return $verdict; }
		 *         $ok = ! empty( json_decode( wp_remote_retrieve_body( $res ), true )['success'] );
		 *         return $ok ? $verdict : new WP_Error( 'hcaptcha', __( 'Please complete the verification and submit again.' ) );
		 *     } );
		 *
		 * A hosted ALTCHA (the mechanism behind OpenPorte) — the widget posts
		 * its solution in `altcha`, and the ALTCHA server verifies it:
		 *
		 *     add_filter( 'faz_dsar_pre_submit', function ( $verdict ) {
		 *         $payload = isset( $_POST['altcha'] ) ? sanitize_text_field( wp_unslash( $_POST['altcha'] ) ) : '';
		 *         $res     = wp_remote_post( 'https://eu.altcha.org/api/v1/challenge/verify', array( 'headers' => array( 'Authorization' => 'Bearer ' . ALTCHA_API_KEY, 'Content-Type' => 'application/json' ), 'body' => wp_json_encode( array( 'payload' => $payload ) ) ) );
		 *         if ( is_wp_error( $res ) ) { return $verdict; }
		 *         $ok = 200 === (int) wp_remote_retrieve_response_code( $res );
		 *         return $ok ? $verdict : new WP_Error( 'altcha', __( 'Please complete the verification and submit again.' ) );
		 *     } );
		 *
		 * Note the identical third line in all three. A verification service
		 * that is down, rate-limited or blocked by the host's egress rules must
		 * not become a refusal: this form exercises GDPR Art. 15-22 and Art.
		 * 12(2) obliges the controller to FACILITATE that, so an unreachable
		 * gate lets the submission through. It is also why none of these
		 * recipes uses a visual puzzle setting that a screen-reader user, an
		 * old phone or a keyboard-only visitor could fail to solve.
		 *
		 * Akismet is deliberately absent, and that absence is the
		 * recommendation. The body of this form is a named person's identity
		 * plus the fact that they are exercising a data-protection right;
		 * sending it to a third-party classifier is a processing decision with
		 * its own legal basis, its own record in the Art. 30 register and its
		 * own line in the privacy policy. A cookie-consent plugin must not make
		 * that decision on the controller's behalf. A controller who has mapped
		 * it can of course call Akismet from this very filter.
		 *
		 * @since 1.34.1
		 * @param null|\WP_Error $verdict Null to accept; a WP_Error to refuse.
		 * @param array          $context Sanitized submission fields.
		 */
		$verdict = apply_filters( 'faz_dsar_pre_submit', null, $context );
		if ( is_wp_error( $verdict ) ) {
			$message = $verdict->get_error_message();
			if ( '' === $message ) {
				$message = __( 'Submission rejected.', 'faz-cookie-manager' );
			}
			return $this->reject( 'faz_dsar_gate', $message, $context );
		}

		// This layer keys on the visitor's own address, so it can only ever
		// hold the same person — but that person may be exercising a second
		// right (access, then erasure) or correcting the type they picked. The
		// hour is a bound on confirmation mail aimed at an unverified address
		// and stays; what the refusal must not do is strand them, so it names
		// the fallback the other ceilings name. The form itself says, next to
		// the request type, that one message can carry several rights.
		if ( '' !== $email && false !== get_transient( $this->email_rl_key( $email ) ) ) {
			return $this->reject(
				'faz_dsar_email_throttled',
				__( 'Too many requests. Please wait 1 hour before submitting again, or email us directly to exercise your rights.', 'faz-cookie-manager' ),
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

		// The per-IP layer applies only where the key is one visitor's. Where
		// it is a proxy's, applying it would refuse unrelated data subjects for
		// 24 hours (see MAX_PER_IP_PER_DAY), so the layer steps aside and the
		// ceilings above keep bounding the flood. The condition is recorded for
		// the digest, because an admin cannot configure what nobody told them.
		$ip_cap = $this->cap( 'faz_dsar_max_per_ip_per_day', self::MAX_PER_IP_PER_DAY );
		if ( $ip_cap > 0 ) {
			if ( ! $this->ip_identifies_visitor() ) {
				$this->note_ip_layer_skipped();
			} elseif ( $this->ip_count() >= $ip_cap ) {
				$this->announce_ip_throttled( $ip_cap );
				return $this->reject(
					'faz_dsar_ip_throttled',
					__( 'Too many requests from this connection. Please try again tomorrow, or email us directly to exercise your rights.', 'faz-cookie-manager' ),
					$context
				);
			}
		}

		return true;
	}

	/**
	 * Issue the signed stamp a rendered form carries.
	 *
	 * Three facts travel with the form and come back with the submission: when
	 * it was issued, what its honeypot field is called, and — when the
	 * proof-of-work gate is on — which challenge it must answer. All three are
	 * covered by one HMAC over `wp_salt()`, which is what makes them usable
	 * without keeping a row per visitor: the server recomputes the signature
	 * from the posted values and either they are the ones it issued or they are
	 * not. No challenge table, no session, no cookie, nothing to clean up. A
	 * page cache can only serve an older stamp — and the page that carries
	 * one is kept out of the page cache by veto_page_cache(), so an older
	 * stamp reaches the age window only on a site that filtered the veto off.
	 *
	 * @since 1.34.1
	 * @return array {
	 *     @type int    $t    Issue timestamp.
	 *     @type string $sig  Signature over t + salt + difficulty.
	 *     @type string $hp   Honeypot field name for this render.
	 *     @type string $salt Proof-of-work salt, '' when the gate is off.
	 *     @type int    $diff Required leading hex zeros, 0 when the gate is off.
	 * }
	 */
	public static function issue_stamp() {
		$t    = time();
		$salt = '';
		$diff = 0;
		$sig  = '';

		if ( '' !== self::stamp_secret() ) {
			if ( self::pow_enabled() ) {
				// Unpredictable per render: a salt a client could guess ahead
				// of time is a challenge it could pre-solve, which is the whole
				// cost gone. wp_generate_password() is seeded from
				// random_bytes().
				$salt = \wp_generate_password( 16, false, false );
				$diff = self::pow_difficulty();
			}
			$sig = self::stamp_signature( $t, $salt, $diff );
		}

		return array(
			't'    => $t,
			'sig'  => $sig,
			'hp'   => self::honeypot_field( $t ),
			'salt' => $salt,
			'diff' => $diff,
		);
	}

	/**
	 * The key everything on this form is signed and keyed with.
	 *
	 * `wp_salt()` is pluggable and always present in a loaded WordPress, so in
	 * production this is simply `wp_salt()`. The guard is for the paths where
	 * this class runs with WordPress only partly in place — the standalone unit
	 * harnesses do exactly that — and it returns '' instead of throwing.
	 *
	 * Everything downstream reads '' as "this install does not stamp": no stamp
	 * is rendered, none is verified, and the unstamped-submission rule stands
	 * aside, so the form keeps working and keeps accepting requests. A fatal
	 * raised while rendering a public page would take that page down with it,
	 * which is a worse outcome than any amount of spam — and on this form it
	 * would also be an outright denial of Art. 15-22.
	 *
	 * The leading backslash is not decoration. This file lives in
	 * `FazCookie\Includes`, and PHP resolves an unqualified call against the
	 * current namespace before the global one, so `wp_salt()` here means
	 * `FazCookie\Includes\wp_salt()` — undefined. It is the same mistake as
	 * issues #85 and #9.
	 *
	 * @since 1.34.1
	 * @return string The salt, or '' when WordPress has not provided one.
	 */
	private static function stamp_secret() {
		return function_exists( 'wp_salt' ) ? (string) \wp_salt() : '';
	}

	/**
	 * Sign the facts a form carries.
	 *
	 * Truncated to 32 hex characters (128 bits), which is far past anything
	 * forgeable and keeps the markup small.
	 *
	 * @since 1.34.1
	 * @param int    $t    Issue timestamp.
	 * @param string $salt Proof-of-work salt, or ''.
	 * @param int    $diff Proof-of-work difficulty, or 0.
	 * @return string Signature, or '' when this install cannot sign.
	 */
	private static function stamp_signature( $t, $salt, $diff ) {
		$secret = self::stamp_secret();
		if ( '' === $secret ) {
			return '';
		}
		return substr(
			hash_hmac( 'sha256', 'faz_dsar_stamp|' . (int) $t . '|' . $salt . '|' . (int) $diff, $secret ),
			0,
			32
		);
	}

	/**
	 * The honeypot field name for a given issue time.
	 *
	 * Derived, not stored: the same timestamp always yields the same name, so
	 * the server can recompute what it rendered without remembering anything
	 * about the visitor. Keyed on `wp_salt()`, so the name cannot be predicted
	 * from another site or from the timestamp alone.
	 *
	 * Falls back to the original static name on an install that cannot sign
	 * (see stamp_secret()), so the field is still rendered and a filled one is
	 * still refused — the derived name is a cost raised against a bot, not a
	 * precondition for the form working.
	 *
	 * @since 1.34.1
	 * @param int $t Issue timestamp.
	 * @return string
	 */
	private static function honeypot_field( $t ) {
		$secret = self::stamp_secret();
		if ( '' === $secret ) {
			return 'faz_hp_name';
		}
		return 'faz_hp_' . substr( hash_hmac( 'sha256', 'faz_dsar_hp|' . (int) $t, $secret ), 0, 12 );
	}

	/**
	 * Whether the proof-of-work gate is switched on.
	 *
	 * Default FALSE, so no site changes behaviour on update. Deliberately a
	 * filter and not a setting: the switch belongs with the rest of this form's
	 * knobs (`faz_dsar_max_per_ip_per_day`, `faz_dsar_min_dwell_seconds`, the
	 * proxy filters), all of which are filters, and a checkbox would cost a
	 * settings key, a sanitiser entry, a view, admin JS and a translated label
	 * — for a control a site touches once, if ever, and only while it is being
	 * targeted. One line in a snippet:
	 *
	 *     add_filter( 'faz_dsar_pow_enabled', '__return_true' );
	 *
	 * @since 1.34.1
	 * @return bool
	 */
	public static function pow_enabled() {
		/**
		 * Switch on the self-contained proof-of-work gate for the DSAR form.
		 *
		 * @since 1.34.1
		 * @param bool $enabled Default false.
		 */
		return (bool) \apply_filters( 'faz_dsar_pow_enabled', false );
	}

	/**
	 * Required leading hex zeros, clamped to a range a phone can still clear.
	 *
	 * @since 1.34.1
	 * @return int
	 */
	public static function pow_difficulty() {
		/**
		 * Difficulty of the DSAR proof of work, in leading hex zeros.
		 *
		 * @since 1.34.1
		 * @param int $difficulty Default 4 (one hash in 65,536).
		 */
		$diff = (int) \apply_filters( 'faz_dsar_pow_difficulty', self::POW_DIFFICULTY );
		return max( 1, min( 6, $diff ) );
	}

	/**
	 * How long a stamped form stays submittable.
	 *
	 * 24 hours, which is deliberately NOT tighter than the nonce rendered
	 * beside it: WordPress accepts an anonymous nonce for 12 to 24 hours, so
	 * when a form is older than that the nonce is what fails first, and this
	 * window never becomes a second, earlier expiry that an admin would have
	 * to discover. Its job is only to stop a scraped stamp from being
	 * replayable forever on a site that has raised `nonce_life`.
	 *
	 * Neither expiry is meant to be reached by a visitor: the script replaces
	 * both values with current ones from handle_refresh() before sending, and
	 * the page that carries the form is kept out of full-page caches where the
	 * plugin can see it (veto_page_cache()), because a cached copy older than
	 * the nonce would refuse every visitor with a "refresh the page" that
	 * returns the same copy. Where the refresh cannot run and that veto
	 * was filtered off, or a cache the plugin cannot reach kept the page
	 * anyway, both refusals name the email fallback alongside the reload.
	 *
	 * @since 1.34.1
	 * @return int Seconds; 0 disables the upper bound.
	 */
	private function max_form_age() {
		return $this->cap( 'faz_dsar_max_form_age', DAY_IN_SECONDS );
	}

	/**
	 * Read and verify the stamp on the current submission.
	 *
	 * @since 1.34.1
	 * @return array|null|false Verified stamp (t, salt, diff); null when the
	 *                          submission carries no stamp at all; false when
	 *                          it carries one that does not verify.
	 */
	private function verified_stamp() {
		if ( '' === self::stamp_secret() ) {
			// Nothing was signed, so nothing can be verified. Reported as "no
			// stamp", which the caller accepts.
			return null;
		}

		$t_raw = isset( $_POST['faz_dsar_t'] ) ? trim( (string) \wp_unslash( $_POST['faz_dsar_t'] ) ) : '';
		$sig   = isset( $_POST['faz_dsar_s'] ) ? trim( (string) \wp_unslash( $_POST['faz_dsar_s'] ) ) : '';

		if ( '' === $t_raw || '' === $sig ) {
			return null;
		}

		$t = (int) $t_raw;
		// The salt and the difficulty are read back from the request and then
		// CHECKED by the signature, which is the point: a client that lowers
		// the difficulty or swaps in a pre-solved salt invalidates the stamp,
		// so there is nothing to keep server-side to stop it.
		$salt = isset( $_POST['faz_pow_salt'] ) ? trim( (string) \wp_unslash( $_POST['faz_pow_salt'] ) ) : '';
		$salt = preg_replace( '/[^A-Za-z0-9]/', '', $salt );
		$diff = isset( $_POST['faz_pow_diff'] ) ? (int) $_POST['faz_pow_diff'] : 0;

		if ( ! hash_equals( self::stamp_signature( $t, $salt, $diff ), $sig ) ) {
			return false;
		}

		return array(
			't'    => $t,
			'salt' => $salt,
			'diff' => $diff,
		);
	}

	/**
	 * Whether a submission with no stamp can still be a genuine one.
	 *
	 * It can, for a bounded while after this version starts running, and the
	 * bound is not a guess. The nonce was already verified before this code
	 * runs, and WordPress accepts a nonce for at most `nonce_life` (24 hours by
	 * default); a nonce is created when the page is RENDERED, including a page
	 * that was then cached. So a valid nonce means "this markup was rendered
	 * less than nonce_life ago". Once this version has been live for longer
	 * than that, every renderable form carries a stamp, and a submission
	 * without one did not come from a form this site served.
	 *
	 * Inside the window the opposite is true: a page cached before the update
	 * holds a pre-stamp form with a nonce that still verifies, and refusing it
	 * would deny Art. 15-22 requests for as long as that cache lives. So the
	 * window exists, it is generous (nonce_life plus a day), and it closes by
	 * itself.
	 *
	 * The epoch is recorded by `record_stamp_epoch()` on activation and on
	 * every version bump. If it is missing — an install where that hook never
	 * ran — this returns true: an unknown epoch is not grounds for refusing a
	 * data subject.
	 *
	 * @since 1.34.1
	 * @return bool
	 */
	private function stampless_still_possible() {
		if ( '' === self::stamp_secret() ) {
			// This install issues no stamps, so no submission can carry one.
			return true;
		}

		$since = (int) \get_option( 'faz_dsar_stamped_since', 0 );
		if ( $since <= 0 ) {
			return true;
		}
		// Core's own filter, read rather than redefined: a site that lengthens
		// nonce life lengthens this window with it, because the premise above
		// is exactly "the nonce proves the render is recent".
		$nonce_life = (int) \apply_filters( 'nonce_life', DAY_IN_SECONDS );
		$window     = max( DAY_IN_SECONDS, $nonce_life ) + DAY_IN_SECONDS;
		return time() < ( $since + $window );
	}

	/**
	 * Note when the first version that stamps forms started running.
	 *
	 * Hooked to `faz_after_activate`, which fires on activation and on every
	 * version bump, but written with add_option, so the epoch is set ONCE: the
	 * first activation that stamps forms records it, and no later activation
	 * or upgrade moves it. The grace window above exists for forms rendered
	 * BEFORE any stamp existed and still sitting in a page cache; a form
	 * rendered after the epoch carries a stamp whatever version rendered it,
	 * so restarting the window on every upgrade would only reopen the
	 * stampless path for a day each time. Should the stamp format ever change
	 * incompatibly, that is a new epoch under a NEW option key, not a push of
	 * this one.
	 *
	 * @since 1.34.1
	 */
	public function record_stamp_epoch() {
		\add_option( 'faz_dsar_stamped_since', time(), '', 'yes' );
	}

	/**
	 * The built-in anti-bot layer: honeypot, dwell time, proof of work.
	 *
	 * EVERY check here fails open toward the data subject, and that is not a
	 * concession, it is the requirement. This form is how a person exercises
	 * GDPR Art. 15-22, and Art. 12(2) obliges the controller to facilitate
	 * that; a gate that refuses a genuine request is not an inconvenience, it
	 * is a denial of a right. So the shape is the same one `guard_submission()`
	 * already uses for the per-IP ceiling, which skips rather than refuses when
	 * `faz_client_ip_identifies_visitor()` cannot name a visitor: when a check
	 * cannot be evaluated, it does not run.
	 *
	 * Concretely:
	 *
	 * - No stamp at all? Accept while a form rendered by an earlier version can
	 *   still be sitting in a page cache, because refusing it would deny
	 *   requests for as long as that cache lives. Once it cannot — the nonce
	 *   already verified, and a valid nonce means the markup is recent, see
	 *   stampless_still_possible() — refuse. That window closes on its own, and
	 *   it is the only reason the dwell check below cannot be skipped by simply
	 *   omitting two fields.
	 * - A stamp that does not verify? Refuse, with "reload the page". This is
	 *   the one case that cannot be an honest client: the signature covers
	 *   values this server issued. It is also recoverable in one click, and if
	 *   `wp_salt()` ever did change, the nonce checked before this would have
	 *   failed first with the same instruction.
	 * - Too fast? Refuse, with "submit again in a moment" — which the next
	 *   attempt satisfies by the passage of time, needing nothing from the
	 *   visitor but patience. Too old? "Reload the page".
	 * - Proof of work enabled but no answer? Accept. That is a browser with
	 *   JavaScript off, and it is also a script that chose not to solve — the
	 *   two are indistinguishable from the server and the tie must be broken in
	 *   the data subject's favour. What the gate does buy is this: a client that
	 *   DOES run the form's JavaScript (which is most spam tooling, since the
	 *   form only posts through `fetch`) pays real CPU per submission, and a
	 *   client that fakes an answer is refused. The teeth against a bare
	 *   straight-to-endpoint post are the dwell time above, not this.
	 *
	 * @since 1.34.1
	 * @param array $context Sanitized submission fields.
	 * @return true|\WP_Error
	 */
	public function check_antibot( $context ) {
		$stamp = $this->verified_stamp();

		if ( false === $stamp ) {
			return $this->reject( 'faz_dsar_stamp', $this->stale_form_message(), $context );
		}

		if ( null === $stamp ) {
			// An unstamped submission is the one case where fail-open and
			// "the dwell time must actually cost something" pull against each
			// other: if omitting two fields were always enough to skip the
			// dwell check, the check would be decorative, and the reported
			// spam chain posts straight to this endpoint. The tie is broken by
			// time, not by guessing — see stampless_still_possible(): while a
			// form rendered by an earlier version can still be in a page
			// cache, an unstamped submission is accepted; once it cannot be,
			// it is refused.
			if ( $this->stampless_still_possible() ) {
				// The only check left is the original static honeypot, kept
				// exactly for this path. Its absence proves nothing here —
				// there is no stamp to say what the markup contained — so only
				// a FILLED field refuses.
				if ( ! empty( $_POST['faz_hp_name'] ) ) {
					return $this->reject( 'faz_dsar_honeypot', __( 'Submission rejected.', 'faz-cookie-manager' ), $context );
				}
				return true;
			}
			return $this->reject( 'faz_dsar_stamp', $this->stale_form_message(), $context );
		}

		$age = time() - $stamp['t'];
		$min = $this->cap( 'faz_dsar_min_dwell_seconds', self::MIN_DWELL_SECONDS );
		$max = $this->max_form_age();

		// A negative age means the stamp is dated in the future, which this
		// server signed and only a clock moved backwards can explain. Skip the
		// lower bound rather than refuse an hour of genuine requests over it.
		if ( $min > 0 && $age >= 0 && $age < $min ) {
			return $this->reject(
				'faz_dsar_too_fast',
				__( 'Please wait a moment and submit the form again.', 'faz-cookie-manager' ),
				$context
			);
		}

		if ( $max > 0 && $age > $max ) {
			return $this->reject( 'faz_dsar_stale', $this->stale_form_message(), $context );
		}

		$hp = self::honeypot_field( $stamp['t'] );
		if ( ! empty( $_POST[ $hp ] ) ) {
			return $this->reject( 'faz_dsar_honeypot', __( 'Submission rejected.', 'faz-cookie-manager' ), $context );
		}

		// There is deliberately NO "one submission per rendered form" check
		// here, and the reason is worth keeping: this stamp cannot be made
		// single-use without denying the right it exists to serve.
		//
		// It is rendered into the page body, so every visitor served the same
		// cached HTML carries the SAME stamp. And with the proof of work off —
		// the default — the signature covers an empty salt and a zero
		// difficulty, so it is a function of the issue second alone and even
		// uncached renders collide inside that second. Spending it on first use
		// therefore refuses the second DATA SUBJECT, not the second attempt: on
		// a site that filtered veto_page_cache() off, or whose cache ignored
		// it, one submission would lock the form for everyone until the cache
		// turns over, and even on an uncached site two visitors served in the
		// same second would share one stamp. That trades an Art. 12(2) failure
		// for a replay worth one GET to an attacker, who is still bounded by
		// the per-email, per-IP and site-wide ceilings — those, not the stamp,
		// are what cap how many requests one client can file.
		//
		// A site that wants a genuinely per-visitor gate hangs one on
		// `faz_dsar_pre_submit` (see its PHPDoc): Turnstile and hCaptcha mint a
		// token per visitor and verify it server-side, which is the property a
		// stamp baked into cacheable markup can never have.

		/**
		 * Whether a missing honeypot field refuses the submission.
		 *
		 * Default true, and sound because the stamp verified: the markup that
		 * stamp was issued with DID contain this field, and a text input is
		 * submitted — empty — by every browser that posts the form it sits in,
		 * whether or not JavaScript runs. A submission that omits it therefore
		 * did not come from that markup. The filter exists for the one way this
		 * can be wrong on a real site: a front end that strips hidden inputs,
		 * an optimiser that drops `aria-hidden` blocks, or a builder that
		 * rebuilds the form. If data requests start being refused with
		 * `faz_dsar_honeypot` and nobody is attacking the site, set this false.
		 *
		 * @since 1.34.1
		 * @param bool $reject Default true.
		 */
		if ( ! isset( $_POST[ $hp ] ) && (bool) \apply_filters( 'faz_dsar_reject_missing_honeypot', true ) ) {
			return $this->reject( 'faz_dsar_honeypot', __( 'Submission rejected.', 'faz-cookie-manager' ), $context );
		}

		return $this->check_pow( $stamp, $context );
	}

	/**
	 * Verify the proof of work, if this form carried a challenge.
	 *
	 * ALTCHA's model, self-hosted: the server names a salt and a difficulty,
	 * the client searches for an integer whose `SHA-256(salt + n)` begins with
	 * that many hex zeros, and the server spends ONE hash checking it. No third
	 * party, no API key, no asset from a CDN, nothing stored per challenge —
	 * the salt is trusted because the stamp it travelled in is signed.
	 *
	 * Note what drives this: the salt and difficulty from the SIGNED stamp, not
	 * `pow_enabled()`. A site that switches the gate off mid-flood does not
	 * thereby start ignoring answers to challenges it already issued, and a
	 * site that switches it on does not refuse the forms rendered before.
	 *
	 * @since 1.34.1
	 * @param array $stamp   Verified stamp.
	 * @param array $context Sanitized submission fields.
	 * @return true|\WP_Error
	 */
	private function check_pow( $stamp, $context ) {
		if ( '' === $stamp['salt'] || $stamp['diff'] < 1 ) {
			return true;
		}

		$answer = isset( $_POST['faz_pow_n'] ) ? trim( (string) \wp_unslash( $_POST['faz_pow_n'] ) ) : '';

		if ( '' === $answer ) {
			/**
			 * Fires when a challenged submission arrives with no answer.
			 *
			 * The submission is ACCEPTED — see check_antibot() — because this
			 * is what a browser with JavaScript disabled looks like. The action
			 * is here so a site that wants to know can count them.
			 *
			 * @since 1.34.1
			 * @param array $context Sanitized submission fields.
			 */
			\do_action( 'faz_dsar_pow_unanswered', $context );
			return true;
		}

		// Bounded before hashing: the length cap is what keeps a megabyte of
		// digits from being handed to hash() on every request.
		if ( ! preg_match( '/^[0-9]{1,20}$/', $answer ) ) {
			return $this->reject( 'faz_dsar_pow', __( 'Submission rejected.', 'faz-cookie-manager' ), $context );
		}

		$hash = hash( 'sha256', $stamp['salt'] . $answer );
		if ( 0 !== strncmp( $hash, str_repeat( '0', (int) $stamp['diff'] ), (int) $stamp['diff'] ) ) {
			return $this->reject( 'faz_dsar_pow', __( 'Submission rejected.', 'faz-cookie-manager' ), $context );
		}

		// A correct answer is NOT spent, and that is not an oversight. Look at
		// what the gate does twenty lines up: no answer at all is accepted,
		// because a client with JavaScript off and a client that chose not to
		// solve are indistinguishable here and the tie goes to the data subject.
		// So a script never needs to solve this, let alone replay a solution —
		// it omits `faz_pow_n` and walks through. A single-use claim therefore
		// cannot deter the only client it would be aimed at; the one thing it
		// reliably does is fire on an honest browser that DID run the solver.
		//
		// And it fires hard: the salt is rendered into the page, so a full-page
		// cache hands one challenge to every visitor, the first accepted
		// submission would spend it, and everyone after would be told to reload
		// a page that reloading does not change. Zero deterrence against a bot,
		// a site-wide denial against people exercising Art. 15-22. The cost of
		// this gate is paid per render by whoever answers it, and that is all it
		// was ever able to charge — see check_antibot() for the same conclusion
		// reached about the stamp.

		return true;
	}

	/**
	 * The one message for "this form is no longer the one I issued".
	 *
	 * Shared by the forged stamp, the expired stamp and the replayed challenge,
	 * because they are one situation for the visitor and have one remedy, and
	 * because telling a bot which of the three it tripped is free information.
	 *
	 * The remedy is the reload — and, since 1.34.2, the email fallback beside
	 * it, for the one case where a reload changes nothing: a page a cache kept
	 * past the stamp's life. veto_page_cache() keeps the form out of page
	 * caches, but the veto can be filtered off and a cache beyond the plugin's
	 * reach can ignore it; a refusal that only says "reload" would then be an
	 * instruction the visitor cannot follow.
	 *
	 * @since 1.34.1
	 * @return string
	 */
	private function stale_form_message() {
		return __( 'This form has expired. Please reload the page and submit it again, or email us directly to exercise your rights.', 'faz-cookie-manager' );
	}

	/**
	 * Veto the page cache before output when the queried page carries the form.
	 *
	 * The shortcode's own render runs inside the_content, after the theme has
	 * printed <head>, so its no-store header can almost never be sent there;
	 * only the constant, the LiteSpeed action and the FlyingPress filter get
	 * through. Caches that read only response headers (a reverse proxy, an
	 * edge cache) need the header, so a singular page whose content names the
	 * shortcode is vetoed here, on template_redirect, while headers can still
	 * be sent. A form placed elsewhere (a widget, a template) is still vetoed
	 * by render() through the signals that do not need headers.
	 *
	 * @since 1.34.2
	 */
	public function maybe_veto_page_cache_early() {
		if ( ! is_singular() ) {
			return;
		}
		$post = get_queried_object();
		if ( ! is_object( $post ) || ! isset( $post->post_content ) ) {
			return;
		}
		if ( has_shortcode( (string) $post->post_content, 'faz_dsar_form' ) ) {
			$this->veto_page_cache();
		}
	}

	/**
	 * Keep the page that renders the form out of full-page caches.
	 *
	 * The form bakes an anonymous nonce (valid 12 to 24 hours) and a signed
	 * stamp (24 hours) into the page body. A full-page cache that keeps the
	 * page longer than that — LiteSpeed's default public TTL is seven days,
	 * Cloudflare APO holds HTML until purged, WP Rocket's lifespan can be
	 * raised — hands every later visitor the expired copy: each submission is
	 * refused, the refusal says to refresh, and the refresh returns the same
	 * copy. Nothing is stored, no mail is sent, and the admin sees nothing,
	 * which fails GDPR Art. 12(2) on the one channel the site relies on.
	 *
	 * So the page is not cached. The same signals the plugin already sends
	 * for a country-dependent banner (see Frontend::veto_page_cache_for_bot_variance()):
	 * the `DONOTCACHEPAGE` / `LSCACHE_NO_CACHE` constants that WP Rocket, W3
	 * Total Cache, WP Super Cache, Cache Enabler, SG Optimizer and LiteSpeed
	 * read when they decide at shutdown; the `litespeed_control_set_nocache` action,
	 * which LSCache honours whenever it is fired because it only records
	 * intent; the `flying_press_is_cacheable` filter, the only signal
	 * FlyingPress reads; and Cache_Compatibility::NO_STORE_HEADERS for the
	 * edge — sent only while headers can still be sent, which on most themes
	 * they cannot by the time a shortcode runs, since `<head>` went out
	 * first. The header half is therefore best-effort; the constant, action
	 * and filter half is what does the work, and all three are registered
	 * regardless of output state.
	 *
	 * Cache Compatibility Mode is not consulted: its promise is a
	 * visitor-invariant page, and this page is invariant across visitors but
	 * not across time — the nonce expires. The veto is scoped to the page
	 * that renders the form, which is one page on almost every site. A site
	 * that renders the form in a sitewide widget, and would rather keep its
	 * cache than the form's self-sufficiency, can filter the veto off; the
	 * refusal messages then carry the email fallback, so a visitor handed a
	 * stale copy is still told a way that works.
	 *
	 * Since 1.34.2 the veto is no longer what the form's validity rests on:
	 * the script refreshes the nonce and the stamp from handle_refresh() before
	 * sending, so a copy a cache kept anyway — a widget, a builder or block
	 * template, a synced pattern, an edge cache the veto cannot reach — still
	 * submits. The veto stays for a visitor without JavaScript, who posts what
	 * the markup carried.
	 *
	 * @since 1.34.2
	 * @return void
	 */
	private function veto_page_cache() {
		if ( $this->page_cache_vetoed ) {
			return;
		}
		/**
		 * Whether a page that renders [faz_dsar_form] is kept out of full-page caches.
		 *
		 * Default true. Returning false restores caching of the page, at the
		 * cost of the form refusing every visitor once the cached copy is
		 * older than the nonce rendered into it (12 to 24 hours).
		 *
		 * @since 1.34.2
		 * @param bool $veto True to keep the page uncached.
		 */
		if ( ! apply_filters( 'faz_dsar_veto_page_cache', true ) ) {
			return;
		}
		$this->page_cache_vetoed = true;

		// The same constants and headers every page veto uses
		// (Cache_Compatibility), so an edge that reads only CDN-Cache-Control
		// or Surrogate-Control is told too.
		Cache_Compatibility::define_no_cache_constants();
		do_action( 'litespeed_control_set_nocache', 'FAZ Cookie Manager data subject request form' );
		// Registered at render time, which is before FlyingPress asks: it
		// decides from its buffered output at shutdown.
		add_filter( 'flying_press_is_cacheable', '__return_false' );

		Cache_Compatibility::send_no_store_headers();
	}

	/**
	 * Record that the per-IP ceiling could not be applied to this request.
	 *
	 * Not a refusal and not an error: the request goes through. It is a fact
	 * about the site's topology — the resolved client address is a proxy's, or
	 * missing — that the admin needs in order to declare that topology
	 * (`faz_loopback_proxy_header` for a proxy on the same host,
	 * `faz_trusted_proxy_cidrs` with `faz_proxy_forwarded_header` for another
	 * CDN, `faz_trust_proxy_headers` with `faz_trusted_proxies` for a load
	 * balancer of their own) and get the ceiling back. It rides the hourly
	 * digest rather than a mail of its own, because
	 * on an affected site it is true of EVERY request and a mail per request
	 * would be the flood.
	 *
	 * Stored for a full window under the shared `faz_dsar_rl_` prefix, so a
	 * reset clears it with the rest of the throttles.
	 *
	 * @since 1.34.1
	 */
	private function note_ip_layer_skipped() {
		set_transient( 'faz_dsar_rl_ip_unidentified', 1, DAY_IN_SECONDS );

		/**
		 * Fires when the per-IP ceiling is skipped for lack of a per-visitor address.
		 *
		 * @since 1.34.1
		 */
		do_action( 'faz_dsar_ip_layer_skipped' );
	}

	/**
	 * Tell the admin that the per-IP ceiling is refusing submissions.
	 *
	 * `announce_suppressed()` cannot carry this: it is keyed to the per-request
	 * MAIL ceiling and is reachable only once a request has been stored
	 * successfully — which is precisely what did not happen here. Without this
	 * notice, five submissions could close the form for 24 hours with nothing
	 * sent and nothing shown anywhere, the only trace being the
	 * `faz_dsar_rejected` action, which has no in-plugin consumer.
	 *
	 * Hourly, under its own transient and the shared prefix, for the same
	 * reason as the digest: one mail at the moment a ceiling tripped is the
	 * easiest thing in the world to bury, and a mail per refusal would hand the
	 * flood a second amplifier.
	 *
	 * @since 1.34.1
	 * @param int $cap The per-IP ceiling that was reached.
	 */
	private function announce_ip_throttled( $cap ) {
		$notice_key = 'faz_dsar_rl_ip_notice';
		if ( false !== get_transient( $notice_key ) ) {
			return;
		}
		set_transient( $notice_key, 1, HOUR_IN_SECONDS );

		$admin_email = (string) get_option( 'admin_email' );
		if ( ! is_email( $admin_email ) ) {
			return;
		}
		$site_name = get_bloginfo( 'name' );

		wp_mail(
			$admin_email,
			/* translators: %s: site name */
			sprintf( __( '[%s] A data request was refused by the per-connection limit', 'faz-cookie-manager' ), $site_name ),
			sprintf(
				/* translators: 1: site name, 2: the per-IP ceiling, 3: admin URL of the stored requests list */
				__( "%1\$s refused a data subject request: the connection it came from had already sent %2\$d in the last 24 hours.\n\nIf that is a flood, nothing needs doing — the visitor was given an email fallback and the limit resets 24 hours after the first request in the window.\n\nIf genuine requests are being refused, the usual cause is that every visitor resolves to the same address because the site sits behind a proxy whose forwarded header the plugin has not been told to read. What restores a per-visitor key depends on which proxy it is, and one line is enough:\n\n- A reverse proxy or cache on the same WordPress host, reached over loopback (nginx, Varnish): set faz_loopback_proxy_header to the one header that proxy writes — HTTP_X_FORWARDED_FOR if its config says proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for, HTTP_X_REAL_IP if it says proxy_set_header X-Real-IP \$remote_addr. Nothing is read from a loopback proxy until this is named, because a header it does not write arrives from the visitor untouched.\n\n- Any other front end the plugin sees over a private address — a proxy in a separate container (REMOTE_ADDR like 172.17.0.3), a load balancer on the LAN — or a CDN other than Cloudflare: add that peer's own range to faz_trusted_proxy_cidrs, for example 172.17.0.0/16 for a Docker bridge or the CDN's published edge ranges. faz_loopback_proxy_header will not reach it: it applies to loopback peers only. Adding the range is the whole change if the front end appends to X-Forwarded-For. If it overwrites a header of its own instead (True-Client-IP, Fastly-Client-IP), also set faz_proxy_forwarded_header to that header — the range alone will not do it.\n\n- Cloudflare: already handled, nothing to set.\n\n- Last resort, for a front end that overwrites the forwarded header rather than appending to it: enable faz_trust_proxy_headers and scope it with faz_trusted_proxies. This path reads the leftmost entry of the chain, so on a front end that appends it would hand the visitor control of the value — prefer faz_trusted_proxy_cidrs above, which reads the chain from the right.\n\nfaz_dsar_max_per_ip_per_day changes the ceiling, and 0 switches it off.\n\nStored requests: %3\$s\n\nYou will get this notice at most once an hour.", 'faz-cookie-manager' ),
				$site_name,
				(int) $cap,
				admin_url( 'edit.php?post_type=' . self::POST_TYPE )
			)
		);
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
		 * @since 1.34.2 Also for an expired nonce (faz_dsar_nonce) and a
		 *               malformed consent ID (faz_dsar_consent_id).
		 * @param string $code    One of faz_dsar_nonce, faz_dsar_consent_id,
		 *                        faz_dsar_gate, faz_dsar_email_throttled,
		 *                        faz_dsar_site_throttled, faz_dsar_ip_throttled,
		 *                        faz_dsar_stamp, faz_dsar_too_fast,
		 *                        faz_dsar_stale, faz_dsar_honeypot, faz_dsar_pow.
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
		return 'faz_dsar_rl_em_' . substr( hash_hmac( 'sha256', strtolower( $email ), \wp_salt() ), 0, 16 );
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
	 * It also reports the one thing an admin cannot otherwise see: that the
	 * per-connection ceiling is not in force because no per-visitor address can
	 * be resolved (see note_ip_layer_skipped()). That is the layer which would
	 * normally stop a flood from one source, so a digest that did not mention
	 * it would be describing half the situation.
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

		$body = sprintf(
			/* translators: 1: site name, 2: requests in the last 24 hours, 3: the per-request mail ceiling, 4: requests awaiting action, 5: admin URL of the stored requests list */
			__( "%1\$s has received %2\$d data subject requests in the last 24 hours, past the %3\$d after which they are no longer emailed one by one. They are still being recorded: %4\$d are awaiting action.\n\nReview them here: %5\$s\n\nThis usually means the form is being targeted by automated submissions. Install a form-protection plugin (ALTCHA / OpenPorte, Turnstile, hCaptcha) and hook it into the faz_dsar_pre_submit filter, so the genuine requests are emailed individually again.\n\nYou will get this summary at most once an hour while it lasts.", 'faz-cookie-manager' ),
			$site_name,
			$in_window,
			$cap,
			$pending,
			admin_url( 'edit.php?post_type=' . self::POST_TYPE )
		);

		if ( false !== get_transient( 'faz_dsar_rl_ip_unidentified' ) ) {
			$body .= "\n\n" . __( "One limit is not helping here: the per-connection ceiling is switched off on this site because every visitor resolves to the same address, which happens behind a proxy whose forwarded header the plugin has not been told to read. It is deliberately skipped rather than applied, since applying it would refuse unrelated people's requests for 24 hours. What gets it back depends on which proxy sits in front, and it is one line either way: for a reverse proxy or cache on this same host, reached over loopback, set faz_loopback_proxy_header to the single header that proxy writes (HTTP_X_FORWARDED_FOR for proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for, HTTP_X_REAL_IP for proxy_set_header X-Real-IP \$remote_addr) — nothing is read from it until that header is named, because one it does not write arrives from the visitor untouched; for any front end the plugin sees over a private address instead, such as a proxy in a separate container or a load balancer on the LAN, and for a CDN other than Cloudflare, add that peer's own range to faz_trusted_proxy_cidrs (172.17.0.0/16 for a Docker bridge, or the CDN's published edge ranges), since faz_loopback_proxy_header applies to loopback peers only, and if that front end overwrites a header of its own rather than appending to X-Forwarded-For, also name it in faz_proxy_forwarded_header, because the range alone will not do it; faz_trust_proxy_headers with faz_trusted_proxies remains available but reads the leftmost entry of the chain, so prefer the range above, which reads it from the right. Cloudflare needs nothing.", 'faz-cookie-manager' );
		}

		wp_mail(
			$admin_email,
			/* translators: %s: site name */
			sprintf( __( '[%s] Data requests are arriving faster than they can be emailed', 'faz-cookie-manager' ), $site_name ),
			$body
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

	/** Validate the exact alphabet used by the consent-log identifier. */
	public static function valid_consent_id( $value ) {
		return is_string( $value ) && ( '' === $value || 1 === preg_match( '#^[A-Za-z0-9=+/]{1,190}$#D', $value ) );
	}

	/**
	 * Compare a declared consent ID with the consent cookie of the browser
	 * that submits the request.
	 *
	 * Not proof of ownership -- a cookie can be copied, and a requester may
	 * write from another device -- but a signal the administrator should see
	 * next to the verification button: a match says the ID was read from the
	 * submitting browser's own record; a mismatch that the request names
	 * someone else's history or a mistyped ID. Only the outcome is stored,
	 * never the cookie's ID.
	 *
	 * @param string $consent_id Declared, already validated consent ID.
	 * @return string '' (nothing declared), 'match', 'mismatch' or 'no_cookie'.
	 */
	public static function consent_cookie_match( $consent_id ) {
		$consent_id = (string) $consent_id;
		if ( '' === $consent_id ) {
			return '';
		}
		$parsed = function_exists( 'faz_parse_consent_cookie' ) ? faz_parse_consent_cookie() : array();
		$stored = isset( $parsed['consentid'] ) ? (string) $parsed['consentid'] : '';
		if ( '' === $stored ) {
			return 'no_cookie';
		}
		return hash_equals( $stored, $consent_id ) ? 'match' : 'mismatch';
	}

	/**
	 * Store the request as a private post so it survives email failures.
	 *
	 * @return int Post ID.
	 */
	private function store_request( $name, $email, $type, $message, $consent_id = '' ) {
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
					'_dsar_consent_id' => $consent_id,
					'_dsar_consent_id_cookie' => self::consent_cookie_match( $consent_id ),
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
			$consent_id = (string) get_post_meta( $post_id, '_dsar_consent_id', true );
			if ( '' !== $consent_id ) {
				$body .= "\n\n" . __( 'Consent ID:', 'faz-cookie-manager' ) . ' ' . $consent_id;
			}
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
