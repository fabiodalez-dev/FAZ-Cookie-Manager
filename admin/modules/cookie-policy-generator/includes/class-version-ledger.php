<?php
/**
 * Ledger of the legal-document versions the administrator has acknowledged.
 *
 * The renderer already stamps every Cookie Policy with a content hash
 * (`data-faz-policy-version`, see Generator::policy_version_hash). What was
 * missing is the other half of the pair: a record of the hash the operator has
 * actually *seen and accepted*. Without it the plugin can tell that a policy
 * changed but not whether anybody noticed, and there is no honest way to ask
 * the one question that matters — is this change material enough to require
 * fresh consent?
 *
 * That question is not ours to answer. Re-prompting every visitor is a real
 * cost (consent fatigue, lost analytics, support tickets), and re-prompting
 * nobody after a substantive change is a compliance failure. Only the operator
 * knows which of the two just happened. So this class deliberately does the
 * least it can: it remembers a hash, and it reports whether the current one
 * still matches. Everything downstream — the notice, the re-consent bump — is
 * driven by an explicit click, never by this code.
 *
 * The option is keyed by document slug rather than storing a bare hash, because
 * the Cookie Policy is the first of several generated legal documents planned
 * for this engine. A second document must be able to slot in beside this one
 * without a migration.
 *
 * @package FazCookie\Admin\Modules\Cookie_Policy_Generator\Includes
 * @since   1.26.0
 */

namespace FazCookie\Admin\Modules\Cookie_Policy_Generator\Includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Record and compare acknowledged legal-document versions.
 */
class Version_Ledger {

	/**
	 * Option holding the acknowledged hash per document slug.
	 *
	 * Shape: array( '<document>' => array( 'hash' => '<6hex>.<6hex>', 'acknowledged_at' => <int> ) ).
	 */
	const OPTION = 'faz_legal_doc_acknowledged';

	/** Document slug this ledger entry belongs to. */
	const DOCUMENT = 'cookie-policy';

	/**
	 * Shape of a policy version hash: two 6-char sha1 prefixes joined by a dot
	 * (template fingerprint, then data fingerprint). Anything else is refused
	 * rather than stored — a malformed entry would either nag forever or
	 * silence a real change.
	 */
	const HASH_PATTERN = '/^[a-f0-9]{6}\.[a-f0-9]{6}$/';

	/**
	 * Current version hash of the Cookie Policy, as the renderer would stamp it.
	 *
	 * WHY THE REQUEST_URI PIN: `{{COOKIE_POLICY_URL}}` is built from the request
	 * path (Renderer::current_url()) and is part of the hashed substitution
	 * data. Computing the hash straight from `/wp-admin/admin.php?page=…` would
	 * therefore produce a value that differs on every admin screen and never
	 * matches the one stored a moment earlier — the notice would fire forever.
	 * Pinning the path to '/' makes the value self-consistent, which is the only
	 * property the ledger needs. It is NOT expected to equal the hash rendered
	 * on the public policy page, whose own URL is legitimately part of its data.
	 *
	 * @return string Hash in `<6hex>.<6hex>` form, or '' when no policy renders
	 *                (e.g. a POPIA install with mandatory fields still empty).
	 */
	public static function current_hash() {
		$original = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- stored verbatim and restored, never used as a value.

		try {
			$_SERVER['REQUEST_URI'] = '/';
			$html                   = Renderer::render( array() );
		} finally {
			// Unconditional restore: a throw inside render() must not leave the
			// rest of the request believing it is on a different URL.
			if ( null === $original ) {
				unset( $_SERVER['REQUEST_URI'] );
			} else {
				$_SERVER['REQUEST_URI'] = $original;
			}
		}

		// Read the hash back off the markup rather than recomputing it: the
		// attribute is the single source of truth the golden-render suite pins,
		// and a second code path would be free to drift from it.
		if ( preg_match( '/data-faz-policy-version="([a-f0-9]{6}\.[a-f0-9]{6})"/', (string) $html, $m ) ) {
			return $m[1];
		}
		return '';
	}

	/**
	 * The hash the administrator last confirmed, if any.
	 *
	 * @return string Stored hash, or '' when absent or malformed.
	 */
	public static function acknowledged_hash() {
		$ledger = get_option( self::OPTION, array() );
		if ( ! is_array( $ledger ) || ! isset( $ledger[ self::DOCUMENT ]['hash'] ) ) {
			return '';
		}
		$hash = $ledger[ self::DOCUMENT ]['hash'];
		if ( ! is_string( $hash ) ) {
			return '';
		}
		$hash = trim( $hash );
		return preg_match( self::HASH_PATTERN, $hash ) ? $hash : '';
	}

	/**
	 * Record a hash as acknowledged.
	 *
	 * @param mixed $hash Candidate hash; anything not matching HASH_PATTERN is refused.
	 * @return bool True when stored.
	 */
	public static function acknowledge( $hash ) {
		if ( ! is_string( $hash ) || ! preg_match( self::HASH_PATTERN, $hash ) ) {
			return false;
		}

		$ledger = (array) get_option( self::OPTION, array() );
		$ledger[ self::DOCUMENT ] = array(
			'hash'            => $hash,
			'acknowledged_at' => time(),
		);
		// Autoload NO: read only on the Cookie Policy admin screen, so there is
		// no reason for it to ride along on every front-end request.
		update_option( self::OPTION, $ledger, false );
		return true;
	}

	/**
	 * Compare the current policy against the acknowledged one.
	 *
	 * Statuses:
	 *  - `unavailable` — nothing renders (mandatory fields missing). Nothing is
	 *    written: seeding an empty or junk entry here would make the operator's
	 *    FIRST real configuration look like a change and fire a bogus prompt.
	 *  - `seeded`      — no acknowledgement yet, so the current hash is adopted
	 *    silently. This is what keeps the feature's own arrival from prompting
	 *    every existing install the moment it updates.
	 *  - `unchanged`   — stored hash still matches.
	 *  - `changed`     — the policy moved since the operator last confirmed it.
	 *
	 * @return array{status:string,current:string,acknowledged:string}
	 */
	public static function evaluate() {
		$current = self::current_hash();
		if ( '' === $current ) {
			return array(
				'status'       => 'unavailable',
				'current'      => '',
				'acknowledged' => self::acknowledged_hash(),
			);
		}

		$acknowledged = self::acknowledged_hash();
		if ( '' === $acknowledged ) {
			self::acknowledge( $current );
			return array(
				'status'       => 'seeded',
				'current'      => $current,
				'acknowledged' => $current,
			);
		}

		return array(
			'status'       => $acknowledged === $current ? 'unchanged' : 'changed',
			'current'      => $current,
			'acknowledged' => $acknowledged,
		);
	}
}
