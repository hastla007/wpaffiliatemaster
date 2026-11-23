<?php
/**
 * Round 14, feature agent f4 (0.15.0): manual corrections stay untouched by refunds (R12-N3,
 * owner decision 2026-11-15) and the core data/hook for sub-affiliate add-ons ("recruited by",
 * affiliates.referred_by, schema 16, wpac_affiliate_recruited).
 * Included from run.php before r8-render.php (helpers check(), t(), q()).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Codes\CodeRepository;
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Commissions\Balance;
use WPAffiliateCaptain\Commissions\Ledger;
use WPAffiliateCaptain\Affiliates\Registration;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\Installer;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Privacy\Privacy;
use WPAffiliateCaptain\Tracking\Tracker;

echo "R14 f4 manual corrections on refunds (R12-N3)\n";
global $wpdb;

$f4_keep = get_option( 'wpac_settings' );
unset( $GLOBALS['test_caps'] );

$f4_partner = static function ( $slug ) {
	return (int) AffiliateRepository::create( array( 'name' => 'F4 ' . $slug, 'email' => 'f4-' . $slug . '@example.de', 'status' => 'active' ) );
};
$f4_earn = static function ( $aff, $order, $net ) {
	$r = wpac_record_sale( array( 'source' => 'test', 'order_id' => (string) $order, 'currency' => 'EUR', 'partner_code' => CodeRepository::main_code( $aff ), 'items' => array( array( 'item_id' => '1', 'product_id' => 10, 'name' => 'Kurs', 'net_minor' => $net, 'quantity' => 1 ) ), 'customer' => array( 'user_id' => 0, 'email' => 'f4-kunde-' . $order . '@example.de' ) ) );
	foreach ( (array) $r['commission_ids'] as $cid ) {
		Ledger::transition( (int) $cid, 'approved' );
	}
	return (int) $r['commission_ids'][0];
};
$f4_refund = static function ( $order, $refund_id, $net = 0 ) {
	$p = array( 'source' => 'test', 'order_id' => (string) $order, 'refund_id' => $refund_id, 'type' => 'refund', 'reason' => 'test' );
	if ( $net ) {
		$p['items'] = array( array( 'item_id' => '1', 'net_minor' => $net ) );
	} else {
		$p['full'] = true;
	}
	return wpac_record_refund( $p );
};
$f4_kind_sum = static function ( $parent, $kinds ) use ( $wpdb ) {
	$in = "'" . implode( "','", (array) $kinds ) . "'";
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(amount_minor),0) FROM ' . t( 'commissions' ) . " WHERE parent_id = %d AND status <> 'rejected' AND kind IN ({$in})", (int) $parent ) );
};
$f4_row = static function ( $id ) use ( $wpdb ) {
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . t( 'commissions' ) . ' WHERE id = %d', (int) $id ) );
};
$f4_balance = static function ( $aff ) use ( $wpdb ) {
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(amount_minor),0) FROM ' . t( 'commissions' ) . " WHERE affiliate_id = %d AND status = 'approved' AND payout_id = 0", (int) $aff ) );
};

/* Penalty −20 € on the commission, then a full refund: the penalty stays. */
$f4_a   = $f4_partner( 'penalty' );
$f4_c   = $f4_earn( $f4_a, 'f4-pen', 100000 );
$f4_amt = (int) $f4_row( $f4_c )->amount_minor;
check( 'R12-N3 setup: commission booked', true, $f4_amt > 2000 );
$f4_pen = Ledger::manual_adjustment( $f4_a, 'EUR', -2000, 'Vertragsstrafe', $f4_c );
check( 'R12-N3 penalty booked against the commission', true, is_int( $f4_pen ) && $f4_pen > 0 );
check( 'R12-N3 manual kinds constant', array( 'adjustment' ), Ledger::MANUAL_KINDS );
$f4_r = $f4_refund( 'f4-pen', 'f4-pen-r1' );
check( 'R12-N3 full refund after a penalty: whole calculated commission reversed', -$f4_amt, $f4_kind_sum( $f4_c, array( 'refund' ) ) );
check( 'R12-N3 full refund after a penalty: penalty untouched', array( 'approved', -2000 ), array( (string) $f4_row( $f4_pen )->status, (int) $f4_row( $f4_pen )->amount_minor ) );
check( 'R12-N3 full refund after a penalty: partner keeps the penalty (balance −20 €)', -2000, $f4_balance( $f4_a ) );
check( 'R12-N3 balance table agrees with the ledger', -2000, (int) Balance::get( $f4_a, 'EUR' ) );
check( 'R12-N3 commission no longer stands (penalty is not order commission)', false, Ledger::commission_stands( 'test', 'f4-pen' ) );
$f4_r2 = $f4_refund( 'f4-pen', 'f4-pen-r1' );
check( 'R12-N3 the same refund again is idempotent', 'unchanged', $f4_r2['status'] );
$f4_r3 = $f4_refund( 'f4-pen', 'f4-pen-r2' );
check( 'R12-N3 a second full refund books nothing more', array( 'unchanged', -$f4_amt ), array( $f4_r3['status'], $f4_kind_sum( $f4_c, array( 'refund' ) ) ) );
// Deleted refund (R13-K3/K5): the reversal is booked back, the penalty stays.
Ledger::reverse_refund( 'test', 'f4-pen', 'f4-pen-r1', 'refund deleted' );
check( 'R12-N3 reverse_refund gives the reversal back, penalty stays', array( $f4_amt - 2000, -2000 ), array( $f4_balance( $f4_a ), (int) $f4_row( $f4_pen )->amount_minor ) );

