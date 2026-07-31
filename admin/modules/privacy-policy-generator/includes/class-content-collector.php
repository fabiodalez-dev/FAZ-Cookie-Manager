<?php
/**
 * Collect what the installed plugins declare about their own data processing.
 *
 * Since 4.9.6 a plugin declares its own processing by calling
 * `wp_add_privacy_policy_content( $plugin_name, $policy_text )`, and
 * `WP_Privacy_Policy_Content::get_suggested_policy_text()` hands back every
 * registered contribution. FAZ is already a producer of one such block (see
 * `CLI::register_privacy_hooks()`); this class makes it a consumer too.
 *
 * HARD CONSTRAINT — collection is admin-only, and this is not negotiable.
 * `wp_add_privacy_policy_content()` calls `_doing_it_wrong()` unless it runs
 * inside wp-admin on `admin_init` or later (guard added in 4.9.7), so
 * producers register there and their content simply does not exist in any
 * other context. Forcing the issue is worse than useless: firing
 * `do_action( 'admin_init' )` from WP-CLI to "warm up" the producers emitted
 * six `_doing_it_wrong` notices and then fataled inside WooCommerce, whose
 * `OrderAttributionController::get_order_screen_id()` calls
 * `wc_get_page_screen_id()` — undefined outside a real admin request. Do not
 * move this onto a REST route or a cron event later; it will fatal on sites
 * running WooCommerce.
 *
 * SNAPSHOT, NOT LIVE. Collection therefore cannot happen at render time: the
 * blocks are persisted into the `faz_privacy_content_snapshot` option and the
 * public document renders from that. Required independently anyway — Cache
 * Compatibility Mode (1.21.0) demands render invariance, and a live collection
 * would make the output depend on request context.
 *
 * Full rationale: docs/privacy-policy-collection-design.md.
 *
 * @package FazCookie\Admin\Modules\Privacy_Policy_Generator\Includes
 * @since   1.26.0
 */

namespace FazCookie\Admin\Modules\Privacy_Policy_Generator\Includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Snapshot the privacy-policy content other plugins register about themselves.
 *
 * The point of aggregating is editorial, not technical: we must not author
 * claims about what a site processes on behalf of software we did not write.
 * WooCommerce's description of WooCommerce is both more accurate than anything
 * we could write and, legally, WooCommerce's statement rather than ours.
 *
 * EDITABLE PLACEHOLDERS, mirroring `Section_Overrides`. The collected text is
 * a placeholder, not a saved value: an empty override means "keep tracking
 * upstream" and the block silently adopts whatever the plugin now says. Once
 * the operator writes their own wording, that wording is the document and an
 * upstream change is flagged, never silently applied — the same judgement the
 * Cookie Policy makes, because an explicit editorial decision is the most
 * specific source there is. The anchor for that decision is the block's
 * `source_hash` at the moment the override was saved; the block's own identity
 * anchor is the plugin, matched first by text (so a rename or a translated
 * display name carries the block forward) and then by name (so a rewritten
 * declaration updates the block it belongs to).
 *
 * FAZ keeps its own snapshot and computes its own diff on purpose. Core's
 * `added`/`updated`/`removed` flags are computed against the
 * `_wp_suggested_privacy_policy_content` post meta of the page named by
 * `wp_page_for_privacy_policy`; on a site where that option is 0 there is no
 * baseline and those flags are meaningless. Change detection has to work
 * whether or not the operator ever configured core's privacy page.
 *
 * KNOWN, ACCEPTED SIDE EFFECT. `get_suggested_policy_text()` refreshes core's
 * `_wp_suggested_privacy_policy_content` meta cache when a privacy page IS
 * configured, which can pre-empt core's own "suggested text has changed" admin
 * notice. It is core's public, sanctioned accessor — the same refresh happens
 * whenever the operator opens core's Privacy Policy Guide — the effect is
 * bounded to installs that both configured a privacy page and visit FAZ
 * screens, and FAZ's own three-state flagging supersedes that notice for
 * FAZ-managed documents. Reading the private `$policy_content` property by
 * Reflection to avoid it was considered and rejected: worse for wp.org review
 * and brittle against core refactors.
 *
 * KNOWN BLIND SPOT, documented rather than fixed. If a site's admin locale
 * changes, every translated `policy_text` changes with it, so every block
 * legitimately reports an update. Worse, a plugin that translates BOTH its
 * name and its text matches neither identity pass and cycles removed + added,
 * orphaning any override on it. Core has exactly the same limitation, and
 * plugin display names are in practice untranslated brand names.
 */
