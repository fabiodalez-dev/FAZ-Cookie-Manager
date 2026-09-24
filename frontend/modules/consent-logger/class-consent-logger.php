<?php
/**
 * Frontend consent logger.
 *
 * Registers AJAX and REST handlers to log visitor consent from the frontend.
 *
 * @package FazCookie\Frontend\Modules\ConsentLogger
 */

namespace FazCookie\Frontend\Modules\Consent_Logger;

use FazCookie\Admin\Modules\Consentlogs\Includes\Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Consent Logger - handles frontend consent logging via AJAX and REST.
 *
 * @class       Consent_Logger
 * @version     3.0.0
 * @package     FazCookie
 */
class Consent_Logger {

	/**
	 * How long one token value lasts before a new one is minted, in seconds.
	 *
	 * The token is not a secret — it ships inside cacheable HTML — so it does
	 * not identify a visitor and rotating it faster buys nothing. What it
	 * proves is that the page came from this installation, because only this
	 * installation knows the salts wp_hash() uses.
	 */
	const TOKEN_BUCKET = 43200; // 12 * HOUR_IN_SECONDS.

	/**
	 * How far back a token is still accepted, in seconds, by default.
	 *
	 * A cached page carries the token that was current when it was stored, so
	 * the window has to outlast the page cache, not the visit. Only the
	 * current and previous bucket used to be accepted — 12 to 24 hours — while
	 * LiteSpeed Cache ships a 604800-second (7 day) public TTL and WP Rocket
	 * and W3TC defaults are measured in days too. From about a day after a
	 * page was cached, every consent POST from it was rejected with a 403 and
	 * the record was lost; the visitor saw a working banner, because the call
	 * is fire-and-forget, so the only thing missing was the Art. 7(1)
	 * accountability record. Reported with production figures in issue #292:
	 * one site logged 57 consents and rejected 286 on the same day.
	 *
	 * Seven days matches the longest common cache default. A site that caches
	 * for longer raises it through the faz_consent_token_max_age filter.
	 */
	const TOKEN_MAX_AGE = 604800; // 7 * DAY_IN_SECONDS.

	/**
	 * Upper bound for the filtered window, in seconds.
	 *
	 * A token older than a month says nothing useful about the page that
	 * carried it, and the throttles below are what limit volume anyway.
	 */
	const TOKEN_MAX_AGE_LIMIT = 2592000; // 30 * DAY_IN_SECONDS.

	/**
	 * Option holding the rejected-token tally, so the silence is visible.
	 */
	const REJECTION_OPTION = 'faz_consent_token_rejections';