/* Bonus +10 €, then a partial refund (half): the sale is reduced in proportion, the bonus stays. */
$f4_b    = $f4_partner( 'bonus' );
$f4_c2   = $f4_earn( $f4_b, 'f4-bon', 100000 );
$f4_amt2 = (int) $f4_row( $f4_c2 )->amount_minor;
$f4_bon  = Ledger::manual_adjustment( $f4_b, 'EUR', 1000, 'Messebonus', $f4_c2 );
$f4_refund( 'f4-bon', 'f4-bon-r1', 50000 );
check( 'R12-N3 partial refund after a bonus: sale reduced in proportion', -(int) round( $f4_amt2 / 2 ), $f4_kind_sum( $f4_c2, array( 'refund' ) ) );
check( 'R12-N3 partial refund after a bonus: bonus untouched', array( 'approved', 1000 ), array( (string) $f4_row( $f4_bon )->status, (int) $f4_row( $f4_bon )->amount_minor ) );
$f4_refund( 'f4-bon', 'f4-bon-r2' );
check( 'R12-N3 full refund afterwards: only the calculated commission is reversed', -$f4_amt2, $f4_kind_sum( $f4_c2, array( 'refund' ) ) );
check( 'R12-N3 the bonus is kept after the full refund', 1000, $f4_balance( $f4_b ) );
check( 'R12-N3 a kept bonus does not make the refunded order stand', false, Ledger::commission_stands( 'test', 'f4-bon' ) );

/* Penalty, then a partial refund: no longer netted as if it was an earlier refund. */
$f4_p    = $f4_partner( 'partial' );
$f4_c3   = $f4_earn( $f4_p, 'f4-par', 100000 );
$f4_amt3 = (int) $f4_row( $f4_c3 )->amount_minor;
Ledger::manual_adjustment( $f4_p, 'EUR', -(int) round( $f4_amt3 / 4 ), 'Strafe', $f4_c3 );
$f4_refund( 'f4-par', 'f4-par-r1', 50000 );
check( 'R12-N3 partial refund after a penalty: full pro-rata reduction (penalty not netted)', -(int) round( $f4_amt3 / 2 ), $f4_kind_sum( $f4_c3, array( 'refund' ) ) );