class Content_Collector {

	/**
	 * Option holding the snapshot. Written with autoload false: it is
	 * admin/render-source data, never needed on an ordinary frontend request,
	 * and can run to tens of kilobytes on a plugin-heavy site.
	 */
	const OPTION = 'faz_privacy_content_snapshot';

	/** Stored shape version. Bump and migrate in place when the shape changes. */
	const SCHEMA = 1;

	/**
	 * Hard cap on stored blocks. No real install comes close; the cap exists
	 * so a malformed or pathological producer cannot grow the option without
	 * bound. Same rationale as `Section_Overrides::MAX_SECTIONS`.
	 */
	const MAX_BLOCKS = 100;

	/** Maximum length of one block body, in characters (WooCommerce's is ~5 KB). */
	const MAX_HTML = 60000;

	/** Maximum length of a stored plugin display name, in characters. */
	const MAX_NAME = 200;

	/**
	 * Register the collection listener. Called once from
	 * `CLI::register_privacy_hooks()`.
	 *
	 * `current_screen` rather than `admin_init` for two reasons. It fires only
	 * in genuine wp-admin page requests — `set_current_screen()` is not
	 * reached by WP-CLI, REST or admin-ajax, whereas `admin_init` fires on
	 * admin-ajax.php and can be force-fired from the CLI, which is the exact
	 * path that fatals WooCommerce. And it fires AFTER `admin_init`, so every
	 * producer registered at default priority — FAZ's own included — has
	 * already called `wp_add_privacy_policy_content()`.
	 *
	 * @return void
	 */
	public static function register_hooks() {
		\add_action( 'current_screen', array( __CLASS__, 'maybe_collect' ) );
	}

	/**
	 * Collect, if and only if this really is an operator looking at a FAZ
	 * admin screen.
	 *
	 * Restricting to FAZ's own screens is deliberate. Collecting on every
	 * wp-admin pageview sitewide would run the read (and risk an option write)
	 * forever, to keep fresh a snapshot only FAZ's document consumes. Firing
	 * on FAZ screens refreshes it exactly when the operator is looking at the
	 * plugin — including, later, the privacy-policy editor screen itself,
	 * which lands on a `faz-cookie-manager-*` slug and therefore always opens
	 * against freshly collected data.
	 *
	 * @param \WP_Screen $screen Current admin screen.
	 * @return void
	 */
	public static function maybe_collect( $screen ) {
		// Belt and braces: restate the environment `current_screen` implies,
		// so the method stays safe if anything ever calls it directly.
		if ( ! \is_admin() || ! \did_action( 'admin_init' ) ) {
			return;
		}

		// Explicit denial of every context the core guard forbids.
		if ( \wp_doing_ajax() || \wp_doing_cron() ) {
			return;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return;
		}

		// Collection writes an option; only an operator-grade request may.
		if ( ! \current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! is_object( $screen ) || ! isset( $screen->id ) ) {
			return;
		}
		if ( false === strpos( (string) $screen->id, 'faz-cookie-manager' ) ) {
			return;
		}

		self::collect();
	}

