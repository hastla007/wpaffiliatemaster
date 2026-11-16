<?php
/**
 * Package L3 (0.15.x): Workflow Captain provider contract AFF-WF-01..06.
 * Normalised post-commit events wpac_workflow_event( $envelope ) with stable IDs and redacted
 * payload, read actions wpac_workflow_read(), contract fixtures (replay, sale/refund ordering,
 * multisite, erasure, missing listener, redaction, rollback).
 * Included from run.php before r8-render.php (helpers check(), t(), q(), sale(), refund()).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Codes\CodeRepository;
use WPAffiliateCaptain\Codes\CodeRequests;
use WPAffiliateCaptain\Commissions\Ledger;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\Jobs;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Core\WorkflowEvents;
use WPAffiliateCaptain\Payouts\Corrections;
use WPAffiliateCaptain\Payouts\PayoutService;
use WPAffiliateCaptain\Payouts\ProfileRepository;
use WPAffiliateCaptain\Privacy\Privacy;
use WPAffiliateCaptain\Promotion\Creatives;

echo "R15 L3 Workflow Captain provider contract (AFF-WF-01..06)\n";
global $wpdb;

$l3_keep = get_option( 'wpac_settings' );
update_option( 'wpac_settings', array_merge( $l3_keep, array( 'min_payout_minor' => 5000, 'self_billing' => 1, 'vat_rate' => '19', 'operator_country' => 'DE', 'operator_address' => "Hafen 1\n20457 Hamburg", 'operator_vat_id' => 'DE999999999', 'debtor_name' => 'Shop GmbH', 'terminated_open' => 'pay', 'vies_check' => 0, 'maintenance_mode' => 0 ) ) );
Settings::flush_cache();
unset( $GLOBALS['test_caps'] );
$GLOBALS['test_user'] = 1;

$l3_partner = static function ( $slug ) use ( $wpdb ) {
	$id = (int) AffiliateRepository::create( array( 'name' => 'L3 Erika ' . $slug, 'email' => 'l3-' . $slug . '@example.de', 'status' => 'active' ) );
	ProfileRepository::save( $id, array( 'legal_name' => 'L3 Erika ' . $slug, 'address' => "Seestraße 5\n24103 Kiel", 'country' => 'DE', 'account_holder' => 'L3 Erika ' . $slug, 'iban' => 'DE89370400440532013000', 'tax_status' => 'business', 'vat_id' => 'DE123456789' ) );
	$wpdb->query( 'UPDATE ' . t( 'payout_profiles' ) . " SET updated_at = '2020-01-01 00:00:00' WHERE affiliate_id = " . (int) $id );
	if ( ! CodeRepository::main_code( $id ) ) {
		CodeRepository::create( $id, CodeRepository::random_code() );
	}
	return $id;
};
$l3_sale = static function ( $aff, $order, $net, $email = 'l3-kaeufer@example.de' ) {
	return wpac_record_sale( array( 'source' => 'test', 'order_id' => (string) $order, 'currency' => 'EUR', 'partner_code' => CodeRepository::main_code( $aff ), 'items' => array( array( 'item_id' => '1', 'product_id' => 10, 'name' => 'Kurs', 'net_minor' => $net ) ), 'customer' => array( 'user_id' => 0, 'email' => $email, 'name' => 'Käufer Kunz', 'address' => 'Käuferweg 9', 'postcode' => '10115' ) ) );
};

// Listener of the workflow engine: records every envelope and whether a transaction was running.
$GLOBALS['l3_events'] = array();
WorkflowEvents::register_hooks();
$l3_listener = static function ( $envelope ) {
	$GLOBALS['l3_events'][] = array( $envelope, Db::in_transaction() );
};
add_action( 'wpac_workflow_event', $l3_listener, 10, 1 );
$l3_types = static function () {
	return array_map( static function ( $e ) { return $e[0]['type']; }, $GLOBALS['l3_events'] );
};
$l3_of = static function ( $type ) {
	return array_values( array_filter( array_map( static function ( $e ) { return $e[0]; }, $GLOBALS['l3_events'] ), static function ( $e ) use ( $type ) { return $e['type'] === $type; } ) );
};
$l3_all = array(); // Every envelope of the run, for the redaction checks.
$l3_keep_events = static function () use ( &$l3_all ) {
	foreach ( $GLOBALS['l3_events'] as $e ) {
		$l3_all[] = $e[0];
	}
	$GLOBALS['l3_events'] = array();
};

/* ---------------------------------------------------------------------------------------------
 * WF-02: envelope of a sale (attribution + commission), after COMMIT, stable fields.
 * ------------------------------------------------------------------------------------------- */
