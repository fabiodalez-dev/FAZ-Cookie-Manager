<?php
/**
 * A/B testing helpers for banner variants.
 *
 * Pure, dependency-free logic for the "A/B testing of banner variants"
 * feature (Settings → Banner Control → A/B test). Keeping the split
 * assignment and the results aggregation in a WordPress-free class lets both
 * be unit-tested standalone (tests/unit/test-abtest-php.php) and keeps the
 * WordPress-coupled callers — Frontend (selection) and the consent-logs
 * Controller (stats) — thin.
 *
 * The feature lets a site run two or more of its EXISTING compliant banner
 * rows side by side with a persistent random split, then reports the
 * accept-rate per variant from the consent log so the admin can optimise the
 * consent UX with evidence. This class never authors a banner and never
 * relaxes any compliance guarantee — it only chooses AMONG banner rows the
 * admin already created (each independently equal-weight / opt-in) and
 * aggregates rows already written to wp_faz_consent_logs.
 *
 * @package FazCookie\Includes
 */

namespace FazCookie\Includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Stateless A/B testing calculations.
 *
 * @class   Ab_Test
 * @since   1.25.0
 * @package FazCookie
 */
class Ab_Test {

	/**
	 * Intersect a list of candidate variant slugs with the slugs that are
	 * actually usable (active banner rows), preserving the candidate order and
	 * dropping duplicates / blanks.
	 *
	 * Used to validate the admin-configured variant list at both selection and
	 * reporting time: a variant whose banner was deleted or deactivated after
	 * the test was set up silently drops out, so the split never serves a
	 * missing banner and the stats panel never invents an empty column.
	 *
	 * @param array $candidates Configured variant slugs (order = admin intent).
	 * @param array $active     Slugs that resolve to a usable banner row.
	 * @return string[] Ordered, unique intersection.
	 */
	public static function filter_slugs( $candidates, $active ) {
		$candidates = is_array( $candidates ) ? $candidates : array();
		$active     = is_array( $active ) ? $active : array();

		// Normalise the active set to a fast lookup of trimmed string slugs.
		$active_set = array();
		foreach ( $active as $slug ) {
			$slug = is_scalar( $slug ) ? trim( (string) $slug ) : '';
			if ( '' !== $slug ) {
				$active_set[ $slug ] = true;
			}
		}

		$out  = array();
		$seen = array();
		foreach ( $candidates as $slug ) {
			$slug = is_scalar( $slug ) ? trim( (string) $slug ) : '';
			if ( '' === $slug || isset( $seen[ $slug ] ) || ! isset( $active_set[ $slug ] ) ) {
				continue;
			}
			$seen[ $slug ] = true;
			$out[]         = $slug;
		}
		return $out;
	}

	/**
	 * Assign a visitor to one of the valid variant slugs.
	 *
	 * The split is STICKY after a consent action: the caller supplies the banner
	 * slug already stored in the strictly-necessary consent record. If it is
	 * still a valid variant, that same variant is returned. A visitor who has
	 * not acted yet gets a fresh random pick without setting an experiment
	 * cookie before consent. The random index stays a parameter so this method
	 * remains pure and deterministically testable — the caller passes wp_rand().
	 *
	 * @param array  $valid_slugs  Slugs already filtered to valid variants.
	 * @param string $stored_value Banner slug from the stored consent scope.
	 * @param int    $random_index Caller-supplied random integer (any range).
	 * @return string Chosen variant slug, or '' when fewer than two variants.
	 */
	public static function pick_variant( $valid_slugs, $stored_value, $random_index ) {
		$valid = array();
		if ( is_array( $valid_slugs ) ) {
			foreach ( $valid_slugs as $slug ) {
				$slug = is_scalar( $slug ) ? trim( (string) $slug ) : '';
				if ( '' !== $slug ) {
					$valid[] = $slug;
				}
			}
		}
		$valid = array_values( array_unique( $valid ) );

		// An A/B test needs at least two variants; below that the caller should
		// fall back to the normal single-banner selection.
		if ( count( $valid ) < 2 ) {
			return '';
		}

		$stored_value = is_scalar( $stored_value ) ? trim( (string) $stored_value ) : '';
		if ( '' !== $stored_value && in_array( $stored_value, $valid, true ) ) {
			return $stored_value;
		}

		$n = count( $valid );
		$i = (int) $random_index;
		// Normalise any integer (incl. negatives) into [0, n).
		$i = ( ( $i % $n ) + $n ) % $n;
		return $valid[ $i ];
	}