	/**
	 * Perform one collection: read the registered contributions, sanitise
	 * them, diff against the stored snapshot, and persist only if something
	 * material changed.
	 *
	 * Guards are the caller's responsibility — `maybe_collect()` applies them,
	 * and so must the future admin screen's explicit refresh control.
	 *
	 * @return array The current (possibly just-updated) snapshot.
	 */
	public static function collect() {
		$snapshot = self::get_snapshot();
		$incoming = self::registered_content();
		$result   = self::diff( $snapshot['blocks'], $incoming );

		if ( ! $result['changed'] ) {
			// An unchanged collection is a pure read. Not even `collected_at`
			// moves: the timestamp records the last MATERIAL change, and
			// bumping it would write the option on every admin pageview.
			return $snapshot;
		}

		$snapshot['schema']       = self::SCHEMA;
		$snapshot['collected_at'] = time();
		$snapshot['blocks']       = $result['blocks'];

		\update_option( self::OPTION, $snapshot, false );

		return $snapshot;
	}

	/**
	 * The stored snapshot, shape-validated. Never returns junk: a corrupt or
	 * foreign-schema option reads as an empty snapshot and the next collection
	 * rebuilds it from scratch.
	 *
	 * @return array{schema:int,collected_at:int,blocks:array}
	 */
	public static function get_snapshot() {
		$empty = array(
			'schema'       => self::SCHEMA,
			'collected_at' => 0,
			'blocks'       => array(),
		);

		$raw = \get_option( self::OPTION );
		if ( ! is_array( $raw ) ) {
			return $empty;
		}
		if ( ! isset( $raw['schema'] ) || (int) $raw['schema'] !== self::SCHEMA ) {
			return $empty;
		}
		if ( ! isset( $raw['blocks'] ) || ! is_array( $raw['blocks'] ) ) {
			return $empty;
		}

		$blocks = array();
		foreach ( $raw['blocks'] as $id => $block ) {
			if ( ! is_string( $id ) || '' === $id ) {
				continue;
			}
			$normalised = self::normalize_block( $block );
			if ( null === $normalised ) {
				// Drop the malformed block, keep its siblings. A legal
				// document is better off missing one section than absent.
				continue;
			}
			$blocks[ $id ] = $normalised;
		}

		return array(
			'schema'       => self::SCHEMA,
			'collected_at' => isset( $raw['collected_at'] ) && is_numeric( $raw['collected_at'] ) ? (int) $raw['collected_at'] : 0,
			'blocks'       => $blocks,
		);
	}

	/**
	 * Per-block rows for the admin editor. Pure read, no side effects.
	 *
	 * `stale` and `orphaned` are derived here and deliberately never stored:
	 * a derived flag cannot desynchronise from the data it describes, a
	 * stored copy can. Same reason `Section_Overrides::describe()` derives
	 * `active` instead of persisting it.
	 *
	 * @return array<int,array{id:string,plugin_name:string,source_html:string,override:string,effective_html:string,removed:bool,stale:bool,orphaned:bool,added:int,updated:int}>
	 */
	public static function describe() {
		$snapshot = self::get_snapshot();
		$rows     = array();

		foreach ( $snapshot['blocks'] as $id => $block ) {
			$override = $block['override']['text'];
			$rows[]   = array(
				'id'             => $id,
				'plugin_name'    => $block['plugin_name'],
				'source_html'    => $block['source_html'],
				'override'       => $override,
				'effective_html' => '' !== $override ? $override : $block['source_html'],
				'removed'        => $block['removed'] > 0,
				'stale'          => '' !== $override && $block['override']['anchor_hash'] !== $block['source_hash'],
				'orphaned'       => '' !== $override && $block['removed'] > 0,
				'added'          => $block['added'],
				'updated'        => $block['updated'],
			);
		}

		// Stable, human order for the UI. Ties break on id so the order never
		// depends on option storage order.
		usort(
			$rows,
			function ( $a, $b ) {
				$cmp = strcasecmp( $a['plugin_name'], $b['plugin_name'] );
				return 0 !== $cmp ? $cmp : strcmp( $a['id'], $b['id'] );
			}
		);

		return $rows;
	}

