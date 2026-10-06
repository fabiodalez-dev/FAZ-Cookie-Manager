<?php
/**
 * How a visitor withdraws consent, and whether that route has been verified.
 *
 * Several jurisdictions' rule sets assert `ui.revisit_widget_required`. The
 * requirement they encode is not "show FAZ's floating widget" — it is that a
 * visitor must have a standing, always-available way to reopen their choices
 * and withdraw, because withdrawing has to stay as easy as giving (GDPR Art.
 * 7(3)). The floating widget is one implementation of that. A persistent link
 * in the site footer is another, and the Garante's 2021 cookie guidelines
 * explicitly contemplate exactly that: a link to a dedicated area.
 *
 * The plugin used to conflate the two. Because the only route it could see was
 * its own widget, the widget switch was locked on whenever a reachable rule set
 * asserted the requirement — so an administrator who had put
 * `[faz_cookie_settings type="link"]` in the footer, on purpose, could not turn
 * the floating one off. The requirement was satisfied; the screen could not
 * tell, so it refused. That is the report this class answers.
 *
 * The asymmetry that shapes the whole design: the widget needs no verification
 * because the plugin renders it, whereas the footer link lives in a theme, a
 * block template or a widget area that the plugin does not control and cannot
 * read. An administrator ticking "I put a link in the footer" is a claim, not a
 * fact, and accepting a claim here would mean accepting a site with no
 * withdrawal route at all. So the claim is CHECKED: the plugin fetches its own
 * pages as an anonymous visitor and looks for the marker the shortcode emits.
 *
 * Every branch fails closed. No verification, a verification that failed, a
 * verification too old to trust, or a site the plugin cannot even reach, all
 * mean the same thing — the requirement is not satisfied, so the widget lock
 * stands and the visitor keeps a way out.
 *
 * @since   1.34.0
 * @package FazCookie\Includes
 */

namespace FazCookie\Includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Withdrawal_Path
 */
class Withdrawal_Path {

	/**
	 * FAZ's own floating revisit widget. The default, and the only route that
	 * needs no verification, because the plugin renders it itself.
	 */
	const PATH_WIDGET = 'widget';

	/**
	 * A persistent link or button the administrator placed in the site
	 * template, carrying the marker the shortcode emits.
	 */
	const PATH_FOOTER_LINK = 'footer_link';

	/**
	 * Where the verification result is stored.
	 */
	const PROBE_OPTION = 'faz_withdrawal_link_probe';

	/**
	 * The attribute every FAZ preference-reopening control carries.
	 *
	 * Matching the ATTRIBUTE and not a CSS class is deliberate: the class
	 * differs between the button and link forms of the shortcode
	 * (`faz-cookie-settings-btn` / `faz-cookie-settings-link`), and the admin
	 * screen also documents a hand-written `<a>` using this attribute directly.
	 * The attribute is the one thing all three have in common, and it is the
	 * thing script.js actually binds its delegated handler to — so what is
	 * verified here is precisely what will work for the visitor.
	 */
	const MARKER = 'data-faz-open-preferences';

	/**
	 * How long a successful verification is trusted, in seconds.
	 *
	 * A footer link can be removed at any time by editing a template, and
	 * nothing notifies the plugin when that happens. The daily re-check keeps
	 * this fresh in normal operation; this window is what bounds the damage
	 * when WP-Cron is not running — after it, the requirement goes back to
	 * unsatisfied and the widget reappears.
	 *
	 * Two weeks rather than something tight: a short window turns a dead cron
	 * into a widget that silently comes back, which looks like a bug and
	 * invites the administrator to fight the plugin. Two weeks is long enough
	 * that only a genuinely broken install reaches it, and the screen always
	 * shows the date of the last check so the state is never a mystery.
	 */
	const VALID_FOR = 1209600;

	/**
	 * Maximum number of URLs a single verification fetches.
	 */
	const MAX_PROBE_URLS = 3;

