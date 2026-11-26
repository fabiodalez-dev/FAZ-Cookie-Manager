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
 * The render is visitor-invariant. Until 1.34.2 it read the opt-out cookie
 * and emitted either the opt-out form or a "you have already opted out"
 * notice with only a withdraw button; under a full-page cache the first
 * logged-out visitor to warm the entry chose which of the two everybody else
 * saw, and an entry primed by an opted-out visitor left every other visitor
 * with no control to opt OUT with (Cal. Civ. Code 1798.135(a)) until it was
 * purged. Now one document ships both states — the opt-out form live, the
 * withdraw state inert in a <template> — and frontend/js/faz-dnsmpi.js picks
 * from document.cookie, which is the visitor's own and never cached. The
 * nonce the same cache freezes is recoverable the same way: a stale one is
 * refused with a code the script recognises, and it fetches fresh nonces from
 * the no-store `faz_dnsmpi_nonce` action and resends once.
 *
 * The form also works with no script at all: it posts to admin-ajax.php with
 * the PLAIN_POST_ARG marker in its action URL, and the same handlers answer
 * that request with a confirmation page instead of JSON (respond()).
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
	/**
	 * Read-only AJAX action that hands out fresh opt-out and rescind nonces.
	 *
	 * @since 1.34.2
	 */
	const NONCE_ACTION   = 'faz_dnsmpi_nonce';
	const COOKIE_NAME    = 'fazcookie-dnsmpi';
	const COOKIE_DAYS    = 365;
	const STATUS_FIELD   = 'dnsmpi_optout';
	const STATUS_RESCIND = 'dns_rescinded';

	/**
	 * Query argument on the forms' own action URL. A request that carries it
	 * is the browser submitting the form by itself (no script, or the script
	 * blocked or broken), so the answer must be a page a person can read.
	 * frontend/js/faz-dnsmpi.js posts to the bare admin-ajax.php URL and never
	 * sends it.
	 */
	const PLAIN_POST_ARG = 'faz_dnsmpi_plain';

	/**
	 * Site-wide ceiling on per-request admin notification mail, per 24 hours.
	 *
	 * Every accepted opt-out mailed admin_email, and the only bound was the
	 * per-address 60-second window, which an address-rotating client walks
	 * past. The DSAR form already caps its own per-request mail the same way.
	 * Past the ceiling the opt-out is still recorded and honoured; only the
	 * mail is skipped (every opt-out stays visible in the consent log).
	 * Filterable via `faz_dnsmpi_max_notifications_per_day`.
	 */
	const MAX_NOTIFICATIONS_PER_DAY = 50;

	/**
	 * Option holding the admin-mail window: array( 'n' => count, 'start' => epoch ).
	 * Prefixed faz_dnsmpi_rl_ like the other Do-Not-Sell throttles.
	 */
	const MAIL_WINDOW_OPTION = 'faz_dnsmpi_rl_mail_day';

	/**
	 * Option holding the second the shared-address audit slot was last taken
	 * in (see claim_shared_audit_slot()).
	 */
	const SHARED_AUDIT_OPTION = 'faz_dnsmpi_shared_audit_slot';

	/**
	 * Prefix of the per-day counters of opt-outs and withdrawals that were
	 * honoured without a consent-log row of their own (see count_unlogged()).
	 * One options row per kind per UTC day: faz_dnsmpi_unlogged_optout_20261126.
	 * Deliberately NOT under faz_dnsmpi_rl_ / faz_dnsmpi_lock_: those are
	 * throttle state that is swept; this is part of the record.
	 */
	const UNLOGGED_OPTION_PREFIX = 'faz_dnsmpi_unlogged_';

	/**
	 * How long a per-day counter is kept: the 24 months the CCPA Regulations
	 * (7101) ask a business to keep its record of consumer requests for,
	 * plus a month of margin.
	 */
	const UNLOGGED_RETENTION_DAYS = 760;

	public function __construct() {
		add_shortcode( 'faz_do_not_sell', array( $this, 'render' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'handle_optout' ) );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, array( $this, 'handle_optout' ) );
		add_action( 'wp_ajax_' . self::RESCIND_ACTION, array( $this, 'handle_rescind' ) );
		add_action( 'wp_ajax_nopriv_' . self::RESCIND_ACTION, array( $this, 'handle_rescind' ) );
		add_action( 'wp_ajax_' . self::NONCE_ACTION, array( $this, 'handle_nonce_refresh' ) );
		add_action( 'wp_ajax_nopriv_' . self::NONCE_ACTION, array( $this, 'handle_nonce_refresh' ) );
		// render() enqueues the handler; this hook only covers forms a page
		// builder injects client-side (see maybe_enqueue_assets()).
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_assets' ) );
	}

	/**
	 * Load the DNSMPI form JS on pages that do not render the shortcode.
	 *
	 * render() enqueues it wherever the form is rendered server-side, so a
	 * site that never shows the opt-out form — most of them, CCPA or not —
	 * no longer loads it on every page (#308). Builders that inject the form
	 * client-side can opt back in through `faz_load_shortcode_assets_everywhere`.
	 */
	public function maybe_enqueue_assets() {
		if ( is_admin() ) {
			return;
		}
		if ( faz_load_shortcode_assets_everywhere( 'dnsmpi' ) ) {
			$this->enqueue_dnsmpi_assets();
		}
	}

	/**
	 * Register and enqueue the DNSMPI form handler script.
	 */
	private function enqueue_dnsmpi_assets() {
		if ( ! wp_script_is( 'faz-dnsmpi-form', 'registered' ) ) {
			// Prefer the minified build (~2.3 KB vs ~5.7 KB).
			$suffix = faz_asset_suffix( 'frontend/js/faz-dnsmpi' );
			wp_register_script(
				'faz-dnsmpi-form',
				FAZ_PLUGIN_URL . 'frontend/js/faz-dnsmpi' . $suffix . '.js',
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
					// Which cookie decides the visible state, and where a fresh
					// nonce comes from when the one in the (possibly cached)
					// markup has expired. See render().
					'nonceAction'    => self::NONCE_ACTION,
					'cookieName'     => function_exists( 'faz_consent_cookie_name' ) ? faz_consent_cookie_name( self::COOKIE_NAME ) : self::COOKIE_NAME,
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
	 *
	 * Deliberately reads nothing about the visitor: not the opt-out cookie,
	 * not the consent cookie, nothing. The page this lands on is cacheable —
	 * the plugin's Cache Compatibility Mode promises exactly that — and a
	 * cached render is replayed to visitors whose state the server never saw.
	 * So both states are rendered for everyone, and which one the visitor
	 * gets is decided in the browser from document.cookie by
	 * frontend/js/faz-dnsmpi.js:
	 *
	 *   - the opt-out form is LIVE. It is the mechanism the law requires the
	 *     page to offer, so it is what a visitor with no cookie — and a visitor
	 *     with JavaScript off — must find without help. It is a real form
	 *     (method and action), so for that visitor it also WORKS: through
	 *     1.40.0 it had neither, and without the script a submit was a GET of
	 *     the same page that recorded nothing and set no cookie;
	 *   - the "already opted out / withdraw" state sits inert in a <template>.
	 *     Template content is not rendered, not focusable, not matched by
	 *     querySelector(), and the script swaps it in only for a visitor who
	 *     carries the cookie. The DOM therefore holds exactly one state at a
	 *     time, as it always did, only now the right one for THIS visitor.
	 *
	 * Both nonces are rendered, one per form. A cached copy older than the
	 * nonce life (12 to 24 hours for an anonymous visitor) carries expired
	 * ones; the handlers refuse those with the `stale_nonce` code and the
	 * script fetches fresh ones from handle_nonce_refresh() and resends once,
	 * so the visitor's click lands instead of being told to refresh a page the
	 * cache will serve again unchanged.
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
		// Where the browser sends the form when no script intercepts the
		// submit. The same for every visitor, so the render stays cacheable.
		$plain_url = $this->plain_post_url();

		$this->enqueue_dnsmpi_assets();

		ob_start();
		?>
		<div class="faz-dnsmpi-wrap">
			<h3><?php echo esc_html( $atts['title'] ); ?></h3>

			<div class="faz-dnsmpi-state" data-faz-dnsmpi-state="optout">
				<p><?php esc_html_e( 'As a US resident in a state with applicable privacy laws, you have the right to opt out of the sale of your personal information. Submit the form below to exercise this right.', 'faz-cookie-manager' ); ?></p>
				<form class="faz-dnsmpi-form" method="post" action="<?php echo esc_url( $plain_url ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::AJAX_ACTION ); ?>">
					<input type="hidden" name="nonce"  value="<?php echo esc_attr( $nonce ); ?>">
					<button type="submit" class="faz-dnsmpi-btn"><?php echo esc_html( $atts['button'] ); ?></button>
				</form>
				<div class="faz-dnsmpi-notice" role="status" aria-live="polite" aria-atomic="true" style="display:none;"></div>
			</div>

			<?php
			// Inert until frontend/js/faz-dnsmpi.js finds the opt-out cookie
			// in document.cookie and swaps it in for the state above. Rendered
			// for every visitor so the page stays cacheable; see render().
			?>
			<template class="faz-dnsmpi-rescind-template">
				<div class="faz-dnsmpi-state" data-faz-dnsmpi-state="rescind">
					<div class="faz-dnsmpi-notice success">
						<?php esc_html_e( 'You have already submitted an opt-out request. We will not sell or share your personal information.', 'faz-cookie-manager' ); ?>
					</div>
					<p><?php esc_html_e( 'Changed your mind? You can withdraw your opt-out at any time.', 'faz-cookie-manager' ); ?></p>
					<form class="faz-dnsmpi-rescind-form" method="post" action="<?php echo esc_url( $plain_url ); ?>">
						<input type="hidden" name="action" value="<?php echo esc_attr( self::RESCIND_ACTION ); ?>">
						<input type="hidden" name="nonce"  value="<?php echo esc_attr( $rescind_nonce ); ?>">
						<button type="submit" class="faz-dnsmpi-btn faz-dnsmpi-rescind-btn"><?php esc_html_e( 'Withdraw opt-out / opt back in', 'faz-cookie-manager' ); ?></button>
					</form>
					<div class="faz-dnsmpi-notice" role="status" aria-live="polite" aria-atomic="true" style="display:none;"></div>
				</div>
			</template>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Whether the current AJAX request originates from this site.
	 *
	 * A CSRF control for the unauthenticated opt-out/rescind endpoints, whose
	 * shared nopriv nonce cannot distinguish a same-origin submission from a
	 * cross-site replay. A browser controls these request headers and a page
	 * cannot suppress its own Origin on a cross-origin POST, so this cannot be
	 * spoofed away by the attacker's page.
	 *
	 * Prefers the Fetch Metadata signal (`Sec-Fetch-Site`): `same-origin` is
	 * accepted. `same-site` alone would admit sibling subdomains (a compromised
	 * subdomain could scrape the public nonce and replay the mutation), and a
	 * POST can never legitimately report `none` (that value is reserved for
	 * user-initiated navigations). When the header is absent (older clients,
	 * or a proxy that does not forward it), falls back to requiring the
	 * `Origin` (or `Referer`) scheme, host, AND port to match home_url() —
	 * host alone would again let a sibling subdomain or a different-scheme/port
	 * origin through.
	 *
	 * The one widening is an origin the site declared as its own: the hosts a
	 * translation proxy (GTranslate), a mirror or a CDN serves this same site
	 * on — Settings → Trusted Alternate Domains, the auto-detected GTranslate
	 * domains and the `faz_trusted_origins` filter. The opt-out form rendered
	 * there posts with that host in Origin, and refusing it left a visitor of
	 * the translated site unable to opt out at all. Shared with the consent
	 * log through faz_is_same_origin_request(); see it for the exact rules.
	 *
	 * No usable origin signal at all is still a refusal, because nothing
	 * proves the request came from this site. Browsers attach `Origin` to
	 * every non-GET fetch/XHR — including same-origin ones — so the normal
	 * submission carries it even under `Referrer-Policy: no-referrer`. The
	 * residual gap is a client old enough to send neither `Sec-Fetch-Site`
	 * (Chrome 76+, Firefox 90+, Safari 16.4+) nor `Origin` on a same-origin
	 * POST, on a site that also suppresses the Referer: there the opt-out is
	 * refused rather than silently accepted, which is the safe direction for
	 * a state change.
	 *
	 * @return bool True when the request is same-origin.
	 */
	private function is_same_origin_request() {
		return faz_is_same_origin_request();
	}

	/**
	 * The refusal for a submission whose nonce no longer verifies.
	 *
	 * The payload carries `code: stale_nonce` beside the message. The nonce
	 * is rendered into a cacheable page and an anonymous one lives 12 to 24
	 * hours, so a visitor served a cached copy older than that fails here
	 * through no fault of their own, and "refresh the page" would hand them
	 * the same copy. The code lets frontend/js/faz-dnsmpi.js tell this apart
	 * from every other refusal, fetch fresh nonces (handle_nonce_refresh())
	 * and resend once; the message is what it shows if that fails too.
	 *
	 * @since 1.34.2
	 * @return array{success:bool,data:array}
	 */
	private function stale_nonce_result() {
		return array(
			'success' => false,
			'data'    => array(
				'message' => __( 'Invalid security token. Please refresh the page and try again.', 'faz-cookie-manager' ),
				'code'    => 'stale_nonce',
			),
		);
	}

	/**
	 * Answer the request with the outcome of process_optout() / process_rescind().
	 *
	 * JSON for the script, a page for a browser that submitted the form by
	 * itself. Which one is asked for only chooses the shape of the answer:
	 * the nonce and the origin were checked, and the state changed or not,
	 * before this runs.
	 *
	 * @param array  $result array( 'success' => bool, 'data' => mixed ).
	 * @param string $action AJAX_ACTION or RESCIND_ACTION.
	 * @return void
	 */
	private function respond( array $result, $action ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- selects the response format only; reads nothing as data.
		if ( isset( $_GET[ self::PLAIN_POST_ARG ] ) ) {
			$this->respond_with_page( $result, $action );
			return;
		}
		if ( $result['success'] ) {
			wp_send_json_success( $result['data'] );
			return;
		}
		wp_send_json_error( $result['data'] );
	}

	/**
	 * The forms' own action URL (see PLAIN_POST_ARG).
	 *
	 * admin-ajax.php and not admin-post.php: the class is only constructed on
	 * front-end, REST and AJAX requests (CLI::define_public_hooks() skips the
	 * front end on every other admin request), so an admin_post_nopriv_* hook
	 * would never be registered.
	 *
	 * @return string
	 */
	private function plain_post_url() {
		return admin_url( 'admin-ajax.php?' . self::PLAIN_POST_ARG . '=1' );
	}

	/**
	 * A form that submits `$action` with a nonce minted for this response.
	 *
	 * Used on the confirmation page only, which admin-ajax.php serves with
	 * no-cache headers, so unlike the nonce in the shortcode's (cacheable)
	 * markup this one is never older than the page it is on. It is the same
	 * anonymous nonce handle_nonce_refresh() hands to the script, and it is
	 * no more of a CSRF control here than there: is_same_origin_request() is.
	 *
	 * @param string $action AJAX_ACTION or RESCIND_ACTION.
	 * @param string $label  Button label.
	 * @param string $back   Page the visitor came from, carried along so the
	 *                       next confirmation page can still link back to it.
	 * @return string HTML.
	 */
	private function plain_form( $action, $label, $back ) {
		return '<form method="post" action="' . esc_url( $this->plain_post_url() ) . '">'
			. '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">'
			. '<input type="hidden" name="nonce" value="' . esc_attr( wp_create_nonce( $action ) ) . '">'
			. '<input type="hidden" name="_wp_http_referer" value="' . esc_attr( $back ) . '">'
			. '<button type="submit" class="button button-large">' . esc_html( $label ) . '</button>'
			. '</form>';
	}

	/**
	 * Answer a form the browser submitted by itself with a page.
	 *
	 * The confirmation is served from here and not by redirecting back to the
	 * shortcode's page with a result in the URL: render() must not read the
	 * request (the page is cached), and a cache that ignores query strings
	 * would send the visitor back to the bare form with no word on whether
	 * the opt-out had been applied.
	 *
	 * A stale nonce is the case the script recovers from by fetching a fresh
	 * one. Without the script the visitor cannot, and reloading a cached page
	 * returns the same expired nonce, so the page carries the same request
	 * again with a fresh nonce: one more press of a button, and it lands.
	 *
	 * After an opt-out the page also offers the withdrawal (1798.135(c)): the
	 * shortcode keeps that state in a <template> only the script can swap in.
	 *
	 * @param array  $result array( 'success' => bool, 'data' => mixed ).
	 * @param string $action AJAX_ACTION or RESCIND_ACTION.
	 * @return void
	 */
	private function respond_with_page( array $result, $action ) {
		$data    = $result['data'];
		$message = is_array( $data ) ? ( isset( $data['message'] ) ? (string) $data['message'] : '' ) : (string) $data;
		$stale   = is_array( $data ) && isset( $data['code'] ) && 'stale_nonce' === $data['code'];

		// wp_get_referer() only returns a URL on this site (or one of its
		// allowed redirect hosts), and prefers the _wp_http_referer field
		// plain_form() carries over the Referer header.
		$back = wp_get_referer();
		if ( ! $back ) {
			$back = home_url( '/' );
		}

		$blocks = '';
		$status = 200;
		if ( $stale ) {
			$message = __( 'The security token of the form had expired, so nothing has been changed yet. Use the button below to send your request again.', 'faz-cookie-manager' );
			$blocks  = $this->plain_form(
				$action,
				self::RESCIND_ACTION === $action
					? __( 'Withdraw opt-out / opt back in', 'faz-cookie-manager' )
					: __( 'Submit Opt-Out Request', 'faz-cookie-manager' ),
				$back
			);
		} elseif ( ! $result['success'] ) {
			$status = 403;
		} elseif ( self::AJAX_ACTION === $action ) {
			$blocks = '<p>' . esc_html__( 'Changed your mind? You can withdraw your opt-out at any time.', 'faz-cookie-manager' ) . '</p>'
				. $this->plain_form( self::RESCIND_ACTION, __( 'Withdraw opt-out / opt back in', 'faz-cookie-manager' ), $back );
		}

		// The default handler wraps the whole string in one <p>…</p>. Closing
		// that paragraph after the message and leaving the last one open
		// keeps the forms out of it, so the document it prints is valid.
		$html = esc_html( $message ) . '</p>'
			. $blocks
			. '<p><a href="' . esc_url( $back ) . '">' . esc_html__( 'Return to the previous page', 'faz-cookie-manager' ) . '</a>';

		// In an AJAX request wp_die() prints its message bare, for a script to
		// read. This answer is read by a person, so ask for the full page.
		add_filter( 'wp_die_ajax_handler', array( $this, 'page_die_handler' ) );
		wp_die(
			$html, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from escaped parts.
			esc_html__( 'Do Not Sell or Share My Personal Information', 'faz-cookie-manager' ),
			array( 'response' => (int) $status )
		);
	}

	/**
	 * The wp_die() handler for respond_with_page(): core's HTML page.
	 *
	 * @return string
	 */
	public function page_die_handler() {
		return '_default_wp_die_handler';
	}

	/**
	 * AJAX handler that hands out fresh opt-out and rescind nonces.
	 *
	 * Read-only and unauthenticated on purpose. The anonymous nonces it mints
	 * are the same ones render() prints into a public page, so nothing is
	 * exposed that a GET of that page does not expose already; a cross-site
	 * page cannot read the JSON (no CORS header is sent) and cannot execute
	 * it (`nosniff` plus a non-script content type). The real CSRF control
	 * on the two state-changing handlers remains is_same_origin_request(),
	 * which a scraped or refreshed nonce does not bypass. No same-origin check
	 * is applied HERE because a same-origin GET carries no Origin header and,
	 * under `Referrer-Policy: no-referrer`, no Referer either: on a browser
	 * without Fetch Metadata that check would refuse the one request whose
	 * job is to let a visitor's click land.
	 *
	 * admin-ajax.php sends `nocache_headers()` itself and every page cache
	 * excludes it, so these never come back from a cache.
	 *
	 * @since 1.34.2
	 * @return void
	 */
	public function handle_nonce_refresh() {
		wp_send_json_success(
			array(
				'optout'  => wp_create_nonce( self::AJAX_ACTION ),
				'rescind' => wp_create_nonce( self::RESCIND_ACTION ),
			)
		);
	}

	/**
	 * AJAX handler for the opt-out submission.
	 */
	public function handle_optout() {
		$this->respond( $this->process_optout(), self::AJAX_ACTION );
	}

	/**
	 * Apply an opt-out and say how it went.
	 *
	 * Once the nonce and the origin are accepted the opt-out is ALWAYS
	 * honoured: the cookie is set and the answer is a success. The right to
	 * opt out (Cal. Civ. Code 1798.120, 1798.135; CCPA Regulations 7026) is
	 * not something a throttle may refuse. Through 1.40.0 a second opt-out from
	 * the same address inside 60 seconds was answered "Too many requests" and
	 * got no cookie: behind a NAT, and since the window is keyed per IPv6 /64
	 * on a whole LAN, that second request is routinely a second person. The
	 * windows now bound only what is not a right, the consent-log row and the
	 * admin mail, and an opt-out that gets no row of its own is counted
	 * (count_unlogged()) so the record shows the gap instead of hiding it.
	 *
	 * @return array{success:bool,data:mixed} What wp_send_json_success() /
	 *               wp_send_json_error() is to carry.
	 */
	private function process_optout() {
		if ( ! check_ajax_referer( self::AJAX_ACTION, 'nonce', false ) ) {
			return $this->stale_nonce_result();
		}

		// The nopriv nonce is identical for every anonymous visitor and is
		// rendered into the public shortcode page, so on its own it is spam
		// hardening, not a CSRF control: an attacker can scrape it and replay it
		// from a cross-site page to forge an opt-out audit record and plant the
		// long-lived opt-out cookie in a victim's browser. Require the request to
		// be same-origin before performing the state change.
		if ( ! $this->is_same_origin_request() ) {
			return array(
				'success' => false,
				'data'    => __( 'This request must originate from this website.', 'faz-cookie-manager' ),
			);
		}

		// Idempotency: if the browser already carries the opt-out cookie, skip
		// creating a duplicate log entry and re-sending the admin notification.
		if ( isset( $_COOKIE[ ( function_exists( 'faz_consent_cookie_name' ) ? faz_consent_cookie_name( self::COOKIE_NAME ) : self::COOKIE_NAME ) ] ) && '1' === $_COOKIE[ ( function_exists( 'faz_consent_cookie_name' ) ? faz_consent_cookie_name( self::COOKIE_NAME ) : self::COOKIE_NAME ) ] ) {
			return array(
				'success' => true,
				'data'    => array( 'message' => __( 'You have already opted out. We will not sell or share your personal information.', 'faz-cookie-manager' ) ),
			);
		}

		// Per-connection 60-second window (see claim_window()). It decides who
		// writes the audit row and asks for the mail, never whether the
		// opt-out is applied:
		// - a lock name: this request holds the address's window and logs;
		// - true: the address does not identify one visitor, so the row is
		// bounded site-wide instead (claim_shared_audit_slot());
		// - false: the address's window is held. No row, no mail.
		$lock_key = $this->claim_window( 'faz_dnsmpi_' );
		$audit    = is_string( $lock_key ) || ( true === $lock_key && $this->claim_shared_audit_slot() );

		try {
			// The cookie first: it is the opt-out, and nothing after it may
			// stand between the visitor and it.
			$this->set_optout_cookie();
			if ( $audit ) {
				$ip_hash = $this->hash_ip();
				$this->log_optout( $ip_hash );
				if ( $this->claim_notification() ) {
					$this->notify_admin( $ip_hash );
				}
			} else {
				$this->count_unlogged( 'optout' );
			}
		} finally {
			// Release DB lock regardless of success or exception; the transient
			// maintains the ongoing 60-second throttle window.
			if ( is_string( $lock_key ) ) {
				delete_option( $lock_key );
			}
		}

		return array(
			'success' => true,
			'data'    => array(
				'message' => __( 'Your opt-out request has been received. We will not sell or share your personal information.', 'faz-cookie-manager' ),
			),
		);
	}

	/**
	 * AJAX handler to rescind a previous CCPA opt-out (opt back in).
	 *
	 * Required by CCPA 1798.135(c): consumers must be able to opt back in
	 * after exercising their right to opt out of the sale of personal data.
	 */
	public function handle_rescind() {
		$this->respond( $this->process_rescind(), self::RESCIND_ACTION );
	}

	/**
	 * Withdraw an opt-out and say how it went.
	 *
	 * Like the opt-out, the withdrawal is always applied once the nonce and
	 * the origin are accepted; the windows bound its audit row only.
	 *
	 * @return array{success:bool,data:mixed}
	 */
	private function process_rescind() {
		if ( ! check_ajax_referer( self::RESCIND_ACTION, 'nonce', false ) ) {
			return $this->stale_nonce_result();
		}

		// Same CSRF hardening as process_optout(): the anonymous nonce is shared,
		// so require a same-origin request before forging an opt-back-in record.
		if ( ! $this->is_same_origin_request() ) {
			return array(
				'success' => false,
				'data'    => __( 'This request must originate from this website.', 'faz-cookie-manager' ),
			);
		}

		// Idempotency: if the opt-out cookie is already absent, nothing to log.
		$has_optout_cookie = isset( $_COOKIE[ ( function_exists( 'faz_consent_cookie_name' ) ? faz_consent_cookie_name( self::COOKIE_NAME ) : self::COOKIE_NAME ) ] ) && '1' === $_COOKIE[ ( function_exists( 'faz_consent_cookie_name' ) ? faz_consent_cookie_name( self::COOKIE_NAME ) : self::COOKIE_NAME ) ];

		// Same per-connection window as the opt-out, with the same meaning.
		$lock_key = $this->claim_window( 'faz_dnsmpi_rsc_' );
		$audit    = is_string( $lock_key ) || ( true === $lock_key && $this->claim_shared_audit_slot() );

		try {
			$this->clear_optout_cookie();
			if ( $has_optout_cookie ) {
				if ( $audit ) {
					$ip_hash = $this->hash_ip();
					$this->log_rescind( $ip_hash );
				} else {
					$this->count_unlogged( 'rescind' );
				}
			}
		} finally {
			if ( is_string( $lock_key ) ) {
				delete_option( $lock_key );
			}
		}

		return array(
			'success' => true,
			'data'    => array(
				'message' => __( 'Your opt-out has been withdrawn. You may submit a new request at any time.', 'faz-cookie-manager' ),
			),
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
				'consent_id' => $consent_prefix . '-' . self::random_hex( 8 ),
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
		// NOT httponly, deliberately: the cookie carries only the literal '1'
		// (no secret to protect), and script.js must be able to read it —
		// _fazApplyDnsmpiOptOut() denies the sell/share categories in the
		// client store when this cookie is present. With httponly the client
		// could not see the opt-out, so _fazUnblockServerSide() restored the
		// very scripts the server had blocked for it (the stored consent still
		// said "yes"), undoing the opt-out one tick after page load.
		setcookie( function_exists( 'faz_consent_cookie_name' ) ? faz_consent_cookie_name( self::COOKIE_NAME ) : self::COOKIE_NAME, '1', array(
			'expires'  => $expires,
			'path'     => '/',
			'domain'   => '',
			'secure'   => is_ssl(),
			'httponly' => false,
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
		setcookie( function_exists( 'faz_consent_cookie_name' ) ? faz_consent_cookie_name( self::COOKIE_NAME ) : self::COOKIE_NAME, '', array(
			'expires'  => time() - 3600,
			'path'     => '/',
			'domain'   => '',
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		) );
		if ( isset( $_COOKIE[ ( function_exists( 'faz_consent_cookie_name' ) ? faz_consent_cookie_name( self::COOKIE_NAME ) : self::COOKIE_NAME ) ] ) ) {
			unset( $_COOKIE[ ( function_exists( 'faz_consent_cookie_name' ) ? faz_consent_cookie_name( self::COOKIE_NAME ) : self::COOKIE_NAME ) ] );
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
	 * Count an opt-out or a withdrawal that was honoured without a consent-log
	 * row of its own.
	 *
	 * Two bounds keep a replayed nonce from writing one row per request: the
	 * per-address 60-second window and, where the address identifies nobody,
	 * one site-wide slot per second. Both also catch real people: the second
	 * person behind a NAT, two visitors of a proxied site in the same second.
	 * Their request is applied, but through 1.40.0 nothing recorded that it had
	 * happened, so the register of opt-out requests was short by a number
	 * nobody could name. This is that number, per UTC day and per kind, shown
	 * in System Status. It is an upper bound on the people concerned: a replay
	 * and a double submission from one browser are counted too.
	 *
	 * One statement, so concurrent requests cannot lose an increment the way
	 * a get_option() / update_option() pair would, and one small row per day
	 * however many requests arrive. Consent_Logger::record_refusal() gates its
	 * own tally per client instead; that gate is no use here, because the
	 * requests this counts are exactly the ones that share a client address.
	 * The row is written and read with direct queries on purpose: the options
	 * cache would hand back a count that misses other requests' increments.
	 *
	 * @param string $kind 'optout' or 'rescind'.
	 * @return void
	 */
	private function count_unlogged( $kind ) {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! in_array( $kind, array( 'optout', 'rescind' ), true ) ) {
			return;
		}
		$name = self::UNLOGGED_OPTION_PREFIX . $kind . '_' . gmdate( 'Ymd', time() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- an atomic increment, which the options API cannot express; $wpdb->options is core's own table name.
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '1', 'no') ON DUPLICATE KEY UPDATE option_value = CAST(option_value AS UNSIGNED) + 1", $name ) );
	}

	/**
	 * The opt-outs and withdrawals honoured without a consent-log row.
	 *
	 * Read by System Status. Counters older than UNLOGGED_RETENTION_DAYS are
	 * dropped here, on an administrator's page view, rather than on the
	 * visitor's request that writes them.
	 *
	 * @param int $days Length of the recent window to total, in days.
	 * @return array{optout:int,rescind:int,recent_optout:int,recent_rescind:int,last:string}
	 *               Totals over the retained record, totals over the last
	 *               `$days` days, and the most recent day counted (Ymd, UTC;
	 *               '' when none).
	 */
	public static function unlogged_tally( $days = 30 ) {
		global $wpdb;
		$tally = array(
			'optout'         => 0,
			'rescind'        => 0,
			'recent_optout'  => 0,
			'recent_rescind' => 0,
			'last'           => '',
		);
		if ( ! isset( $wpdb ) ) {
			return $tally;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the counters are incremented with a direct query (count_unlogged()), so a cached read would be stale; $wpdb->options is core's own table name.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( self::UNLOGGED_OPTION_PREFIX ) . '%' ) );
		if ( ! is_array( $rows ) ) {
			return $tally;
		}
		$now    = time();
		$recent = gmdate( 'Ymd', $now - ( max( 1, (int) $days ) - 1 ) * DAY_IN_SECONDS );
		$floor  = gmdate( 'Ymd', $now - self::UNLOGGED_RETENTION_DAYS * DAY_IN_SECONDS );
		foreach ( $rows as $row ) {
			if ( ! preg_match( '/^' . preg_quote( self::UNLOGGED_OPTION_PREFIX, '/' ) . '(optout|rescind)_(\d{8})$/', (string) $row->option_name, $m ) ) {
				continue;
			}
			if ( strcmp( $m[2], $floor ) < 0 ) {
				delete_option( $row->option_name );
				continue;
			}
			$n = max( 0, (int) $row->option_value );
			$tally[ $m[1] ] += $n;
			if ( strcmp( $m[2], $recent ) >= 0 ) {
				$tally[ 'recent_' . $m[1] ] += $n;
			}
			if ( $n > 0 && strcmp( $m[2], $tally['last'] ) > 0 ) {
				$tally['last'] = $m[2];
			}
		}
		return $tally;
	}

	/**
	 * Whether a request whose address does not identify one visitor may write
	 * its audit row (and ask for the admin mail).
	 *
	 * claim_window() is skipped there so one visitor cannot refuse everyone
	 * else's opt-out, and the opt-out itself is always applied. The audit row
	 * is not a right, though, and without a bound a script replaying the
	 * public nonce wrote one consent-log row per request. A single site-wide
	 * slot per second caps that write; a real opt-out that loses the race is
	 * still applied through its cookie, and the caller counts it
	 * (count_unlogged()) so the record says a row is missing.
	 *
	 * The slot is taken in one statement. A transient read and written in two
	 * gave the slot to every request that read it before the first one wrote
	 * it, so a burst from concurrent workers wrote one row per worker. Without
	 * a persistent object cache it is a single options row holding the second
	 * it was last taken in: INSERT IGNORE creates it for one request, and a
	 * later second takes it over with an UPDATE that only matches an older
	 * second, which one request alone can win. A query that fails gives no
	 * slot: the audit row is skipped, the opt-out is not.
	 *
	 * @return bool True when this request may write the audit row.
	 */
	private function claim_shared_audit_slot() {
		if ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) {
			return (bool) wp_cache_add( 'faz_dnsmpi_shared_audit', 1, 'faz_throttle', 1 );
		}
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return false;
		}
		$name = self::SHARED_AUDIT_OPTION;
		$now  = time();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the slot must be taken in one statement, which the options API cannot express; $wpdb->options is core's own table name.
		if ( 1 === (int) $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, (string) $now ) ) ) {
			return true;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- compare-and-set on the second the slot was last taken in; $wpdb->options is core's own table name.
		return 1 === (int) $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND CAST(option_value AS UNSIGNED) < %d", (string) $now, $name, $now ) );
	}

	/**
	 * Hex token that survives a host without a usable entropy source.
	 *
	 * random_bytes() throws there; the opt-out has already been applied when
	 * the log and the mail lock ask for a token, so a throw would answer a
	 * 500 for a request that succeeded.
	 *
	 * @param int $bytes Number of random bytes.
	 * @return string
	 */
	private static function random_hex( $bytes ) {
		try {
			return bin2hex( random_bytes( $bytes ) );
		} catch ( \Throwable $e ) {
			return function_exists( 'wp_generate_password' ) ? wp_generate_password( $bytes * 2, false, false ) : substr( hash( 'sha256', uniqid( '', true ) ), 0, $bytes * 2 );
		}
	}

	/**
	 * Claim the per-connection 60-second window for an opt-out or rescind.
	 *
	 * The window is keyed on the client address, so it is skipped when that
	 * address does not identify one visitor (a proxy's own address, a private
	 * or loopback peer, no address at all): there every visitor shares one
	 * bucket, and one request refused everyone else's CCPA opt-out for a
	 * minute — the IP_Hasher contract the DSAR form already follows. The lock
	 * row holds its claim time so a request that died between add_option()
	 * and its finally block cannot hold the address forever.
	 *
	 * A held window refuses nothing either. An address that does identify one
	 * connection is still routinely several people (a household or an office
	 * behind one IPv4, a whole LAN inside one IPv6 /64), so the callers apply
	 * the opt-out or the withdrawal in every case and read `false` as "this
	 * request writes no audit row and asks for no mail".
	 *
	 * @param string $prefix Option/transient prefix ('faz_dnsmpi_' or 'faz_dnsmpi_rsc_').
	 * @return string|bool Lock option name to release, true when no window
	 *                     applies, false when the window is held (the request
	 *                     is honoured without an audit row).
	 */
	private function claim_window( $prefix ) {
		if ( ! $this->ip_identifies_visitor() ) {
			return true;
		}
		$hash     = substr( $this->rate_hash(), 0, 16 );
		$rl_key   = $prefix . 'rl_' . $hash;
		$lock_key = $prefix . 'lock_' . $hash;

		if ( false !== get_transient( $rl_key ) ) {
			return false;
		}
		if ( ! add_option( $lock_key, time(), '', 'no' ) ) {
			$held = (int) get_option( $lock_key, 0 );
			if ( $held > 0 && ( time() - $held ) < 120 ) {
				return false;
			}
			// Stale lock (or a pre-1.39.1 row holding the literal 1): reclaim it.
			delete_option( $lock_key );
			if ( ! add_option( $lock_key, time(), '', 'no' ) ) {
				return false;
			}
		}
		// Lock acquired — write the durability transient for the window.
		set_transient( $rl_key, 1, 60 );
		return $lock_key;
	}

	/**
	 * Whether this opt-out may still send its own admin notification in the
	 * current 24-hour window.
	 *
	 * The window is fixed: it closes 24 hours after the FIRST mail in it, like
	 * the DSAR form's site-wide ceiling (DSAR_Shortcode::window_bump()). A
	 * transient re-set on every mail would push its own expiry forward, so on
	 * a site with an opt-out a day the counter would never reset and the mail
	 * would stop for good once it reached the ceiling. It is kept in a
	 * non-autoloaded option, which an object-cache flush cannot reset either.
	 *
	 * The read and the write happen under a short lock. Opt-outs from different
	 * addresses do not share the per-address lock, so without one N parallel
	 * requests all read the same count, all pass the ceiling and all send,
	 * which is the burst from rotating addresses the ceiling exists to stop.
	 * A request that cannot get the lock sends no mail: under that much
	 * contention the site is being flooded, and the ceiling is a flood limit.
	 *
	 * @return bool
	 */
	private function claim_notification() {
		$max = (int) apply_filters( 'faz_dnsmpi_max_notifications_per_day', self::MAX_NOTIFICATIONS_PER_DAY );
		if ( $max <= 0 ) {
			return false;
		}
		$lock = self::MAIL_WINDOW_OPTION . '_lock';
		// The value names this request, so a release or a reclaim removes
		// only the lock it means and never one another request took since.
		$mine = time() . ':' . self::random_hex( 6 );
		$held = false;
		for ( $try = 0; $try < 10; $try++ ) {
			if ( $this->insert_mail_lock( $lock, $mine ) ) {
				$held = true;
				break;
			}
			$seen = $this->read_mail_lock( $lock );
			if ( null === $seen ) {
				// Released between the INSERT and the read: try again now.
				continue;
			}
			if ( time() - (int) $seen > 10 ) {
				// A request died holding it: remove that lock (only that one)
				// and retry the INSERT.
				$this->delete_mail_lock( $lock, $seen );
				continue;
			}
			usleep( 20000 );
		}
		if ( ! $held ) {
			return false;
		}
		try {
			return $this->claim_notification_locked( $max );
		} finally {
			$this->delete_mail_lock( $lock, $mine );
		}
	}

	/**
	 * Take the mail-window lock: true for exactly one request.
	 *
	 * INSERT IGNORE on the unique option_name inserts for one request and
	 * changes nothing for the others. add_option() is not a mutex: it writes
	 * with INSERT … ON DUPLICATE KEY UPDATE, which reports success to a second
	 * request whose value differs, and it answers from a cached copy of a row
	 * another request has already deleted.
	 *
	 * @param string $lock  Option name.
	 * @param string $value This request's lock value.
	 * @return bool
	 */
	private function insert_mail_lock( $lock, $value ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- a mutex row needs INSERT IGNORE, which the options API does not offer; $wpdb->options is core's own table name.
		return 1 === (int) $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $lock, $value ) );
	}

	/**
	 * The lock row's value as stored, never a cached copy, or null.
	 *
	 * @param string $lock Option name.
	 * @return string|null
	 */
	private function read_mail_lock( $lock ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the holder releases the row from another request, so a cached read would never see it go; $wpdb->options is core's own table name.
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $lock ) );
		return null === $value ? null : (string) $value;
	}

	/**
	 * Delete the lock row only while it still holds `$value`.
	 *
	 * @param string $lock  Option name.
	 * @param string $value The value this request took or found stale.
	 * @return void
	 */
	private function delete_mail_lock( $lock, $value ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- compare-and-delete in one statement; delete_option() removes the row by name and would drop a lock another request took after this one's expired.
		$wpdb->delete( $wpdb->options, array( 'option_name' => $lock, 'option_value' => $value ), array( '%s', '%s' ) );
	}

	/**
	 * claim_notification()'s read-modify-write; the caller holds the lock.
	 *
	 * The write is a compare-and-swap on the row as read, not a plain
	 * update_option(): the lock is reclaimed after 10 seconds, so a holder
	 * that stalls between its read and its write can resume after another
	 * request has counted a mail, and a plain write would then put back a
	 * count that misses that mail. When the row changed in between, this
	 * request sends no mail.
	 *
	 * @param int $max Mails allowed per window.
	 * @return bool
	 */
	private function claim_notification_locked( $max ) {
		global $wpdb;
		$name = self::MAIL_WINDOW_OPTION;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the compare-and-swap below needs the row exactly as stored, never a cached copy; $wpdb->options is core's own table name.
		$raw   = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
		$data  = null === $raw ? false : maybe_unserialize( $raw );
		$now   = time();
		$start = is_array( $data ) && isset( $data['start'] ) ? (int) $data['start'] : 0;
		$count = is_array( $data ) && isset( $data['n'] ) ? (int) $data['n'] : 0;
		if ( ! $start || $now - $start >= 86400 ) {
			$start = $now;
			$count = 0;
		}
		if ( $count >= $max ) {
			return false;
		}
		$next = maybe_serialize(
			array(
				'n'     => $count + 1,
				'start' => $start,
			)
		);
		if ( null === $raw ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the first row of a window is created only if no other request created it first; $wpdb->options is core's own table name.
			$swapped = 1 === (int) $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, $next ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- compare-and-swap: the row is replaced only while it still holds the value read above.
			$swapped = 1 === (int) $wpdb->update( $wpdb->options, array( 'option_value' => $next ), array( 'option_name' => $name, 'option_value' => $raw ), array( '%s' ), array( '%s', '%s' ) );
		}
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( $name, 'options' );
		}
		return $swapped;
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