$l3_a = $l3_partner( 'a' );
check( 'AFF-WF-02 partner created: affiliate.created + affiliate.status? (created only)', array( 'affiliate.created' ), $l3_types() );
$l3_keep_events();
$l3_r   = $l3_sale( $l3_a, 'l3-1', 50000 );
$l3_cid = (int) $l3_r['commission_ids'][0];
check( 'AFF-WF-02 sale: sale.attributed then commission.created', array( 'sale.attributed', 'commission.created' ), $l3_types() );
check( 'AFF-WF-02 every event fired outside a transaction', array( false, false ), array_map( static function ( $e ) { return $e[1]; }, $GLOBALS['l3_events'] ) );
$l3_env = $l3_of( 'commission.created' )[0];
check( 'AFF-WF-02 envelope fields: id, type, version, occurred_at, site_id, source, object, data', array( 'id', 'type', 'version', 'occurred_at', 'site_id', 'source', 'object', 'data' ), array_keys( $l3_env ) );
check(
	'AFF-WF-02 envelope values: UUID v5, version 1, UTC time, object commission, site 1',
	array( 1, '1', 1, array( 'type' => 'commission', 'id' => (string) $l3_cid ), 1, 'affiliate-captain' ),
	array( preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $l3_env['id'] ), $l3_env['version'], preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $l3_env['occurred_at'] ), $l3_env['object'], $l3_env['site_id'], $l3_env['source'] )
);
check(
	'AFF-WF-02 commission.created data (whitelist)',
	array( 'commission_id' => $l3_cid, 'affiliate_id' => $l3_a, 'kind' => 'sale', 'currency' => 'EUR', 'source' => 'test', 'order_id' => 'l3-1', 'parent_id' => 0 ),
	array_intersect_key( $l3_env['data'], array_flip( array( 'commission_id', 'affiliate_id', 'kind', 'currency', 'source', 'order_id', 'parent_id' ) ) )
);
check( 'AFF-WF-02 sale.attributed data: source, order, partner, method only', array( 'source' => 'test', 'order_id' => 'l3-1', 'affiliate_id' => $l3_a, 'method' => 'code' ), $l3_of( 'sale.attributed' )[0]['data'] );
check( 'AFF-WF-01 event types published (wpac_workflow_event_types)', array( true, true ), array( isset( wpac_workflow_event_types()['commission.created'] ), isset( wpac_workflow_event_types()['affiliate.erased'] ) ) );
$l3_keep_events();

/* WF-04: a workflow listener that reports the sale again never books a second commission. ---- */
$l3_count = static function () use ( $wpdb ) {
	return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . t( 'commissions' ) );
};
$l3_dup   = null;
$l3_echo  = static function ( $env ) use ( &$l3_dup, $l3_a, $l3_sale ) {
	if ( 'sale.attributed' === $env['type'] && 'l3-2' === $env['data']['order_id'] ) {
		$l3_dup = $l3_sale( $l3_a, 'l3-2', 30000 ); // Re-report from the event.
	}
};
add_action( 'wpac_workflow_event', $l3_echo, 20, 1 );
$l3_before = $l3_count();
$l3_sale( $l3_a, 'l3-2', 30000 );
check( 'AFF-WF-04 sale reported again from a workflow event: duplicate, exactly one commission booked', array( 'duplicate', $l3_before + 1 ), array( is_array( $l3_dup ) ? $l3_dup['status'] : 'error', $l3_count() ) );
$GLOBALS['test_hooks']['wpac_workflow_event'][20] = array();
$l3_keep_events();

/* ---------------------------------------------------------------------------------------------
 * WF-06 replay: the same source event twice → the same envelope ID; another revision → another ID.
 * ------------------------------------------------------------------------------------------- */
do_action( 'wpac_commission_created', $l3_cid );
do_action( 'wpac_commission_created', $l3_cid );
$l3_rep = $l3_of( 'commission.created' );
check( 'AFF-WF-06 replay: same envelope id for the same event (dedupe key), also = the original', array( true, $l3_env['id'] ), array( 2 === count( $l3_rep ) && $l3_rep[0]['id'] === $l3_rep[1]['id'], $l3_rep[0]['id'] ) );
$l3_keep_events();
Ledger::transition( $l3_cid, 'approved' );
$l3_st = $l3_of( 'commission.status_changed' );
check( 'AFF-WF-02 status change: commission.status_changed with status approved, new ID', array( 1, 'approved', true ), array( count( $l3_st ), $l3_st[0]['data']['status'], $l3_st[0]['id'] !== $l3_env['id'] ) );
do_action( 'wpac_commission_approved', $l3_cid );
check( 'AFF-WF-06 replay of the status hook: same ID', $l3_st[0]['id'], $l3_of( 'commission.status_changed' )[1]['id'] );
$l3_keep_events();

