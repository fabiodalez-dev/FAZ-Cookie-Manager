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
	 * Add the server's verdict to a sanitised decision map.
	 *
	 * @param array  $clean      Sanitised categories map, as it will be stored.
	 * @param array  $data       The log payload (`signal_gpc`, `url`, `consent_id`).
	 * @param string $consent    Raw valid consent cookie for this request.
	 * @param array  $previous   The previous row for this consent id, or array().
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
				}
			}
		}
		if ( empty( $ids ) ) {
			return $clean;
		}

		$carried_from_previous = self::previous_exceptions( $previous );
		$url                   = self::request_page_url( $data );

		foreach ( $ids as $id ) {
			// Already on record for this visitor: the runtime re-asserts the
			// marker on later pages, and those pages need no placeholder of
			// their own. Judging them as fresh clicks would mark every second
			// page view unverified.
			if ( isset( $carried_from_previous[ $id ] ) ) {
				$clean[ self::CARRIED . $id ] = 'yes';
				continue;
			}
			$clean[ self::SERVED . $id ] = self::served( $id, $data, $consent, $url ) ? 'yes' : 'no';
		}

		return $clean;
	}

	/**
	 * Whether every circumstance of a served exception holds.
	 *
	 * @param string $id      Service id.
	 * @param array  $data    Log payload.
	 * @param string $consent Raw consent cookie.
	 * @param string $url     Normalised page URL.
	 * @return bool
	 */
	private static function served( $id, array $data, $consent, $url ) {
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
		if ( '' === (string) $consent ) {
			return false;
		}
		$quoted = preg_quote( $id, '/' );
		if ( ! preg_match( '/(?:^|,)svc\.' . $quoted . ':yes(?=,|$)/', $consent ) ) {
			return false;
		}
		if ( ! preg_match( '/(?:^|,)gpcx\.' . $quoted . ':1(?=,|$)/', $consent ) ) {
			return false;
		}

		// 4. The page must be on record as having offered that embed. This is
		//    the fact the client cannot invent: the inventory is written during
		//    render, from the server's own placeholder builder.
		if ( '' === $url || ! class_exists( '\\FazCookie\\Frontend\\Includes\\Embed_Inventory' ) ) {
			return false;
		}
		$category = Embed_Inventory::page_category( $url, $id );
		if ( '' === $category ) {
			return false;
		}

		// 5. And that category must be one a GPC signal actually closes —
		//    otherwise there was nothing to make an exception to.
		return self::category_is_sale_share( $category );
	}

	/**
	 * Whether a category is flagged as sale or sharing.
	 *
	 * @param string $slug Category slug.
	 * @return bool
	 */
	private static function category_is_sale_share( $slug ) {
		global $wpdb;
		$table = $wpdb->prefix . 'faz_cookie_categories';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is plugin prefix + literal; slug is bound. Audit path: a cached answer could contradict the row being written.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT sell_personal_data, share_personal_data FROM {$table} WHERE slug = %s LIMIT 1", $slug ) );
		if ( ! $row ) {
			return false;
		}
		return ( (int) $row->sell_personal_data > 0 ) || ( (int) $row->share_personal_data > 0 );
	}

	/**
	 * Exceptions the previous row for this consent id already carried.
	 *
	 * @param array $previous Previous log row.
	 * @return array<string,bool>
	 */
	private static function previous_exceptions( array $previous ) {
		if ( empty( $previous['categories'] ) ) {
			return array();
		}
		$stored = json_decode( (string) $previous['categories'], true );
		if ( ! is_array( $stored ) ) {
			return array();
		}
		$out = array();
		foreach ( array_keys( $stored ) as $key ) {
			$key = (string) $key;
			foreach ( array( 'meta.gpc_exception.', self::SERVED, self::CARRIED ) as $prefix ) {
				if ( 0 === strpos( $key, $prefix ) ) {
					$id = substr( $key, strlen( $prefix ) );
					if ( '' !== $id ) {
						$out[ $id ] = true;
					}
				}
			}
		}
		return $out;
	}

	/**
	 * The page this consent post came from.
	 *
	 * Prefers the browser-set Referer: a script can choose what to put in the
	 * payload's `url`, but not what the browser sends in that header. Falls
	 * back to the payload when the header is absent (referrer policies strip
	 * it), which is no worse than judging on the payload alone.
	 *
	 * @param array $data Log payload.
	 * @return string
	 */
	private static function request_page_url( array $data ) {
		if ( ! empty( $_SERVER['HTTP_REFERER'] ) ) {
			$referer = faz_normalize_page_url( sanitize_text_field( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) );
			if ( '' !== $referer ) {
				return $referer;
			}
		}
		return isset( $data['url'] ) ? faz_normalize_page_url( (string) $data['url'] ) : '';
	}
}