	/**
	 * Which withdrawal route the administrator configured.
	 *
	 * @param array|null $settings Settings to read, or null to load them.
	 * @return string PATH_WIDGET or PATH_FOOTER_LINK.
	 */
	public static function configured_path( $settings = null ) {
		if ( ! is_array( $settings ) ) {
			$settings = get_option( 'faz_settings', array() );
		}
		// is_string() BEFORE the cast, not just a cast. The sanitiser whitelists
		// this key, but this method reads the raw option, and an imported
		// settings file or a hand-edited row can hold an array there. Casting an
		// array to string raises an "Array to string conversion" warning — on a
		// FRONT-END request, since the overlay asks this predicate per visit, and
		// with display_errors on that warning is printed into the page. The
		// verdict was already fail-closed; what was missing was arriving at it
		// quietly. Found by tests/unit/test-withdrawal-path-php.php, which feeds
		// exactly that shape in.
		$stored = isset( $settings['banner_control']['withdrawal_path'] )
			? $settings['banner_control']['withdrawal_path']
			: self::PATH_WIDGET;
		$path   = is_string( $stored ) ? strtolower( trim( $stored ) ) : '';

		return self::PATH_FOOTER_LINK === $path ? self::PATH_FOOTER_LINK : self::PATH_WIDGET;
	}