/* ---------------------------------------------------------------------------------------------
 * WF-02 post-commit guarantee: transition() inside an outer transaction (it nests) fired its
 * status hook inside that transaction; now it waits for the COMMIT and is dropped on rollback.
 * ------------------------------------------------------------------------------------------- */
$l3_c2  = (int) $l3_sale( $l3_a, 'l3-3', 20000 )['commission_ids'][0];
$l3_keep_events();
$GLOBALS['test_fired'] = array();
$l3_mid = -1;
Db::transaction(
	static function () use ( $l3_c2, &$l3_mid ) {
		Ledger::transition( $l3_c2, 'approved' );
		$l3_mid = count( $GLOBALS['l3_events'] );
	}
);
check( 'AFF-WF-02 transition inside an outer transaction: hook and event only after COMMIT', array( 0, true, array( 'commission.status_changed' ) ), array( $l3_mid, hook_fired_outside_tx( 'wpac_commission_approved' ), $l3_types() ) );
$l3_keep_events();
$l3_c3 = (int) $l3_sale( $l3_a, 'l3-4', 20000 )['commission_ids'][0];
$l3_keep_events();
$GLOBALS['test_fired'] = array();
$l3_before = $l3_count();
try {
	Db::transaction(
		static function () use ( $l3_a, $l3_c3 ) {
			Ledger::transition( $l3_c3, 'approved' );
			wpac_create_adjustment( $l3_a, 1200, 'EUR', 'Rollback-Test' );
			throw new \RuntimeException( 'rollback' );
		}
	);
} catch ( \RuntimeException $e ) {
	unset( $e );
}
check( 'AFF-WF-06 rollback: no workflow event, no source hook, nothing booked', array( array(), false, false, $l3_before, 'pending' ), array( $l3_types(), hook_fired_outside_tx( 'wpac_commission_approved' ), hook_fired_outside_tx( 'wpac_commission_created' ), $l3_count(), in_array( (string) Ledger::find( $l3_c3 )->status, array( 'pending', 'review' ), true ) ? 'pending' : (string) Ledger::find( $l3_c3 )->status ) );
// Payout paid inside an outer transaction: paid hooks wait for the COMMIT as well.
$l3_p0 = PayoutService::create_for_affiliate( $l3_a, gmdate( 'Y-m-d' ), 'EUR' );
$l3_keep_events();
$GLOBALS['test_fired'] = array();
$l3_mid = -1;
Db::transaction(
	static function () use ( $l3_p0, &$l3_mid ) {
		PayoutService::mark_paid( $l3_p0 );
		$l3_mid = count( $GLOBALS['l3_events'] );
	}
);
check( 'AFF-WF-02 mark_paid inside an outer transaction: wpac_payout_paid/commission_paid after COMMIT', array( true, 0, true, true ), array( $l3_p0 > 0, $l3_mid, hook_fired_outside_tx( 'wpac_payout_paid' ), hook_fired_outside_tx( 'wpac_commission_paid' ) ) );
check( 'AFF-WF-02 payout.paid event (no PayPal address, no claim)', array( 'payout_id', 'affiliate_id', 'number', 'status', 'currency', 'amount_minor', 'method' ), array_keys( $l3_of( 'payout.paid' )[0]['data'] ) );
$l3_keep_events();

/* ---------------------------------------------------------------------------------------------
 * WF-06 parallel sale and refund: the refund arrives first (parked), the sale books, the queue
 * applies the refund. The events keep the booking order: attribution, commission, then the
 * adjustment that refers to it.
 * ------------------------------------------------------------------------------------------- */
