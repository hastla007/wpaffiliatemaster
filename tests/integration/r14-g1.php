<?php
/**
 * Audit round 14, fix agent g1: a partner manager can no longer redirect payouts (R14-S1: route
 * capabilities, e-mail change, notifications, 30-day approval of new bank details), VIES only with
 * the payout permission and never for restricted partners (R14-S4), CI and uninstall lists
 * (R14-A3/A4). The checks that change process-wide state (coupons, uninstall, multisite, account
 * link confirmation) run in r14-g1-isolated.php.
 * Included from run.php before r8-render.php.
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Admin\Pages\AffiliatesPage;
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Core\Capabilities;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Payouts\ProfileRepository;
use WPAffiliateCaptain\Payouts\Vies;
use WPAffiliateCaptain\Privacy\Restriction;

echo "R14 G1 payout redirection by partner managers, VIES permission, uninstall, CI\n";
global $wpdb;
$g1_root = dirname( __DIR__, 2 );
$g1_src  = static function ( $file ) use ( $g1_root ) {
	return (string) file_get_contents( $g1_root . '/' . $file );
};
$g1_keep = get_option( 'wpac_settings' );
update_option( 'wpac_settings', array_merge( (array) $g1_keep, array( 'maintenance_mode' => 0, 'payout_method_manual' => 1 ) ) );
Settings::flush_cache();
unset( $GLOBALS['test_caps'] );

// ---------------------------------------------------------------------------------------------
// R14-S1 / R14-S4: route capabilities.
$g1_admin = $g1_src( 'src/Admin/Admin.php' );
$g1_caps  = array();
foreach ( array( 'user_link_resend', 'user_link_manual', 'user_unlink', 'vies_check', 'bank_approve', 'bank_reject', 'profile_update', 'affiliate_update' ) as $g1_route ) {
	preg_match( "/'" . $g1_route . "'\s*=>\s*array\(\s*AffiliatesPage::class,\s*Capabilities::([A-Z_]+)/", $g1_admin, $g1_m );
	$g1_caps[ $g1_route ] = $g1_m[1] ?? '';
}
check(
	'R14-S1/S4 account link, disconnect, resend, VIES and bank approval need the payout permission',
	array( 'user_link_resend' => 'MANAGE_PAYOUTS', 'user_link_manual' => 'MANAGE_PAYOUTS', 'user_unlink' => 'MANAGE_PAYOUTS', 'vies_check' => 'MANAGE_PAYOUTS', 'bank_approve' => 'MANAGE_PAYOUTS', 'bank_reject' => 'MANAGE_PAYOUTS', 'profile_update' => 'MANAGE_PAYOUTS', 'affiliate_update' => 'MANAGE_AFFILIATES' ),
	$g1_caps
);

// ---------------------------------------------------------------------------------------------
// R14-S1 repro (sec/repro_pm_takeover.php): e-mail change -> disconnect -> link own alias account
// -> new IBAN in the partner area.
$g1_users              = $GLOBALS['test_users'] ?? null;
$GLOBALS['test_users'] = array(
	7  => (object) array( 'ID' => 7, 'user_login' => 'pm', 'user_email' => 'pm@shop.test' ),
	50 => (object) array( 'ID' => 50, 'user_login' => 'victim', 'user_email' => 'victim@partner.test' ),
	51 => (object) array( 'ID' => 51, 'user_login' => 'pm-alias', 'user_email' => 'pm-alias@evil.test' ),
);
$g1_aff = AffiliateRepository::create( array( 'name' => 'Victim', 'email' => 'victim@partner.test', 'status' => 'active', 'user_id' => 50 ) );
$g1_old = 'DE89370400440532013000';
$g1_new = 'DE02120300000000202051';
$g1_profile = array( 'tax_status' => 'private', 'legal_name' => 'Victim', 'country' => 'DE', 'account_holder' => 'Victim', 'iban' => $g1_old, 'payout_method' => 'sepa' );
ProfileRepository::save( $g1_aff, $g1_profile, 'admin' );
check( 'R14-S1 a new partner has no recent access change', false, AffiliateRepository::access_changed_recently( $g1_aff ) );

// The partner manager may no longer change the e-mail of a partner with access or payout details.
$GLOBALS['test_user'] = 7;
$GLOBALS['test_caps'] = array( Capabilities::MANAGE_AFFILIATES );
$g1_plain = AffiliateRepository::create( array( 'name' => 'Plain', 'email' => 'plain@partner.test', 'status' => 'pending' ) ); // No account yet.
check(
	'R14-S1 e-mail change: partner manager only for partners without access and payout details',
	array( false, true ),
	array( AffiliatesPage::may_change_email( AffiliateRepository::find( $g1_aff ) ), AffiliatesPage::may_change_email( AffiliateRepository::find( $g1_plain ) ) )
);
$GLOBALS['test_caps'] = array( Capabilities::MANAGE_AFFILIATES, Capabilities::MANAGE_PAYOUTS );
check( 'R14-S1 e-mail change allowed with the payout permission', true, AffiliatesPage::may_change_email( AffiliateRepository::find( $g1_aff ) ) );

// Someone with both permissions (or the old flow) does it anyway: the bank details still cannot
// be redirected without an approval.
$GLOBALS['test_mails'] = array();
AffiliateRepository::set_email( $g1_aff, 'pm-alias@evil.test' );
$GLOBALS['test_mails'] = array();
AffiliateRepository::unlink_user( $g1_aff, 'manual' );
$g1_unlink_to = array_map(
	static function ( $m ) {
		return is_array( $m[0] ) ? implode( ',', $m[0] ) : $m[0];
	},
	$GLOBALS['test_mails']
);
check(
	'R14-S1 disconnecting informs the partner (new and previous address) and the admin',
	array( 2, 'pm-alias@evil.test,victim@partner.test', true ),
	array( count( $GLOBALS['test_mails'] ), $g1_unlink_to[0] ?? '', false !== strpos( (string) ( $GLOBALS['test_mails'][1][1] ?? '' ), 'disconnected' ) )
);
$GLOBALS['test_caps'] = array( Capabilities::MANAGE_AFFILIATES, Capabilities::MANAGE_PROGRAM, 'list_users' );
$GLOBALS['test_mails'] = array();
$g1_linked = AffiliateRepository::link_user( $g1_aff, 'pm-alias@evil.test' );
check(
	'R14-S1 manual link after a recent e-mail change: old address informed too',
	array( true, 51, 'pm-alias@evil.test,victim@partner.test' ),
	array( $g1_linked, (int) AffiliateRepository::find( $g1_aff )->user_id, is_array( $GLOBALS['test_mails'][0][0] ?? null ) ? implode( ',', $GLOBALS['test_mails'][0][0] ) : '' )
);
check( 'R14-S1 access change remembered', true, AffiliateRepository::access_changed_recently( $g1_aff ) );

// Partner area (logged in as the alias account): the new IBAN waits for approval.
$GLOBALS['test_user'] = 51;
unset( $GLOBALS['test_caps'] );
$GLOBALS['test_mails'] = array();
$g1_res = ProfileRepository::save( $g1_aff, array( 'iban' => $g1_new ) + $g1_profile, 'partner' );
$g1_pending = ProfileRepository::pending_bank_change( $g1_aff );
check(
	'R14-S1 repro: new IBAN after a recent access change is held, payouts keep the previous IBAN',
	array( true, $g1_old, true, WPAffiliateCaptain\Core\Iban::mask( '2051', 'DE' ) ),
	array( $g1_res, ProfileRepository::iban( ProfileRepository::get( $g1_aff ) ), is_array( $g1_pending ), is_array( $g1_pending ) ? ProfileRepository::pending_bank_label( $g1_pending ) : '' )
);
$g1_subjects = wp_list_pluck( $GLOBALS['test_mails'], 1 );
check(
	'R14-S1 held change: partner and admin informed, no "bank details were changed" mail',
	array( true, true, false ),
	array(
		(bool) preg_grep( '/wait for our approval/', $g1_subjects ),
		(bool) preg_grep( '/wait for approval/', $g1_subjects ),
		(bool) preg_grep( '/Your bank details were changed/', $g1_subjects ),
	)
);
check( 'R14-S1 held change recorded in the audit log', 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . WPAffiliateCaptain\Core\Db::table( 'audit' ) . " WHERE action = 'payout_profile.bank_change_requested' AND object_id = %d", $g1_aff ) ) );

// Approval by the payout permission applies it; the partner is told.
$GLOBALS['test_user'] = 1;
$GLOBALS['test_mails'] = array();
check( 'R14-S1 approval applies the held IBAN', array( true, $g1_new, null ), array( ProfileRepository::approve_bank_change( $g1_aff ), ProfileRepository::iban( ProfileRepository::get( $g1_aff ) ), ProfileRepository::pending_bank_change( $g1_aff ) ) );
check( 'R14-S1 approval sends the "bank details changed" notice', true, (bool) preg_grep( '/Your bank details were changed/', wp_list_pluck( $GLOBALS['test_mails'], 1 ) ) );

// Rejection keeps the stored details.
ProfileRepository::save( $g1_aff, array( 'iban' => $g1_old ) + $g1_profile, 'partner' );
check( 'R14-S1 rejection keeps the stored IBAN', array( true, true, $g1_new, null ), array( is_array( ProfileRepository::pending_bank_change( $g1_aff ) ), ProfileRepository::reject_bank_change( $g1_aff ), ProfileRepository::iban( ProfileRepository::get( $g1_aff ) ), ProfileRepository::pending_bank_change( $g1_aff ) ) );
check( 'R14-S1 nothing to approve or reject', array( 'wpac_none', 'wpac_none' ), array( ProfileRepository::approve_bank_change( $g1_aff )->get_error_code(), ProfileRepository::reject_bank_change( $g1_aff )->get_error_code() ) );

// Manual payment details are held the same way; the admin's own change replaces a held one.
ProfileRepository::save( $g1_aff, array( 'payout_method' => 'manual', 'payment_details' => 'PayPal: thief@evil.test' ) + $g1_profile, 'partner' );
check( 'R14-S1 switch to other payment details is held too', array( true, 'sepa' ), array( is_array( ProfileRepository::pending_bank_change( $g1_aff ) ), ProfileRepository::method( ProfileRepository::get( $g1_aff ) ) ) );
ProfileRepository::save( $g1_aff, array( 'iban' => $g1_old ) + $g1_profile, 'admin' );
check( 'R14-S1 an admin change of the bank details replaces the held one', array( null, $g1_old ), array( ProfileRepository::pending_bank_change( $g1_aff ), ProfileRepository::iban( ProfileRepository::get( $g1_aff ) ) ) );

// Without a recent access change a partner's change applies right away (as before).
delete_option( 'wpac_access_changed_' . $g1_aff );
delete_option( 'wpac_prev_email_' . $g1_aff );
ProfileRepository::save( $g1_aff, array( 'iban' => $g1_new ) + $g1_profile, 'partner' );
check( 'R14-S1 no recent access change: the partner\'s new IBAN applies directly', array( null, $g1_new ), array( ProfileRepository::pending_bank_change( $g1_aff ), ProfileRepository::iban( ProfileRepository::get( $g1_aff ) ) ) );
// After 30 days the mark expires.
update_option( 'wpac_access_changed_' . $g1_aff, time() - 31 * DAY_IN_SECONDS );
check( 'R14-S1 the access change counts for 30 days only', array( false, false ), array( AffiliateRepository::access_changed_recently( $g1_aff ), get_option( 'wpac_access_changed_' . $g1_aff ) ) );
// First payout details of a freshly linked partner are not held (nothing is redirected).
AffiliateRepository::mark_access_change( $g1_plain );
ProfileRepository::save( $g1_plain, array( 'tax_status' => 'private', 'legal_name' => 'Plain', 'country' => 'DE', 'account_holder' => 'Plain', 'iban' => $g1_old, 'payout_method' => 'sepa' ), 'partner' );
check( 'R14-S1 first bank details are never held', array( null, $g1_old ), array( ProfileRepository::pending_bank_change( $g1_plain ), ProfileRepository::iban( ProfileRepository::get( $g1_plain ) ) ) );
// Privacy erasure removes a held change with the profile.
ProfileRepository::save( $g1_plain, array( 'tax_status' => 'private', 'legal_name' => 'Plain', 'country' => 'DE', 'account_holder' => 'Plain', 'iban' => $g1_new, 'payout_method' => 'sepa' ), 'partner' );
$g1_held = is_array( ProfileRepository::pending_bank_change( $g1_plain ) );
ProfileRepository::delete( $g1_plain );
check( 'R14-S1 the held change goes with the payout profile', array( true, null ), array( $g1_held, ProfileRepository::pending_bank_change( $g1_plain ) ) );
check( 'R14-S1 portal shows the held change', array( true, true ), array( false !== strpos( $g1_src( 'src/Portal/Portal.php' ), "'bank_pending'" ), false !== strpos( $g1_src( 'src/Portal/Portal.php' ), 'ProfileRepository::pending_bank_change( (int) $aff->id )' ) ) );

// ---------------------------------------------------------------------------------------------
// R14-S4: no VIES request for a restricted partner (Art. 18), also from the admin path.
update_option( 'wpac_settings', array_merge( (array) get_option( 'wpac_settings' ), array( 'vies_check' => 1 ) ) );
Settings::flush_cache();
$g1_vat = AffiliateRepository::create( array( 'name' => 'VAT GmbH', 'email' => 'vat@partner.test', 'status' => 'active' ) );
ProfileRepository::save( $g1_vat, array( 'tax_status' => 'business', 'legal_name' => 'VAT GmbH', 'country' => 'AT', 'vat_id' => 'ATU12345678', 'account_holder' => 'VAT GmbH', 'iban' => $g1_old, 'payout_method' => 'sepa' ), 'admin' );
Restriction::add( $g1_vat, 'request', 'accuracy disputed' );
$GLOBALS['test_http_calls'] = array();
$g1_called = 0;
$GLOBALS['test_http'] = static function () use ( &$g1_called ) {
	++$g1_called;
	return array( 'body' => wp_json_encode( array( 'valid' => false ) ) );
};
check( 'R14-S4 restricted partner: no VIES request, no result stored', array( null, 0, false ), array( Vies::check( $g1_vat ), $g1_called, get_option( 'wpac_vies_' . $g1_vat ) ) );
Restriction::remove( $g1_vat, '', false );
unset( $GLOBALS['test_http'] );
update_option( 'wpac_settings', array_merge( (array) get_option( 'wpac_settings' ), array( 'vies_check' => 0 ) ) );

// ---------------------------------------------------------------------------------------------
// R14-A1/A2/A3: uninstall lists (behaviour in r14-g1-isolated.php).
$g1_un = $g1_src( 'uninstall.php' );
check(
	'R14-A1/A3 uninstall removes coupon code IDs, manual order assignments, form groups, bank changes and access marks',
	array( true, true, true, true, true ),
	array(
		false !== strpos( $g1_un, "delete_post_meta_by_key( '_wpac_code_id' )" ),
		false !== strpos( $g1_un, "'_wpac_manual_affiliate', '_wpac_manual_own'" ),
		false !== strpos( $g1_un, "delete_post_meta_by_key( '_wpac_form_groups' )" ),
		false !== strpos( $g1_un, "wpac\\\\_bank\\\\_pending\\\\_%" ),
		false !== strpos( $g1_un, "wpac\\\\_access\\\\_changed\\\\_%" ),
	)
);
preg_match( '/function wpac_uninstall_tables\(\)\s*\{\s*return array\((.*?)\);/s', $g1_un, $g1_t );
preg_match_all( "/'([a-z_]+)'/", $g1_t[1] ?? '', $g1_tables );
preg_match( '/function wpac_uninstall_financial_tables\(\)\s*\{\s*return array\((.*?)\);/s', $g1_un, $g1_f );
preg_match_all( "/'([a-z_]+)'/", $g1_f[1] ?? '', $g1_fin );
$g1_db_tables = WPAffiliateCaptain\Core\Db::TABLES;
sort( $g1_db_tables );
$g1_un_tables = $g1_tables[1];
sort( $g1_un_tables );
check( 'R14-A2 uninstall table lists in sync with Core\\Db::TABLES and Installer::FINANCIAL_TABLES', array( $g1_db_tables, WPAffiliateCaptain\Core\Installer::FINANCIAL_TABLES ), array( $g1_un_tables, $g1_fin[1] ) );

// ---------------------------------------------------------------------------------------------
// R14-A4: CI covers deprecations and the newest PHP.
$g1_ci    = $g1_src( '.github/workflows/ci.yml' );
$g1_smoke = $g1_src( 'tests/ci/smoke.php' );
$g1_neon  = $g1_src( 'phpstan.neon.dist' );
check(
	'R14-A4 smoke test fails on deprecations/notices; integration on PHP 7.4 and 8.4; smoke on 8.4; PHPStan level 3 with baseline',
	array( true, true, true, true, true, true ),
	array(
		false !== strpos( $g1_smoke, 'Deprecated|Notice' ),
		(bool) preg_match( '/php: "7\.4"/', $g1_ci ),
		(bool) preg_match( '/php: "8\.4"/', $g1_ci ),
		false !== strpos( $g1_ci, 'latest-php:' ) && false !== strpos( $g1_ci, 'latest-php, plugin-check]' ),
		(bool) preg_match( '/level:\s*3\b/', $g1_neon ),
		false !== strpos( $g1_neon, 'phpstan-baseline.neon' ) && is_file( $g1_root . '/phpstan-baseline.neon' ),
	)
);
// The bootstrap's handler records deprecations from src/ (not from tests or silenced ones).
$g1_before = $GLOBALS['wpac_test_deprecations'];
trigger_error( 'test deprecation from a test file', E_USER_DEPRECATED );
check( 'R14-A4 deprecations outside src/ are not counted', $g1_before, $GLOBALS['wpac_test_deprecations'] );

$GLOBALS['test_user'] = 1;
unset( $GLOBALS['test_caps'] );
if ( null === $g1_users ) {
	unset( $GLOBALS['test_users'] );
} else {
	$GLOBALS['test_users'] = $g1_users;
}
update_option( 'wpac_settings', $g1_keep );
Settings::flush_cache();

// Isolated part: coupon meta, uninstall (single site and network), purge tool, link confirmation.
$g1_prefix = getenv( 'WPAC_TEST_DB_PREFIX' );
putenv( 'WPAC_TEST_DB_PREFIX=r14g1_' );
$g1_out = array();
exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/r14-g1-isolated.php' ) . ' 2>&1', $g1_out, $g1_rc );
putenv( false === $g1_prefix ? 'WPAC_TEST_DB_PREFIX' : 'WPAC_TEST_DB_PREFIX=' . $g1_prefix );
$g1_iso = json_decode( (string) end( $g1_out ), true );
if ( ! is_array( $g1_iso ) || 0 !== $g1_rc ) {
	check( 'R14 G1 isolated checks ran', 'json', implode( "\n", $g1_out ) );
} else {
	foreach ( $g1_iso as $g1_name => $g1_pair ) {
		check( $g1_name, $g1_pair[0], $g1_pair[1] );
	}
}
