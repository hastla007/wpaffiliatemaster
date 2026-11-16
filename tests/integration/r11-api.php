<?php
/**
 * Contract 1.3.0 extension points for add-ons: read/write API (partners, commissions), events
 * for every ledger booking (after the COMMIT) and wpac_payout_cancelled. Included from run.php
 * right after r11-payout-api.php (uses its r11_partner()/r11_payout() helpers and check(), t()).
 * The portal hooks are checked in r11-api-portal.php (needs the template stubs of r8-render.php).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Affiliates\Groups;
use WPAffiliateCaptain\Commissions\Dunning;
use WPAffiliateCaptain\Commissions\Ledger;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Payouts\Corrections;
use WPAffiliateCaptain\Payouts\PayoutService;

echo "R11 API: read/write functions, ledger events, payout cancelled\n";
global $wpdb;

/** Fires of an action since a mark: [ count, all outside a transaction, args of the last fire ]. */
function r11a_fired( $tag, $mark ) {
	$n   = 0;
	$out = true;
	$arg = null;
	foreach ( array_slice( $GLOBALS['test_fired'], $mark ) as $f ) {
		if ( $f[0] === $tag ) {
			++$n;
			$out = $out && ! $f[2];
			$arg = $f[1];
		}
	}
	return array( $n, $out, $arg );
}
function r11a_mark() {
	return count( $GLOBALS['test_fired'] );
}
function r11a_code( $r ) {
	return is_wp_error( $r ) ? $r->get_error_code() : 'ok';
}
function r11a_audit( $action, $object_id ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( 'SELECT actor_id, details FROM ' . t( 'audit' ) . ' WHERE action = %s AND object_id = %d ORDER BY id DESC LIMIT 1', $action, (int) $object_id ) );
}
$r11a_secret = static function ( $data ) {
	$json = (string) wp_json_encode( $data );
	foreach ( array( 'iban', 'DE89', 'tax_number', 'vat_id', 'legal_name', 'address', 'application', 'profile_snapshot', 'kunde-' ) as $needle ) {
		if ( false !== stripos( $json, $needle ) ) {
			return $needle;
		}
	}
	return '';
};

$r11a_user            = $GLOBALS['test_user'];
$GLOBALS['test_user'] = 7; // Actor of the writes below.

$r11a_group = Groups::create( 'R11 API Gruppe' );
$r11a_aff   = r11_partner( 'api-a' );
$r11a_b     = r11_partner( 'api-b' );
$r11a_sale  = wpac_record_sale( array( 'source' => 'test', 'order_id' => 'r11a-1', 'currency' => 'EUR', 'partner_code' => WPAffiliateCaptain\Codes\CodeRepository::main_code( $r11a_aff ), 'items' => array( array( 'item_id' => '1', 'product_id' => 10, 'name' => 'Kurs', 'net_minor' => 20000 ), array( 'item_id' => '2', 'product_id' => 11, 'name' => 'Kurs 2', 'net_minor' => 10000 ) ), 'customer' => array( 'user_id' => 0, 'email' => 'kunde-r11a@example.de' ) ) );
list( $r11a_c1, $r11a_c2 ) = array_map( 'intval', $r11a_sale['commission_ids'] );

/* Read: partners ------------------------------------------------------------------------------ */
$r11a_keys = array( 'id', 'user_id', 'group_id', 'status', 'status_reason', 'slug', 'name', 'email', 'website', 'channel', 'created_at', 'updated_at', 'approved_at', 'referred_by', 'program' ); // referred_by since 0.15.0, program since contract 1.5.0.
$r11a_a    = wpac_get_affiliate( $r11a_aff );
check( 'R11A wpac_get_affiliate: documented fields in this order, typed', array( $r11a_keys, $r11a_aff, 'active', 'r11-api-a@example.de', true ), array( array_keys( $r11a_a ), $r11a_a['id'], $r11a_a['status'], $r11a_a['email'], is_int( $r11a_a['group_id'] ) ) );
check( 'R11A wpac_get_affiliate: no bank, tax or application data', '', $r11a_secret( $r11a_a ) );
check( 'R11A wpac_get_affiliate: unknown ID → null (like wpac_get_payout)', array( null, null ), array( wpac_get_affiliate( 999999 ), wpac_get_affiliate( 0 ) ) );