$l3_pr = wpac_record_refund( array( 'source' => 'l3src', 'order_id' => 'par-1', 'refund_id' => 'r1', 'items' => array( array( 'item_id' => '1', 'net_minor' => 2000 ) ), 'reason' => 'Teilerstattung Käufer Kunz' ) );
check( 'AFF-WF-06 refund before its sale: parked, no event yet', array( 'parked', array() ), array( is_array( $l3_pr ) ? $l3_pr['status'] : 'error', $l3_types() ) );
wpac_record_sale( array( 'source' => 'l3src', 'order_id' => 'par-1', 'currency' => 'EUR', 'partner_code' => CodeRepository::main_code( $l3_a ), 'netted_refund_ids' => array(), 'items' => array( array( 'item_id' => '1', 'net_minor' => 10000 ) ), 'customer' => array( 'user_id' => 0, 'email' => 'l3-par@example.de' ) ) );
$wpdb->query( 'UPDATE ' . t( 'jobs' ) . " SET next_attempt_at = '2000-01-01 00:00:00' WHERE idempotency_key LIKE '%:par-1:%'" );
Jobs::run_due();
$l3_ty = $l3_types();
check( 'AFF-WF-06 parallel sale/refund: attributed < created < adjusted', array( 'sale.attributed', 'commission.created', 'commission.adjusted' ), array_values( array_intersect( $l3_ty, array( 'sale.attributed', 'commission.created', 'commission.adjusted' ) ) ) );
$l3_adj = $l3_of( 'commission.adjusted' )[0];
check( 'AFF-WF-06 commission.adjusted: refund object, partner, no reason text', array( 'refund', 'l3src:par-1:r1', array( $l3_a ), false ), array( $l3_adj['object']['type'], $l3_adj['object']['id'], $l3_adj['data']['affiliate_ids'], isset( $l3_adj['data']['reason'] ) ) );
// The same refund again: unchanged, no second event (nothing booked).
$l3_keep_events();
wpac_record_refund( array( 'source' => 'l3src', 'order_id' => 'par-1', 'refund_id' => 'r1', 'items' => array( array( 'item_id' => '1', 'net_minor' => 2000 ) ) ) );
check( 'AFF-WF-06 refund reported again: no event', array(), $l3_types() );

/* ---------------------------------------------------------------------------------------------
 * Partner status, recruitment, creatives, code requests, payouts, correction credit note.
 * ------------------------------------------------------------------------------------------- */
