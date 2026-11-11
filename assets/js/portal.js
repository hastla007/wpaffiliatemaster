/* WP Affiliate Captain partner area: copy buttons (fields stay selectable without JavaScript). */
( function () {
	var i18n = window.wpacPortal || { copied: 'Copied.', failed: 'Please copy manually.' };
	function announce( text ) {
		var live = document.getElementById( 'wpac-live' );
		if ( live ) {
			live.textContent = '';
			window.setTimeout( function () { live.textContent = text; }, 50 );
		}
	}
	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest ? e.target.closest( '[data-wpac-copy]' ) : null;
		if ( ! btn ) {
			return;
		}
		var field = document.getElementById( btn.getAttribute( 'data-wpac-copy' ) );
		if ( ! field ) {
			return;
		}
		field.select();
		var done = function () {
			var label = btn.textContent;
			btn.textContent = '✔';
			window.setTimeout( function () { btn.textContent = label; }, 1500 );
			announce( i18n.copied );
		};
		if ( navigator.clipboard && window.isSecureContext ) {
			navigator.clipboard.writeText( field.value ).then( done, function () { announce( i18n.failed ); } );
		} else {
			try {
				document.execCommand( 'copy' ) ? done() : announce( i18n.failed );
			} catch ( err ) {
				announce( i18n.failed );
			}
		}
	} );
	document.addEventListener( 'focusin', function ( e ) {
		if ( e.target.classList && e.target.classList.contains( 'wpac-copy' ) ) {
			e.target.select();
		}
	} );
} )();