$r11a_list = wpac_get_affiliates( array( 'search' => 'R11 api-', 'per_page' => 1, 'page' => 2, 'orderby' => 'id', 'order' => 'asc' ) );
check( 'R11A wpac_get_affiliates: shape, paging, order', array( array( 'items', 'total', 'page', 'per_page' ), 2, 2, 1, 1, $r11a_b ), array( array_keys( $r11a_list ), $r11a_list['total'], $r11a_list['page'], $r11a_list['per_page'], count( $r11a_list['items'] ), $r11a_list['items'][0]['id'] ) );
check( 'R11A wpac_get_affiliates: items have the single shape', $r11a_keys, array_keys( $r11a_list['items'][0] ) );
check(
	'R11A wpac_get_affiliates: invalid status, per_page > 100, page 0, orderby outside the whitelist, order, group',
	array( 'wpac_invalid_status', 'wpac_invalid_per_page', 'wpac_invalid_page', 'wpac_invalid_orderby', 'wpac_invalid_order', 'wpac_invalid_group' ),
	array( r11a_code( wpac_get_affiliates( array( 'status' => 'x' ) ) ), r11a_code( wpac_get_affiliates( array( 'per_page' => 101 ) ) ), r11a_code( wpac_get_affiliates( array( 'page' => 0 ) ) ), r11a_code( wpac_get_affiliates( array( 'orderby' => 'email; DROP' ) ) ), r11a_code( wpac_get_affiliates( array( 'order' => 'sideways' ) ) ), r11a_code( wpac_get_affiliates( array( 'group' => -1 ) ) ) )
);

/* Write: partner profile ---------------------------------------------------------------------- */
$r11a_u = wpac_update_affiliate( $r11a_aff, array( 'name' => 'R11 api-a Neu', 'website' => 'https://partner.example', 'channel' => 'Blog', 'group' => $r11a_group ) );
check( 'R11A wpac_update_affiliate: fields saved, partner returned', array( 'R11 api-a Neu', 'https://partner.example', 'Blog', $r11a_group ), array( $r11a_u['name'], $r11a_u['website'], $r11a_u['channel'], $r11a_u['group_id'] ) );
$r11a_au = r11a_audit( 'affiliate.updated', $r11a_aff );
check( 'R11A wpac_update_affiliate is audited with the current user', array( 7, true ), array( $r11a_au ? (int) $r11a_au->actor_id : 0, $r11a_au && false !== strpos( (string) $r11a_au->details, 'group_id' ) ) );
check(
	'R11A wpac_update_affiliate validation: other fields, unknown group, empty name, too long name, invalid URL, unknown partner',
	array( 'wpac_invalid_field', 'wpac_invalid_group', 'wpac_invalid_name', 'wpac_too_long_name', 'wpac_invalid_website', 'wpac_not_found' ),
	array( r11a_code( wpac_update_affiliate( $r11a_aff, array( 'email' => 'x@example.de' ) ) ), r11a_code( wpac_update_affiliate( $r11a_aff, array( 'group' => 999999 ) ) ), r11a_code( wpac_update_affiliate( $r11a_aff, array( 'name' => '  ' ) ) ), r11a_code( wpac_update_affiliate( $r11a_aff, array( 'name' => str_repeat( 'x', 191 ) ) ) ), r11a_code( wpac_update_affiliate( $r11a_aff, array( 'website' => 'javascript:alert(1)' ) ) ), r11a_code( wpac_update_affiliate( 999999, array( 'name' => 'x' ) ) ) )
);
check( 'R11A wpac_update_affiliate: a refused change leaves the data as they were', array( 'R11 api-a Neu', 'r11-api-a@example.de' ), array( AffiliateRepository::find( $r11a_aff )->name, AffiliateRepository::find( $r11a_aff )->email ) );
check( 'R11A wpac_get_affiliates filters by group', array( 1, $r11a_aff ), ( function () use ( $r11a_group ) { $l = wpac_get_affiliates( array( 'group' => $r11a_group ) ); return array( $l['total'], $l['items'][0]['id'] ?? 0 ); } )() );