/* Refunds after increases are unchanged by a manual correction (same result as without one). */
$f4_inc_case = static function ( $slug, $manual ) use ( $f4_partner, $f4_earn, $f4_refund, $f4_kind_sum ) {
	$aff = $f4_partner( 'inc-' . $slug );
	$cid = $f4_earn( $aff, 'f4-inc-' . $slug, 100000 );
	Ledger::record_increase( array( 'source' => 'test', 'order_id' => 'f4-inc-' . $slug, 'currency' => 'EUR', 'items' => array( array( 'item_id' => '1', 'product_id' => 10, 'name' => 'Kurs', 'net_minor' => 150000, 'quantity' => 1 ) ), 'reason' => 'raised' ) );
	if ( $manual ) {
		Ledger::manual_adjustment( $aff, 'EUR', $manual, 'manuell', $cid );
	}
	$f4_refund( 'f4-inc-' . $slug, 'r1', 30000 );
	$one = array( $f4_kind_sum( $cid, array( 'refund' ) ), $f4_kind_sum( $cid, array( 'increase' ) ) );
	$f4_refund( 'f4-inc-' . $slug, 'r2' );
	return array( $one, $f4_kind_sum( $cid, array( 'refund' ) ), $f4_kind_sum( $cid, array( 'increase' ) ) );
};
$f4_plain = $f4_inc_case( 'plain', 0 );
check( 'R12-N3 increase case: penalty does not change the refunds', $f4_plain, $f4_inc_case( 'pen', -1500 ) );
check( 'R12-N3 increase case: bonus does not change the refunds', $f4_plain, $f4_inc_case( 'bon', 700 ) );
check( 'R12-N3 increase case: full refund reverses sale plus approved/open increases', true, $f4_plain[1] < 0 );
check( 'R12-N3 adjusted_sum() keeps its default (all children)', $f4_kind_sum( $f4_c2, array( 'refund', 'adjustment' ) ), Ledger::adjusted_sum( $f4_c2 ) );
check( 'R12-N3 adjusted_sum( automatic only ) leaves manual corrections out', $f4_kind_sum( $f4_c2, array( 'refund' ) ), Ledger::adjusted_sum( $f4_c2, false, true ) );

echo "R14 f4 recruited by (referred_by, schema 16, wpac_affiliate_recruited)\n";

if ( ! function_exists( 'wp_get_referer' ) ) {
	function wp_get_referer() { return isset( $_SERVER['HTTP_REFERER'] ) ? $_SERVER['HTTP_REFERER'] : false; }
}
if ( ! function_exists( 'wp_get_raw_referer' ) ) {
	function wp_get_raw_referer() { return isset( $_SERVER['HTTP_REFERER'] ) ? $_SERVER['HTTP_REFERER'] : false; }
}
if ( ! function_exists( 'wp_validate_redirect' ) ) {
	function wp_validate_redirect( $location, $default = '' ) { return '' !== (string) $location ? $location : $default; }
}