	/**
	 * What a renderer consumes: render-ready blocks, already resolved to the
	 * operator's wording where one exists.
	 *
	 * A block whose producer is gone AND which carries an override stays in:
	 * the operator's wording is their document until they delete it. A
	 * removed block without an override never gets here — the diff deleted it.
	 *
	 * @return array<string,array{plugin_name:string,html:string}>
	 */
	public static function effective_blocks() {
		$snapshot = self::get_snapshot();
		$out      = array();

		foreach ( $snapshot['blocks'] as $id => $block ) {
			$override = $block['override']['text'];
			if ( '' === $override && $block['removed'] > 0 ) {
				continue;
			}
			$out[ $id ] = array(
				'plugin_name' => $block['plugin_name'],
				'html'        => '' !== $override ? $override : $block['source_html'],
			);
		}

		return $out;
	}

	/**
	 * Save (or clear) the operator's own wording for one block.
	 *
	 * An empty or whitespace-only body clears the override, which puts the
	 * block back to tracking upstream — the Cookie Policy's "empty box means
	 * shipped text" rule. Clearing the override of a block whose producer is
	 * gone deletes the block outright: nothing is left to track and nothing is
	 * left to render.
	 *
	 * An unknown id returns false rather than creating a bucket, so a typo in
	 * a future UI cannot invent a section (same judgement as
	 * `Section_Overrides::sanitize()`).
	 *
	 * Capability and nonce checks belong to the caller. This is the data
	 * layer, and it must stay callable from standalone tests.
	 *
	 * @param string $block_id Block id.
	 * @param string $html     Operator-authored HTML.
	 * @return bool True when the snapshot was actually written.
	 */
	public static function set_override( $block_id, $html ) {
		$block_id = (string) $block_id;
		$snapshot = self::get_snapshot();

		if ( '' === $block_id || ! isset( $snapshot['blocks'][ $block_id ] ) ) {
			return false;
		}

		$block = $snapshot['blocks'][ $block_id ];
		$clean = self::sanitize_html( $html );

		if ( '' === $clean ) {
			if ( $block['removed'] > 0 ) {
				unset( $snapshot['blocks'][ $block_id ] );
			} elseif ( '' === $block['override']['text'] ) {
				return false;
			} else {
				$snapshot['blocks'][ $block_id ]['override'] = array(
					'text'        => '',
					'anchor_hash' => '',
				);
			}
		} else {
			if ( $clean === $block['override']['text'] && $block['source_hash'] === $block['override']['anchor_hash'] ) {
				return false;
			}
			$snapshot['blocks'][ $block_id ]['override'] = array(
				'text'        => $clean,
				// The anchor is the source as it stood when the operator
				// decided. When the source moves away from it, the block is
				// stale — flagged for review, never overwritten.
				'anchor_hash' => $block['source_hash'],
			);
		}

		// `collected_at` deliberately does not move: it records the last time
		// COLLECTION found a change, and an editorial decision is not one.
		\update_option( self::OPTION, $snapshot, false );

		return true;
	}

	/**
	 * Sanitise one block body.
	 *
	 * Order matters and is load-bearing: kses, then HTML-aware clip, then trim, and the
	 * hash is taken on the FINAL stored string. Hashing the producer's raw
	 * text instead would make every block whose text kses touches re-hash
	 * differently from what is stored, producing a phantom "updated" — and a
	 * false stale flag on every operator-edited block — on every single
	 * collection, forever.
	 *
	 * @param string $html Raw HTML.
	 * @return string
	 */
	private static function sanitize_html( $html ) {
		$html = \wp_kses_post( (string) $html );
		if ( self::length( $html ) <= self::MAX_HTML ) {
			return trim( $html );
		}

		// A character slice can end inside `<a href="…` or leave an open
		// element. Rebalance after clipping, then reduce the text budget by the
		// closing tags that balancing added until the final stored HTML fits.
		$budget = self::MAX_HTML;
		while ( $budget > 0 ) {
			$clipped    = self::clip( $html, $budget );
			$last_open  = strrpos( $clipped, '<' );
			$last_close = strrpos( $clipped, '>' );
			if ( false !== $last_open && ( false === $last_close || $last_open > $last_close ) ) {
				$clipped = substr( $clipped, 0, $last_open );
			}
			$balanced = function_exists( 'force_balance_tags' ) ? \force_balance_tags( $clipped ) : $clipped;
			$length   = self::length( $balanced );
			if ( $length <= self::MAX_HTML ) {
				return trim( $balanced );
			}
			$budget -= max( 1, $length - self::MAX_HTML );
		}
		return '';
	}