/* Write: partner status ----------------------------------------------------------------------- */
check( 'R11A wpac_set_affiliate_status: reason required, invalid status', array( 'wpac_reason_required', 'wpac_invalid_status', 'wpac_not_found' ), array( r11a_code( wpac_set_affiliate_status( $r11a_b, 'paused', '' ) ), r11a_code( wpac_set_affiliate_status( $r11a_b, 'gone', 'x' ) ), r11a_code( wpac_set_affiliate_status( 999999, 'paused', 'x' ) ) ) );
$r11a_m = r11a_mark();
$r11a_s = wpac_set_affiliate_status( $r11a_b, 'paused', 'Prüfung' );
$r11a_as = r11a_audit( 'affiliate.status', $r11a_b );
check( 'R11A wpac_set_affiliate_status: paused with reason, audited, event fired', array( 'paused', 'Prüfung', 7, 1 ), array( $r11a_s['status'], $r11a_s['status_reason'], $r11a_as ? (int) $r11a_as->actor_id : 0, r11a_fired( 'wpac_affiliate_status_changed', $r11a_m )[0] ) );
check( 'R11A wpac_set_affiliate_status: back to active', 'active', wpac_set_affiliate_status( $r11a_b, 'active' )['status'] );

/* Read: commissions --------------------------------------------------------------------------- */
$r11a_ckeys = array( 'id', 'affiliate_id', 'kind', 'parent_id', 'reference', 'status', 'currency', 'amount_minor', 'base_minor', 'rate_type', 'rate', 'rule_id', 'source', 'order_id', 'item_id', 'product_ref', 'description', 'hold_until', 'payout_id', 'created_at', 'updated_at', 'program' ); // program since contract 1.5.0.
$r11a_c     = wpac_get_commission( $r11a_c1 );
check( 'R11A wpac_get_commission: documented fields, typed', array( $r11a_ckeys, $r11a_c1, $r11a_aff, 'sale', 'review', 'r11a-1', true ), array( array_keys( $r11a_c ), $r11a_c['id'], $r11a_c['affiliate_id'], $r11a_c['kind'], $r11a_c['status'], $r11a_c['order_id'], is_int( $r11a_c['amount_minor'] ) ) );
check( 'R11A wpac_get_commission: no customer data', '', $r11a_secret( $r11a_c ) );
check( 'R11A wpac_get_commission: unknown → null (like wpac_get_payout)', null, wpac_get_commission( 999999 ) );
$r11a_cl = wpac_get_commissions( array( 'affiliate_id' => $r11a_aff, 'kind' => 'sale', 'status' => 'review', 'from' => gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ), 'to' => gmdate( 'Y-m-d', time() + DAY_IN_SECONDS ), 'orderby' => 'amount_minor', 'order' => 'asc' ) );
check( 'R11A wpac_get_commissions: filters, order by amount', array( 2, array( $r11a_c2, $r11a_c1 ) ), array( $r11a_cl['total'], array_column( $r11a_cl['items'], 'id' ) ) );
check( 'R11A wpac_get_commissions: other kind gives nothing', 0, wpac_get_commissions( array( 'affiliate_id' => $r11a_aff, 'kind' => 'bonus' ) )['total'] );
check(
	'R11A wpac_get_commissions validation: status, kind, from, to, orderby',
	array( 'wpac_invalid_status', 'wpac_invalid_kind', 'wpac_invalid_from', 'wpac_invalid_to', 'wpac_invalid_orderby' ),
	array( r11a_code( wpac_get_commissions( array( 'status' => 'x' ) ) ), r11a_code( wpac_get_commissions( array( 'kind' => 'x' ) ) ), r11a_code( wpac_get_commissions( array( 'from' => '2026-02-30' ) ) ), r11a_code( wpac_get_commissions( array( 'to' => 'gestern' ) ) ), r11a_code( wpac_get_commissions( array( 'orderby' => 'description' ) ) ) )
);

