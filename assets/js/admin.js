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
	Array.prototype.forEach.call( e.target.querySelectorAll ? e.target.querySelectorAll( '[data-wpac-confirm-select]' ) : [], function ( s ) {
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
/* Partner search field (Admin::partner_picker() in large programs): suggestions while typing. */
( function () {
	var timer = null;
	document.addEventListener( 'input', function ( e ) {
		var input = e.target;
		if ( ! input.getAttribute || ! input.getAttribute( 'data-wpac-partner-search' ) || ! window.fetch ) {
			return;
		}
		var list = document.getElementById( input.getAttribute( 'list' ) );
		var q = input.value.trim();
		if ( ! list || q.length < 2 || /#\d+$/.test( q ) ) {
			return;
		}
		clearTimeout( timer );
		timer = setTimeout( function () {
			var url = input.getAttribute( 'data-wpac-ajax' ) + '?action=wpac_partner_search&nonce=' + encodeURIComponent( input.getAttribute( 'data-wpac-partner-search' ) ) + '&status=' + encodeURIComponent( input.getAttribute( 'data-wpac-status' ) || '' ) + '&q=' + encodeURIComponent( q );
			window.fetch( url, { credentials: 'same-origin' } ).then( function ( r ) {
				return r.json();
			} ).then( function ( res ) {
				if ( ! res || ! res.success ) {
					return;
				}
				list.innerHTML = '';
				res.data.forEach( function ( label ) {
					var o = document.createElement( 'option' );
					o.value = label;
					list.appendChild( o );
				} );
			} ).catch( function () {} );
		}, 250 );
	} );
} )();
/* Payout bulk action: a file download ends without a page change. The form sends a token; the
   download response sets the cookie wpac_dl_<token> (PayoutsPage::download_started()). Only once
   it is there is the list reloaded (new status, message about the download), so a reload never
   cancels a download that has not started yet. If it takes too long, a "Refresh page" link is
   shown instead. Without JavaScript the download works the same; the status shows on refresh. */
document.addEventListener( 'submit', function ( e ) {
	var form = e.target;
	if ( ! form.classList || ! form.classList.contains( 'wpac-bulk-form' ) ) {
		return;
	}
	var s = form.querySelector( '[name="bulk_action"]' );
	if ( ! s || [ 'sepa', 'csv', 'paypal' ].indexOf( s.value ) === -1 || e.defaultPrevented ) {
		return;
	}
	var token = '';
	while ( token.length < 24 ) {
		token += Math.random().toString( 36 ).slice( 2 );
	}
	token = token.slice( 0, 24 ).replace( /[^a-z0-9]/g, '0' );
	var field = form.querySelector( 'input[name="wpac_dl_token"]' );
	if ( ! field ) {
		field = document.createElement( 'input' );
		field.type = 'hidden';
		field.name = 'wpac_dl_token';
		form.appendChild( field );
	}
	field.value = token;
	var name = 'wpac_dl_' + token + '=';
	var started = Date.now();
	var hinted = false;
	var timer = window.setInterval( function () {
		if ( ( '; ' + document.cookie ).indexOf( '; ' + name ) > -1 ) {
			window.clearInterval( timer );
			var dir = window.location.pathname.replace( /[^\/]*$/, '' ) || '/';
			[ dir, dir.replace( /\/$/, '' ) || '/' ].forEach( function ( path ) {
				document.cookie = name + '; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=' + path;
			} );
			window.setTimeout( function () {
				window.location.reload();
			}, 1000 );
			return;
		}
		var waited = Date.now() - started;
		if ( ! hinted && waited > 30000 ) {
			// Still waiting for the server: offer a manual refresh, keep watching for the download.
			hinted = true;
			var box = document.querySelector( '.wpac-download-status' );
			if ( box ) {
				var p = document.createElement( 'p' );
				var a = document.createElement( 'a' );
				p.textContent = box.getAttribute( 'data-wpac-download-wait' ) + ' ';
				a.href = window.location.href;
				a.textContent = box.getAttribute( 'data-wpac-download-refresh' );
				p.appendChild( a );
				box.className += ' notice notice-info inline';
				box.appendChild( p );
			}
		}
		if ( waited > 600000 ) {
			window.clearInterval( timer );
		}
	}, 500 );
} );
/* Wide tables scroll inside their own box instead of widening the page (small screens). Only a
   box whose table overflows is a focusable, named region (keyboard scrolling); it is named after
   the table's caption or the heading right before it, so several tables on a page differ. */
document.addEventListener( 'DOMContentLoaded', function () {
	var heading = function ( table ) {
		var caption = table.querySelector( 'caption' );
		if ( caption && caption.textContent.trim() ) {
			return caption.textContent.trim();
		}
		if ( table.getAttribute( 'aria-label' ) ) {
			return table.getAttribute( 'aria-label' );
		}
		// The nearest preceding h2/h3 (also inside a preceding sibling, e.g. a form or a card).
		for ( var el = table; el && ! ( el.classList && el.classList.contains( 'wrap' ) ); el = el.parentNode ) {
			for ( var sib = el.previousElementSibling; sib; sib = sib.previousElementSibling ) {
				if ( /^H[23]$/.test( sib.tagName ) && sib.textContent.trim() ) {
					return sib.textContent.trim();
				}
				var inner = sib.querySelectorAll ? sib.querySelectorAll( 'h2, h3' ) : [];
				if ( inner.length && inner[ inner.length - 1 ].textContent.trim() ) {
					return inner[ inner.length - 1 ].textContent.trim();
				}
			}
		}
		var h1 = document.querySelector( '.wrap h1' );
		return ( h1 && h1.textContent.trim() ) || document.title;
	};
	var boxes = [];
	var update = function () {
		boxes.forEach( function ( box ) {
			if ( box.scrollWidth > box.clientWidth ) {
				box.setAttribute( 'tabindex', '0' );
				box.setAttribute( 'role', 'region' );
				box.setAttribute( 'aria-label', box.getAttribute( 'data-wpac-label' ) );
			} else {
				box.removeAttribute( 'tabindex' );
				box.removeAttribute( 'role' );
				box.removeAttribute( 'aria-label' );
			}
		} );
	};
	Array.prototype.forEach.call( document.querySelectorAll( '.wrap table.widefat:not(.table-view-list)' ), function ( table ) {
		if ( table.parentNode.classList.contains( 'wpac-table-scroll' ) ) {
			return;
		}
		var box = document.createElement( 'div' );
		box.className = 'wpac-table-scroll';
		box.setAttribute( 'data-wpac-label', heading( table ) );
		table.parentNode.insertBefore( box, table );
		box.appendChild( table );
		boxes.push( box );
	} );
	update();
	var timer;
	window.addEventListener( 'resize', function () {
		window.clearTimeout( timer );
		timer = window.setTimeout( update, 150 );
	} );
} );
/* Image from the media library (SET-AC-16): data-wpac-media="input id" opens the library, data-wpac-media-clear empties the field. */
document.addEventListener( 'click', function ( e ) {
	var btn = e.target.closest ? e.target.closest( '[data-wpac-media], [data-wpac-media-clear]' ) : null;
	if ( ! btn ) {
		return;
	}
	var clear = btn.hasAttribute( 'data-wpac-media-clear' );
	var input = document.getElementById( btn.getAttribute( clear ? 'data-wpac-media-clear' : 'data-wpac-media' ) );
	if ( ! input ) {
		return;
	}
	var preview = document.getElementById( input.id + '-preview' );
	if ( clear ) {
		e.preventDefault();
		input.value = '0';
		if ( preview ) {
			preview.textContent = '';
		}
		return;
	}
	if ( ! window.wp || ! window.wp.media ) {
		return; /* Without the media library the ID can be typed. */
	}
	e.preventDefault();
	var frame = window.wp.media( { title: btn.getAttribute( 'data-wpac-media-title' ), library: { type: [ 'image/png', 'image/jpeg' ] }, multiple: false } );
	frame.on( 'select', function () {
		var a = frame.state().get( 'selection' ).first().toJSON();
		input.value = a.id;
		if ( preview ) {
			preview.textContent = '';
			var img = document.createElement( 'img' );
			img.src = a.sizes && a.sizes.thumbnail ? a.sizes.thumbnail.url : a.url;
			img.alt = '';
			img.style.maxHeight = '60px';
			img.style.verticalAlign = 'middle';
			preview.appendChild( img );
		}
	} );
	frame.open();
} );
