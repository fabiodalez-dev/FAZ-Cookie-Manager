/**
 * FAZ Cookie Manager — System Status page.
 *
 * Builds a plain-text snapshot of the current admin page (cards →
 * heading + key/value rows) and copies it to the clipboard. Used by
 * users to share their environment when reporting bugs.
 *
 * Localized strings come from `fazConfig.i18n.systemStatus` (see
 * `enqueue_scripts()` in admin/class-admin.php).
 */
(function () {
	'use strict';

	var btn = document.getElementById( 'faz-copy-status' );
	if ( ! btn ) {
		return;
	}

	/**
	 * The block boundaries a reader sees but `textContent` does not.
	 *
	 * A browser paints `<div>`, `<p>`, `<li>` and their kin on a line of their
	 * own and collapses the whitespace around them. `textContent` does the
	 * opposite on both counts: it returns the template's own newlines and tabs
	 * verbatim, and returns nothing at all where the markup — rather than the
	 * source formatting — created the break. So a cell written across several
	 * source lines produced a ragged, tab-indented continuation with no `label:`
	 * prefix; "…ago." followed immediately by the per-cause `<div>` produced
	 * "ago.Stale origin token"; and the `<br>`-separated plugin list produced
	 * "Akismet 5.3WooCommerce 9.1FAZ Cookie Manager 1.32.0" — all of it in the
	 * snapshot people paste into bug reports.
	 */
	var BLOCK_TAGS = 'br,div,p,li,tr,dt,dd,section,header,footer,blockquote,pre,ul,ol,table,h1,h2,h3,h4,h5,h6';

	/**
	 * An element's text as the lines a reader would actually see.
	 *
	 * Works on a clone: marks every block boundary with a newline, then collapses
	 * each line's own whitespace and drops the empty ones. One rule serves both
	 * shapes below, so a row added later inherits the format instead of depending
	 * on how its markup happened to be indented.
	 */
	function blocks( el ) {
		if ( ! el ) {
			return [];
		}
		var clone = el.cloneNode( true );
		clone.querySelectorAll( BLOCK_TAGS ).forEach( function ( node ) {
			node.parentNode.insertBefore( document.createTextNode( '\n' ), node );
			node.parentNode.insertBefore( document.createTextNode( '\n' ), node.nextSibling );
		} );
		return clone.textContent
			.split( '\n' )
			.map( function ( line ) {
				return line.replace( /\s+/g, ' ' ).trim();
			} )
			.filter( function ( line ) {
				return '' !== line;
			} );
	}

	/** One row, one line: a block boundary becomes a single space. */
	function flat( el ) {
		return blocks( el ).join( ' ' );
	}

	/** A list: every entry on a line of its own. */
	function lines( el ) {
		return blocks( el ).join( '\n' );
	}

	btn.addEventListener( 'click', function () {
		var text = 'FAZ Cookie Manager — System Status\n' + '='.repeat( 50 ) + '\n\n';

		document.querySelectorAll( '#faz-system-status .faz-card' ).forEach( function ( card ) {
			var heading = card.querySelector( '.faz-card-header h3' );
			if ( heading ) {
				text += flat( heading ) + '\n' + '-'.repeat( 30 ) + '\n';
			}

			var table = card.querySelector( '.faz-status-table' );
			if ( table ) {
				table.querySelectorAll( 'tr' ).forEach( function ( row ) {
					var cells = row.querySelectorAll( 'td' );
					if ( cells.length >= 2 ) {
						text += flat( cells[ 0 ] ) + ': ' + flat( cells[ 1 ] ) + '\n';
					}
				} );
			}

			var list = card.querySelector( 'div[style*="line-height"]' );
			if ( list ) {
				var listText = lines( list );
				if ( listText ) {
					text += listText + '\n';
				}
			}

			text += '\n';
		} );

		var copiedMsg = ( window.fazConfig
			&& window.fazConfig.i18n
			&& window.fazConfig.i18n.systemStatus
			&& window.fazConfig.i18n.systemStatus.copied )
			|| 'Status copied to clipboard!';

		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( text ).then( function () {
				if ( window.FAZ && typeof window.FAZ.notify === 'function' ) {
					window.FAZ.notify( copiedMsg, 'success' );
				}
			} );
		}
	} );
}() );