AffiliateRepository::set_status( $l3_a, 'paused', 'Prüfung' );
AffiliateRepository::set_status( $l3_a, 'active' );
$l3_sc = $l3_of( 'affiliate.status_changed' );
check( 'AFF-WF-02 affiliate.status_changed twice, different IDs, no reason text', array( 2, 'paused', 'active', 'active', true, false ), array( count( $l3_sc ), $l3_sc[0]['data']['status'], $l3_sc[0]['data']['previous_status'], $l3_sc[1]['data']['status'], $l3_sc[0]['id'] !== $l3_sc[1]['id'], isset( $l3_sc[0]['data']['reason'] ) ) );
$l3_keep_events();
do_action( 'wpac_affiliate_recruited', $l3_a, $l3_a + 1000 );
check( 'AFF-WF-02 affiliate.recruited', array( 'affiliate_id' => $l3_a, 'recruiter_id' => $l3_a + 1000 ), $l3_of( 'affiliate.recruited' )[0]['data'] );
$l3_cr_data = array( 'title' => 'L3 Banner', 'type' => 'text', 'content' => 'Jetzt buchen: {link}', 'attachment_id' => 0, 'landing_url' => '', 'disclosure' => '', 'group_id' => 0 );
$l3_cr = Creatives::save_draft( $l3_cr_data, 0, 'neu' );
Creatives::approve( $l3_cr );
check( 'AFF-WF-02 creative.created (draft) and creative.updated (approved)', array( 'draft', 'approved' ), array( $l3_of( 'creative.created' )[0]['data']['status'], $l3_of( 'creative.updated' )[0]['data']['status'] ) );
$l3_b  = $l3_partner( 'b' );
$l3_rq = CodeRequests::request( AffiliateRepository::find( $l3_b ), 'l3wunsch' );
check( 'AFF-WF-02 code_request.created', array( true, (int) $l3_rq, $l3_b ), array( is_int( $l3_rq ), $l3_of( 'code_request.created' )[0]['data']['code_request_id'], $l3_of( 'code_request.created' )[0]['data']['affiliate_id'] ) );
CodeRequests::reject( (int) $l3_rq, 'Name einer Person' );
$l3_rj = $l3_of( 'code_request.rejected' );
check( 'AFF-WF-02 code_request.rejected: same object as the request (row ID passed on), no reason', array( 1, (string) $l3_rq, false ), array( count( $l3_rj ), $l3_rj ? $l3_rj[0]['object']['id'] : '', $l3_rj && isset( $l3_rj[0]['data']['reason'] ) ) );
do_action( 'wpac_code_request_approved', $l3_b, 'L3OK', 777, true );
check( 'AFF-WF-02 code_request.approved with main flag', array( 'code_request_id' => 777, 'affiliate_id' => $l3_b, 'code' => 'L3OK', 'main' => true ), $l3_of( 'code_request.approved' )[0]['data'] );
// Payout created, paid; correction credit note; cancelled payout.
$l3_c4 = (int) $l3_sale( $l3_b, 'l3-b1', 100000 )['commission_ids'][0];
Ledger::transition( $l3_c4, 'approved' );
$l3_p1 = PayoutService::create_for_affiliate( $l3_b, gmdate( 'Y-m-d' ), 'EUR' );
check( 'AFF-WF-02 payout.created', array( $l3_p1, $l3_b ), array( $l3_of( 'payout.created' )[0]['data']['payout_id'], $l3_of( 'payout.created' )[0]['data']['affiliate_id'] ) );
PayoutService::mark_paid( $l3_p1 );
$l3_k = Corrections::create( $l3_p1, 12000, 'Provisionssatz korrigiert', 'l3-korr', 10000 );
$l3_ce = $l3_of( 'correction.generated' );
check( 'AFF-WF-02 correction.generated: payout and partner, no path, no reason', array( true, 1, $l3_p1, $l3_b, false, false ), array( is_int( $l3_k ), count( $l3_ce ), $l3_ce ? $l3_ce[0]['data']['payout_id'] : 0, $l3_ce ? $l3_ce[0]['data']['affiliate_id'] : 0, $l3_ce && isset( $l3_ce[0]['data']['path'] ), $l3_ce && isset( $l3_ce[0]['data']['reason'] ) ) );
$l3_c5 = (int) $l3_sale( $l3_b, 'l3-b2', 100000 )['commission_ids'][0];
Ledger::transition( $l3_c5, 'approved' );
$l3_p2 = PayoutService::create_for_affiliate( $l3_b, gmdate( 'Y-m-d' ), 'EUR' );
PayoutService::cancel( $l3_p2, 'Test' );
check( 'AFF-WF-02 payout.cancelled', array( $l3_p2, 'cancelled' ), array( $l3_of( 'payout.cancelled' ) ? $l3_of( 'payout.cancelled' )[0]['data']['payout_id'] : 0, $l3_of( 'payout.cancelled' ) ? $l3_of( 'payout.cancelled' )[0]['data']['status'] : '' ) );
wpac_create_adjustment( $l3_b, -500, 'EUR', 'Abzug wegen Rückfrage', array( 'reference' => 'team:7' ) );
$l3_ma = array_values( array_filter( $l3_of( 'commission.created' ), static function ( $e ) { return 'adjustment' === $e['data']['kind']; } ) );
check( 'AFF-WF-02 manual booking: commission.created without reason/reference', array( 1, -500, false, false ), array( count( $l3_ma ), $l3_ma ? $l3_ma[0]['data']['amount_minor'] : 0, $l3_ma && isset( $l3_ma[0]['data']['reason'] ), $l3_ma && isset( $l3_ma[0]['data']['reference'] ) ) );
$l3_keep_events();

/* ---------------------------------------------------------------------------------------------
 * WF-06 multisite: events per site, the site ID is part of the envelope and of the event ID.
 * ------------------------------------------------------------------------------------------- */
$GLOBALS['test_multisite'] = true;
$GLOBALS['test_blog_id']   = 2;
do_action( 'wpac_commission_created', $l3_cid );
$GLOBALS['test_blog_id']   = 1;
do_action( 'wpac_commission_created', $l3_cid );
unset( $GLOBALS['test_multisite'], $GLOBALS['test_blog_id'] );
$l3_ms = $l3_of( 'commission.created' );
check( 'AFF-WF-06 multisite: site_id 2 and 1, different IDs; site 1 = the original ID', array( 2, 1, true, $l3_env['id'] ), array( $l3_ms[0]['site_id'], $l3_ms[1]['site_id'], $l3_ms[0]['id'] !== $l3_ms[1]['id'], $l3_ms[1]['id'] ) );
$l3_keep_events();

/* ---------------------------------------------------------------------------------------------
 * WF-02 filter wpac_workflow_event_data can only remove fields.
 * ------------------------------------------------------------------------------------------- */
