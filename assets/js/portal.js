/* Affiliate Captain partner area: copy buttons (fields stay selectable without JavaScript). */
( function () {
	var i18n = window.wpacPortal || { copied: 'Copied.', failed: 'Please copy manually.' };
	function announce( text, btn ) {
		// The live region of the block around the button (e.g. recommended targets outside the
		// partner area, R14-U8), else the one of the partner area.
		var scope = btn && btn.closest ? btn.closest( '.wpac-portal' ) : null;
		var live  = ( scope && scope.querySelector( '.wpac-live' ) ) || document.getElementById( 'wpac-live' );
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
			// The original label is kept once, so a second click within 1.5 s does not keep "✔".
			if ( ! btn.hasAttribute( 'data-wpac-label' ) ) {
				btn.setAttribute( 'data-wpac-label', btn.textContent );
			}
			window.clearTimeout( btn.wpacTimer );
			btn.textContent = '✔';
			btn.wpacTimer = window.setTimeout( function () { btn.textContent = btn.getAttribute( 'data-wpac-label' ); }, 1500 );
			announce( i18n.copied, btn );
		};
		if ( navigator.clipboard && window.isSecureContext ) {
			navigator.clipboard.writeText( field.value ).then( done, function () { announce( i18n.failed, btn ); } );
		} else {
			try {
				document.execCommand( 'copy' ) ? done() : announce( i18n.failed, btn );
			} catch ( err ) {
				announce( i18n.failed, btn );
			}
		}
	} );
	document.addEventListener( 'focusin', function ( e ) {
		if ( e.target.classList && e.target.classList.contains( 'wpac-copy' ) ) {
			e.target.select();
		}
	} );
	function ready() {
		// Copy buttons only work with JavaScript: they are rendered hidden and shown here.
		Array.prototype.forEach.call( document.querySelectorAll( '.wpac-copy-btn[hidden]' ), function ( btn ) {
			btn.hidden = false;
		} );
		// A message that is already in the page is not announced by its live region: move the
		// focus to it – unless a field asks for the focus (autofocus after an error) or the address
		// points to an anchor in the page (e.g. #wpac-objection after an objection).
		var notice = document.querySelector( '.wpac-notice[data-wpac-focus]' );
		var target = null;
		if ( window.location.hash && window.location.hash.length > 1 ) {
			try {
				target = document.getElementById( decodeURIComponent( window.location.hash.slice( 1 ) ) );
			} catch ( err ) {
				target = null;
			}
		}
		if ( notice && ! target && ! document.querySelector( '[autofocus]' ) && ( ! document.activeElement || document.activeElement === document.body ) ) {
			notice.focus();
		}
	}
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', ready );
	} else {
		ready();
	}
} )();
/* Payout details: show the fields of the chosen payout method. Both sections are rendered visible,
   so all fields work without JavaScript; here the one of the other method is hidden. */
( function () {
	function update() {
		var s = document.getElementById( 'wpac_payout_method' );
		if ( ! s ) {
			return;
		}
		var method = s.value;
		var manual = 'manual' === method;
		// A section belongs to every method named in its classes (wpac-method-sepa wpac-method-bank …).
		Array.prototype.forEach.call( document.querySelectorAll( '.wpac-method-sepa, .wpac-method-bank, .wpac-method-manual' ), function ( el ) {
			el.hidden = ! el.classList.contains( 'wpac-method-' + method );
		} );
		var holder = document.getElementById( 'wpac_account_holder' );
		if ( holder ) {
			holder.required = ! manual;
		}
		var iban = document.getElementById( 'wpac_iban' );
		if ( iban ) {
			iban.required = 'sepa' === method && '1' === s.getAttribute( 'data-wpac-iban-required' );
		}
		var details = document.getElementById( 'wpac_payment_details' );
		if ( details ) {
			details.required = manual && '1' === s.getAttribute( 'data-wpac-details-required' );
		}
		var account = document.getElementById( 'wpac_account_number' );
		if ( account ) {
			account.required = 'bank' === method && '1' === s.getAttribute( 'data-wpac-account-required' );
		}
		var country = document.getElementById( 'wpac_bank_country' );
		if ( country ) {
			country.required = 'bank' === method;
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