$f4_set = static function ( array $s ) {
	$cur = get_option( 'wpac_settings' );
	update_option( 'wpac_settings', array_merge( is_array( $cur ) ? $cur : array(), $s ) );
	Settings::flush_cache();
};
$f4_col = $wpdb->get_row( "SELECT COLUMN_TYPE t, IS_NULLABLE n, COLUMN_DEFAULT d FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . t( 'affiliates' ) . "' AND COLUMN_NAME = 'referred_by'" );
$f4_idx = (int) $wpdb->get_var( "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . t( 'affiliates' ) . "' AND INDEX_NAME = 'referred_by' AND COLUMN_NAME = 'referred_by'" );
check(
	'0.15.0 schema 16: affiliates.referred_by BIGINT UNSIGNED NOT NULL DEFAULT 0 with index, schema verified',
	array( 17, 17, 17, true, 'NO', '0', 1, array() ),
	array( Installer::DB_VERSION, WPAC_DB_VERSION, Installer::db_version(), $f4_col ? 0 === strpos( strtolower( (string) $f4_col->t ), 'bigint' ) && false !== strpos( strtolower( (string) $f4_col->t ), 'unsigned' ) : null, $f4_col ? $f4_col->n : null, $f4_col ? trim( (string) $f4_col->d, "'" ) : null, $f4_idx, Installer::verify_schema() )
);
// Migration 15 → 16: dbDelta adds the column and the index to an existing table.
q( 'ALTER TABLE ' . t( 'affiliates' ) . ' DROP INDEX referred_by, DROP COLUMN referred_by' );
check( '0.15.0 verify_schema() reports the missing column', true, in_array( 'missing column ' . t( 'affiliates' ) . '.referred_by', Installer::verify_schema(), true ) );
update_option( 'wpac_db_version', 15 );
Installer::install_schema();
$f4_idx = (int) $wpdb->get_var( "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . t( 'affiliates' ) . "' AND INDEX_NAME = 'referred_by'" );
check( '0.15.0 migration 15 → 17 adds column and index (dbDelta) and raises the version', array( 17, 1, array() ), array( (int) get_option( 'wpac_db_version' ), $f4_idx, Installer::verify_schema() ) );
AffiliateRepository::clear_cache();

$f4_recruited = array();
add_action(
	'wpac_affiliate_recruited',
	static function ( $aff, $rec ) use ( &$f4_recruited ) {
		$f4_recruited[] = array( (int) $aff, (int) $rec );
	},
	10,
	2
);
$f4_ra     = $f4_partner( 'rec-a' );
$f4_rb     = $f4_partner( 'rec-b' );
$f4_rc     = $f4_partner( 'rec-c' );
$f4_code_a = CodeRepository::main_code( $f4_ra );
$f4_code_b = CodeRepository::main_code( $f4_rb );

/* Registration path: ?partner= code on the registration URL. */
$f4_set( array( 'referral_param' => 'partner', 'cookie_mode' => 'always' ) );
if ( function_exists( 'WC' ) && WC() && WC()->session ) {
	WC()->session->set( Tracker::SESSION_KEY, null );
}
unset( $_COOKIE[ Tracker::cookie_name() ], $_SERVER['HTTP_REFERER'] );
$_GET['partner'] = $f4_code_a;
check( 'recruited: ?partner= code on the registration URL', $f4_ra, Registration::recruiter( 'neu-1@example.de', 0 ) );
$_GET['partner'] = AffiliateRepository::find( $f4_ra )->slug;
check( 'recruited: vanity slug on the registration URL', $f4_ra, Registration::recruiter( 'neu-1@example.de', 0 ) );
check( 'recruited: never the applicant (same e-mail, also +tag)', array( 0, 0 ), array( Registration::recruiter( 'f4-rec-a@example.de', 0 ), Registration::recruiter( 'F4-Rec-A+x@example.de', 0 ) ) );
$wpdb->update( t( 'affiliates' ), array( 'user_id' => 4711 ), array( 'id' => $f4_ra ) );
AffiliateRepository::clear_cache( $f4_ra );
check( 'recruited: never the applicant (same WordPress user)', 0, Registration::recruiter( 'anders@example.de', 4711 ) );
$wpdb->update( t( 'affiliates' ), array( 'user_id' => 0 ), array( 'id' => $f4_ra ) );
AffiliateRepository::clear_cache( $f4_ra );
$_GET['partner'] = 'GIBTSNICHT-' . wp_rand();
check( 'recruited: unknown code and no touch → none', 0, Registration::recruiter( 'neu-1@example.de', 0 ) );
unset( $_GET['partner'] );

/* Registration path: the form was posted from a page whose URL carried the code (referer). */
$_SERVER['HTTP_REFERER'] = 'https://example.de/partner-werden/?partner=' . rawurlencode( $f4_code_b );
check( 'recruited: code in the URL of the page the form was sent from', $f4_rb, Registration::recruiter( 'neu-2@example.de', 0 ) );
unset( $_SERVER['HTTP_REFERER'] );

/* Registration path: captured referral (consented cookie). */
$_COOKIE[ Tracker::cookie_name() ] = Tracker::sign( $f4_code_b . '||0|' . ( time() - 60 ) );
check( 'recruited: captured referral touch (cookie)', $f4_rb, Registration::recruiter( 'neu-3@example.de', 0 ) );
$_GET['partner'] = $f4_code_a;
check( 'recruited: a code on the URL wins over an older touch', $f4_ra, Registration::recruiter( 'neu-3@example.de', 0 ) );
unset( $_GET['partner'] );
AffiliateRepository::set_status( $f4_rb, 'paused', 'Pause' );
check( 'recruited: a paused partner does not recruit', 0, Registration::recruiter( 'neu-3@example.de', 0 ) );
AffiliateRepository::set_status( $f4_rb, 'active' );
unset( $_COOKIE[ Tracker::cookie_name() ] );

/* finalize(): auto approval fires the hook after creation; pending fires on approval only once. */
$f4_fin = new ReflectionMethod( Registration::class, 'finalize' );
$f4_fin->setAccessible( true );
$f4_app = static function ( $slug, $recruiter ) {
	return array( 'user_id' => 0, 'name' => 'F4 ' . $slug, 'email' => 'f4-' . $slug . '@example.de', 'website' => '', 'channel' => '', 'application_note' => '', 'application_data' => array(), 'group_id' => 0, 'terms' => null, 'referred_by' => $recruiter );
};
$f4_set( array( 'auto_approve' => 1 ) );
$f4_recruited = array();
$f4_n1        = (int) $f4_fin->invoke( null, $f4_app( 'neu-auto', $f4_ra ) );
check( 'recruited: auto-approved registration stores the recruiter and fires the hook once', array( $f4_ra, array( array( $f4_n1, $f4_ra ) ) ), array( (int) AffiliateRepository::find( $f4_n1 )->referred_by, $f4_recruited ) );
$f4_set( array( 'auto_approve' => 0 ) );
$f4_recruited = array();
$f4_n2        = (int) $f4_fin->invoke( null, $f4_app( 'neu-pend', $f4_rb ) );
check( 'recruited: pending application stores the recruiter, no hook yet', array( $f4_rb, array() ), array( (int) AffiliateRepository::find( $f4_n2 )->referred_by, $f4_recruited ) );
AffiliateRepository::set_status( $f4_n2, 'active' );
check( 'recruited: hook fires on the approval', array( array( $f4_n2, $f4_rb ) ), $f4_recruited );
AffiliateRepository::set_status( $f4_n2, 'paused', 'Pause' );
AffiliateRepository::set_status( $f4_n2, 'active' );
check( 'recruited: reactivation after a pause does not fire again', 1, count( $f4_recruited ) );
AffiliateRepository::set_status( $f4_rc, 'paused', 'Pause' );
$f4_recruited = array();
$f4_n3        = (int) $f4_fin->invoke( null, $f4_app( 'neu-paused-rec', $f4_rc ) );
check( 'recruited: recruiter paused before the confirmation → no recruiter, no hook', array( 0, array() ), array( (int) AffiliateRepository::find( $f4_n3 )->referred_by, $f4_recruited ) );
AffiliateRepository::set_status( $f4_rc, 'active' );
$f4_n4 = (int) $f4_fin->invoke( null, $f4_app( 'neu-none', 0 ) );
check( 'recruited: no recruiter without referral', 0, (int) AffiliateRepository::find( $f4_n4 )->referred_by );
// Hook only after COMMIT: inside a transaction it waits.
$f4_recruited = array();
$f4_inside    = -1;
Db::transaction(
	static function () use ( $f4_rb, &$f4_recruited, &$f4_inside ) {
		AffiliateRepository::create( array( 'name' => 'F4 tx', 'email' => 'f4-tx@example.de', 'status' => 'active', 'referred_by' => $f4_rb ) );
		$f4_inside = count( $f4_recruited );
	}
);
check( 'recruited: fired after COMMIT, not before', array( 0, 1 ), array( $f4_inside, count( $f4_recruited ) ) );

/* Admin/API: set, validate (unknown, self, cycles), audit, filter. */
$f4_x = $f4_partner( 'chain-x' );
$f4_y = $f4_partner( 'chain-y' );
$f4_z = $f4_partner( 'chain-z' );
$f4_r = wpac_update_affiliate( $f4_y, array( 'referred_by' => $f4_x ) );
check( 'referred_by: wpac_update_affiliate() sets it and returns it', $f4_x, is_array( $f4_r ) ? $f4_r['referred_by'] : $f4_r->get_error_code() );
wpac_update_affiliate( $f4_z, array( 'referred_by' => (string) $f4_y ) );
check( 'referred_by: self-referral refused', 'wpac_invalid_recruiter', wpac_update_affiliate( $f4_x, array( 'referred_by' => $f4_x ) )->get_error_code() );
check( 'referred_by: direct cycle A→B→A refused', 'wpac_recruiter_cycle', wpac_update_affiliate( $f4_x, array( 'referred_by' => $f4_y ) )->get_error_code() );
check( 'referred_by: indirect cycle X→Y→Z→X refused', 'wpac_recruiter_cycle', wpac_update_affiliate( $f4_x, array( 'referred_by' => $f4_z, 'name' => 'Nicht speichern' ) )->get_error_code() );
check( 'referred_by: nothing saved after a refused change', array( 0, 'F4 chain-x' ), array( (int) AffiliateRepository::find( $f4_x )->referred_by, (string) AffiliateRepository::find( $f4_x )->name ) );
check( 'referred_by: unknown partner refused', 'wpac_invalid_recruiter', wpac_update_affiliate( $f4_x, array( 'referred_by' => 99999999 ) )->get_error_code() );
check( 'referred_by: invalid values refused', array( 'wpac_invalid_referred_by', 'wpac_invalid_referred_by', 'wpac_invalid_referred_by' ), array( wpac_update_affiliate( $f4_x, array( 'referred_by' => -1 ) )->get_error_code(), wpac_update_affiliate( $f4_x, array( 'referred_by' => 'abc' ) )->get_error_code(), wpac_update_affiliate( $f4_x, array( 'referred_by' => array( 1 ) ) )->get_error_code() ) );
check( 'referred_by: a change does not fire wpac_affiliate_recruited', 1, count( $f4_recruited ) );
$f4_audit = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . t( 'audit' ) . " WHERE action = 'affiliate.referred_by' AND object_id = %d ORDER BY id DESC LIMIT 1", $f4_y ) );
$f4_ctx   = $f4_audit ? json_decode( (string) $f4_audit->details, true ) : null;
check( 'referred_by: change audited with from/to', array( 0, $f4_x ), is_array( $f4_ctx ) ? array( (int) $f4_ctx['from'], (int) $f4_ctx['to'] ) : null );
$f4_list = wpac_get_affiliates( array( 'referred_by' => $f4_x ) );
check( 'referred_by: wpac_get_affiliates() filters by recruiter', array( 1, $f4_y ), array( $f4_list['total'], $f4_list['items'][0]['id'] ) );
check( 'referred_by: filter validated', 'wpac_invalid_referred_by', wpac_get_affiliates( array( 'referred_by' => 'x' ) )->get_error_code() );
check( 'referred_by: wpac_get_affiliate() includes it (int)', array( $f4_y, 0 ), array( wpac_get_affiliate( $f4_z )['referred_by'], wpac_get_affiliate( $f4_x )['referred_by'] ) );
wpac_update_affiliate( $f4_z, array( 'referred_by' => 0 ) );
$f4_r = wpac_update_affiliate( $f4_x, array( 'referred_by' => $f4_z ) );
check( 'referred_by: removed with 0; then the former cycle is allowed', array( 0, $f4_z ), array( wpac_get_affiliate( $f4_z )['referred_by'], is_array( $f4_r ) ? $f4_r['referred_by'] : $f4_r->get_error_code() ) );

/* Admin form: capability, picker, validation before saving. */
check( 'referred_by: admin action needs MANAGE_AFFILIATES', true, false !== strpos( (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Admin/Admin.php' ), "'affiliate_update'  => array( AffiliatesPage::class, Capabilities::MANAGE_AFFILIATES )" ) );
$f4_page = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Admin/Pages/AffiliatesPage.php' );
check( 'referred_by: edit page offers the picker without the partner itself and saves through set_referred_by()', array( true, true ), array( false !== strpos( $f4_page, "partner_picker( 'referred_by', \$recruiter, '', array( 'id' => 'wpac-referred_by', 'aria-describedby' => 'wpac-referred_by-desc' ), array( (int) \$id ) )" ), false !== strpos( $f4_page, 'AffiliateRepository::set_referred_by( $id, $recruiter )' ) ) );

/* Privacy: export lists the recruiter ID; erasure keeps the pseudonymous links only. */
$f4_w = $f4_partner( 'chain-w' );
wpac_update_affiliate( $f4_w, array( 'referred_by' => $f4_y ) );
$f4_exp  = Privacy::export( 'f4-chain-y@example.de' );
$f4_rows = array();
foreach ( $f4_exp['data'] as $f4_item ) {
	if ( 'wpac-affiliate' === $f4_item['group_id'] ) {
		foreach ( $f4_item['data'] as $f4_d ) {
			$f4_rows[ $f4_d['name'] ] = $f4_d['value'];
		}
	}
}
check( 'referred_by: Art. 15 export lists the recruiter partner ID', '#' . $f4_x, isset( $f4_rows['Recruited by (partner ID)'] ) ? $f4_rows['Recruited by (partner ID)'] : null );
Privacy::erase_partner( AffiliateRepository::find( $f4_y ) );
AffiliateRepository::clear_cache();
$f4_erased = AffiliateRepository::find( $f4_y );
check( 'referred_by: erasure keeps the IDs (own recruiter and recruits), deletes the rest', array( $f4_x, 'deleted-' . $f4_y . '@invalid.invalid', $f4_y ), array( (int) $f4_erased->referred_by, (string) $f4_erased->email, (int) AffiliateRepository::find( $f4_w )->referred_by ) );

unset( $_GET['partner'] );
update_option( 'wpac_settings', $f4_keep );
Settings::flush_cache();
