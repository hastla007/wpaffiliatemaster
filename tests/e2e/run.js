/*
 * Browser tests for WP Affiliate Captain (Playwright + axe-core) against a running WordPress with
 * WooCommerce (blocks checkout) and this plugin. Not part of CI: it needs a prepared site.
 *
 *   cd tests/e2e && npm install
 *   WP_URL=http://127.0.0.1:8088 ADMIN_USER=admin ADMIN_PASS=... PARTNER_USER=... PARTNER_PASS=... \
 *   PRODUCT_ID=14 PARTNER_CODE=ME9P-BG9B node run.js
 *
 * Optional: REGISTER_PATH (/become-a-partner/), PORTAL_PATH (/partner-area/), CHECKOUT_PATH (/checkout/).
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
		if ( 'error' === m.type() && /wp-affiliate-captain|wpac/i.test( m.text() + ( m.location().url || '' ) ) ) {
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
	for ( const tab of [ 'overview', 'commissions', 'payouts', 'links' ] ) {
		await page.goto( BASE + CFG.portal + '?wpac_tab=' + tab );
		const v = await axe( page, '.wpac-portal' );
		check( 0 === v.length, 'Barrierefreiheit Partnerbereich „' + tab + '“', v.join( '; ' ) );
	}
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

( async () => {
	fs.mkdirSync( ART, { recursive: true } );
	const browser = await chromium.launch();
	for ( const suite of [ registration, portal, admin, editor, checkout ] ) {
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
