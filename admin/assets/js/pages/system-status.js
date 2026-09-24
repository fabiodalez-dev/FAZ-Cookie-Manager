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
	 * An element's text as one line.
	 *
	 * textContent returns the template's own newlines and tabs — the browser
	 * collapses whitespace when it PAINTS, not when it reads — so a cell
	 * written across several source lines used to produce a ragged,
	 * tab-indented continuation line with no `label:` prefix, breaking the
	 * one-row-one-line shape every other row obeys. Collapsing here makes that
	 * a property of the builder rather than a habit every future view author
	 * has to remember.
	 */
	function flat( el ) {
		return el ? el.textContent.replace( /\s+/g, ' ' ).trim() : '';
	}

	/**
	 * A `<br>`-separated block as real lines.
	 *
	 * `<br>` contributes nothing to textContent, so the Active Plugins list
	 * came out as one run-together string ("Akismet 5.3WooCommerce 9.1…") in
	 * the snapshot people paste into bug reports.
	 */
	function lines( el ) {
		if ( ! el ) {
			return '';
		}
		var clone = el.cloneNode( true );
		clone.querySelectorAll( 'br' ).forEach( function ( br ) {
			br.parentNode.replaceChild( document.createTextNode( '\n' ), br );
		} );
		return clone.textContent
			.split( '\n' )
			.map( function ( line ) {
				return line.replace( /\s+/g, ' ' ).trim();
			} )
			.filter( function ( line ) {
				return '' !== line;
			} )
			.join( '\n' );
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
