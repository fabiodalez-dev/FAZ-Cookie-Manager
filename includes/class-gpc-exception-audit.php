<?php
/**
 * Decide, server-side, whether a logged GPC exception is one the server served.
 *
 * The consent cookie cannot prove that a visitor clicked Accept on a blocked
 * embed: a script on the page can write the same pairs (issue #285). No token
 * fixes that — anything issued into the page is readable by a script in that
 * page. So the record does not claim authenticity. It claims consistency: the
 * server writes down whether the exception matches the circumstances it would
 * have had to be minted in, and an administrator reading the log can tell an
 * exception the server would have served from one it would not.
 *
 * A forgery that reproduces a real click's circumstances stays indistinguishable.
 * That limit is stated in the changelog rather than hidden behind a verdict.
 *
 * @package FazCookie\Includes
 */

namespace FazCookie\Includes;

use FazCookie\Frontend\Includes\Embed_Inventory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Gpc_Exception_Audit
 */
class Gpc_Exception_Audit {

	/**
	 * Keys only this class may write. A client sending them is ignored.
	 */
	const SERVED  = 'meta.gpc_exception_served.';
	const CARRIED = 'meta.gpc_exception_carried.';

	/**
	 * Most exceptions judged per row.
	 *
	 * Each one can cost an inventory lookup, and each one adds a verdict key to
	 * a map the controller caps at 250 entries; the controller reserves this
	 * many of them (250 - MAX_IDS for the client) so a verdict is never the
	 * entry that gets cut. No real page offers fifty separate blocked embeds.
	 */
	const MAX_IDS = 50;

	/**
	 * category_is_sale_share() answers already given in this request.
	 *
	 * @var array<string,bool>
	 */
	private static $sale_share_memo = array();

	/**
	 * Add the server's verdict to a sanitised decision map.
	 *
	 * @param array  $clean      Sanitised categories map, as it will be stored.
	 * @param array  $data       The log payload (`signal_gpc`, `url`, `consent_id`).
	 * @param string $consent    Raw valid consent cookie for this request.
	 * @param array  $previous   The newest earlier row for this consent id that
	 *                           records any GPC-exception key, or array().
	 * @return array The map with the verdict keys added.
	 */
	public static function decide( array $clean, array $data, $consent = '', array $previous = array() ) {
		// The namespace belongs to the server. Whatever the client sent under
		// it is dropped before anything else looks at the map.
		foreach ( array_keys( $clean ) as $key ) {
			if ( 0 === strpos( (string) $key, self::SERVED ) || 0 === strpos( (string) $key, self::CARRIED ) ) {
				unset( $clean[ $key ] );
			}
		}

		$ids = array();
		foreach ( array_keys( $clean ) as $key ) {
			$key = (string) $key;
			if ( 0 === strpos( $key, 'meta.gpc_exception.' ) ) {
				$id = substr( $key, strlen( 'meta.gpc_exception.' ) );
				if ( '' !== $id ) {
					$ids[] = $id;
					// Bounded: ids past MAX_IDS get no verdict key at all
					// rather than an unbounded run of lookups.
					if ( count( $ids ) >= self::MAX_IDS ) {
						break;
					}
				}
			}
		}
		if ( empty( $ids ) ) {
			return $clean;
		}

		$carried_from_previous = self::previous_exceptions( $previous );
		$urls                  = self::request_page_urls( $data );

		foreach ( $ids as $id ) {
			// Already on record for this visitor: the runtime re-asserts the
			// marker on later pages, and those pages need no placeholder of
			// their own. Judging them as fresh clicks would mark every second
			// page view unverified.
			//
			// The carried verdict keeps what the first one said, verbatim:
			// 'yes' or 'no' as stored. Writing 'yes' regardless turned an
			// exception the server had judged unverified into an
			// unremarkable "carried" one on the very next page, and mapping a
			// legacy bare claim ('') to 'yes' did the same for exceptions
			// never judged at all. A '' carries as '': recorded, still
			// unjudged.
			//
			// The carry also requires the current cookie to still hold the
			// pairs: after a withdrawal (or a forged re-assertion) they are
			// gone, and the claim falls through to served() below.
			if ( isset( $carried_from_previous[ $id ] ) && self::cookie_holds_exception( $id, $consent ) ) {
				$clean[ self::CARRIED . $id ] = $carried_from_previous[ $id ];
				continue;
			}
			$clean[ self::SERVED . $id ] = self::served( $id, $data, $consent, $urls ) ? 'yes' : 'no';
		}

		return $clean;
	}

