/*
 * Browser tests for Affiliate Captain (Playwright + axe-core) against a running WordPress with
 * WooCommerce (blocks checkout) and this plugin. Runs in CI (job "Browser tests") against the
 * site of tests/ci/install-wp.sh; tests/ci/e2e-data.php adds the data of the suites autoCoupon
 * and reauth (E2E_* variables).
 *
 *   cd tests/e2e && npm install
 *   WP_URL=http://127.0.0.1:8088 ADMIN_USER=admin ADMIN_PASS=... PARTNER_USER=... PARTNER_PASS=... \
 *   PRODUCT_ID=14 PARTNER_CODE=ME9P-BG9B node run.js
 *
 * Optional: REGISTER_PATH (/become-a-partner/), PORTAL_PATH (/partner-area/), CHECKOUT_PATH (/checkout/),
 * CART_PATH (/cart/), CLASSIC_CART_PATH (/e2e-classic-cart/), REF_PARAM (partner), SUITES (all).
 */
'use strict';

const { chromium } = require( 'playwright' );
const fs = require( 'fs' );
const path = require( 'path' );

const env = ( k, d ) => process.env[ k ] || d;
const BASE = env( 'WP_URL', 'http://127.0.0.1:8088' ).replace( /\/$/, '' );
const CFG = {
	admin: [ env( 'ADMIN_USER', 'admin' ), env( 'ADMIN_PASS', '' ) ],
	partner: [ env( 'PARTNER_USER', '' ), env( 'PARTNER_PASS', '' ) ],
	register: env( 'REGISTER_PATH', '/become-a-partner/' ),
	portal: env( 'PORTAL_PATH', '/partner-area/' ),
	checkout: env( 'CHECKOUT_PATH', '/checkout/' ),
	product: env( 'PRODUCT_ID', '' ),
	code: env( 'PARTNER_CODE', '' ),
	param: env( 'REF_PARAM', 'partner' ),
	// tests/ci/e2e-data.php
	autoCode: env( 'E2E_AUTO_CODE', '' ),
	autoCoupon: env( 'E2E_AUTO_COUPON', '' ).toLowerCase(),
	customerCoupon: env( 'E2E_CUSTOMER_COUPON', '' ).toLowerCase(),
	classicCart: env( 'CLASSIC_CART_PATH', '/e2e-classic-cart/' ),
	cart: env( 'CART_PATH', '/cart/' ),
	payouts: [ 'A', 'B', 'C' ].map( ( l ) => ( { id: env( 'E2E_PAYOUT_' + l, '' ), no: env( 'E2E_PAYOUT_' + l + '_NO', '' ) } ) ),
};
const AXE = fs.readFileSync( require.resolve( 'axe-core/axe.min.js' ), 'utf8' );
const ART = path.join( __dirname, 'artifacts' );

let failures = 0;
let checks = 0;
function check( ok, label, detail ) {
	checks++;
	if ( ! ok ) {
		failures++;
	}
	console.log( ( ok ? '  ok   ' : '  FAIL ' ) + label + ( ! ok && detail ? ' — ' + detail : '' ) );
}

/** Errors of this plugin's scripts and uncaught exceptions on a page. */
function watchErrors( page ) {
	const errors = [];
	page.on( 'pageerror', ( e ) => errors.push( 'pageerror: ' + e.message ) );
	page.on( 'console', ( m ) => {
		if ( 'error' === m.type() && /affiliate-captain|wpac/i.test( m.text() + ( m.location().url || '' ) ) ) {
			errors.push( 'console: ' + m.text() );
		}
	} );
	return errors;
}

