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

	btn.addEventListener( 'click', function () {
		var text = 'FAZ Cookie Manager — System Status\n' + '='.repeat( 50 ) + '\n\n';

		document.querySelectorAll( '#faz-system-status .faz-card' ).forEach( function ( card ) {
			var heading = card.querySelector( '.faz-card-header h3' );
			if ( heading ) {
				text += heading.textContent + '\n' + '-'.repeat( 30 ) + '\n';
			}

			var table = card.querySelector( '.faz-status-table' );
			if ( table ) {
				table.querySelectorAll( 'tr' ).forEach( function ( row ) {
					var cells = row.querySelectorAll( 'td' );
					if ( cells.length >= 2 ) {
						text += cells[ 0 ].textContent.trim() + ': ' + cells[ 1 ].textContent.trim() + '\n';
					}
				} );
			}

			var list = card.querySelector( 'div[style*="line-height"]' );
			if ( list ) {
				text += list.textContent.trim().replace( /\n\s+/g, '\n' ) + '\n';
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