/* Write: commission status -------------------------------------------------------------------- */
check( 'R11A wpac_set_commission_status: invalid status, reject without reason, unknown', array( 'wpac_invalid_status', 'wpac_reason_required', 'wpac_not_found' ), array( r11a_code( wpac_set_commission_status( $r11a_c1, 'paid' ) ), r11a_code( wpac_set_commission_status( $r11a_c1, 'rejected', '' ) ), r11a_code( wpac_set_commission_status( 999999, 'approved' ) ) ) );
// Under review and still in the hold period: approval moves it to "pending" (as in the admin).
$r11a_ap = wpac_set_commission_status( $r11a_c1, 'approved', 'Geprüft' );
$r11a_ca = r11a_audit( 'commission.status', $r11a_c1 );
check( 'R11A wpac_set_commission_status approved during the hold period: review → pending, audited with actor', array( 'pending', 7, true ), array( $r11a_ap['status'], $r11a_ca ? (int) $r11a_ca->actor_id : 0, $r11a_ca && false !== strpos( (string) $r11a_ca->details, '"to":"pending"' ) ) );
$r11a_m  = r11a_mark();
$r11a_ap = wpac_set_commission_status( $r11a_c1, 'approved', 'Früh freigegeben' );
$r11a_ca = r11a_audit( 'commission.status', $r11a_c1 );
check( 'R11A wpac_set_commission_status approved: ledger transition, event, audit', array( 'approved', 1, true ), array( $r11a_ap['status'], r11a_fired( 'wpac_commission_approved', $r11a_m )[0], $r11a_ca && false !== strpos( (string) $r11a_ca->details, '"to":"approved"' ) ) );
check( 'R11A wpac_set_commission_status: approving twice follows the ledger rules', 'wpac_state', r11a_code( wpac_set_commission_status( $r11a_c1, 'approved' ) ) );
$r11a_rj = wpac_set_commission_status( $r11a_c2, 'rejected', 'Storniert' );
check( 'R11A wpac_set_commission_status rejected (with reason)', array( 'rejected', 'Storniert' ), array( $r11a_rj['status'], (string) Ledger::events( $r11a_c2 )[ count( Ledger::events( $r11a_c2 ) ) - 1 ]->reason ) );

/* Write: manual booking ----------------------------------------------------------------------- */
check(
	'R11A wpac_create_adjustment validation: amount 0, float, reason, currency, unknown partner',
	array( 'wpac_amount', 'wpac_amount', 'wpac_reason_required', 'wpac_invalid_currency', 'wpac_not_found' ),
	array( r11a_code( wpac_create_adjustment( $r11a_aff, 0, 'EUR', 'x' ) ), r11a_code( wpac_create_adjustment( $r11a_aff, 12.5, 'EUR', 'x' ) ), r11a_code( wpac_create_adjustment( $r11a_aff, 500, 'EUR', ' ' ) ), r11a_code( wpac_create_adjustment( $r11a_aff, 500, 'EURO', 'x' ) ), r11a_code( wpac_create_adjustment( 999999, 500, 'EUR', 'x' ) ) )
);
$r11a_m   = r11a_mark();
$r11a_adj = wpac_create_adjustment( $r11a_aff, 1500, 'eur', 'Bonus Messe' );
$r11a_ma  = r11a_audit( 'commission.manual', $r11a_aff );
check( 'R11A wpac_create_adjustment: approved booking returned, audited, wpac_commission_created once after commit', array( 'adjustment', 'approved', 1500, 'EUR', 7, array( 1, true, array( $r11a_adj['id'] ) ) ), array( $r11a_adj['kind'], $r11a_adj['status'], $r11a_adj['amount_minor'], $r11a_adj['currency'], $r11a_ma ? (int) $r11a_ma->actor_id : 0, r11a_fired( 'wpac_commission_created', $r11a_m ) ) );