/** axe-core scan; returns serious/critical violations (WCAG 2.1 A/AA) inside `selector`. */
async function axe( page, selector ) {
	await page.addScriptTag( { content: AXE } );
	return page.evaluate( async ( sel ) => {
		const ctx = document.querySelector( sel ) ? sel : document;
		const r = await window.axe.run( ctx, { runOnly: { type: 'tag', values: [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa' ] } } );
		return r.violations.filter( ( v ) => [ 'serious', 'critical' ].includes( v.impact ) ).map( ( v ) => v.id + ' (' + v.nodes.length + '): ' + v.nodes.slice( 0, 2 ).map( ( n ) => n.target.join( ' ' ) ).join( ' | ' ) );
	}, selector );
}

async function login( context, [ user, pass ] ) {
	const page = await context.newPage();
	await page.goto( BASE + '/wp-login.php' );
	await page.fill( '#user_login', user );
	await page.fill( '#user_pass', pass );
	await Promise.all( [ page.waitForNavigation(), page.click( '#wp-submit' ) ] );
	await page.close();
}

async function registration( browser ) {
	console.log( 'Bewerbungsformular' );
	const ctx = await browser.newContext();
	const page = await ctx.newPage();
	const errors = watchErrors( page );
	await page.goto( BASE + CFG.register );
	const form = page.locator( 'form:has([name="wpac_register"])' );
	const submit = form.locator( 'button[type="submit"], input[type="submit"]' ).first();
	check( await form.count() === 1, 'Formular wird angezeigt' );
	// Server-side validation: bypass the browser's own checks.
	await form.evaluate( ( f ) => f.setAttribute( 'novalidate', 'novalidate' ) );
	await page.fill( '[name="wpac_name"]', 'Browser Test' );
	await page.fill( '[name="wpac_email"]', 'keine-mail' );
	if ( await page.locator( '[name="wpac_website"]' ).count() ) {
		await page.fill( '[name="wpac_website"]', 'https://browser.example' );
	}
	await Promise.all( [ page.waitForNavigation(), submit.click() ] );
	const invalid = await page.locator( '[aria-invalid="true"]' ).count();
	check( invalid > 0, 'Fehlerhafte Felder sind mit aria-invalid markiert', invalid + ' Felder' );
	check( 'Browser Test' === await page.inputValue( '[name="wpac_name"]' ), 'Eingaben bleiben nach dem Fehler erhalten' );
	const alert = page.locator( '[role="alert"], .wpac-error, .wpac-notice-error' ).first();
	check( await alert.count() > 0 && ( await alert.innerText() ).trim().length > 0, 'Fehlermeldung ist sichtbar' );
	const violations = await axe( page, 'form:has([name="wpac_register"])' );
	check( 0 === violations.length, 'Barrierefreiheit Formular (axe, WCAG 2.1 AA, serious/critical)', violations.join( '; ' ) );
	// Valid application.
	await page.fill( '[name="wpac_email"]', 'browser' + Date.now() + '@example.test' );
	const terms = page.locator( '[name="wpac_terms"]' );
	if ( await terms.count() ) {
		await terms.check();
	}
	await Promise.all( [ page.waitForNavigation(), page.locator( 'form:has([name="wpac_register"])' ).locator( 'button[type="submit"], input[type="submit"]' ).first().click() ] );
	const text = await page.locator( 'body' ).innerText();
	if ( /wpac_err=rate/.test( page.url() ) ) {
		console.log( '  (Ratenbegrenzung aktiv – erneute Bewerbung von dieser IP wird korrekt abgewiesen; Erfolgsfall übersprungen)' );
	} else {
		check( /bestätig|confirm/i.test( text ), 'Bewerbung abgeschickt: Hinweis auf die Bestätigungsmail' );
	}
	check( 0 === errors.length, 'keine JavaScript-Fehler', errors.join( '; ' ) );
	await ctx.close();
}

async function portal( browser ) {
	console.log( 'Partnerbereich' );
	const ctx = await browser.newContext( { permissions: [ 'clipboard-read', 'clipboard-write' ], baseURL: BASE } );
	await login( ctx, CFG.partner );
	const page = await ctx.newPage();
	const errors = watchErrors( page );
	await page.goto( BASE + CFG.portal + '?wpac_tab=links' );
	const btn = page.locator( '[data-wpac-copy]' ).first();
	check( await btn.count() > 0, 'Kopier-Schaltfläche vorhanden' );
	if ( await btn.count() ) {
		const fieldId = await btn.getAttribute( 'data-wpac-copy' );
		const value = await page.inputValue( '#' + fieldId );
		await btn.click();
		const clip = await page.evaluate( () => navigator.clipboard.readText().catch( () => '' ) );
		check( clip === value && value.length > 0, 'Kopieren legt den Partnerlink in die Zwischenablage', clip + ' vs ' + value );
		await page.waitForTimeout( 200 );
		const live = await page.locator( '#wpac-live' ).innerText().catch( () => '' );
		check( live.trim().length > 0, 'Bildschirmleser-Ansage nach dem Kopieren (aria-live)', JSON.stringify( live ) );
	}
	// Keyboard: tabs reachable and activatable with the keyboard.
	await page.goto( BASE + CFG.portal );
	const tabs = page.locator( 'a[href*="wpac_tab="]' );
	check( await tabs.count() >= 4, 'Navigation zwischen den Bereichen vorhanden', String( await tabs.count() ) );
	await tabs.nth( 1 ).focus();
	await Promise.all( [ page.waitForNavigation(), page.keyboard.press( 'Enter' ) ] );
	check( /wpac_tab=/.test( page.url() ), 'Bereich per Tastatur (Enter) wechselbar', page.url() );
	const current = await page.locator( 'a[aria-current="page"][href*="wpac_tab="]' ).count();
	check( current === 1, 'aktiver Bereich ist mit aria-current gekennzeichnet' );
	for ( const tab of [ 'overview', 'commissions', 'payouts', 'links', 'profile', 'account' ] ) {
		await page.goto( BASE + CFG.portal + '?wpac_tab=' + tab );
		const v = await axe( page, '.wpac-portal' );
		check( 0 === v.length, 'Barrierefreiheit Partnerbereich „' + tab + '“', v.join( '; ' ) );
	}
	// "My account": a changed channel is saved.
	await page.goto( BASE + CFG.portal + '?wpac_tab=account' );
	const channel = 'E2E ' + Date.now();
	await page.fill( '#wpac_acc_channel', channel );
	await Promise.all( [ page.waitForNavigation(), page.click( '.wpac-form button[type="submit"]' ) ] );
	check( ( await page.inputValue( '#wpac_acc_channel' ) ) === channel, 'Mein Konto: Kanal gespeichert' );
	// Phone width: tables stack, no horizontal scrolling of the page.
	await page.setViewportSize( { width: 375, height: 800 } );
	await page.goto( BASE + CFG.portal + '?wpac_tab=commissions' );
	const overflow = await page.evaluate( () => document.documentElement.scrollWidth - document.documentElement.clientWidth );
	check( overflow <= 1, 'Partnerbereich auf dem Handy ohne waagerechtes Scrollen', 'overflow ' + overflow + 'px' );
	check( 0 === errors.length, 'keine JavaScript-Fehler', errors.join( '; ' ) );
	await ctx.close();
}

async function admin( browser ) {
	console.log( 'Verwaltung' );
	const ctx = await browser.newContext();
	await login( ctx, CFG.admin );
	const page = await ctx.newPage();
	const errors = watchErrors( page );
	await page.goto( BASE + '/wp-admin/admin.php?page=wpac-rules' );
	const scope = page.locator( 'select[name="scope"]' ).first();
	check( await scope.count() === 1, 'Regel-Formular vorhanden' );
	const group = page.locator( 'select[name="group_id"]' ).first();
	const aff = page.locator( 'select[name="affiliate_id"]' ).first();
	await scope.selectOption( 'group' );
	check( await group.isVisible() && ! await aff.isVisible(), 'Bereich „Gruppe“ zeigt nur die Gruppenauswahl' );
	await scope.selectOption( 'affiliate' );
	check( ! await group.isVisible() && await aff.isVisible(), 'Bereich „Partner“ zeigt nur die Partnerauswahl' );
	const target = page.locator( 'select[name="target"]' ).first();
	const targetValue = page.locator( 'input[name="target_value"]' ).first();
	await target.selectOption( 'all' );
	check( ! await targetValue.isVisible(), 'Ziel „alle“ blendet das ID/SKU-Feld aus' );
	await target.selectOption( 'sku' );
	check( await targetValue.isVisible(), 'Ziel „SKU“ blendet das ID/SKU-Feld ein' );
	const del = page.locator( '[data-wpac-confirm]' ).first();
	if ( await del.count() ) {
		let message = '';
		page.once( 'dialog', ( d ) => { message = d.message(); d.dismiss(); } );
		await del.click();
		await page.waitForTimeout( 300 );
		check( message.length > 0 && /wp-admin\/admin\.php\?page=wpac-rules/.test( page.url() ), 'Löschen fragt nach und „Abbrechen“ löscht nichts', message );
	}
	await page.goto( BASE + '/wp-admin/admin.php?page=wpac-commissions' );
	const all = page.locator( 'thead input[type="checkbox"]' ).first();
	if ( await all.count() ) {
		await all.check();
		const rows = await page.locator( 'tbody input[type="checkbox"][name]' ).count();
		const checked = await page.locator( 'tbody input[type="checkbox"][name]:checked' ).count();
		check( rows > 0 && rows === checked, 'Alle auswählen markiert jede Zeile', checked + '/' + rows );
	}
	for ( const p of [ 'wpac', 'wpac-affiliates', 'wpac-affiliates&action=edit&id=1', 'wpac-commissions', 'wpac-payouts', 'wpac-rules', 'wpac-creatives', 'wpac-integrations', 'wpac-audit', 'wpac-settings' ] ) {
		await page.goto( BASE + '/wp-admin/admin.php?page=' + p );
		const v = await axe( page, '#wpbody-content .wrap' );
		check( 0 === v.length, 'Barrierefreiheit Admin „' + p + '“', v.join( '; ' ) );
	}
	check( 0 === errors.length, 'keine JavaScript-Fehler', errors.join( '; ' ) );
	await ctx.close();
}

async function editor( browser ) {
	console.log( 'Block-Editor' );
	const ctx = await browser.newContext();
	await login( ctx, CFG.admin );
	const page = await ctx.newPage();
	const errors = watchErrors( page );
	await page.goto( BASE + '/wp-admin/post-new.php?post_type=page' );
	await page.waitForFunction( () => window.wp && wp.data && wp.blocks && wp.data.select( 'core/block-editor' ), null, { timeout: 60000 } );
	// Close the welcome guide if shown.
	await page.evaluate( () => { try { wp.data.dispatch( 'core/preferences' ).set( 'core/edit-post', 'welcomeGuide', false ); } catch ( e ) {} } );
	const types = await page.evaluate( () => wp.blocks.getBlockTypes().filter( ( b ) => 0 === b.name.indexOf( 'wpac/' ) ).map( ( b ) => b.name + '=' + b.title ) );
	check( types.length >= 4, 'Plugin-Blöcke sind im Editor registriert', types.join( ', ' ) );
	await page.evaluate( () => {
		const b = wp.blocks.createBlock( 'wpac/partner-welcome' );
		wp.data.dispatch( 'core/block-editor' ).insertBlocks( b );
	} );
	const frame = page.frameLocator( 'iframe[name="editor-canvas"]' );
	const canvas = ( await page.locator( 'iframe[name="editor-canvas"]' ).count() ) ? frame : page;
	const preview = canvas.locator( '.wpac-welcome' ).first();
	await preview.waitFor( { timeout: 20000 } ).catch( () => {} );
	check( await preview.count() > 0, 'Vorschau des Blocks „Partner-Willkommen“ wird gerendert' );
	const valid = await page.evaluate( () => wp.data.select( 'core/block-editor' ).getBlocks().every( ( b ) => b.isValid ) );
	check( valid, 'eingefügte Blöcke sind gültig' );
	check( 0 === errors.length, 'keine JavaScript-Fehler', errors.join( '; ' ) );
	await ctx.close();
}

async function checkout( browser ) {
	console.log( 'Blocks-Checkout' );
	if ( ! CFG.product || ! CFG.code ) {
		console.log( '  (übersprungen: PRODUCT_ID und PARTNER_CODE setzen)' );
		return;
	}
	const ctx = await browser.newContext();
	const page = await ctx.newPage();
	const errors = watchErrors( page );
	await page.goto( BASE + '/?add-to-cart=' + CFG.product );
	await page.goto( BASE + CFG.checkout );
	const field = page.getByLabel( /Partner/i ).first();
	await field.waitFor( { timeout: 30000 } ).catch( () => {} );
	check( await field.count() > 0, 'Feld „Partnercode“ im Checkout sichtbar' );
	const email = page.locator( '#email' );
	await email.fill( 'browser-checkout@example.test' );
	const fill = async ( sel, v ) => { const l = page.locator( sel ).first(); if ( await l.count() ) { await l.fill( v ); } };
	await fill( '#billing-first_name, #shipping-first_name', 'Bruno' );
	await fill( '#billing-last_name, #shipping-last_name', 'Browser' );
	await fill( '#billing-address_1, #shipping-address_1', 'Kai 3' );
	await fill( '#billing-postcode, #shipping-postcode', '24103' );
	await fill( '#billing-city, #shipping-city', 'Kiel' );
	await field.fill( 'FALSCH-0000' );
	await page.locator( '.wc-block-components-checkout-place-order-button' ).click();
	const err = page.locator( '.wc-block-components-notice-banner.is-error, .wc-block-components-validation-error' ).first();
	await err.waitFor( { timeout: 20000 } ).catch( () => {} );
	check( await err.count() > 0, 'ungültiger Partnercode wird im Checkout abgelehnt', ( await err.count() ) ? await err.innerText() : '' );
	await field.fill( CFG.code );
	await Promise.all( [ page.waitForURL( /order-received/, { timeout: 60000 } ).catch( () => {} ), page.locator( '.wc-block-components-checkout-place-order-button' ).click() ] );
	check( /order-received/.test( page.url() ), 'Bestellung mit gültigem Partnercode abgeschlossen', page.url() );
	check( 0 === errors.length, 'keine JavaScript-Fehler', errors.join( '; ' ) );
	await ctx.close();
}

// ---------------------------------------------------------------------------------------------
// Automatic partner coupon (setting "auto_apply_coupon", src/Integrations/WooCommerce/AutoCoupon.php).

/** Cart of this browser context as the Store API reports it (shares the shop session cookie). */
async function storeCart( page ) {
	const r = await page.request.get( BASE + '/?rest_route=/wc/store/v1/cart' );
	const j = await r.json().catch( () => ( {} ) );
	return {
		coupons: ( j.coupons || [] ).map( ( c ) => String( c.code ).toLowerCase() ),
		items: ( j.items || [] ).reduce( ( n, i ) => n + ( i.quantity || 0 ), 0 ),
	};
}

/** Poll the Store API cart until `test` holds (or the time is up); returns the last cart. */
async function waitCart( page, test, ms = 15000 ) {
	const end = Date.now() + ms;
	let cart = await storeCart( page );
	while ( ! test( cart ) && Date.now() < end ) {
		await page.waitForTimeout( 500 );
		cart = await storeCart( page );
	}
	return cart;
}

const same = ( a, b ) => JSON.stringify( a ) === JSON.stringify( b );

/** Switch the setting in Settings → Promotion like an admin does. */
async function setAutoCoupon( browser, on ) {
	const ctx = await browser.newContext();
	await login( ctx, CFG.admin );
	const page = await ctx.newPage();
	await page.goto( BASE + '/wp-admin/admin.php?page=wpac-settings&tab=promotion' );
	const box = page.locator( '#wpac-auto_apply_coupon' );
	await box.setChecked( on );
	await Promise.all( [ page.waitForNavigation(), page.locator( '#submit' ).click() ] );
	await page.goto( BASE + '/wp-admin/admin.php?page=wpac-settings&tab=promotion' );
	const ok = ( await box.isChecked() ) === on;
	await ctx.close();
	return ok;
}

/**
 * A visitor follows the partner link to the product page and clicks "Add to cart" (classic
 * form: the referral is taken from the link page). Without a recognisable button (theme), the
 * add-to-cart link is opened from the partner link page instead.
 */
async function linkAndAdd( page ) {
	await page.goto( BASE + '/?post_type=product&p=' + CFG.product );
	const product = page.url();
	const link = product + ( product.includes( '?' ) ? '&' : '?' ) + CFG.param + '=' + encodeURIComponent( CFG.autoCode );
	await page.goto( link );
	const before = ( await storeCart( page ) ).items;
	const btn = page.locator( 'form.cart button[type="submit"], button.single_add_to_cart_button' ).first();
	if ( await btn.count() && await btn.isVisible() ) {
		await Promise.all( [ page.waitForLoadState( 'load' ), btn.click() ] );
	} else {
		console.log( '  (kein „In den Warenkorb“-Knopf gefunden – Warenkorb-Link von der Partnerlink-Seite aus)' );
		await page.goto( BASE + '/?add-to-cart=' + CFG.product, { referer: link } );
	}
	return waitCart( page, ( c ) => c.items > before );
}

/** Coupon chips/rows of `code` on the current page (classic cart table or Cart/Checkout blocks). */
async function couponRows( page, code, blocks ) {
	if ( ! blocks ) {
		return page.locator( 'tr.cart-discount' ).evaluateAll( ( rows, c ) => rows.filter( ( r ) => r.textContent.toLowerCase().includes( c ) ).length, code );
	}
	const sel = '.wc-block-components-totals-discount__coupon-list-item';
	await page.locator( '.wc-block-components-totals-item, .wc-block-components-order-summary' ).first().waitFor( { timeout: 30000 } ).catch( () => {} );
	await page.locator( sel ).first().waitFor( { timeout: 5000 } ).catch( () => {} );
	return page.locator( sel ).evaluateAll( ( rows, c ) => rows.filter( ( r ) => r.textContent.toLowerCase().includes( c ) ).length, code );
}

/** Fill the Blocks checkout like the checkout suite and place the order; returns the order ID. */
async function placeBlocksOrder( page, email ) {
	await page.goto( BASE + CFG.checkout );
	if ( ! await page.locator( '#email' ).waitFor( { timeout: 30000 } ).then( () => true, () => false ) ) {
		return '';
	}
	await page.locator( '#email' ).fill( email );
	const fill = async ( sel, v ) => { const l = page.locator( sel ).first(); if ( await l.count() ) { await l.fill( v ); } };
	await fill( '#billing-first_name, #shipping-first_name', 'Clara' );
	await fill( '#billing-last_name, #shipping-last_name', 'Coupon' );
	await fill( '#billing-address_1, #shipping-address_1', 'Kai 5' );
	await fill( '#billing-postcode, #shipping-postcode', '24103' );
	await fill( '#billing-city, #shipping-city', 'Kiel' );
	await Promise.all( [ page.waitForURL( /order-received/, { timeout: 60000 } ).catch( () => {} ), page.locator( '.wc-block-components-checkout-place-order-button' ).click() ] );
	const m = page.url().match( /order-received\/(\d+)/ ) || page.url().match( /[?&]order-received=(\d+)/ );
	return m ? m[ 1 ] : '';
}

async function autoCoupon( browser ) {
	console.log( 'Automatischer Partner-Gutschein' );
	if ( ! CFG.product || ! CFG.autoCode || ! CFG.autoCoupon || ! CFG.customerCoupon ) {
		console.log( '  (übersprungen: PRODUCT_ID, E2E_AUTO_CODE, E2E_AUTO_COUPON, E2E_CUSTOMER_COUPON setzen – tests/ci/e2e-data.php)' );
		return;
	}
	try {
		await autoCouponSteps( browser );
	} finally {
		// Never leave the setting on for the later CI steps (cross-plugin and smoke test), also
		// after an abort.
		await setAutoCoupon( browser, false ).catch( () => {} );
	}
}

async function autoCouponSteps( browser ) {
	const code = CFG.autoCoupon;
	check( await setAutoCoupon( browser, true ), 'Einstellung „Partner-Gutschein automatisch anwenden“ eingeschaltet (Einstellungen → Werbung)' );

	// 1. Classic: partner link → product page → "Add to cart".
	let ctx = await browser.newContext();
	let page = await ctx.newPage();
	let errors = watchErrors( page );
	let cart = await linkAndAdd( page );
	check( cart.items > 0, 'Partnerlink → Produkt in den Warenkorb gelegt', JSON.stringify( cart ) );
	check( same( cart.coupons, [ code ] ), 'Gutschein des Partners automatisch angewendet (genau einmal)', JSON.stringify( cart.coupons ) );
	await page.goto( BASE + CFG.classicCart );
	check( 1 === await couponRows( page, code, false ), 'klassischer Warenkorb zeigt den Gutschein genau einmal' );
	await page.goto( BASE + CFG.cart );
	check( 1 === await couponRows( page, code, true ), 'Warenkorb-Block zeigt den Gutschein genau einmal' );
	await page.goto( BASE + CFG.checkout );
	check( 1 === await couponRows( page, code, true ), 'Checkout-Block zeigt den Gutschein genau einmal' );
	await page.goto( BASE + '/?add-to-cart=' + CFG.product );
	cart = await waitCart( page, ( c ) => c.items >= 2 );
	check( cart.items >= 2 && same( cart.coupons, [ code ] ), 'weiteres Produkt: Gutschein bleibt einmal im Warenkorb', JSON.stringify( cart ) );
	check( 0 === errors.length, 'keine JavaScript-Fehler', errors.join( '; ' ) );
	await ctx.close();

	// 2. Blocks: the product is added through the Store API (as the product blocks do) on the
	//    partner link page; then Cart and Checkout blocks, order.
	ctx = await browser.newContext();
	page = await ctx.newPage();
	errors = watchErrors( page );
	await page.goto( BASE + '/?' + CFG.param + '=' + encodeURIComponent( CFG.autoCode ) );
	const added = await page.evaluate( async ( id ) => {
		const api = '/?rest_route=/wc/store/v1/cart';
		const first = await fetch( api, { credentials: 'same-origin' } );
		const nonce = first.headers.get( 'Nonce' ) || first.headers.get( 'X-WC-Store-API-Nonce' ) || '';
		const r = await fetch( '/?rest_route=/wc/store/v1/cart/add-item', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Nonce: nonce }, body: JSON.stringify( { id: Number( id ), quantity: 1 } ) } );
		return r.status;
	}, CFG.product );
	cart = await waitCart( page, ( c ) => c.items > 0 && c.coupons.length > 0, 5000 );
	check( 201 === added || 200 === added, 'Store API (Blöcke): Produkt von der Partnerlink-Seite aus hinzugefügt', 'HTTP ' + added );
	check( same( cart.coupons, [ code ] ), 'Store API (Blöcke): Gutschein automatisch angewendet (genau einmal)', JSON.stringify( cart ) );
	await page.goto( BASE + CFG.cart );
	check( 1 === await couponRows( page, code, true ), 'Warenkorb-Block (Store-API-Warenkorb) zeigt den Gutschein genau einmal' );
	const orderId = await placeBlocksOrder( page, 'auto-coupon-' + Date.now() + '@example.test' );
	check( '' !== orderId, 'Bestellung mit automatischem Gutschein im Checkout-Block abgeschlossen', page.url() );
	check( 0 === errors.length, 'keine JavaScript-Fehler', errors.join( '; ' ) );
	await ctx.close();
	if ( orderId ) {
		await autoCouponOrder( browser, orderId, code );
	}

	// 3. A coupon the customer entered ("individual use only") is never removed or combined.
	ctx = await browser.newContext();
	page = await ctx.newPage();
	await page.goto( BASE + '/?add-to-cart=' + CFG.product );
	await page.goto( BASE + CFG.classicCart );
	await page.locator( '#coupon_code' ).fill( CFG.customerCoupon );
	await page.locator( 'button[name="apply_coupon"]' ).click();
	cart = await waitCart( page, ( c ) => c.coupons.includes( CFG.customerCoupon ) );
	check( same( cart.coupons, [ CFG.customerCoupon ] ), 'Kunde löst eigenen Gutschein (nur einzeln verwendbar) ein', JSON.stringify( cart.coupons ) );
	cart = await linkAndAdd( page );
	check( same( cart.coupons, [ CFG.customerCoupon ] ), 'danach Partnerlink + Produkt: Kunden-Gutschein bleibt, Partner-Gutschein wird nicht dazugelegt', JSON.stringify( cart.coupons ) );
	await page.goto( BASE + CFG.cart );
	check( 1 === await couponRows( page, CFG.customerCoupon, true ) && 0 === await couponRows( page, code, true ), 'Warenkorb-Block zeigt nur den Kunden-Gutschein' );
	await ctx.close();

	// 4. The customer removes the automatic coupon: it is not added again.
	ctx = await browser.newContext();
	page = await ctx.newPage();
	cart = await linkAndAdd( page );
	check( same( cart.coupons, [ code ] ), 'Partnerlink + Produkt: Gutschein angewendet', JSON.stringify( cart.coupons ) );
	await page.goto( BASE + CFG.cart );
	await couponRows( page, code, true );
	const chipRemove = page.locator( '.wc-block-components-totals-discount__coupon-list-item button, .wc-block-components-chip__remove' ).first();
	if ( await chipRemove.count() ) {
		await chipRemove.click();
	} else {
		console.log( '  (kein Entfernen-Knopf im Warenkorb-Block – klassischer Warenkorb)' );
		await page.goto( BASE + CFG.classicCart );
		await page.locator( 'a.woocommerce-remove-coupon' ).first().click();
	}
	cart = await waitCart( page, ( c ) => 0 === c.coupons.length );
	check( 0 === cart.coupons.length, 'Kunde entfernt den automatischen Gutschein', JSON.stringify( cart.coupons ) );
	await page.goto( BASE + CFG.cart );
	check( 0 === await couponRows( page, code, true ), 'nach erneutem Laden des Warenkorbs nicht wieder da' );
	await page.goto( BASE + '/?add-to-cart=' + CFG.product );
	cart = await storeCart( page );
	check( 0 === cart.coupons.length, 'weiteres Produkt: nicht wieder angewendet', JSON.stringify( cart.coupons ) );
	cart = await linkAndAdd( page );
	check( 0 === cart.coupons.length, 'erneuter Partnerlink + Produkt: nicht wieder angewendet', JSON.stringify( cart.coupons ) );
	await ctx.close();

	// 5. Setting off (default): no coupon.
	check( await setAutoCoupon( browser, false ), 'Einstellung wieder ausgeschaltet' );
	ctx = await browser.newContext();
	page = await ctx.newPage();
	cart = await linkAndAdd( page );
	check( cart.items > 0 && 0 === cart.coupons.length, 'Einstellung aus: Partnerlink + Produkt ohne Gutschein', JSON.stringify( cart ) );
	await ctx.close();
}

/** The order of the automatic coupon in the admin: coupon, note, partner after payment. */
async function autoCouponOrder( browser, orderId, code ) {
	const ctx = await browser.newContext();
	await login( ctx, CFG.admin );
	const page = await ctx.newPage();
	const errors = watchErrors( page );
	await page.goto( BASE + '/wp-admin/admin.php?page=wc-orders&action=edit&id=' + orderId );
	if ( ! await page.locator( '#wpac-order' ).count() ) {
		await page.goto( BASE + '/wp-admin/post.php?action=edit&post=' + orderId );
	}
	const box = page.locator( '#wpac-order' );
	check( await box.count() === 1, 'Bestellung ' + orderId + ': Box „Affiliate Captain“ vorhanden' );
	const coupons = ( await page.locator( '.wc-used-coupons, .wc_coupon_list' ).first().textContent().catch( () => '' ) || '' ).toLowerCase();
	check( coupons.includes( code ), 'Bestellung trägt den Gutschein', coupons.replace( /\s+/g, ' ' ).trim() );
	check( ( await box.textContent() ).toUpperCase().includes( code.toUpperCase() ), 'Bestellung vermerkt: Gutschein automatisch nach dem Partnerlink angewendet (Bestell-Meta)', ( await box.textContent() ).replace( /\s+/g, ' ' ).trim() );
	// Payment received: the order is attributed and the commission booked.
	await page.locator( '#order_status' ).selectOption( 'wc-processing', { force: true } );
	await Promise.all( [ page.waitForNavigation(), page.locator( 'button.save_order' ).first().click() ] );
	const text = ( await page.locator( '#wpac-order' ).textContent() ).replace( /\s+/g, ' ' ).trim();
	check( text.includes( 'E2E Coupon Partner' ), 'nach Zahlungseingang dem Partner zugeordnet', text );
	check( text.toUpperCase().includes( code.toUpperCase() ), 'Vermerk zum automatischen Gutschein bleibt', text );
	const link = page.locator( '#wpac-order a[href*="wpac-commissions"]' ).first();
	if ( await link.count() ) {
		await page.goto( await link.getAttribute( 'href' ) );
		const list = await page.locator( '#wpbody-content' ).textContent();
		check( list.includes( 'E2E Coupon Partner' ), 'Provision der Bestellung für den Partner gebucht' );
	} else {
		check( false, 'Link „Provisionen ansehen“ in der Bestellung' );
	}
	check( 0 === errors.length, 'keine JavaScript-Fehler', errors.join( '; ' ) );
	await ctx.close();
}

// ---------------------------------------------------------------------------------------------
// Password confirmation before money actions (src/Admin/Reauth.php).

/** Open the payouts list, select one payout and choose a bulk action (not yet submitted). */
async function choosePayoutAction( page, id, action ) {
	await page.goto( BASE + '/wp-admin/admin.php?page=wpac-payouts' );
	await page.locator( 'input[name="ids[]"][value="' + id + '"]' ).check();
	await page.locator( '#wpac-bulk-action' ).selectOption( action );
	if ( 'paid' === action ) {
		await page.locator( '.wpac-bulk input[name="confirm"]' ).check();
	}
	return page.locator( '.wpac-bulk button[type="submit"]' );
}

/** Payout numbers listed with a status (filter of the payouts list). */
async function payoutsWithStatus( page, status ) {
	await page.goto( BASE + '/wp-admin/admin.php?page=wpac-payouts&status=' + status );
	return page.locator( '#wpbody-content' ).textContent();
}

async function reauth( browser ) {
	console.log( 'Passwortbestätigung vor Geldaktionen' );
	const [ A, B, C ] = CFG.payouts;
	if ( ! A.id || ! B.id || ! C.id ) {
		console.log( '  (übersprungen: E2E_PAYOUT_A/B/C setzen – tests/ci/e2e-data.php)' );
		return;
	}
	const onReauth = ( page ) => /[?&]page=wpac-reauth/.test( page.url() );

	// Login session 1: transfer list download → confirmation, wrong then right password.
	let ctx = await browser.newContext( { acceptDownloads: true } );
	await login( ctx, CFG.admin );
	let page = await ctx.newPage();
	let errors = watchErrors( page );
	let apply = await choosePayoutAction( page, A.id, 'csv' );
	await Promise.all( [ page.waitForNavigation(), apply.click() ] );
	check( onReauth( page ), 'Überweisungsliste herunterladen → Weiterleitung zur Passwortbestätigung', page.url() );
	check( /^[a-f0-9]{32}$/.test( await page.locator( 'form.wpac-reauth input[name="wpac_reauth"]' ).inputValue().catch( () => '' ) ), 'ursprüngliche Aktion wird aufbewahrt (Token im Formular, nicht in der URL)' );
	check( ! /ids|bulk_action/.test( page.url() ), 'keine Formulardaten in der URL', page.url() );
	const v = await axe( page, '#wpbody-content .wrap' );
	check( 0 === v.length, 'Barrierefreiheit Bestätigungsseite (axe)', v.join( '; ' ) );
	await page.fill( '#wpac-reauth-password', 'falsches-passwort' );
	await Promise.all( [ page.waitForNavigation(), page.locator( 'form.wpac-reauth button[type="submit"]' ).click() ] );
	check( onReauth( page ) && await page.locator( '.notice-error' ).count() > 0, 'falsches Passwort: Fehlermeldung, Bestätigungsseite bleibt', page.url() );
	const peek = await ctx.newPage();
	check( ( await payoutsWithStatus( peek, 'exported' ) ).indexOf( A.no ) < 0, 'falsches Passwort: Aktion nicht ausgeführt (Auszahlung nicht exportiert)' );
	await peek.close();
	await page.fill( '#wpac-reauth-password', CFG.admin[ 1 ] );
	const [ download ] = await Promise.all( [
		page.waitForEvent( 'download', { timeout: 30000 } ).catch( () => null ),
		page.locator( 'form.wpac-reauth button[type="submit"]' ).click(),
	] );
	check( !! download, 'richtiges Passwort: Download der Überweisungsliste startet' );
	if ( download ) {
		const file = fs.readFileSync( await download.path(), 'utf8' );
		check( /\.csv$/i.test( download.suggestedFilename() ) && file.includes( A.no ), 'Datei enthält die gewählte Auszahlung', download.suggestedFilename() );
	}
	check( ( await payoutsWithStatus( page, 'exported' ) ).includes( A.no ), 'Auszahlung als exportiert markiert' );

	// Within 15 minutes: "Mark as paid" without a second prompt.
	apply = await choosePayoutAction( page, B.id, 'paid' );
	await Promise.all( [ page.waitForNavigation(), apply.click() ] );
	check( ! onReauth( page ) && /page=wpac-payouts/.test( page.url() ), 'innerhalb von 15 Minuten: „Als bezahlt markieren“ ohne erneute Abfrage', page.url() );
	check( ( await payoutsWithStatus( page, 'paid' ) ).includes( B.no ), 'Auszahlung als bezahlt markiert' );
	check( 0 === errors.length, 'keine JavaScript-Fehler', errors.join( '; ' ) );
	await ctx.close();

	// Login session 2 (new login = own confirmation): "Mark as paid" asks again.
	ctx = await browser.newContext();
	await login( ctx, CFG.admin );
	page = await ctx.newPage();
	errors = watchErrors( page );
	apply = await choosePayoutAction( page, C.id, 'paid' );
	await Promise.all( [ page.waitForNavigation(), apply.click() ] );
	check( onReauth( page ), 'neue Anmeldung: „Als bezahlt markieren“ → Passwortbestätigung', page.url() );
	const other = await ctx.newPage();
	check( ( await payoutsWithStatus( other, 'paid' ) ).indexOf( C.no ) < 0, 'vor der Bestätigung nicht bezahlt' );
	await other.close();
	await page.fill( '#wpac-reauth-password', CFG.admin[ 1 ] );
	await Promise.all( [ page.waitForNavigation(), page.locator( 'form.wpac-reauth button[type="submit"]' ).click() ] );
	check( /page=wpac-payouts/.test( page.url() ) && await page.locator( '.notice-success' ).count() > 0, 'richtiges Passwort: Aktion ausgeführt, zurück bei den Auszahlungen', page.url() );
	check( ( await payoutsWithStatus( page, 'paid' ) ).includes( C.no ), 'Auszahlung nach der Bestätigung bezahlt' );
	check( 0 === errors.length, 'keine JavaScript-Fehler', errors.join( '; ' ) );
	await ctx.close();
}

( async () => {
	fs.mkdirSync( ART, { recursive: true } );
	const browser = await chromium.launch();
	// SUITES=autoCoupon,reauth runs only these (local runs).
	const only = env( 'SUITES', '' ).split( ',' ).filter( Boolean );
	for ( const suite of [ registration, portal, admin, editor, checkout, autoCoupon, reauth ].filter( ( s ) => ! only.length || only.includes( s.name ) ) ) {
		try {
			await suite( browser );
		} catch ( e ) {
			check( false, suite.name + ': Abbruch', e.message.split( '\n' )[ 0 ] );
		}
	}
	await browser.close();
	console.log( '\n' + checks + ' Browser-Prüfungen, ' + failures + ' Fehler' );
	process.exit( failures ? 1 : 0 );
} )();
