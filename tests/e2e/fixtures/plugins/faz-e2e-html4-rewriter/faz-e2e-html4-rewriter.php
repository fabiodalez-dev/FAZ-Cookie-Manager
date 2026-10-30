<?php
/**
 * Plugin Name: FAZ E2E HTML4 Rewriter
 * Description: Passes the frontend HTML through DOMDocument::loadHTML()/saveHTML(), as page optimisers such as WPSpeed's image optimiser do, when the request carries ?faz_html4_rewrite=1. With ?faz_html4_rewrite=translate it also behaves like a translation proxy (GTranslate Translation Proxy): every text node is translated, including the body of a <script type="text/template">, which the proxy parses as HTML.
 * Version: 0.1.0
 *
 * @package FazCookieE2E
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'template_redirect',
	static function () {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		if ( ! isset( $_GET['faz_html4_rewrite'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- test fixture switch.
			return;
		}
		// Priority 0: this buffer opens before FAZ's own (priority 10), so it
		// receives the page after FAZ has finished with it — the position a
		// page optimiser is in.
		ob_start(
			static function ( $html ) {
				if ( ! is_string( $html ) || '' === $html || ! class_exists( 'DOMDocument' ) ) {
					return $html;
				}
				$translate = 'translate' === $_GET['faz_html4_rewrite']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput -- test fixture switch, compared to a literal.
				if ( $translate ) {
					// What the proxy did to the old template: the body of a
					// text/template script is read as HTML, its text is
					// translated and it is written back. `<\/div>` is text to an
					// HTML parser, so it comes back escaped and the elements are
					// closed wherever the parser decides.
					$html = (string) preg_replace_callback(
						'#(<script\b[^>]*\btype=(["\'])text/template\2[^>]*>)(.*?)(</script>)#is',
						static function ( $m ) {
							return $m[1] . faz_e2e_proxy_translate_fragment( $m[3] ) . $m[4];
						},
						$html
					);
				}
				$doc = new DOMDocument();
				libxml_use_internal_errors( true );
				$loaded = $doc->loadHTML( mb_encode_numericentity( $html, array( 0x80, 0x10FFFF, 0, 0x1FFFFF ), 'UTF-8' ) );
				libxml_clear_errors();
				if ( ! $loaded ) {
					return $html;
				}
				if ( $translate ) {
					faz_e2e_proxy_translate_nodes( $doc );
				}
				$out = $doc->saveHTML();
				return is_string( $out ) && '' !== $out ? $out : $html;
			}
		);
	},
	0
);

/**
 * Translate every visible text node and alt text under the document, the way
 * a translation proxy does: prefix "[t] ". Script and style bodies are code.
 *
 * @param DOMDocument $doc Parsed document.
 * @return void
 */
function faz_e2e_proxy_translate_nodes( DOMDocument $doc ) {
	$xpath = new DOMXPath( $doc );
	foreach ( $xpath->query( '//text()[normalize-space() != ""][not(ancestor::script)][not(ancestor::style)]' ) as $node ) {
		$node->nodeValue = '[t] ' . ltrim( $node->nodeValue );
	}
	foreach ( $xpath->query( '//img[@alt != ""]' ) as $img ) {
		$img->setAttribute( 'alt', '[t] ' . $img->getAttribute( 'alt' ) );
	}
}

/**
 * Translate an HTML fragment as the proxy treats a text/template script body.
 *
 * @param string $fragment Script body.
 * @return string
 */
function faz_e2e_proxy_translate_fragment( $fragment ) {
	$doc = new DOMDocument();
	libxml_use_internal_errors( true );
	$doc->loadHTML( '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body><div id="faz-e2e-fragment">' . $fragment . '</div></body></html>' );
	libxml_clear_errors();
	faz_e2e_proxy_translate_nodes( $doc );
	$root = $doc->getElementById( 'faz-e2e-fragment' );
	if ( ! $root ) {
		return $fragment;
	}
	$out = '';
	foreach ( $root->childNodes as $child ) {
		$out .= $doc->saveHTML( $child );
	}
	return $out;
}
