<?php
/**
 * Render checks of audit panel round 14, fix agent g3: conversion "—" without visits (U6), payout
 * filter notice (U2), payout details tab of terminated partners (U3), tier bonus text and progress
 * bar (U4), conditions without zero values and copy buttons with names (U8), HTML text links
 * (T10). Included from tests/integration/run.php after the render files (template stubs of
 * r8-render.php).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Portal\Portal;

echo "R14 g3 render: statistics, payouts, tiers, text links\n";

global $wpdb;
$r14g3r_keep_user = isset( $GLOBALS['test_user'] ) ? $GLOBALS['test_user'] : 0;
$r14g3r_keep_get  = $_GET;
$r14g3r_user      = static function ( $uid, $aff ) {
	global $wpdb;
	$wpdb->update( t( 'affiliates' ), array( 'user_id' => $uid ), array( 'id' => (int) $aff ) );
	AffiliateRepository::clear_cache( (int) $aff );
	$GLOBALS['test_users'][ $uid ] = (object) array( 'ID' => $uid, 'user_email' => 'r14g3-' . $uid . '@example.de', 'display_name' => 'R14G3', 'user_login' => 'r14g3-' . $uid, 'roles' => array( 'customer' ) );
	$GLOBALS['test_user']          = $uid;
};

// Partner without visits: conversion "—" (R14-U6); links tab with text link snippets (R14-T10).
$r14g3r_uid = 914301;
$r14g3r_aff = AffiliateRepository::create( array( 'name' => 'R14G3 Render', 'email' => 'r14g3-render@example.de', 'status' => 'active' ) );
$r14g3r_user( $r14g3r_uid, $r14g3r_aff );
sale( 'r14g3r-1', WPAffiliateCaptain\Codes\CodeRepository::main_code( $r14g3r_aff ), array( 10000 ) );
$_GET = array( 'wpac_tab' => 'statistics' );
$r14g3r = r_render( array( Portal::class, 'render' ) );
check( 'R14-U6 statistics: conversion "—" with 0 visits, explanation shown, no errors', array( true, true, array(), '' ), array( (bool) preg_match( '/Conversion rate<\/span><strong class="wpac-card__value">—<\/strong>/', $r14g3r[0] ), false !== strpos( $r14g3r[0], 'only counts sales after a click on your link' ), $r14g3r[1], $r14g3r[2] ) );

$_GET   = array( 'wpac_tab' => 'links' );
$r14g3r = r_render( array( Portal::class, 'render' ) );
check(
	'R14-T10 links tab: copyable HTML text link with rel="sponsored noopener" next to partner and short link',
	array( true, 2, true, array(), '' ),
	array(
		false !== strpos( $r14g3r[0], 'rel=&quot;sponsored noopener&quot;' ),
		substr_count( $r14g3r[0], '<details class="wpac-snippet">' ),
		false !== strpos( $r14g3r[0], 'aria-label="Copy: HTML text link: Your partner link"' ),
		$r14g3r[1],
		$r14g3r[2],
	)
);
check(
	'R14-U8 recommended targets: copy button named after the page, own live region',
	array( true, true ),
	array(
		(bool) preg_match( '/data-wpac-copy="wpac-target-5-1" aria-label="Copy your partner link to [^"]+"/', $r14g3r[0] ),
		false !== strpos( $r14g3r[0], '<div class="wpac-portal wpac-targets"><h3>Recommended target pages</h3><p class="wpac-sr wpac-live" aria-live="polite"></p>' ),
	)
);
$_GET   = array( 'wpac_tab' => 'links', 'target' => '/kurse/', 'sub' => 'a1' );
$r14g3r = r_render( array( Portal::class, 'render' ) );
check( 'R14-T10 generated link gets a text link snippet too', 3, substr_count( $r14g3r[0], '<details class="wpac-snippet">' ) );

// Overview: tier bonus in the partner's words and progress bar (R14-U4), no zero values (R14-U8).
$r14g3r_keep_settings = get_option( 'wpac_settings' );
update_option( 'wpac_settings', array_merge( (array) $r14g3r_keep_settings, array( 'min_payout_minor' => 0, 'hold_days' => 0 ) ) );
Settings::flush_cache();
$r14g3r_user( 914302, (int) $GLOBALS['r14g3_tier_aff'] );
$_GET   = array( 'wpac_tab' => 'overview' );
$r14g3r = r_render( array( Portal::class, 'render' ) );
check(
	'R14-U4 overview: monthly bonus explained, progress with text (1 of 3 orders, 2 more to +2 %)',
	array( true, true, true, true, array(), '' ),
	array(
		false !== strpos( $r14g3r[0], 'From 3 orders in a month: +' ),
		(bool) preg_match( '/<progress max="3" value="1" aria-labelledby="wpac-tier-title" aria-describedby="wpac-tier-text">/', $r14g3r[0] ),
		false !== strpos( $r14g3r[0], 'This month so far: 1 order – 2 more orders to +' ),
		false === strpos( $r14g3r[0], '→' ),
		$r14g3r[1],
		$r14g3r[2],
	)
);
check(
	'R14-U8 conditions: "no minimum amount" and "none" instead of 0,00 / 0 days',
	array( true, true, false ),
	array(
		false !== strpos( $r14g3r[0], '<dt>Minimum payout amount</dt><dd>No minimum amount</dd>' ),
		false !== strpos( $r14g3r[0], '<dt>Hold period</dt><dd>None – commissions are approved right away</dd>' ),
		false !== strpos( $r14g3r[0], '0 days (returns' ),
	)
);
update_option( 'wpac_settings', $r14g3r_keep_settings );
Settings::flush_cache();
$wpdb->update( t( 'tiers' ), array( 'active' => 0 ), array( 'id' => (int) $GLOBALS['r14g3_tier_id'] ) );

// Payouts: date filter and notice (R14-U2); terminated partner with an open payout (R14-U3).
$r14g3r_pa = (int) $wpdb->get_var( 'SELECT id FROM ' . t( 'affiliates' ) . " WHERE email = 'r12a1-r14g3@example.de'" );
$r14g3r_user( 914303, $r14g3r_pa );
$_GET   = array( 'wpac_tab' => 'payouts' );
$r14g3r = r_render( array( Portal::class, 'render' ) );
check(
	'R14-U3 terminated partner with an unpaid payout: payout details tab and hint',
	array( true, true, array(), '' ),
	array(
		false !== strpos( $r14g3r[0], 'wpac_tab=profile' ),
		false !== strpos( $r14g3r[0], 'Until your last payout please keep your payout details up to date.' ),
		$r14g3r[1],
		$r14g3r[2],
	)
);
check( 'R14-U2 payouts tab: the cancelled payout without document is not listed', 1, substr_count( $r14g3r[0], '<tr role="row"><td' ) );
$_GET   = array( 'wpac_tab' => 'payouts', 'wpac_from' => '2099-01-01', 'wpac_to' => '2099-12-31' );
$r14g3r = r_render( array( Portal::class, 'render' ) );
check( 'R14-U2 payouts tab: date filter applies, notice instead of an empty table, filter form stays', array( 0, true, false, true ), array( substr_count( $r14g3r[0], '<tr role="row"><td' ), false !== strpos( $r14g3r[0], 'No payouts match the filter.' ), false !== strpos( $r14g3r[0], 'Your payouts</caption>' ), false !== strpos( $r14g3r[0], 'value="2099-01-01"' ) ) );
$_GET   = array( 'wpac_tab' => 'profile' );
$r14g3r = r_render( array( Portal::class, 'render' ) );
check( 'R14-U3 payout details form is rendered for the terminated partner', true, false !== strpos( $r14g3r[0], 'name="wpac_portal_action" value="profile"' ) );
foreach ( $wpdb->get_col( 'SELECT id FROM ' . t( 'payouts' ) . ' WHERE affiliate_id = ' . (int) $r14g3r_pa . " AND status IN ('pending','exported')" ) as $r14g3r_pid ) {
	WPAffiliateCaptain\Payouts\PayoutService::mark_paid( (int) $r14g3r_pid );
}
foreach ( $wpdb->get_col( 'SELECT id FROM ' . t( 'commissions' ) . ' WHERE affiliate_id = ' . (int) $r14g3r_pa . " AND status IN ('pending','review','approved')" ) as $r14g3r_cid ) {
	WPAffiliateCaptain\Commissions\Ledger::transition( (int) $r14g3r_cid, 'rejected' ); // The commissions released by the cancellation.
}
$_GET   = array( 'wpac_tab' => 'profile' );
$r14g3r = r_render( array( Portal::class, 'render' ) );
check( 'R14-U3 nothing open any more: no payout details tab', array( false, false ), array( false !== strpos( $r14g3r[0], 'wpac_tab=profile' ), false !== strpos( $r14g3r[0], 'value="profile"' ) ) );

$_GET                 = $r14g3r_keep_get;
$GLOBALS['test_user'] = $r14g3r_keep_user;
