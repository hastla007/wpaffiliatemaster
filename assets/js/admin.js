/* Affiliate Captain admin: confirmation for destructive actions (progressive enhancement). */
document.addEventListener( 'click', function ( e ) {
	var el = e.target.closest ? e.target.closest( '[data-wpac-confirm]' ) : null;
	if ( el && ! window.confirm( el.getAttribute( 'data-wpac-confirm' ) ) ) {
		e.preventDefault();
	}
} );
/* Bulk actions that need a confirmation: data-wpac-bulk-confirm="action" inside the list form. */
document.addEventListener( 'submit', function ( e ) {
	var form = e.target;
	var marker = form.querySelector ? form.querySelector( '[data-wpac-bulk-confirm]' ) : null;
	if ( ! marker ) {
		return;
	}
	var wanted = marker.getAttribute( 'data-wpac-bulk-confirm' );
	var chosen = Array.prototype.some.call( form.querySelectorAll( 'select[name="action"], select[name="action2"]' ), function ( s ) {
		return s.value === wanted;
	} );
	if ( chosen && ! window.confirm( marker.getAttribute( 'data-wpac-bulk-message' ) ) ) {
		e.preventDefault();
	}
} );
document.addEventListener( 'change', function ( e ) {
	if ( e.target.classList && e.target.classList.contains( 'wpac-cb-all' ) ) {
		var form = e.target.form;
		Array.prototype.forEach.call( form.querySelectorAll( '.wpac-cb' ), function ( cb ) {
			cb.checked = e.target.checked;
		} );
	}
} );
/* Dependent fields: data-wpac-depends="select_name=value1|value2" (all visible without JavaScript). */
( function () {
	function update( form ) {
		Array.prototype.forEach.call( form.querySelectorAll( '[data-wpac-depends]' ), function ( el ) {
			var rule = el.getAttribute( 'data-wpac-depends' ).split( '=' );
			var field = form.querySelector( '[name="' + rule[0] + '"]' );
			if ( ! field ) {
				return;
			}
			var show = rule[1].split( '|' ).indexOf( field.value ) !== -1;
			el.hidden = ! show;
			Array.prototype.forEach.call( el.matches( 'input,select,textarea' ) ? [ el ] : el.querySelectorAll( 'input,select,textarea' ), function ( input ) {
				input.disabled = ! show;
			} );
		} );
	}
	document.addEventListener( 'DOMContentLoaded', function () {
		Array.prototype.forEach.call( document.querySelectorAll( 'form' ), function ( form ) {
			if ( form.querySelector( '[data-wpac-depends]' ) ) {
				update( form );
				form.addEventListener( 'change', function () { update( form ); } );
			}
		} );
	} );
} )();