	/**
	 * Whether every circumstance of a served exception holds.
	 *
	 * @param string   $id      Service id.
	 * @param array    $data    Log payload.
	 * @param string   $consent Raw consent cookie.
	 * @param string[] $urls    Candidate page URLs, in order; see request_page_urls().
	 * @return bool
	 */
	private static function served( $id, array $data, $consent, array $urls ) {
		// 1. The signal the exception is an exception TO must have been present.
		if ( empty( $data['signal_gpc'] ) ) {
			return false;
		}

		// 2. A standing Do Not Sell request outranks a per-embed click, so an
		//    exception cannot be served while one is in force.
		if ( isset( $_COOKIE['fazcookie-dnsmpi'] ) && '1' === sanitize_text_field( wp_unslash( $_COOKIE['fazcookie-dnsmpi'] ) ) ) {
			return false;
		}

		// 3. The cookie this request carried must actually hold the grant AND
		//    its marker. A log row claiming an exception the cookie does not
		//    carry describes no state the runtime was ever in.
		if ( ! self::cookie_holds_exception( $id, $consent ) ) {
			return false;
		}

		// 4. The page must be on record as having offered that embed. This is
		//    the fact the client cannot invent: the inventory is written during
		//    render, from the server's own placeholder builder.
		//    The first candidate with a row decides.
		if ( empty( $urls ) || ! class_exists( '\\FazCookie\\Frontend\\Includes\\Embed_Inventory' ) ) {
			return false;
		}
		$category = '';
		foreach ( $urls as $url ) {
			$category = Embed_Inventory::page_category( $url, $id );
			if ( '' !== $category ) {
				break;
			}
		}
		if ( '' === $category ) {
			return false;
		}

		// 5. And that category must be one a GPC signal actually closes —
		//    otherwise there was nothing to make an exception to.
		return self::category_is_sale_share( $category );
	}

	/**
	 * Whether the consent cookie carries both the grant and the marker for an id.
	 *
	 * Shared by served() and the carry gate in decide(): an exception already
	 * on record may only be carried forward while the current request's cookie
	 * still holds svc.<id>:yes + gpcx.<id>:1. After a withdrawal (or a forged
	 * re-assertion) those pairs are gone, and the claim is judged as a fresh
	 * one instead of inheriting the earlier verdict.
	 *
	 * @param string $id      Service id.
	 * @param string $consent Raw consent cookie.
	 * @return bool
	 */
	private static function cookie_holds_exception( $id, $consent ) {
		if ( '' === (string) $consent ) {
			return false;
		}
		$quoted = preg_quote( $id, '/' );
		return (bool) preg_match( '/(?:^|,)svc\.' . $quoted . ':yes(?=,|$)/', $consent )
			&& (bool) preg_match( '/(?:^|,)gpcx\.' . $quoted . ':1(?=,|$)/', $consent );
	}

	/**
	 * Whether a category is flagged as sale or sharing.
	 *
	 * @param string $slug Category slug.
	 * @return bool
	 */
	private static function category_is_sale_share( $slug ) {
		$slug = (string) $slug;
		if ( isset( self::$sale_share_memo[ $slug ] ) ) {
			return self::$sale_share_memo[ $slug ];
		}
		global $wpdb;
		$table = $wpdb->prefix . 'faz_cookie_categories';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is plugin prefix + literal; slug is bound. Audit path: a persistent cache could contradict the row being written; the memo above lives for this request only.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT sell_personal_data, share_personal_data FROM {$table} WHERE slug = %s LIMIT 1", $slug ) );

		self::$sale_share_memo[ $slug ] = $row
			? ( ( (int) $row->sell_personal_data > 0 ) || ( (int) $row->share_personal_data > 0 ) )
			: false;
		return self::$sale_share_memo[ $slug ];
	}

