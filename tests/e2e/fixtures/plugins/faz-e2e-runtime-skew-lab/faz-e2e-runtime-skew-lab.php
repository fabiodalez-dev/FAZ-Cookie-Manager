<?php
/**
 * Plugin Name: FAZ E2E Runtime Skew Lab
 * Description: Test-only. Prints, on one page chosen by option, the third-party markup the runtime-skew E2E spec needs: a script that moves image-bearing nodes with the while(firstChild) appendChild idiom while the deferred consent runtime is still on its way, and inline data scripts whose JSON strings contain media markup. Never shipped — lives under tests/e2e/fixtures.
 * Version: 0.1.0
 *
 * Nothing here acts unless the option `faz_e2e_runtime_skew_page` names the
 * post_name of the singular page being rendered.
 *
 *   ?faz_e2e_mover=raw
 *       Prints #faz-e2e-mover-source (three <p> children, each holding a
 *       same-origin <img>), an empty #faz-e2e-mover-target and, right after
 *       them, an inline script that moves every child across with
 *
 *           while (source.firstChild) target.appendChild(source.firstChild);
 *
 *       This is the ordinary "move the children" idiom a slider, a lightbox or
 *       a page builder uses. It runs while the document is still parsing, so
 *       FAZ's deferred script.min.js has not executed yet and the inline
 *       bootstrap (frontend/js/bootstrap.js) is the layer holding insertions.
 *       If an insertion is queued without the node being taken out of its old
 *       parent, `source.firstChild` never changes and the loop never ends: the
 *       page freezes before DOMContentLoaded.
 *
 *   ?faz_e2e_mover=guarded
 *       The same markup and loop with an iteration ceiling (1000 for three
 *       nodes). The page survives either way, and window.__fazE2eMover reports
 *       whether the ceiling was hit — the same defect as a deterministic value
 *       instead of a hung renderer.
 *
 *   always, on that page
 *       A <video poster> pointing at i.ytimg.com (a provider the catalogue files
 *       under marketing) as the positive control that the server's media pass
 *       ran on the response, followed by three inline scripts that are pure
 *       `window.NAME = <JSON>;` data assignments whose string values CONTAIN
 *       <video poster=…> / <source src=…> markup. A server-side HTML pass that
 *       rewrites tags inside a <script> body inserts double-quoted attributes
 *       into those string literals and the script stops parsing.
 *
 * @package FazCookie\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether the request renders the page this lab is configured for.
 *
 * @return bool
 */
function faz_e2e_skew_on_page() {
	$slug = (string) get_option( 'faz_e2e_runtime_skew_page', '' );
	if ( '' === $slug || ! is_singular() ) {
		return false;
	}
	$post = get_post();
	return $post && $slug === $post->post_name;
}

add_action(
	'wp_footer',
	static function () {
		if ( ! faz_e2e_skew_on_page() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only test switch.
		$mode = isset( $_GET['faz_e2e_mover'] ) ? sanitize_key( wp_unslash( $_GET['faz_e2e_mover'] ) ) : '';
		if ( in_array( $mode, array( 'raw', 'guarded' ), true ) ) {
			$img = esc_url( includes_url( 'images/w-logo-blue.png' ) );
			// No whitespace between the children: the loop count is then exactly
			// the number of element children, which the spec asserts.
			echo '<div id="faz-e2e-mover-source">';
			for ( $i = 0; $i < 3; $i++ ) {
				echo '<p class="faz-e2e-moved" data-i="' . (int) $i . '"><img src="' . $img . '" width="1" height="1" alt=""></p>';
			}
			echo '</div><div id="faz-e2e-mover-target"></div>' . "\n";
			$limit = 'guarded' === $mode ? 1000 : 0;
			echo '<script id="faz-e2e-mover-js">(function(){'
				. 'var limit=' . (int) $limit . ';'
				. 'var source=document.getElementById("faz-e2e-mover-source");'
				. 'var target=document.getElementById("faz-e2e-mover-target");'
				. 'var state={mode:"' . esc_js( $mode ) . '",iterations:0,tripped:false,finished:false,sourceLeft:-1,'
				// Where in the page lifecycle the loop ran: the waiting window is
				// "bootstrap installed, runtime not started yet".
				. 'bootstrap:!!window._fazBootstrap,runtimeStarted:!!window._fazRuntimeStarted};'
				. 'window.__fazE2eMover=state;'
				. 'while(source.firstChild){'
				. 'state.iterations++;'
				. 'if(limit&&state.iterations>limit){state.tripped=true;break;}'
				. 'target.appendChild(source.firstChild);'
				. '}'
				. 'state.sourceLeft=source.childNodes.length;'
				. 'state.finished=true;'
				. '})();</script>' . "\n";
		}

		// (e) Positive control first: a real element the media pass must park.
		echo '<video id="faz-e2e-poster-control" poster="https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg" width="16" height="9" preload="none"></video>' . "\n";
		// Then the probes. Nowdoc: printed byte for byte, no PHP escaping.
		echo <<<'HTML'
<script id="faz-e2e-poster-probe-1">window.__fazE2ePosterProbe1 = "<video poster=\"https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg\"></video>";</script>
<script id="faz-e2e-poster-probe-2">window.__fazE2ePosterProbe2 = "<video poster='https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg'></video>";</script>
<script id="faz-e2e-poster-probe-3">window.__fazE2ePosterProbe3 = {"html":"<source src=\"https://i.ytimg.com/vi/dQw4w9WgXcQ/clip.mp4\" type=\"video/mp4\">"};</script>

HTML;
	},
	5
);