$l3_filter = static function ( $data, $type ) {
	unset( $data['order_id'] );
	$data['amount_minor'] = 1;            // Changed value: ignored.
	$data['email']        = 'x@example.de'; // New key: ignored.
	return $data;
};
add_filter( 'wpac_workflow_event_data', $l3_filter, 10, 2 );
do_action( 'wpac_commission_created', $l3_cid );
$GLOBALS['test_hooks']['wpac_workflow_event_data'] = array();
$l3_fd = $l3_of( 'commission.created' )[0]['data'];
check( 'AFF-WF-02 data filter: removed field gone, changed value and new key ignored', array( false, (int) Ledger::find( $l3_cid )->amount_minor, false ), array( isset( $l3_fd['order_id'] ), $l3_fd['amount_minor'], isset( $l3_fd['email'] ) ) );
$l3_keep_events();

/* ---------------------------------------------------------------------------------------------
 * WF-06 privacy erasure: affiliate.erased carries only the ID (and whether part was deferred).
 * ------------------------------------------------------------------------------------------- */
$l3_e = $l3_partner( 'erase' );
$l3_keep_events();
Privacy::erase_partner( AffiliateRepository::find( $l3_e ) );
$l3_er = $l3_of( 'affiliate.erased' );
check( 'AFF-WF-06 erasure: affiliate.erased with affiliate_id and deferred only, nothing personal', array( 1, array( 'affiliate_id', 'deferred' ), false, false ), array( count( $l3_er ), $l3_er ? array_keys( $l3_er[0]['data'] ) : array(), $l3_er && false !== strpos( wp_json_encode( $l3_er[0] ), 'l3-erase@example.de' ), $l3_er && false !== strpos( wp_json_encode( $l3_er[0] ), 'Erika erase' ) ) );
$l3_keep_events();

/* ---------------------------------------------------------------------------------------------
 * WF-06 redaction for every type: only whitelisted fields, no forbidden key at any depth, no
 * buyer e-mail/name/address, partner e-mail/name, IBAN, VAT ID or reason texts in any envelope.
 * ------------------------------------------------------------------------------------------- */
$l3_seen = array();
$l3_bad  = array();
$l3_walk = static function ( $value, $path ) use ( &$l3_walk, &$l3_bad ) {
	foreach ( (array) $value as $k => $v ) {
		if ( is_string( $k ) && in_array( strtolower( $k ), WorkflowEvents::FORBIDDEN, true ) ) {
			$l3_bad[] = $path . '.' . $k;
		}
		if ( is_array( $v ) ) {
			$l3_walk( $v, $path . '.' . $k );
		}
	}
};
$l3_needles = array( 'l3-kaeufer@example.de', 'Käufer Kunz', 'Käuferweg', '10115', 'l3-a@example.de', 'L3 Erika', 'DE89370400440532013000', 'DE123456789', 'Seestraße', 'Teilerstattung', 'Prüfung', 'Name einer Person', 'Provisionssatz', 'Abzug wegen', 'team:7', 'l3-par@example.de' );
foreach ( $l3_all as $env ) {
	$l3_seen[ $env['type'] ] = true;
	if ( array_diff( array_keys( $env['data'] ), WorkflowEvents::FIELDS[ $env['type'] ] ) ) {
		$l3_bad[] = $env['type'] . ': ' . implode( ',', array_diff( array_keys( $env['data'] ), WorkflowEvents::FIELDS[ $env['type'] ] ) );
	}
	$l3_walk( $env, $env['type'] );
	$json = wp_json_encode( $env, JSON_UNESCAPED_UNICODE );
	foreach ( $l3_needles as $n ) {
		if ( false !== strpos( $json, $n ) ) {
			$l3_bad[] = $env['type'] . ' contains ' . $n;
		}
	}
}
check( 'AFF-WF-06 redaction: every event type was emitted in this run', array(), array_values( array_diff( array_keys( WorkflowEvents::FIELDS ), array_keys( $l3_seen ) ) ) );
check( 'AFF-WF-06 redaction: no forbidden key, no personal data, only whitelisted fields (all types)', array(), $l3_bad );

/* ---------------------------------------------------------------------------------------------
 * WF-06 missing listener: nothing is built (off before has_action), bookings unchanged. Off-switch.
 * A failing listener never affects the booking.
 * ------------------------------------------------------------------------------------------- */