	/**
	 * Constructor - register hooks.
	 */
	public function __construct() {
		// Public REST route.
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	/**
	 * Register public REST route for consent logging.
	 *
	 * @return void
	 */
	public function register_rest_routes() {
		// `permission_callback => __return_true` is intentional. This endpoint
		// records the GDPR consent decision of an anonymous visitor — it MUST
		// be reachable without authentication, otherwise no consent could
		// ever be logged. Abuse mitigation:
		//   - Required HMAC `token` embedded in a page rendered by this site.
		//   - Same-origin request validation (Fetch Metadata / Origin / Referer).
		//   - All inputs sanitized via `sanitize_callback`.
		//   - The handler verifies both controls before any DB write.
		// See `handle_rest_consent()` for the verification logic.
		register_rest_route(
			'faz/v1',
			'/consent',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_rest_consent' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					// Deliberately NOT declared `required`. WordPress rejects a
					// missing required arg in `has_valid_params()` — keyed on
					// `null === $param`, so an empty string passes and an absent
					// one does not — and answers 400 `rest_missing_callback_param`
					// before the callback runs. The token would still be
					// enforced, but by a gate that counts nothing: a request
					// carrying no token at all would be refused where this class
					// cannot see it, while System Status reported that very
					// shape as counted. That is the silence issue #292 was
					// about, one layer up. The handler refuses an absent token
					// itself, with 403 `missing_token`, and records the cause.
					'token' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'consent_id' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'status'     => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => 'partial',
					),
					'categories' => array(
						'type'    => array( 'object', 'array' ),
						'default' => array(),
					),
					'url'        => array(
						'type'              => 'string',
						'sanitize_callback' => 'esc_url_raw',
					),
					'banner_slug' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'policy_revision' => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'tc_string' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'gpp_string' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * The token a page rendered right now carries.
	 *
	 * One place mints it and one place accepts it. They used to be two copies
	 * of the same arithmetic, in two files, free to drift apart.
	 *
	 * @param int|null $at Unix timestamp, or null for now.
	 * @return string
	 */
	public static function current_token( $at = null ) {
		$at = null === $at ? time() : (int) $at;
		return wp_hash( 'faz_consent_' . (string) (int) floor( $at / self::TOKEN_BUCKET ) );
	}

	/**
	 * How far back a token is accepted, in seconds.
	 *
	 * Filter: faz_consent_token_max_age. A site whose page cache outlives the
	 * default raises it to match; below one bucket is meaningless (the current
	 * token would expire before the page it is printed on finishes loading)
	 * and above TOKEN_MAX_AGE_LIMIT is refused.
	 *
	 * @return int
	 */
	public static function token_max_age() {
		/**
		 * Filters how long a consent-log origin token stays acceptable.
		 *
		 * @param int $max_age Seconds. Default 7 days.
		 */
		$max_age = (int) apply_filters( 'faz_consent_token_max_age', self::TOKEN_MAX_AGE );
		if ( $max_age < self::TOKEN_BUCKET ) {
			return self::TOKEN_BUCKET;
		}
		if ( $max_age > self::TOKEN_MAX_AGE_LIMIT ) {
			return self::TOKEN_MAX_AGE_LIMIT;
		}
		return $max_age;
	}

	/**
	 * Whether a token was minted by this site inside the accepted window.
	 *
	 * Widening the window does not weaken the control it provides. The token
	 * says "this HTML came from this installation", which a third party cannot
	 * forge at any age, and replay from another origin is stopped by
	 * is_same_origin_request() rather than by the token's age.
	 *
	 * @param string   $token Token from the request.
	 * @param int|null $at    Unix timestamp, or null for now.
	 * @return bool
	 */
	public static function token_is_valid( $token, $at = null ) {
		if ( ! is_string( $token ) || '' === $token ) {
			return false;
		}
		$at      = null === $at ? time() : (int) $at;
		$bucket  = (int) floor( $at / self::TOKEN_BUCKET );
		$buckets = (int) ceil( self::token_max_age() / self::TOKEN_BUCKET );
		for ( $i = 0; $i <= $buckets; $i++ ) {
			if ( hash_equals( wp_hash( 'faz_consent_' . (string) ( $bucket - $i ) ), $token ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * How many whole UTC days the refusal tally covers.
	 *
	 * Seven per-day counters, not one anchored total. The anchored shape it
	 * replaces expired the whole tally once its first refusal aged out, so a
	 * site refusing continuously saw the figure collapse from thousands to one
	 * the instant the window rolled, and climb again — a sawtooth reported as
	 * "the last 7 days". Per-day buckets make the claim true, cost seven
	 * integers per cause, and keep the property the anchored shape got right:
	 * a cause that stopped more than a week ago reads as zero without needing
	 * a new refusal to roll it over, because every bucket has simply aged out.
	 *
	 * The window is seven CALENDAR days in UTC, today included — not a
	 * 168-hour sliding window. A per-day counter cannot express finer without
	 * storing an event per refusal, which is not worth a diagnostic's while.
	 */
	const REJECTION_WINDOW_DAYS = 7;

	/**
	 * Why a consent record was refused.
	 *
	 * Counted separately because the causes call for different answers and
	 * carry very different traffic. A stale token means the page cache
	 * outlived the window and the lost records are real visitors'. A
	 * cross-origin refusal is normally automated traffic, so folding it into
	 * the same figure would make an alarming number out of noise and train
	 * the administrator to ignore the row. A failed write is the gravest of
	 * the five and the only one that is always a genuine consent: the visitor
	 * answered, the row did not land, and the response was a 500 nobody sees
	 * because the call is fire-and-forget.
	 */
	const CAUSE_STALE_TOKEN  = 'stale_token';
	const CAUSE_CROSS_ORIGIN = 'cross_origin';
	const CAUSE_MISSING_TOKEN = 'missing_token';
	const CAUSE_THROTTLED    = 'throttled';
	const CAUSE_WRITE_FAILED = 'write_failed';

	/**
	 * Every cause the tally knows, in the order System Status reports them.
	 *
	 * @return string[]
	 */
	public static function refusal_causes() {
		return array(
			self::CAUSE_STALE_TOKEN,
			self::CAUSE_CROSS_ORIGIN,
			self::CAUSE_MISSING_TOKEN,
			self::CAUSE_THROTTLED,
			self::CAUSE_WRITE_FAILED,
		);
	}

	/**
	 * The UTC day a timestamp belongs to, as an integer index.
	 *
	 * Integer arithmetic on purpose: no gmdate(), no timezone, nothing that
	 * changes under DST or under the site's timezone setting.
	 *
	 * @param int $at Unix timestamp.
	 * @return int
	 */
	private static function day_index( $at ) {
		return (int) floor( (int) $at / DAY_IN_SECONDS );
	}

	/**
	 * The refusal tally, as anything reading it should see it.
	 *
	 * The window is applied on the way OUT as well as on the way in: buckets
	 * older than the window are dropped when the tally is read, so a cache
	 * problem a purge fixed a fortnight ago stops being reported without
	 * waiting for another refusal to roll it over. One rule, read by the
	 * writer below and by System Status.
	 *
	 * @param int|null $at Unix timestamp, or null for now.
	 * @return array{count:int,since:int,last:int,causes:array<string,int>}
	 */
	public static function rejection_tally( $at = null ) {
		$at    = null === $at ? time() : (int) $at;
		$empty = array( 'count' => 0, 'since' => 0, 'last' => 0, 'causes' => array() );
		$tally = get_option( self::REJECTION_OPTION, array() );
		if ( ! is_array( $tally ) ) {
			return $empty;
		}

		$days = self::retained_days( $tally, $at );
		if ( empty( $days ) ) {
			return $empty;
		}

		$count  = 0;
		$causes = array();
		foreach ( $days as $counts ) {
			foreach ( $counts as $cause => $n ) {
				$n = max( 0, (int) $n );
				if ( 0 === $n ) {
					continue;
				}
				$count += $n;
				$causes[ $cause ] = ( isset( $causes[ $cause ] ) ? $causes[ $cause ] : 0 ) + $n;
			}
		}
		if ( 0 === $count ) {
			return $empty;
		}

		$oldest = min( array_keys( $days ) );
		$last   = isset( $tally['last'] ) ? (int) $tally['last'] : 0;

		return array(
			'count'  => $count,
			'since'  => $oldest * DAY_IN_SECONDS,
			// A 'last' from the future (clock skew between the web and database
			// hosts) would read as a refusal that has not happened yet.
			'last'   => min( $last, $at ),
			'causes' => $causes,
		);
	}

	/**
	 * The day buckets still inside the window, normalised.
	 *
	 * Tolerates the flat {since,count,last} shape a site may already hold from
	 * the first cut of this feature: it becomes one bucket, dated at its own
	 * anchor, under the only cause that shape could ever have counted. Cheaper
	 * than a migration and it cannot fatal on an admin page.
	 *
	 * @param array $tally Raw option value.
	 * @param int   $at    Unix timestamp.
	 * @return array<int,array<string,int>> Day index => cause => count.
	 */
	private static function retained_days( array $tally, $at ) {
		$floor = self::day_index( $at ) - ( self::REJECTION_WINDOW_DAYS - 1 );

		if ( ! isset( $tally['days'] ) || ! is_array( $tally['days'] ) ) {
			// Legacy flat shape.
			$legacy_count = isset( $tally['count'] ) ? (int) $tally['count'] : 0;
			$legacy_since = isset( $tally['since'] ) ? (int) $tally['since'] : 0;
			if ( $legacy_count < 1 || $legacy_since < 1 ) {
				return array();
			}
			$day = self::day_index( $legacy_since );
			if ( $day < $floor ) {
				return array();
			}
			return array( $day => array( self::CAUSE_STALE_TOKEN => $legacy_count ) );
		}

		$known    = self::refusal_causes();
		$retained = array();
		foreach ( $tally['days'] as $day => $counts ) {
			$day = (int) $day;
			// A bucket dated in the future is skew, not data; keeping it would
			// hold the tally open indefinitely.
			if ( $day < $floor || $day > self::day_index( $at ) ) {
				continue;
			}
			if ( ! is_array( $counts ) ) {
				continue;
			}
			$clean = array();
			foreach ( $counts as $cause => $n ) {
				if ( ! in_array( (string) $cause, $known, true ) ) {
					continue;
				}
				$n = max( 0, (int) $n );
				if ( $n > 0 ) {
					$clean[ (string) $cause ] = $n;
				}
			}
			if ( ! empty( $clean ) ) {
				$retained[ $day ] = $clean;
			}
		}
		ksort( $retained );
		return $retained;
	}

	/**
	 * Count a refused consent record, and say so in the error log once an hour.
	 *
	 * A consent log that stops recording without complaining is worse than one
	 * that complains: nothing on the site looks broken, and the gap is only
	 * discovered when the records are needed. System Status reads this tally.
	 *
	 * The write is rate-limited per client per cause, and deliberately not on
	 * the key the endpoint's own throttle uses — a junk token must never spend
	 * the consent budget a real visitor needs moments later. The endpoint's
	 * per-IP throttle runs AFTER the token check, so without a gate of its own
	 * this accounting would hand any anonymous caller one guaranteed database
	 * write per request, on the one route that has to stay reachable without
	 * authentication. The cost is that a flood from a single address is counted
	 * once per window rather than in full; the case this tally exists for —
	 * many distinct visitors each refused once from stale cached HTML — is
	 * unaffected, because each of them is its own client.
	 *
	 * @param string $cause One of the CAUSE_* constants.
	 * @return void
	 */
	private static function record_refusal( $cause ) {
		$cause = (string) $cause;
		if ( ! in_array( $cause, self::refusal_causes(), true ) ) {
			return;
		}

		if ( function_exists( 'faz_throttle_request' )
			&& faz_throttle_request( 'faz_consent_refusal_' . $cause, 10 ) ) {
			return;
		}

		$now   = time();
		$day   = self::day_index( $now );
		$tally = get_option( self::REJECTION_OPTION, array() );
		$tally = is_array( $tally ) ? $tally : array();
		$days  = self::retained_days( $tally, $now );

		if ( ! isset( $days[ $day ] ) ) {
			$days[ $day ] = array();
		}
		$days[ $day ][ $cause ] = ( isset( $days[ $day ][ $cause ] ) ? (int) $days[ $day ][ $cause ] : 0 ) + 1;

		update_option(
			self::REJECTION_OPTION,
			array( 'days' => $days, 'last' => $now ),
			false
		);

		if ( ! get_transient( 'faz_consent_token_rejected_notice' ) ) {
			set_transient( 'faz_consent_token_rejected_notice', 1, HOUR_IN_SECONDS );
			$reported = self::rejection_tally( $now );
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- a consent record was refused; that belongs in the log whatever the site's debug settings.
			error_log( self::refusal_log_line( $cause, $reported ) );
		}
	}

	/**
	 * The error-log sentence for a refusal, naming its own cause.
	 *
	 * Not translated: it goes to the server log, which is read by whoever
	 * administers the server rather than by the site's audience, and the
	 * strings a translator has to carry are expensive enough already.
	 *
	 * @param string $cause    The cause that triggered this line.
	 * @param array  $reported The tally as System Status will report it.
	 * @return string
	 */
	private static function refusal_log_line( $cause, array $reported ) {
		$causes = array();
		foreach ( $reported['causes'] as $name => $n ) {
			$causes[] = $name . '=' . (int) $n;
		}

		switch ( $cause ) {
			case self::CAUSE_CROSS_ORIGIN:
				$why = 'the request carried no same-origin signal. That is usually automated traffic, but if this figure tracks your visitor numbers your pages are being served on a host, scheme or port that differs from your WordPress Address (Settings -> General): check that www/apex, http/https and any non-default port all redirect to the canonical one.';
				break;
			case self::CAUSE_MISSING_TOKEN:
				$why = 'the request carried no origin token at all, which the plugin\'s own frontend never does. Automated traffic is the usual source.';
				break;
			case self::CAUSE_THROTTLED:
				$why = 'the request was rate-limited. Visitors sharing one outbound address can collide here.';
				break;
			case self::CAUSE_WRITE_FAILED:
				$why = 'the record could not be written to the database. This one is always a real visitor\'s real consent — check the wp_faz_consent_logs table and the database error log.';
				break;
			default:
				$why = sprintf(
					'its origin token was not valid. HTML served from a cache older than the accepted window of %d seconds is the usual cause — raise it with the faz_consent_token_max_age filter or shorten the cache TTL — but a token from another installation or a malformed one is refused the same way.',
					self::token_max_age()
				);
				break;
		}

		return sprintf(
			'FAZ Cookie Manager: refused a consent record because %s (last %d days, per cause: %s). The count is rate-limited to one per client per cause every 10 seconds, so it is a floor rather than an exact total.',
			$why,
			self::REJECTION_WINDOW_DAYS,
			$causes ? implode( ', ', $causes ) : 'none'
		);
	}

	/**
	 * Handle REST consent logging.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_rest_consent( $request ) {
		// The HMAC is intentionally cache-compatible, hence it is public to any
		// visitor who receives the page and is not a CSRF credential by itself.
		// Require a browser-provided same-origin signal before accepting it; this
		// prevents a third-party page from replaying a leaked/current token to
		// create arbitrary consent-log records in a visitor's context.
		if ( ! $this->is_same_origin_request() ) {
			self::record_refusal( self::CAUSE_CROSS_ORIGIN );
			return new \WP_Error(
				'cross_origin_request',
				__( 'This request must originate from this website.', 'faz-cookie-manager' ),
				array( 'status' => 403 )
			);
		}

		// Verify the cache-compatible, time-bucketed token, accepting every
		// bucket inside the window below so a page held by a full-page cache
		// keeps recording consent for as long as that cache serves it.
		$token = $request->get_param( 'token' );
		if ( ! empty( $token ) ) {
			if ( ! self::token_is_valid( $token ) ) {
				self::record_refusal( self::CAUSE_STALE_TOKEN );
				return new \WP_Error(
					'invalid_token',
					'Invalid origin token.',
					array( 'status' => 403 )
				);
			}
		} else {
			// No token = request not from a page rendered by this plugin —
			// either the parameter was absent (which is why the route does not
			// declare it `required`; see `register_routes()`) or it arrived
			// empty. The inline beacon returns before fetching when its
			// localized object is absent, so this is reached by automated
			// traffic rather than by a visitor — counted under its own cause so
			// it cannot be mistaken for records a cache lost.
			self::record_refusal( self::CAUSE_MISSING_TOKEN );
			return new \WP_Error(
				'missing_token',
				'Origin token required.',
				array( 'status' => 403 )
			);
		}

		// Dual guardrail: per-IP AND per-consent_id throttle.
		// The IP check prevents a single client from flooding with different consent_ids.
		// The consent_id check prevents replaying the same consent_id from different IPs.
		$consent_id           = $request->get_param( 'consent_id' );
		$sanitized_consent_id = sanitize_text_field( (string) ( $consent_id ?? '' ) );
		$is_ip_throttled      = faz_throttle_request( 'faz_consent_ip', 10 );
		$is_consent_throttled = false;

		// Answer a throttled caller before doing any work for it. The block
		// below runs a database query (last_logged_status) and arms two
		// transients, and until now all of that happened even when the verdict
		// at the bottom was already decided — so a client being rate-limited
		// still cost a query per request, which is the flooding this endpoint
		// throttles in the first place. Nothing below can clear an IP throttle,
		// so returning here changes no outcome, only the price of reaching it.
		if ( $is_ip_throttled ) {
			// A dropped record is a dropped record even when the answer is 200:
			// the fire-and-forget client never inspects it, so without counting
			// this the loss is as invisible as the one issue #292 was about.
			self::record_refusal( self::CAUSE_THROTTLED );
			return rest_ensure_response( array( 'throttled' => true ) );
		}

		if ( '' !== $sanitized_consent_id ) {
			// The per-consent_id throttle exists to stop one id being replayed
			// from many IPs. But the consent_id is deliberately KEPT across
			// sessions (script.js keeps `consentid` so analytics can correlate),
			// so a visitor who accepts and then withdraws minutes later posts the
			// SAME id — and the withdrawal was dropped inside the 300s window,
			// with an HTTP 200 the fire-and-forget client never inspects.
			//
			// The surviving row then affirmatively states "accepted" for a
			// visitor who has withdrawn: worse than a missing record, and the one
			// record Art. 7(3) accountability actually needs. A status change is
			// never a replay — it is the event — so it bypasses the id throttle.
			// The per-IP throttle above still bounds flooding.
			// Allowlisted BEFORE the comparison, and matching the set the
			// controller keeps (anything else it folds to 'partial'). Without
			// this, sanitize_key() made any non-empty string a "change", so a
			// caller could alternate junk values — or even valid ones — and mint
			// a fresh row on every request, using the accountability bypass as an
			// unthrottled write path.
			$status = sanitize_key( (string) $request->get_param( 'status' ) );
			if ( ! in_array( $status, array( 'accepted', 'rejected', 'partial', 'dnsmpi_optout', 'dns_rescinded', 'pmp_grant' ), true ) ) {
				$status = '';
			}
			// One lookup of the newest row serves both comparisons below. They
			// used to query it separately, and the second identical SELECT ran
			// before the throttle verdict — paid by the replay traffic this
			// block exists to stop charging for.
			$previous_row   = Controller::get_instance()->get_log_by_consent_id( $sanitized_consent_id );
			$previous       = $this->last_logged_status( $previous_row );
			$status_changed = '' !== $status && $status !== $previous;

			// A GPC exception minted from a blocked embed does not change the
			// status — a visitor who saved preferences and then opened a map
			// posts "partial" twice — so inside the 300s window the second post
			// was dropped and the exception was never recorded at all. It is the
			// single riskiest consent event this plugin writes (an exception to a
			// binding opt-out), and the row that proves it existed is the whole
			// point of logging it. Treat a NEW exception key as a change; the
			// chg1/chgn caps below still bound how many land in a window, so this
			// does not reopen the unthrottled write path they close.
			if ( ! $status_changed && $this->has_new_gpc_exception( $sanitized_consent_id, $request->get_param( 'categories' ), $previous_row ) ) {
				$status_changed = true;
			}

			// The window is ARMED unconditionally and its verdict ignored only for
			// a status change. Skipping the call entirely — the first shape of this
			// fix — meant a bypass never armed anything, so the next identical
			// replay was let through too and only the one after it was blocked.
			// That silently weakened the 300s guarantee by one request; arming it
			// here keeps replays throttled from the very first repeat while a real
			// change still always lands.
			$consent_key   = 'faz_consent_' . substr( md5( $sanitized_consent_id ), 0, 8 );
			$window_closed = faz_throttle_request( $consent_key, 300 );

			// The FIRST change in a window is always free; the ones after it are
			// rate-limited. A flat cap on every change was tried first and was
			// wrong — it re-broke the case this guard exists for (accept by
			// mistake, reject seconds later, withdrawal dropped again). Splitting
			// the two keeps the immediate correction guaranteed while denying a
			// script an unbounded write path by alternating valid statuses, which
			// the per-IP throttle alone does not stop across a distributed set of
			// addresses sharing one consent_id.
			if ( $status_changed ) {
				$hash = substr( md5( $sanitized_consent_id ), 0, 8 );
				if ( faz_throttle_request( 'faz_consent_chg1_' . $hash, 300 ) ) {
					// A change already landed in this window — throttle the rest.
					$status_changed = ! faz_throttle_request( 'faz_consent_chgn_' . $hash, 10 );
				}
			}

			$is_consent_throttled = $status_changed ? false : $window_closed;
		}
		if ( $is_ip_throttled || $is_consent_throttled ) {
			self::record_refusal( self::CAUSE_THROTTLED );
			return rest_ensure_response( array( 'throttled' => true ) );
		}

		$data = array(
			'consent_id' => $request->get_param( 'consent_id' ),
			'status'     => $request->get_param( 'status' ),
			'categories' => $request->get_param( 'categories' ),
			'url'        => $request->get_param( 'url' ),
			'banner_slug' => $request->get_param( 'banner_slug' ),
			'policy_revision' => $request->get_param( 'policy_revision' ),
			// Audit signals derived server-side from the request headers so the
			// signal_gpc_received / signal_dnt_received columns are actually
			// populated in the normal frontend flow (they were always NULL
			// before — the frontend payload never carried them). GPC is the
			// `Sec-GPC: 1` header (mirror of navigator.globalPrivacyControl);
			// DNT is the legacy `DNT: 1` header.
			'signal_gpc' => ( isset( $_SERVER['HTTP_SEC_GPC'] ) && '1' === sanitize_text_field( wp_unslash( $_SERVER['HTTP_SEC_GPC'] ) ) ) ? 1 : 0,
			'signal_dnt' => ( isset( $_SERVER['HTTP_DNT'] ) && '1' === sanitize_text_field( wp_unslash( $_SERVER['HTTP_DNT'] ) ) ) ? 1 : 0,
			'tc_string'  => $request->get_param( 'tc_string' ),
			'gpp_string' => $request->get_param( 'gpp_string' ),
		);

		$result = Controller::get_instance()->log_consent( $data );

		if ( false === $result ) {
			// The gravest of the five: the visitor answered, the row did not
			// land, and the 500 goes to a client that never looks at it.
			self::record_refusal( self::CAUSE_WRITE_FAILED );
			return new \WP_Error(
				'consent_log_failed',
				__( 'Failed to log consent.', 'faz-cookie-manager' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Whether this request carries a GPC exception the last row did not.
	 *
	 * Compares only the `meta.gpc_exception.<id>` keys: a repeat of an exception
	 * already on record is a replay and stays throttled, while the first post
	 * that carries a new one is the event itself. The value is ignored — the
	 * server decides what an exception means at ingest; here the question is
	 * only whether this row would record something the previous one did not.
	 *
	 * Only the keys the controller would actually store are read: a payload is
	 * not walked past that cap, and an exception beyond it — which would never
	 * be written — cannot open the bypass.
	 *
	 * @param string     $consent_id Sanitised consent id.
	 * @param mixed      $categories The request's categories map.
	 * @param array|null $previous   The newest row for this consent id, as
	 *                               already fetched by the caller, or null.
	 * @return bool
	 */
	private function has_new_gpc_exception( $consent_id, $categories, $previous = null ) {
		if ( '' === $consent_id || ! is_array( $categories ) ) {
			return false;
		}
		$cap      = 250 - ( class_exists( '\\FazCookie\\Includes\\Gpc_Exception_Audit' ) ? \FazCookie\Includes\Gpc_Exception_Audit::MAX_IDS : 50 );
		$seen     = 0;
		$incoming = array();
		foreach ( array_keys( $categories ) as $key ) {
			if ( ++$seen > $cap ) {
				break;
			}
			$key = (string) $key;
			if ( 0 === strpos( $key, 'meta.gpc_exception.' ) ) {
				$incoming[ $key ] = true;
			}
		}
		if ( empty( $incoming ) ) {
			return false;
		}
		if ( ! is_array( $previous ) || empty( $previous['categories'] ) ) {
			return true;
		}
		$stored = $previous['categories'];
		if ( is_string( $stored ) ) {
			$stored = json_decode( $stored, true );
		}
		if ( ! is_array( $stored ) ) {
			return true;
		}
		foreach ( array_keys( $incoming ) as $key ) {
			if ( ! array_key_exists( $key, $stored ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The status of the most recent log row for a consent id, if any.
	 *
	 * Used only to tell a status CHANGE apart from a replay of the same status:
	 * the first is the event accountability exists to record and must never be
	 * throttled away, the second is what the throttle is for. Reads the row the
	 * caller fetched through the controller's own newest-row lookup, so there is
	 * no second query with its own idea of "latest".
	 *
	 * @param array|null $previous Newest row for the consent id, or null.
	 * @return string Previous status, or '' when nothing is recorded yet.
	 */
	private function last_logged_status( $previous ) {
		return ( is_array( $previous ) && isset( $previous['status'] ) )
			? sanitize_key( (string) $previous['status'] )
			: '';
	}

	/**
	 * Accept only a browser POST initiated by this exact origin.
	 *
	 * Consent logging is an anonymous endpoint. A public, cache-compatible
	 * token is useful as a request-origin marker but cannot distinguish the
	 * site's own page from a cross-site form/fetch. Fetch Metadata is preferred;
	 * when unavailable, match scheme, host and effective port of Origin (or
	 * Referer) against home_url(). Reject when no trustworthy signal exists.
	 *
	 * @return bool
	 */
	private function is_same_origin_request() {
		if ( ! empty( $_SERVER['HTTP_SEC_FETCH_SITE'] ) ) {
			$fetch_site = sanitize_key( wp_unslash( $_SERVER['HTTP_SEC_FETCH_SITE'] ) );
			return 'same-origin' === $fetch_site;
		}

		$site    = wp_parse_url( home_url( '/' ) );
		$request = array();
		if ( ! empty( $_SERVER['HTTP_ORIGIN'] ) ) {
			$request = wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) );
		} elseif ( ! empty( $_SERVER['HTTP_REFERER'] ) ) {
			$request = wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) );
		}
		if ( ! is_array( $site ) || ! is_array( $request ) ) {
			return false;
		}

		$site_scheme    = isset( $site['scheme'] ) ? strtolower( $site['scheme'] ) : '';
		$request_scheme = isset( $request['scheme'] ) ? strtolower( $request['scheme'] ) : '';
		$site_host      = isset( $site['host'] ) ? strtolower( $site['host'] ) : '';
		$request_host   = isset( $request['host'] ) ? strtolower( $request['host'] ) : '';
		$site_port      = isset( $site['port'] ) ? (int) $site['port'] : ( 'https' === $site_scheme ? 443 : 80 );
		$request_port   = isset( $request['port'] ) ? (int) $request['port'] : ( 'https' === $request_scheme ? 443 : 80 );

		return '' !== $site_scheme && '' !== $site_host
			&& $site_scheme === $request_scheme
			&& $site_host === $request_host
			&& $site_port === $request_port;
	}
}