	/** Character length, multibyte-aware where possible. */
	private static function length( $text ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( (string) $text ) : strlen( (string) $text );
	}

	/**
	 * Clip a string to a character budget, multibyte-aware where possible.
	 *
	 * @param string $text Text.
	 * @param int    $max  Maximum characters.
	 * @return string
	 */
	private static function clip( $text, $max ) {
		$text = (string) $text;
		$max  = (int) $max;
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $text, 0, $max );
		}
		return substr( $text, 0, $max );
	}

	/**
	 * The contributions currently registered by plugins, sanitised and hashed.
	 *
	 * The leading backslashes are not decoration: this file lives in a
	 * namespace, and an unqualified `WP_Privacy_Policy_Content` resolves to
	 * `FazCookie\…\WP_Privacy_Policy_Content` and fatals. Same bug family as
	 * issues #85 and #9, and the same reason the admin-only include has to be
	 * required by hand — REST and other non-admin entry points do not load
	 * wp-admin includes.
	 *
	 * @return array<int,array{name:string,html:string,hash:string}>
	 */
	private static function registered_content() {
		if ( ! class_exists( '\WP_Privacy_Policy_Content' ) ) {
			$file = ABSPATH . 'wp-admin/includes/class-wp-privacy-policy-content.php';
			if ( ! file_exists( $file ) ) {
				return array();
			}
			require_once $file;
		}
		if ( ! method_exists( '\WP_Privacy_Policy_Content', 'get_suggested_policy_text' ) ) {
			return array();
		}

		$raw = \WP_Privacy_Policy_Content::get_suggested_policy_text();
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$out = array();
		foreach ( $raw as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			// Entries carrying a `removed` timestamp are read back out of
			// core's post-meta cache and are NOT currently registered. Keeping
			// them would resurrect every plugin the site ever deactivated.
			if ( ! empty( $entry['removed'] ) ) {
				continue;
			}

			// Core's `added`/`updated` timestamps are ignored on purpose: they
			// are computed against `wp_page_for_privacy_policy`, and mean
			// nothing on a site that never configured one.
			$name = isset( $entry['plugin_name'] ) ? \sanitize_text_field( (string) $entry['plugin_name'] ) : '';
			$name = trim( self::clip( $name, self::MAX_NAME ) );
			$html = self::sanitize_html( isset( $entry['policy_text'] ) ? $entry['policy_text'] : '' );

			if ( '' === $name || '' === $html ) {
				continue;
			}

			$out[] = array(
				'name' => $name,
				'html' => $html,
				'hash' => hash( 'sha256', $html ),
			);
		}

		return $out;
	}

	/**
	 * Reconcile the incoming contributions against the stored blocks.
	 *
	 * Pure function of its inputs plus `time()` — no option reads or writes —
	 * so the whole reconciliation is drivable from tests through `collect()`.
	 *
	 * @param array $stored_blocks Normalised stored blocks, keyed by id.
	 * @param array $incoming      Output of `registered_content()`.
	 * @return array{blocks:array,changed:bool}
	 */
	private static function diff( array $stored_blocks, array $incoming ) {
		$now     = time();
		$blocks  = $stored_blocks;
		$matched = array();

		// PASS 1 — exact identity first. This must precede hash-only matching:
		// two unrelated plugins can publish identical boilerplate, and incoming
		// order must never swap their names (or operator overrides) between ids.
		$remaining = array();
		foreach ( $incoming as $entry ) {
			$id = null;
			foreach ( $blocks as $candidate_id => $block ) {
				if ( isset( $matched[ $candidate_id ] ) ) {
					continue;
				}
				if ( $block['plugin_name'] === $entry['name'] && $block['source_hash'] === $entry['hash'] ) {
					$id = $candidate_id;
					break;
				}
			}
			if ( null === $id ) {
				$remaining[] = $entry;
				continue;
			}

			$matched[ $id ] = true;
			// Adopt the current display name, and revive a block whose
			// producer came back.
			$blocks[ $id ]['plugin_name'] = $entry['name'];
			$blocks[ $id ]['removed']     = 0;
		}

		// PASS 2 — hash-only rename carry-forward, but only when the hash is
		// unique on both sides. Shared boilerplate is not an identity key.
		$unmatched = array();
		foreach ( $remaining as $entry ) {
			$candidates = array();
			foreach ( $blocks as $candidate_id => $block ) {
				if ( ! isset( $matched[ $candidate_id ] ) && $block['source_hash'] === $entry['hash'] ) {
					$candidates[] = $candidate_id;
				}
			}
			$rivals = 0;
			foreach ( $remaining as $other ) {
				if ( $other['hash'] === $entry['hash'] ) {
					++$rivals;
				}
			}
			if ( 1 !== count( $candidates ) || 1 !== $rivals ) {
				$unmatched[] = $entry;
				continue;
			}
			$id = $candidates[0];
			$matched[ $id ] = true;
			$blocks[ $id ]['plugin_name'] = $entry['name'];
			$blocks[ $id ]['removed']     = 0;
		}

		// PASS 3 — match on the plugin name. This is what carries a block
		// across a rewritten declaration.
		//
		// Only a name that is unambiguous on BOTH sides may match: one
		// remaining stored block with that name, one remaining incoming entry
		// with it. Two plugins can register under the same display name (the
		// duplicate ids exist for exactly that reason), and matching a rewrite
		// against the wrong twin would silently swap two blocks' identities —
		// and with them, the operator's overrides. When it is ambiguous the
		// blocks churn (removed + added) instead, which is recoverable;
		// swapped identities are not.
		$still = array();
		foreach ( $unmatched as $entry ) {
			$candidates = array();
			foreach ( $blocks as $candidate_id => $block ) {
				if ( isset( $matched[ $candidate_id ] ) ) {
					continue;
				}
				if ( $block['plugin_name'] === $entry['name'] ) {
					$candidates[] = $candidate_id;
				}
			}

			$rivals = 0;
			foreach ( $unmatched as $other ) {
				if ( $other['name'] === $entry['name'] ) {
					++$rivals;
				}
			}

			if ( 1 !== count( $candidates ) || 1 !== $rivals ) {
				$still[] = $entry;
				continue;
			}

			$id             = $candidates[0];
			$matched[ $id ] = true;

			$blocks[ $id ]['source_html'] = $entry['html'];
			$blocks[ $id ]['source_hash'] = $entry['hash'];
			$blocks[ $id ]['updated']     = $now;
			$blocks[ $id ]['removed']     = 0;
		}

		// PASS 4 — anything still unclaimed is a producer seen for the first
		// time. The cap refuses new blocks; it never truncates the map,
		// because truncation could evict a block the operator has edited.
		foreach ( $still as $entry ) {
			if ( count( $blocks ) >= self::MAX_BLOCKS ) {
				break;
			}
			$id            = self::block_id_for( $entry['name'], $entry['hash'], $blocks );
			$blocks[ $id ] = array(
				'plugin_name' => $entry['name'],
				'source_html' => $entry['html'],
				'source_hash' => $entry['hash'],
				'added'       => $now,
				'updated'     => 0,
				'removed'     => 0,
				'override'    => array(
					'text'        => '',
					'anchor_hash' => '',
				),
			);
			$matched[ $id ] = true;
		}

		// PASS 5 — stored blocks nobody claimed: the producer is gone. Drop
		// the untouched ones, keep and flag the ones the operator rewrote.
		foreach ( $blocks as $id => $block ) {
			if ( isset( $matched[ $id ] ) ) {
				continue;
			}
			if ( '' === $block['override']['text'] ) {
				unset( $blocks[ $id ] );
				continue;
			}
			if ( 0 === $block['removed'] ) {
				$blocks[ $id ]['removed'] = $now;
			}
		}

		// Compare on sorted keys so that mere storage order never reads as a
		// change — an option write on every admin pageview is the bug this
		// guards against.
		$before = $stored_blocks;
		$after  = $blocks;
		ksort( $before );
		ksort( $after );

		return array(
			'blocks'  => $blocks,
			'changed' => ( $before !== $after ),
		);
	}

	/**
	 * A stable, readable id for a newly seen block.
	 *
	 * The slugged plugin name is the id whenever it is free. Two plugins
	 * declaring under the same display name get a hash suffix, so the second
	 * one is not silently folded into the first.
	 *
	 * @param string $name  Plugin display name.
	 * @param string $hash  Source hash of the block.
	 * @param array  $taken Blocks already keyed in the map.
	 * @return string
	 */
	private static function block_id_for( $name, $hash, array $taken ) {
		$base = \sanitize_title( $name );
		if ( '' === $base ) {
			$base = 'plugin';
		}
		if ( ! isset( $taken[ $base ] ) ) {
			return $base;
		}

		$id = $base . '-' . substr( $hash, 0, 8 );
		if ( ! isset( $taken[ $id ] ) ) {
			return $id;
		}

		// Same name AND same text as an existing key: only reachable from a
		// corrupted map, but it must still terminate with a unique key.
		$n = 2;
		while ( isset( $taken[ $id . '-' . $n ] ) ) {
			++$n;
		}
		return $id . '-' . $n;
	}

	/**
	 * Validate and normalise one stored block into canonical key order.
	 *
	 * Canonical order matters: `diff()` decides whether to write by comparing
	 * arrays, and two blocks differing only in key order would read as a
	 * change.
	 *
	 * @param mixed $block Raw stored block.
	 * @return array|null Normalised block, or null when it is unusable.
	 */
	private static function normalize_block( $block ) {
		if ( ! is_array( $block ) ) {
			return null;
		}

		foreach ( array( 'plugin_name', 'source_html', 'source_hash' ) as $key ) {
			if ( ! isset( $block[ $key ] ) || ! is_string( $block[ $key ] ) ) {
				return null;
			}
		}
		if ( '' === $block['source_hash'] || '' === $block['plugin_name'] ) {
			return null;
		}

		$times = array();
		foreach ( array( 'added', 'updated', 'removed' ) as $key ) {
			if ( ! isset( $block[ $key ] ) || ! is_numeric( $block[ $key ] ) ) {
				return null;
			}
			$times[ $key ] = (int) $block[ $key ];
		}

		if ( ! isset( $block['override'] ) || ! is_array( $block['override'] ) ) {
			return null;
		}
		$override = $block['override'];
		if ( ! isset( $override['text'] ) || ! is_string( $override['text'] ) ) {
			return null;
		}
		if ( ! isset( $override['anchor_hash'] ) || ! is_string( $override['anchor_hash'] ) ) {
			return null;
		}

		return array(
			'plugin_name' => $block['plugin_name'],
			'source_html' => $block['source_html'],
			'source_hash' => $block['source_hash'],
			'added'       => $times['added'],
			'updated'     => $times['updated'],
			'removed'     => $times['removed'],
			'override'    => array(
				'text'        => $override['text'],
				'anchor_hash' => $override['anchor_hash'],
			),
		);
	}
}