$l3_hooks_keep = $GLOBALS['test_hooks']['wpac_workflow_event'];
unset( $GLOBALS['test_hooks']['wpac_workflow_event'] );
$l3_probe = 0;
add_filter( 'wpac_workflow_events_enabled', static function ( $on ) use ( &$l3_probe ) { ++$l3_probe; return $on; } );
$l3_before = $l3_count();
$l3_nr     = $l3_sale( $l3_a, 'l3-nolistener', 40000 );
check( 'AFF-WF-06 no listener: sale booked as usual, no envelope built (off-switch filter never asked)', array( 'created', $l3_before + 1, 0, array() ), array( $l3_nr['status'], $l3_count(), $l3_probe, $GLOBALS['l3_events'] ) );
$GLOBALS['test_hooks']['wpac_workflow_event'] = $l3_hooks_keep;
add_filter( 'wpac_workflow_events_enabled', static function () { return false; } );
$l3_sale( $l3_a, 'l3-off', 40000 );
check( 'AFF-WF-02 off-switch wpac_workflow_events_enabled false: no events', array(), $l3_types() );
unset( $GLOBALS['test_hooks']['wpac_workflow_events_enabled'] );
add_action( 'wpac_workflow_event', static function () { throw new \RuntimeException( 'workflow down' ); }, 5, 1 );
$l3_before = $l3_count();
$l3_fr     = wpac_create_adjustment( $l3_a, 300, 'EUR', 'Bonus' );
check( 'AFF-WF-05 failing workflow listener: booking succeeds, ledger unchanged otherwise', array( true, $l3_before + 1 ), array( is_array( $l3_fr ) && 'approved' === $l3_fr['status'], $l3_count() ) );
unset( $GLOBALS['test_hooks']['wpac_workflow_event'][5] );
$l3_keep_events();

/* ---------------------------------------------------------------------------------------------
 * WF-03 read actions: rights, validation, figures, no writes.
 * ------------------------------------------------------------------------------------------- */
