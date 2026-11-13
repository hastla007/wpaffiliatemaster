/* Affiliate Captain partner area: copy buttons (fields stay selectable without JavaScript). */
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
/* Payout details: show the fields of the chosen payout method (all fields work without JavaScript). */
( function () {
	function update() {
		var s = document.getElementById( 'wpac_payout_method' );
		if ( ! s ) {
			return;
		}
		var manual = 'manual' === s.value;
		Array.prototype.forEach.call( document.querySelectorAll( '.wpac-method-sepa' ), function ( el ) { el.hidden = manual; } );
		Array.prototype.forEach.call( document.querySelectorAll( '.wpac-method-manual' ), function ( el ) { el.hidden = ! manual; } );
		var holder = document.getElementById( 'wpac_account_holder' );
		if ( holder ) {
			holder.required = ! manual;
		}
		var iban = document.getElementById( 'wpac_iban' );
		if ( iban ) {
			iban.required = ! manual && '1' === s.getAttribute( 'data-wpac-iban-required' );
		}
		var details = document.getElementById( 'wpac_payment_details' );
		if ( details ) {
			details.required = manual && '1' === s.getAttribute( 'data-wpac-details-required' );
		}
	}
	document.addEventListener( 'change', function ( e ) {
		if ( e.target && 'wpac_payout_method' === e.target.id ) {
			update();
		}
	} );
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', update );
	} else {
		update();
	}
} )();