// Inside an outer transaction the event waits for the COMMIT; a rollback drops it.
$r11a_m      = r11a_mark();
$r11a_inside = -1;
Db::transaction(
	static function () use ( $r11a_aff, $r11a_m, &$r11a_inside ) {
		wpac_create_adjustment( $r11a_aff, 100, 'EUR', 'In Transaktion' );
		$r11a_inside = r11a_fired( 'wpac_commission_created', $r11a_m )[0];
	}
);
check( 'R11A wpac_commission_created waits for the COMMIT of an outer transaction', array( 0, 1, true ), array( $r11a_inside, r11a_fired( 'wpac_commission_created', $r11a_m )[0], r11a_fired( 'wpac_commission_created', $r11a_m )[1] ) );
$r11a_m = r11a_mark();
try {
	Db::transaction(
		static function () use ( $r11a_aff ) {
			wpac_create_adjustment( $r11a_aff, 100, 'EUR', 'Zurückgerollt' );
			throw new RuntimeException( 'rollback' );
		}
	);
} catch ( RuntimeException $e ) {
	unset( $e );
}
check( 'R11A a rolled-back booking fires nothing and leaves no row', array( 0, 0 ), array( r11a_fired( 'wpac_commission_created', $r11a_m )[0], (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . t( 'commissions' ) . " WHERE description = 'Zurückgerollt'" ) ) );

/* Events of the other ledger bookings --------------------------------------------------------- */
// Write-off and repayment of a negative balance.
$r11a_neg = r11_partner( 'api-neg' );
wpac_create_adjustment( $r11a_neg, -800, 'EUR', 'Rückforderung' );
$r11a_m  = r11a_mark();
$r11a_wo = Ledger::write_off( $r11a_neg, 'EUR', 'Uneinbringlich' );
check( 'R11A write-off fires wpac_commission_created once after the COMMIT', array( true, array( 1, true, array( is_wp_error( $r11a_wo ) ? 0 : (int) $r11a_wo ) ) ), array( is_int( $r11a_wo ), r11a_fired( 'wpac_commission_created', $r11a_m ) ) );
$r11a_rep = r11_partner( 'api-rep' );
wpac_create_adjustment( $r11a_rep, -800, 'EUR', 'Rückforderung' );
$r11a_m  = r11a_mark();
$r11a_rp = Dunning::record_repayment( $r11a_rep, 'EUR', 300, 'Überweisung' );
check( 'R11A repayment fires wpac_commission_created once after the COMMIT', array( true, array( 1, true, array( is_wp_error( $r11a_rp ) ? 0 : (int) $r11a_rp ) ) ), array( is_int( $r11a_rp ), r11a_fired( 'wpac_commission_created', $r11a_m ) ) );
check( 'R11A failed write-off (balance not negative) fires nothing', array( 'wpac_not_negative', 0 ), ( function () use ( $r11a_aff ) { $m = r11a_mark(); $r = Ledger::write_off( $r11a_aff, 'EUR', 'x' ); return array( r11a_code( $r ), r11a_fired( 'wpac_commission_created', $m )[0] ); } )() );

// Correction credit note of a paid payout.
$r11a_cp = r11_partner( 'api-kg' );
$r11a_pp = r11_payout( $r11a_cp, 'r11a-kg' );
PayoutService::mark_paid( $r11a_pp );
$r11a_m  = r11a_mark();
$r11a_kg = Corrections::create( $r11a_pp, (int) PayoutService::find( $r11a_pp )->net_minor + 1000, 'Satz korrigiert', 'r11a-kg-1' );
$r11a_kc = is_int( $r11a_kg ) ? (int) Corrections::find( $r11a_kg )->commission_id : 0;
check( 'R11A correction credit note fires wpac_commission_created (kind correction) and wpac_correction_generated after the COMMIT', array( true, array( 1, true, array( $r11a_kc ) ), 'correction', 1, true ), array( is_int( $r11a_kg ), r11a_fired( 'wpac_commission_created', $r11a_m ), wpac_get_commission( $r11a_kc )['kind'] ?? '', r11a_fired( 'wpac_correction_generated', $r11a_m )[0], r11a_fired( 'wpac_correction_generated', $r11a_m )[1] ) );
$r11a_m = r11a_mark();
Corrections::create( $r11a_pp, (int) PayoutService::find( $r11a_pp )->net_minor + 1000, 'Satz korrigiert', 'r11a-kg-1' );
check( 'R11A correction submitted twice: no second event', 0, r11a_fired( 'wpac_commission_created', $r11a_m )[0] );

