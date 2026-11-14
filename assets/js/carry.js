/*
 * Affiliate Captain: keeps the referral of a partner link on the next pages until the visitor
 * answers the consent banner (cookie mode "consent"). Nothing is stored in the browser: same-site
 * links and GET forms get a signed URL parameter (code, click time, signature) for at most the
 * configured window (30 minutes). Once marketing consent is given, the referral is sent to
 * /wpac/v1/touch (consent: true), the server sets the cookie, and the links are restored.
 *
 * Configuration: window.wpacBeacon (carry = parameter name, window = seconds, param = partner
 * parameter, url = touch endpoint); the token comes from the URL (later page), the per-page data
 * block #wpac-beacon-data (landing page) or the beacon answer (event "wpac_carry").
 * Links or forms with the attribute data-wpac-no-carry are left alone.
 */
( function () {
	var c = window.wpacBeacon;
	if ( ! c || ! c.carry || ! window.URL || ! window.URLSearchParams ) {
		return;
	}
	var name = c.carry;
	var token = '';
	var fromUrl = false;
	var stopped = false;
	var marked = [];
	var timer = 0;

	try {
		token = new URL( window.location.href ).searchParams.get( name ) || '';
	} catch ( e ) {
		token = '';
	}
	fromUrl = '' !== token;
	if ( ! token ) {
		var el = document.getElementById( 'wpac-beacon-data' );
		if ( el ) {
			try {
				token = ( JSON.parse( el.textContent ) || {} ).carry || '';
			} catch ( e ) {
				token = '';
			}
		}
	}

	function hasConsent() {
		return typeof window.wp_has_consent === 'function' && window.wp_has_consent( 'marketing' );
	}

	// Payload of the token (only read here; the server checks the signature).
	function payload( t ) {
		try {
			var p = String( t ).split( '.' )[ 0 ].replace( /-/g, '+' ).replace( /_/g, '/' );
			while ( p.length % 4 ) {
				p += '=';
			}
			var j = JSON.parse( window.atob( p ) );
			return j && j[ 0 ] ? { code: String( j[ 0 ] ), ts: parseInt( j[ 3 ], 10 ) || 0 } : null;
		} catch ( e ) {
			return null;
		}
	}

	function remaining() {
		var p = payload( token );
		return p ? ( p.ts + ( parseInt( c.window, 10 ) || 1800 ) ) * 1000 - Date.now() : 0;
	}

	// External targets, other schemes (mailto:, tel:, javascript:), opt-out, admin, login/logout,
	// REST and AJAX endpoints.
	function blocked( u, node, text ) {
		if ( u.origin !== window.location.origin || ! /^https?:$/.test( u.protocol ) || node.hasAttribute( 'data-wpac-no-carry' ) ) {
			return true;
		}
		return /\/wp-admin(\/|$)|\/wp-login\.php|\/wp-json(\/|$)|\/xmlrpc\.php|logout|wc-ajax|rest_route/i.test( text );
	}

	function skip( u, node ) {
		if ( node.hasAttribute( 'download' ) || blocked( u, node, u.pathname + u.search ) ) {
			return true;
		}
		if ( /(^|[?&])[^=&]*nonce[^=&]*=/i.test( u.search ) ) {
			return true; // URLs with a nonce are never changed.
		}
		var q = u.searchParams;
		if ( q.has( name ) || ( c.param && q.has( c.param ) ) ) {
			return true; // Already carried, or a partner link of its own.
		}
		// Anchors on the same page (no reload).
		return u.pathname === window.location.pathname && u.search === window.location.search && '' !== u.hash;
	}

	function decorate( a ) {
		if ( stopped || ! token || ! a || ! a.getAttribute || remaining() <= 0 ) {
			return;
		}
		var href = a.getAttribute( 'href' );
		if ( ! href || '#' === href.charAt( 0 ) || a.hasAttribute( 'data-wpac-carry-href' ) ) {
			return;
		}
		var u;
		try {
			u = new URL( href, window.location.href );
		} catch ( e ) {
			return;
		}
		if ( skip( u, a ) ) {
			return;
		}
		u.searchParams.set( name, token );
		a.setAttribute( 'data-wpac-carry-href', href );
		a.setAttribute( 'href', u.href );
		marked.push( a );
	}

	function decorateAll() {
		if ( stopped || ! token ) {
			return;
		}
		var links = document.querySelectorAll( 'a[href]' );
		for ( var i = 0; i < links.length; i++ ) {
			decorate( links[ i ] );
		}
		window.clearTimeout( timer );
		var left = remaining();
		if ( left > 0 ) {
			timer = window.setTimeout( stop, Math.min( left, 2147483647 ) );
		} else {
			stop();
		}
	}

	// Remove the parameter again (consent given or window passed).
	function stop() {
		if ( stopped ) {
			return;
		}
		stopped = true;
		window.clearTimeout( timer );
		for ( var i = 0; i < marked.length; i++ ) {
			var a = marked[ i ];
			if ( a.hasAttribute( 'data-wpac-carry-href' ) ) {
				a.setAttribute( 'href', a.getAttribute( 'data-wpac-carry-href' ) );
				a.removeAttribute( 'data-wpac-carry-href' );
			}
		}
		marked = [];
		var inputs = document.querySelectorAll( 'input[data-wpac-carry]' );
		for ( var k = 0; k < inputs.length; k++ ) {
			inputs[ k ].parentNode.removeChild( inputs[ k ] );
		}
	}

	var sent = false;
	function granted() {
		var carried = fromUrl && token && ! sent && remaining() > 0;
		stop();
		if ( ! carried ) {
			return; // On the landing page the beacon sends the referral itself.
		}
		sent = true;
		var p = payload( token );
		if ( ! p ) {
			return;
		}
		try {
			window.fetch( c.url, {
				method: 'POST',
				credentials: 'same-origin',
				keepalive: true,
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( { code: p.code, carry: token, consent: true } ),
			} );
		} catch ( e ) {}
	}

	// Banners without WP Consent API support call window.wpacConsentGranted() (kept for the beacon).
	var previous = window.wpacConsentGranted;
	window.wpacConsentGranted = function () {
		if ( typeof previous === 'function' ) {
			previous();
		}
		granted();
	};
	document.addEventListener( 'wp_listen_for_consent_change', function ( ev ) {
		var x = ( ev && ev.detail ) || {};
		if ( 'allow' === x.marketing ) {
			granted();
		}
	} );

	if ( hasConsent() ) {
		granted();
		return;
	}

	// The beacon (full-page cache) gets the token for the landing page from the server.
	document.addEventListener( 'wpac_carry', function ( ev ) {
		if ( ! token && ! stopped && ev && 'string' === typeof ev.detail ) {
			token = ev.detail;
			decorateAll();
		}
	} );

	// Links added later (menus, AJAX) are decorated when they are used.
	function onUse( ev ) {
		var a = ev.target && ev.target.closest ? ev.target.closest( 'a[href]' ) : null;
		if ( a ) {
			decorate( a );
		}
	}
	document.addEventListener( 'click', onUse, true );
	document.addEventListener( 'auxclick', onUse, true );
	document.addEventListener( 'contextmenu', onUse, true );

	// Same-site GET forms (search, filters) get a hidden field.
	document.addEventListener( 'submit', function ( ev ) {
		var f = ev.target;
		if ( stopped || ! token || ! f || 'FORM' !== f.nodeName || remaining() <= 0 ) {
			return;
		}
		if ( 'get' !== String( f.getAttribute( 'method' ) || 'get' ).toLowerCase() || ( f.elements && f.elements.namedItem && f.elements.namedItem( name ) ) ) {
			return;
		}
		var u;
		try {
			u = new URL( f.getAttribute( 'action' ) || window.location.href, window.location.href );
		} catch ( e ) {
			return;
		}
		// A GET form replaces the query of its action: only the path and the fields count.
		if ( blocked( u, f, u.pathname ) ) {
			return;
		}
		var els = f.elements || [];
		for ( var i = 0; i < els.length; i++ ) {
			var n = els[ i ].name || '';
			if ( /nonce/i.test( n ) || n === c.param || ( 'action' === n && /logout/i.test( els[ i ].value || '' ) ) ) {
				return;
			}
		}
		var input = document.createElement( 'input' );
		input.type = 'hidden';
		input.name = name;
		input.value = token;
		input.setAttribute( 'data-wpac-carry', '1' );
		f.appendChild( input );
	}, true );

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', decorateAll );
	} else {
		decorateAll();
	}
} )();