	/**
	 * The stored verification result, normalised.
	 *
	 * @return array{status:string,reason:string,checked:int,urls:array,found:array}
	 */
	public static function probe() {
		$stored = get_option( self::PROBE_OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return array(
			'status'  => isset( $stored['status'] ) ? (string) $stored['status'] : 'never',
			'reason'  => isset( $stored['reason'] ) ? (string) $stored['reason'] : '',
			'checked' => isset( $stored['checked'] ) ? (int) $stored['checked'] : 0,
			'urls'    => isset( $stored['urls'] ) && is_array( $stored['urls'] ) ? $stored['urls'] : array(),
			'found'   => isset( $stored['found'] ) && is_array( $stored['found'] ) ? $stored['found'] : array(),
		);
	}

	/**
	 * Whether the configured route satisfies `ui.revisit_widget_required`.
	 *
	 * The one predicate the runtime overlay and the banner screen both ask, so
	 * the lock the administrator sees and the overlay the visitor gets can
	 * never disagree.
	 *
	 * False for the widget path: there the requirement is satisfied BY the
	 * widget, which is what the lock exists to keep on. It is true only when a
	 * different, verified route makes that lock unnecessary.
	 *
	 * @param array|null $settings Settings to read, or null to load them.
	 * @return bool
	 */
	public static function satisfies_revisit_requirement( $settings = null ) {
		// The path check comes first, and that order is load-bearing: this runs
		// on every front-end request where a rule set asserts the requirement,
		// configured_path() reads the autoloaded settings option, and the probe
		// record deliberately is NOT autoloaded. Sites on the default widget
		// path — nearly all of them — therefore never pay for a second option
		// read, and only a site that chose the footer link loads the evidence
		// that decision needs.
		if ( self::PATH_FOOTER_LINK !== self::configured_path( $settings ) ) {
			return false;
		}

		$probe = self::probe();
		if ( 'verified' !== $probe['status'] || $probe['checked'] <= 0 ) {
			return false;
		}

		// A verification older than the trust window is not evidence any more.
		// Using time() against a stored timestamp means a clock moved backwards
		// reads as "checked in the future", which this treats as stale too —
		// the fail-closed direction.
		$age = time() - $probe['checked'];

		return $age >= 0 && $age <= self::VALID_FOR;
	}

	/**
	 * Full state for the admin screen.
	 *
	 * @param array|null $settings Settings to read, or null to load them.
	 * @return array{path:string,satisfied:bool,status:string,reason:string,checked:int,urls:array,found:array,stale:bool}
	 */
	public static function state( $settings = null ) {
		$path  = self::configured_path( $settings );
		$probe = self::probe();
		$age   = $probe['checked'] > 0 ? time() - $probe['checked'] : -1;

		return array(
			'path'      => $path,
			'satisfied' => self::satisfies_revisit_requirement( $settings ),
			'status'    => $probe['status'],
			'reason'    => $probe['reason'],
			'checked'   => $probe['checked'],
			'urls'      => $probe['urls'],
			'found'     => $probe['found'],
			// Distinct from "failed": the link WAS there, we just cannot claim
			// it still is. The screen says so differently, because the fix is
			// different (press the button, versus go and add the link).
			'stale'     => ( 'verified' === $probe['status'] && ( $age < 0 || $age > self::VALID_FOR ) ),
		);
	}

	/**
	 * URLs to fetch when verifying.
	 *
	 * The front page plus up to two other published pages. "Persistent on every
	 * page" cannot be proven by sampling, but it can be DISPROVEN cheaply, and
	 * a sample of three catches the realistic mistake this guards against — a
	 * link dropped into one page's content rather than into the site template.
	 *
	 * @return string[]
	 */
	public static function probe_urls() {
		$urls = array( home_url( '/' ) );

		$posts_page = (int) get_option( 'page_for_posts' );
		if ( $posts_page > 0 ) {
			$permalink = get_permalink( $posts_page );
			if ( is_string( $permalink ) && '' !== $permalink ) {
				$urls[] = $permalink;
			}
		}

		$front_page = (int) get_option( 'page_on_front' );
		$candidates = get_posts(
			array(
				'post_type'        => 'page',
				'post_status'      => 'publish',
				'numberposts'      => 4,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'exclude'          => array_filter( array( $front_page, $posts_page ) ),
				'suppress_filters' => false,
				'fields'           => 'ids',
			)
		);
		foreach ( (array) $candidates as $candidate_id ) {
			$permalink = get_permalink( (int) $candidate_id );
			if ( is_string( $permalink ) && '' !== $permalink ) {
				$urls[] = $permalink;
			}
		}

		$urls = array_values( array_unique( array_filter( $urls ) ) );

		return array_slice( $urls, 0, self::MAX_PROBE_URLS );
	}

	/**
	 * Fetch the site's own pages and look for the withdrawal control.
	 *
	 * The marker must be present on EVERY page fetched, not on any of them. A
	 * link on the home page alone is not a standing route, and "any" would
	 * accept exactly the mistake this is meant to catch.
	 *
	 * @return array The stored state, as probe() would return it.
	 */
	public static function verify() {
		$urls = self::probe_urls();
		if ( empty( $urls ) ) {
			return self::record( 'failed', 'no_urls', array(), array() );
		}

		$found = array();
		foreach ( $urls as $url ) {
			$response = wp_remote_get(
				$url,
				array(
					'timeout'     => 15,
					'redirection' => 3,
					// No cookies, so the page is fetched exactly as a first-time
					// visitor receives it — the state in which a withdrawal
					// route has to be present.
					'cookies'     => array(),
					'headers'     => array( 'Accept' => 'text/html' ),
					'user-agent'  => 'FAZ-Cookie-Manager/withdrawal-path-check; ' . home_url( '/' ),
				)
			);

			if ( is_wp_error( $response ) ) {
				// Cannot reach our own site: a loopback restriction, a firewall,
				// basic auth on a staging host. Says nothing about the link, so
				// it must not be reported as a missing link — but it is also no
				// evidence, so it fails closed all the same.
				return self::record( 'failed', 'request_failed', $urls, $found );
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( 200 !== $code ) {
				return self::record( 'failed', 'http_' . $code, $urls, $found );
			}

			$body = (string) wp_remote_retrieve_body( $response );
			if ( ! self::body_has_marker( $body ) ) {
				return self::record( 'failed', 'marker_missing', $urls, $found );
			}

			$found[] = $url;
		}

		return self::record( 'verified', 'marker_on_every_page', $urls, $found );
	}

	/**
	 * Whether a fetched page carries the marker on a real element.
	 *
	 * A substring search over the whole response is not evidence of a link.
	 * The marker also appears as TEXT in places a visitor can never click:
	 * with "alternative asset path" on, the whole of script.min.js is printed
	 * inline, and it contains the selector `[data-faz-open-preferences]`; a
	 * theme can leave the snippet in an HTML comment; a `<template>` or a
	 * `<noscript>` block can hold it. Any of those used to satisfy the check on
	 * a page with no link at all, which lifted the widget lock and could leave
	 * the visitor with no way to withdraw — the one outcome this class exists
	 * to prevent.
	 *
	 * So the marker counts only as an ATTRIBUTE of an element tag, after the
	 * regions whose content is not clickable markup have been removed. Any
	 * element, not just `<a>` or `<button>`: script.js binds its handler with
	 * `closest('[data-faz-open-preferences]')`, so a `<span>` or `<li>` carrying
	 * the attribute works for the visitor and must verify too. `<noscript>` is
	 * excluded deliberately — the control only works through script.js, so a
	 * link that exists only when scripts do not run is not a usable route.
	 * The DOM ancestor check also rejects disabled, hidden and inert controls;
	 * the browser retains a fallback for visibility set by external theme CSS.
	 *
	 * @param string $html Response body.
	 * @return bool
	 */
	public static function body_has_marker( $html ) {
		if ( ! is_string( $html ) || false === stripos( $html, self::MARKER ) ) {
			return false;
		}

		// Parsing the ancestor chain also keeps nested templates inert. A
		// substring/regex match cannot establish that a control is usable.
		if ( ! class_exists( '\DOMDocument' ) ) {
			return false;
		}
		$markup = self::strip_non_markup( $html );
		if ( '' === trim( $markup ) ) {
			return false;
		}
		$document = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		try {
			$loaded = $document->loadHTML( $markup, LIBXML_NONET );
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
		}
		if ( ! $loaded ) {
			return false;
		}
		$xpath = new \DOMXPath( $document );
		foreach ( $xpath->query( '//*[@' . self::MARKER . ']' ) as $control ) {
			if ( self::control_is_usable( $control ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether this marked element is a route the visitor can actually take.
	 *
	 * `hidden`, `inert` and the hiding declarations apply to any element, so
	 * they are rejected anywhere in the ancestor chain. `disabled` does not:
	 * see is_disabled().
	 *
	 * @param \DOMElement $control Element carrying the marker.
	 * @return bool
	 */
	private static function control_is_usable( $control ) {
		if ( self::is_disabled( $control ) ) {
			return false;
		}
		for ( $element = $control; $element instanceof \DOMElement; $element = $element->parentNode ) {
			if (
				in_array( strtolower( $element->tagName ), array( 'template', 'noscript', 'script', 'style', 'head' ), true )
				|| $element->hasAttribute( 'hidden' )
				|| $element->hasAttribute( 'inert' )
				|| preg_match( '/(?:^|;)\s*(?:display\s*:\s*none|visibility\s*:\s*(?:hidden|collapse)|content-visibility\s*:\s*hidden)\s*(?:!important\s*)?(?:;|$)/i', $element->getAttribute( 'style' ) )
			) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether `disabled` actually disables this control, per the HTML spec.
	 *
	 * The attribute is not a generic "switch this subtree off": it is defined
	 * only on form controls, and it never applies to a link. `<footer disabled>`,
	 * `<div disabled>` and `<form disabled>` are not disabled states at all —
	 * the attribute is simply ignored, the element still renders, and a marked
	 * `<a>` inside it keeps receiving the script.js handler. Treating any
	 * `disabled` ancestor as fatal rejected those pages, so a footer route the
	 * visitor could use was reported `marker_missing` and the administrator saw
	 * verification fail for no reason they could see.
	 *
	 * So: the attribute counts on the control itself only when the control is a
	 * form control, and it counts on an ancestor only for `<fieldset>`, whose
	 * `disabled` state does propagate — to descendant form controls, and with
	 * the elements inside its FIRST `<legend>` exempted. This mirrors the CSS
	 * `:disabled` pseudo-class, which is what the browser side asks, so the two
	 * answers cannot diverge.
	 *
	 * @param \DOMElement $control Element carrying the marker.
	 * @return bool
	 */
	private static function is_disabled( $control ) {
		$form_controls = array( 'button', 'input', 'select', 'textarea', 'optgroup', 'option', 'fieldset' );
		if ( ! in_array( strtolower( $control->tagName ), $form_controls, true ) ) {
			return false;
		}
		if ( $control->hasAttribute( 'disabled' ) ) {
			return true;
		}
		for ( $element = $control->parentNode; $element instanceof \DOMElement; $element = $element->parentNode ) {
			if ( 'fieldset' !== strtolower( $element->tagName ) || ! $element->hasAttribute( 'disabled' ) ) {
				continue;
			}
			// Everything inside the fieldset's first <legend> stays enabled, so
			// the control is only disabled when it is not in that subtree.
			$first_legend = null;
			foreach ( $element->childNodes as $child ) {
				if ( $child instanceof \DOMElement && 'legend' === strtolower( $child->tagName ) ) {
					$first_legend = $child;
					break;
				}
			}
			if ( null === $first_legend ) {
				return true;
			}
			for ( $ancestor = $control; $ancestor instanceof \DOMElement; $ancestor = $ancestor->parentNode ) {
				if ( $ancestor === $first_legend ) {
					continue 2;
				}
			}
			return true;
		}
		return false;
	}

	/**
	 * Remove the parts of a document whose content is not clickable markup.
	 *
	 * Comments, and the raw-text / inert elements (`script`, `style`,
	 * `noscript`, `textarea`, `title`, `iframe`, `noembed`,
	 * `noframes`, `xmp`, `plaintext`). Scanned left to right with plain string
	 * searches rather than one large lazy regex: an inline bundle is easily
	 * a few hundred kilobytes, and a lazy `.*?` across it is exactly where
	 * PCRE's backtracking limit is reached — preg_replace() then returns null,
	 * and the safest reading of null would be "no marker" anyway.
	 *
	 * An element that is opened and never closed swallows the rest of the
	 * document. That is the browser's behaviour for these elements, and it is
	 * the fail-closed direction here.
	 *
	 * @param string $html Response body.
	 * @return string
	 */
	private static function strip_non_markup( $html ) {
		// Keep template markup for the DOM ancestor check: templates can nest,
		// unlike raw-text elements, so the first closing tag is not their end.
		$opener = '#<!--|<(script|style|noscript|textarea|title|iframe|noembed|noframes|xmp|plaintext)\b[^>]*>#i';
		$length = strlen( $html );
		$offset = 0;
		$kept   = '';

		while ( $offset < $length ) {
			if ( 1 !== preg_match( $opener, $html, $match, PREG_OFFSET_CAPTURE, $offset ) ) {
				$kept .= substr( $html, $offset );
				break;
			}
			$start = $match[0][1];
			$kept .= substr( $html, $offset, $start - $offset );
			$after = $start + strlen( $match[0][0] );

			if ( '<!--' === $match[0][0] ) {
				$end    = strpos( $html, '-->', $after );
				$offset = ( false === $end ) ? $length : $end + 3;
				continue;
			}

			$end = stripos( $html, '</' . $match[1][0], $after );
			if ( false === $end ) {
				$offset = $length;
				continue;
			}
			$close  = strpos( $html, '>', $end );
			$offset = ( false === $close ) ? $length : $close + 1;
		}

		return $kept;
	}

	/**
	 * Re-check on a schedule, but only when the answer can matter.
	 *
	 * Hooked to the existing daily cleanup event rather than adding a new
	 * scheduled event: one more event is one more thing to register on
	 * activation, clear on deactivation and reason about on a site whose cron
	 * is already struggling.
	 *
	 * @return void
	 */
	public static function cron_verify() {
		if ( self::PATH_FOOTER_LINK !== self::configured_path() ) {
			return;
		}
		self::verify();
	}

	/**
	 * Persist a verification result.
	 *
	 * @param string   $status 'verified' or 'failed'.
	 * @param string   $reason Machine-readable cause.
	 * @param string[] $urls   URLs that were fetched.
	 * @param string[] $found  URLs the marker was found on.
	 * @return array The stored state.
	 */
	private static function record( $status, $reason, $urls, $found ) {
		$record = array(
			'status'  => (string) $status,
			'reason'  => (string) $reason,
			'checked' => time(),
			'urls'    => array_values( (array) $urls ),
			'found'   => array_values( (array) $found ),
		);
		// Not autoloaded: read only by the banner screen and the overlay's
		// single predicate, never on a page that does not need it.
		update_option( self::PROBE_OPTION, $record, false );

		return self::probe();
	}
}