// Reinstatement of a chargeback that was already settled in a paid payout.
$r11a_rip = r11_partner( 'api-ri' );
foreach ( array( 'r11a-ri' => 10000, 'r11a-ri2' => 150000 ) as $r11a_o => $r11a_net ) {
	$r11a_ri = wpac_record_sale( array( 'source' => 'test', 'order_id' => $r11a_o, 'currency' => 'EUR', 'partner_code' => WPAffiliateCaptain\Codes\CodeRepository::main_code( $r11a_rip ), 'items' => array( array( 'item_id' => '1', 'product_id' => 10, 'name' => 'Kurs', 'net_minor' => $r11a_net ) ), 'customer' => array( 'user_id' => 0, 'email' => 'kunde-' . $r11a_o . '@example.de' ) ) );
	Ledger::transition( (int) $r11a_ri['commission_ids'][0], 'approved' );
}
wpac_record_refund( array( 'source' => 'test', 'order_id' => 'r11a-ri', 'refund_id' => 'cb-1', 'type' => 'chargeback', 'full' => true, 'reason' => 'Chargeback' ) );
PayoutService::mark_paid( (int) PayoutService::create_for_affiliate( $r11a_rip, gmdate( 'Y-m-d' ), 'EUR' ) );
$r11a_m = r11a_mark();
$r11a_n = Ledger::reinstate( 'test', 'r11a-ri', 'Chargeback gewonnen' );
$r11a_rc = (int) $wpdb->get_var( 'SELECT id FROM ' . t( 'commissions' ) . " WHERE source_order_id = 'r11a-ri' AND kind = 'reinstatement'" );
check( 'R11A reinstatement fires wpac_commission_created once after the COMMIT', array( 1, array( 1, true, array( $r11a_rc ) ) ), array( $r11a_n, r11a_fired( 'wpac_commission_created', $r11a_m ) ) );

// Partner mails: settlements and corrections are no "new commission".
$r11a_n_ev = new ReflectionProperty( WPAffiliateCaptain\Affiliates\Notifications::class, 'commission_events' );
$r11a_n_ev->setAccessible( true );
$r11a_n_ev->setValue( null, array() );
$r11a_set = get_option( 'wpac_settings' );
update_option( 'wpac_settings', array_merge( $r11a_set, array( 'notify_commissions' => 1 ) ) );
WPAffiliateCaptain\Core\Settings::flush_cache();
foreach ( array( (int) $r11a_wo, (int) $r11a_rp, $r11a_kc, $r11a_rc ) as $r11a_id ) {
	WPAffiliateCaptain\Affiliates\Notifications::commission_created( $r11a_id );
}
$r11a_queued = $r11a_n_ev->getValue();
WPAffiliateCaptain\Affiliates\Notifications::commission_created( (int) $r11a_adj['id'] );
$r11a_queued2 = $r11a_n_ev->getValue();
$r11a_n_ev->setValue( null, array() );
update_option( 'wpac_settings', $r11a_set );
WPAffiliateCaptain\Core\Settings::flush_cache();
check( 'R11A no "new commission" mail for write-off, repayment, correction, reinstatement; manual booking still announced', array( array(), 1 ), array( $r11a_queued, count( $r11a_queued2[ $r11a_aff ]['created'] ?? array() ) ) );