	/**
	 * Turn raw grouped consent-log rows into a per-variant results table.
	 *
	 * Zero-fills every requested variant slug (so a variant that has not yet
	 * produced a consent still appears with a 0% rate rather than vanishing)
	 * and computes the acceptance rate for each variant. Order follows the
	 * requested $variant_slugs so the admin sees the columns in the order they
	 * configured.
	 *
	 * Metric definition (well-defined and transparent):
	 *   - NUMERATOR — `accepted`: rows with status 'accepted'.
	 *   - DENOMINATOR — `decisions`: every explicit consent DECISION recorded
	 *     under the variant, i.e.
	 *         accepted + rejected + partial + optout + rescinded
	 *     where `optout` = status 'dnsmpi_optout' and `rescinded` =
	 *     'dns_rescinded' (the two CCPA / Do-Not-Sell "Do Not Sell / opt-out"
	 *     outcomes). An opt-out or a rescind is a deliberate NON-accept
	 *     decision, so it belongs in the denominator — a CCPA (or 'both')
	 *     variant is rated against every decision it produced, not just the
	 *     GDPR-style accept / reject / partial ones.
	 *   - `accept_rate = accepted / decisions * 100` (0 when decisions == 0).
	 *
	 * The denominator is computed from the named per-status counts, NOT from
	 * `total` (COUNT(*) over the window). That makes the rate independent of
	 * any status outside the known decision set, so it can never be silently
	 * diluted by an unexpected value. `total` is still returned verbatim for
	 * backward compatibility, and every per-status count plus the derived
	 * `decisions` denominator is exposed so the dashboard can show the
	 * composition behind the single rate rather than one ambiguous number.
	 *
	 * @param array $rows          Grouped rows, each with keys banner_slug,
	 *                             total, accepted, rejected, partial, optout,
	 *                             rescinded (strings from $wpdb are fine — they
	 *                             are cast here; missing count keys default to
	 *                             0).
	 * @param array $variant_slugs Slugs to report, in display order.
	 * @return array<int, array<string, mixed>> One entry per variant slug.
	 */
	public static function compute_stats( $rows, $variant_slugs ) {
		$rows          = is_array( $rows ) ? $rows : array();
		$variant_slugs = is_array( $variant_slugs ) ? $variant_slugs : array();

		// Index the DB rows by slug for O(1) lookup.
		$by_slug = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['banner_slug'] ) ) {
				continue;
			}
			$by_slug[ (string) $row['banner_slug'] ] = $row;
		}

		$out  = array();
		$seen = array();
		foreach ( $variant_slugs as $slug ) {
			$slug = is_scalar( $slug ) ? trim( (string) $slug ) : '';
			if ( '' === $slug || isset( $seen[ $slug ] ) ) {
				continue;
			}
			$seen[ $slug ] = true;

			$row       = isset( $by_slug[ $slug ] ) ? $by_slug[ $slug ] : array();
			$total     = isset( $row['total'] ) ? (int) $row['total'] : 0;
			$accepted  = isset( $row['accepted'] ) ? (int) $row['accepted'] : 0;
			$rejected  = isset( $row['rejected'] ) ? (int) $row['rejected'] : 0;
			$partial   = isset( $row['partial'] ) ? (int) $row['partial'] : 0;
			$optout    = isset( $row['optout'] ) ? (int) $row['optout'] : 0;
			$rescinded = isset( $row['rescinded'] ) ? (int) $row['rescinded'] : 0;

			// Explicit, documented denominator: every consent DECISION recorded
			// under this variant. Opt-out / rescind are deliberate non-accepts,
			// so they count as decisions — see the method docblock. Derived from
			// the named statuses (not $total) so the rate can't be diluted by a
			// value outside the known decision set.
			$decisions = $accepted + $rejected + $partial + $optout + $rescinded;

			$out[] = array(
				'slug'        => $slug,
				'total'       => $total,
				'accepted'    => $accepted,
				'rejected'    => $rejected,
				'partial'     => $partial,
				'optout'      => $optout,
				'rescinded'   => $rescinded,
				'decisions'   => $decisions,
				'accept_rate' => $decisions > 0 ? round( $accepted / $decisions * 100, 1 ) : 0,
			);
		}
		return $out;
	}
}
