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
/* Ask before a form is sent with a value in a select that has data-wpac-confirm-select
   (e.g. re-attribution of an order in the WooCommerce order screen). */
document.addEventListener( 'submit', function ( e ) {
	Array.prototype.forEach.call( e.target.querySelectorAll ? e.target.querySelectorAll( 'select[data-wpac-confirm-select]' ) : [], function ( s ) {
		if ( ! e.defaultPrevented && s.value && ! window.confirm( s.getAttribute( 'data-wpac-confirm-select' ) ) ) {
			e.preventDefault();
			e.stopImmediatePropagation();
		}
	} );
}, true );
/* Creatives: choose the image from the media library (wp.media is loaded on that page), with a preview. */
document.addEventListener( 'click', function ( e ) {
	var btn = e.target.closest ? e.target.closest( '#wpac-pick, #wpac-unpick' ) : null;
	if ( ! btn ) {
		return;
	}
	var input = document.getElementById( 'wpac-attachment' );
	var box = document.querySelector( '.wpac-attachment' );
	var unpick = document.getElementById( 'wpac-unpick' );
	var none = ( document.getElementById( 'wpac-pick' ) || btn ).getAttribute( 'data-wpac-none' ) || '';
	function show( url, alt ) {
		if ( ! box ) {
			return;
		}
		box.textContent = '';
		if ( url ) {
			var img = document.createElement( 'img' );
			img.src = url;
			img.alt = alt || '';
			img.className = 'wpac-attachment-img';
			box.appendChild( img );
		} else {
			var span = document.createElement( 'span' );
			span.className = 'description';
			span.textContent = none;
			box.appendChild( span );
		}
		if ( unpick ) {
			unpick.hidden = ! url;
		}
	}
	if ( 'wpac-unpick' === btn.id ) {
		input.value = '';
		show( '', '' );
		return;
	}
	if ( ! window.wp || ! window.wp.media ) {
		return;
	}
	var frame = window.wp.media( { library: { type: 'image' }, multiple: false } );
	frame.on( 'select', function () {
		var att = frame.state().get( 'selection' ).first().toJSON();
		input.value = att.id;
		show( att.sizes && att.sizes.thumbnail ? att.sizes.thumbnail.url : att.url, att.alt );
	} );
	frame.open();
} );
/* Payout bulk action: a file download ends without a page change – reload the list afterwards,
   so the new status and the message about the download are shown. */
document.addEventListener( 'submit', function ( e ) {
	var form = e.target;
	if ( ! form.classList || ! form.classList.contains( 'wpac-bulk-form' ) ) {
		return;
	}
	var s = form.querySelector( '[name="bulk_action"]' );
	if ( s && [ 'sepa', 'csv', 'paypal' ].indexOf( s.value ) > -1 && ! e.defaultPrevented ) {
		window.setTimeout( function () {
			window.location.reload();
		}, 2500 );
	}
} );
/* Wide tables scroll inside their own box instead of widening the page (small screens). */
document.addEventListener( 'DOMContentLoaded', function () {
	Array.prototype.forEach.call( document.querySelectorAll( '.wrap table.widefat:not(.table-view-list)' ), function ( table ) {
		if ( table.parentNode.classList.contains( 'wpac-table-scroll' ) ) {
			return;
		}
		var box = document.createElement( 'div' );
		box.className = 'wpac-table-scroll';
		table.parentNode.insertBefore( box, table );
		box.appendChild( table );
	} );
} );