/* wpac_payout_cancelled ----------------------------------------------------------------------- */
$r11a_cx = r11_partner( 'api-cx' );
$r11a_p  = r11_payout( $r11a_cx, 'r11a-cx-1' );
$r11a_m  = r11a_mark();
check( 'R11A failed cancellation (no reason) fires nothing', array( 'wpac_reason_required', 0 ), array( r11a_code( PayoutService::cancel( $r11a_p, '' ) ), r11a_fired( 'wpac_payout_cancelled', $r11a_m )[0] ) );
$r11a_ok = PayoutService::cancel( $r11a_p, 'Falscher Zeitraum' );
check( 'R11A wpac_payout_cancelled fires once after the COMMIT with the payout ID', array( true, array( 1, true, array( $r11a_p ) ) ), array( $r11a_ok, r11a_fired( 'wpac_payout_cancelled', $r11a_m ) ) );

// A claimed payout (payment provider) cancelled after the waiting time.
$r11a_p2 = r11_payout( $r11a_cx, 'r11a-cx-2' );
$r11a_cl2 = wpac_claim_payout( $r11a_p2, 'paypal' );
$wpdb->query( $wpdb->prepare( 'UPDATE ' . t( 'payouts' ) . ' SET exported_at = %s WHERE id = %d', gmdate( 'Y-m-d H:i:s', time() - 46 * DAY_IN_SECONDS ), $r11a_p2 ) );
$r11a_m  = r11a_mark();
$r11a_ok = PayoutService::cancel( $r11a_p2, 'Dienst hat nicht gezahlt', true );
check( 'R11A wpac_payout_cancelled also for a claimed payout', array( true, true, array( 1, true, array( $r11a_p2 ) ) ), array( is_array( $r11a_cl2 ), $r11a_ok, r11a_fired( 'wpac_payout_cancelled', $r11a_m ) ) );

// Rebuild: the cancellation of the old payout fires once the rebuild is committed.
$r11a_p3 = r11_payout( $r11a_cx, 'r11a-cx-3' );
$r11a_m  = r11a_mark();
$r11a_nw = PayoutService::rebuild( $r11a_p3 );
check( 'R11A rebuild: wpac_payout_cancelled for the old payout after the COMMIT', array( true, array( 1, true, array( $r11a_p3 ) ) ), array( is_int( $r11a_nw ) && $r11a_nw > 0, r11a_fired( 'wpac_payout_cancelled', $r11a_m ) ) );

/* Mail sender name ---------------------------------------------------------------------------- */
$GLOBALS['test_hooks']['wpac_mail_from_name'][10][] = array( static function ( $name, $type ) { return 'payout_paid' === $type ? "Marke\r\nBcc: x@example.de" : $name; }, 2 );
$r11a_hdr = array();
$GLOBALS['test_hooks']['wpac_mail'][99][] = array( static function ( $mail ) use ( &$r11a_hdr ) { $r11a_hdr = $mail['headers']; return false; }, 1 );
$r11a_set = get_option( 'wpac_settings' );
update_option( 'wpac_settings', array_merge( $r11a_set, array( 'mail_from_email' => 'partner@example.de' ) ) );
WPAffiliateCaptain\Core\Settings::flush_cache();
WPAffiliateCaptain\Affiliates\Notifications::send( 'payout_paid', 'p@example.de', 'Betreff', 'Text' );
$r11a_from = implode( "\n", array_filter( (array) $r11a_hdr, static function ( $h ) { return 0 === strpos( (string) $h, 'From:' ); } ) );
update_option( 'wpac_settings', $r11a_set );
WPAffiliateCaptain\Core\Settings::flush_cache();
remove_all_filters( 'wpac_mail_from_name' );
unset( $GLOBALS['test_hooks']['wpac_mail'][99] );
check( 'R11A wpac_mail_from_name sets the sender name (header-safe: no line breaks)', 'From: Marke Bcc: x@example.de <partner@example.de>', $r11a_from );

$GLOBALS['test_user'] = $r11a_user;