$l3_err = static function ( $r ) {
	return is_wp_error( $r ) ? $r->get_error_code() : 'ok';
};
$l3_writes = static function () use ( $wpdb ) {
	return array( (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . t( 'commissions' ) ), (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . t( 'audit' ) ), (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . t( 'commission_events' ) ) );
};
$l3_w0 = $l3_writes();
$l3_ps = wpac_workflow_read( 'partner.status', array( 'affiliate_id' => $l3_a ) );
check( 'AFF-WF-03 partner.status: status fields, no name/e-mail', array( $l3_a, 'active', false, false, array( 'affiliate_id', 'status', 'group_id', 'referred_by', 'restricted', 'approved_at', 'updated_at' ) ), array( $l3_ps['affiliate_id'], $l3_ps['status'], isset( $l3_ps['email'] ), isset( $l3_ps['name'] ), array_keys( $l3_ps ) ) );
$l3_today = wp_date( 'Y-m-d' );
$l3_ms    = wpac_workflow_read( 'metrics.summary', array( 'affiliate_id' => $l3_a, 'from' => $l3_today, 'to' => $l3_today ) );
$l3_sum   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT SUM(amount_minor) FROM ' . t( 'commissions' ) . " WHERE affiliate_id = %d AND status <> 'rejected'", $l3_a ) );
check( 'AFF-WF-03 metrics.summary of a partner: conversions and commission per currency (as the partner area)', array( true, $l3_sum ), array( $l3_ms['conversions'] >= 5, isset( $l3_ms['commission']['EUR'] ) ? $l3_ms['commission']['EUR'] : null ) );
$l3_pm   = wpac_workflow_read( 'metrics.summary', array( 'from' => $l3_today, 'to' => $l3_today ) );
list( $l3_s, $l3_e2 ) = Db::local_range( $l3_today, $l3_today );
$l3_tot  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT SUM(amount_minor) FROM ' . t( 'commissions' ) . " WHERE currency = 'EUR' AND status <> 'rejected' AND created_at BETWEEN %s AND %s", $l3_s, $l3_e2 ) );
$l3_att  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . t( 'attributions' ) . ' WHERE created_at BETWEEN %s AND %s', $l3_s, $l3_e2 ) );
check( 'AFF-WF-03 metrics.summary of the program: commission per currency and attributed orders', array( 0, $l3_tot, $l3_att ), array( $l3_pm['affiliate_id'], $l3_pm['commission']['EUR'], $l3_pm['conversions'] ) );
$l3_cs = wpac_workflow_read( 'campaign.status', array( 'affiliate_id' => $l3_a, 'campaign' => '', 'from' => $l3_today, 'to' => $l3_today ) );
check( 'AFF-WF-03 campaign.status: orders without campaign, partner status, active', array( true, 'active', true ), array( $l3_cs['conversions'] >= 5, $l3_cs['partner_status'], $l3_cs['active'] ) );
$l3_mm = wpac_workflow_read( 'metrics.monthly', array( 'affiliate_id' => $l3_a, 'months' => 3 ) );
check( 'AFF-WF-03 metrics.monthly: 3 months, current first', array( 3, wp_date( 'Y-m' ) ), array( count( $l3_mm['months'] ), $l3_mm['months'][0]['month'] ) );
check( 'AFF-WF-03 read actions write nothing (commissions, audit log, events)', $l3_w0, $l3_writes() );
check(
	'AFF-WF-03 validation: unknown action, unknown argument, array value, bad date, range > 366 days, unknown partner, missing partner, months',
	array( 'wpac_unknown_action', 'wpac_invalid_field', 'wpac_invalid_affiliate_id', 'wpac_invalid_from', 'wpac_invalid_range', 'wpac_invalid_range', 'wpac_not_found', 'wpac_invalid_affiliate_id', 'wpac_invalid_months' ),
	array(
		$l3_err( wpac_workflow_read( 'commission.approve', array() ) ),
		$l3_err( wpac_workflow_read( 'partner.status', array( 'affiliate_id' => $l3_a, 'email' => 'x' ) ) ),
		$l3_err( wpac_workflow_read( 'partner.status', array( 'affiliate_id' => array( $l3_a ) ) ) ),
		$l3_err( wpac_workflow_read( 'metrics.summary', array( 'from' => '2026-02-30' ) ) ),
		$l3_err( wpac_workflow_read( 'metrics.summary', array( 'from' => '2025-01-01', 'to' => '2026-06-01' ) ) ),
		$l3_err( wpac_workflow_read( 'metrics.summary', array( 'from' => '2026-06-02', 'to' => '2026-06-01' ) ) ),
		$l3_err( wpac_workflow_read( 'partner.status', array( 'affiliate_id' => 99999999 ) ) ),
		$l3_err( wpac_workflow_read( 'campaign.status', array() ) ),
		$l3_err( wpac_workflow_read( 'metrics.monthly', array( 'affiliate_id' => $l3_a, 'months' => 37 ) ) ),
	)
);
$GLOBALS['test_caps'] = array( 'read' );
$l3_no_cap = $l3_err( wpac_workflow_read( 'partner.status', array( 'affiliate_id' => $l3_a ) ) );
$GLOBALS['test_caps'] = array( 'wpac_manage_affiliates' );
$l3_cap = $l3_err( wpac_workflow_read( 'partner.status', array( 'affiliate_id' => $l3_a ) ) );
unset( $GLOBALS['test_caps'] );
$GLOBALS['test_user'] = 0;
$l3_anon = $l3_err( wpac_workflow_read( 'partner.status', array( 'affiliate_id' => $l3_a ) ) );
$GLOBALS['test_doing_cron'] = true;
$l3_cron = $l3_err( wpac_workflow_read( 'partner.status', array( 'affiliate_id' => $l3_a ) ) );
unset( $GLOBALS['test_doing_cron'] );
$GLOBALS['test_user'] = 1;
check( 'AFF-WF-03 rights: user without wpac_manage_affiliates refused, with it allowed; no user only in WP-Cron', array( 'wpac_forbidden', 'ok', 'wpac_forbidden', 'ok' ), array( $l3_no_cap, $l3_cap, $l3_anon, $l3_cron ) );

/* WF-01: documentation published and linked. ------------------------------------------------ */
$l3_doc = (string) file_get_contents( WPAC_DIR . 'docs/WORKFLOW-PROVIDER.md' );
check(
	'AFF-WF-01 docs/WORKFLOW-PROVIDER.md documents every event type and read action; linked from CONTRACT.md and README',
	array( array(), true, true ),
	array(
		array_values( array_filter( array_merge( array_keys( WorkflowEvents::FIELDS ), array_keys( \WPAffiliateCaptain\Core\WorkflowRead::ACTIONS ) ), static function ( $k ) use ( $l3_doc ) { return false === strpos( $l3_doc, '`' . $k . '`' ); } ) ),
		false !== strpos( (string) file_get_contents( WPAC_DIR . 'docs/CONTRACT.md' ), 'WORKFLOW-PROVIDER.md' ),
		false !== strpos( (string) file_get_contents( WPAC_DIR . 'README.md' ), 'WORKFLOW-PROVIDER.md' ),
	)
);

remove_all_filters( 'wpac_workflow_event' );
$GLOBALS['l3_events'] = array();
update_option( 'wpac_settings', $l3_keep );
Settings::flush_cache();