	/**
	 * Exceptions the previous row already carried, with the verdict it gave.
	 *
	 * id => 'yes' | 'no' | ''. An explicit served verdict wins over a carried
	 * one, and either wins over the bare client key, which records the claim but
	 * no verdict ('' — a row written before the server judged anything).
	 *
	 * @param array $previous Previous log row.
	 * @return array<string,string>
	 */
	private static function previous_exceptions( array $previous ) {
		if ( empty( $previous['categories'] ) ) {
			return array();
		}
		$stored = $previous['categories'];
		if ( is_string( $stored ) ) {
			$stored = json_decode( $stored, true );
		}
		if ( ! is_array( $stored ) ) {
			return array();
		}
		// Lowest rank first; a higher rank overwrites whatever a lower one set.
		$ranked = array(
			'meta.gpc_exception.' => 0,
			self::CARRIED         => 1,
			self::SERVED          => 2,
		);
		$out  = array();
		$rank = array();
		foreach ( $stored as $key => $value ) {
			$key = (string) $key;
			foreach ( $ranked as $prefix => $level ) {
				if ( 0 !== strpos( $key, $prefix ) ) {
					continue;
				}
				$id = substr( $key, strlen( $prefix ) );
				if ( '' === $id || ( isset( $rank[ $id ] ) && $rank[ $id ] >= $level ) ) {
					continue;
				}
				$rank[ $id ] = $level;
				// The verdict carries verbatim: 'yes' or 'no' as stored, and ''
				// for anything else — a bare client key (level 0) or a carried
				// '' — so an exception the server never judged can never turn
				// into 'yes' by passing through the chain (M-1).
				$out[ $id ] = 0 === $level ? '' : ( ( 'yes' === $value || 'no' === $value ) ? $value : '' );
			}
		}
		return $out;
	}

	/**
	 * The pages this consent post may have come from, most trusted first.
	 *
	 * Prefers the browser-set Referer, which a stray or careless script leaves
	 * pointing at the page the visitor was really on. It is not proof: a script
	 * on the site's own pages can pass `referrer` to fetch() and name any page
	 * of the same origin, exactly as it can write any `url` into the payload.
	 * Neither source makes a forged click distinguishable from a real one —
	 * the record claims consistency, not authenticity (see the class docblock).
	 * Falls back to the payload when the header is absent (referrer policies
	 * strip it), which is no worse than judging on the payload alone.
	 *
	 * A Referer reduced to its bare origin (`Referrer-Policy: origin` or
	 * `strict-origin`, some privacy extensions) names the home page whatever
	 * page the visitor was on, so a genuine click anywhere else found no row.
	 * The payload url is then offered as a second candidate — only after the
	 * origin itself, only on the same host, and only when it names a deeper
	 * path: it can add the one page the header could not name, never replace a
	 * page the header did name.
	 *
	 * @param array $data Log payload.
	 * @return string[]
	 */
	private static function request_page_urls( array $data ) {
		$payload = isset( $data['url'] ) ? faz_normalize_page_url( (string) $data['url'] ) : '';
		if ( empty( $_SERVER['HTTP_REFERER'] ) ) {
			return '' === $payload ? array() : array( $payload );
		}
		$referer = faz_normalize_page_url( sanitize_text_field( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) );
		if ( '' === $referer ) {
			return '' === $payload ? array() : array( $payload );
		}

		$ref_parts = faz_parse_url( $referer );
		$ref_path  = is_array( $ref_parts ) && isset( $ref_parts['path'] ) ? (string) $ref_parts['path'] : '';
		$bare      = is_array( $ref_parts ) && ( '' === $ref_path || '/' === $ref_path ) && empty( $ref_parts['query'] );
		if ( ! $bare || '' === $payload ) {
			return array( $referer );
		}

		$pay_parts = faz_parse_url( $payload );
		$pay_path  = is_array( $pay_parts ) && isset( $pay_parts['path'] ) ? (string) $pay_parts['path'] : '';
		$same_host = is_array( $pay_parts )
			&& isset( $ref_parts['host'], $pay_parts['host'] )
			&& strtolower( (string) $ref_parts['host'] ) === strtolower( (string) $pay_parts['host'] )
			&& ( isset( $ref_parts['port'] ) ? (int) $ref_parts['port'] : 0 ) === ( isset( $pay_parts['port'] ) ? (int) $pay_parts['port'] : 0 );
		if ( ! $same_host || '' === $pay_path || '/' === $pay_path ) {
			return array( $referer );
		}
		return array( $referer, $payload );
	}
}
