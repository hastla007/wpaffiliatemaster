<?php
/**
 * Package L3 (0.15.0): the domain actions of the hook contract (docs/CONTRACT.md) fire after the
 * COMMIT, also inside an outer transaction, and not at all after a rollback; their arguments;
 * a sale reported again from a listener never books twice.
 * The provisional workflow interface that this file also covered is not part of 0.15.0
 * (TODO.md, decision D13); its checks left with it.
 * Included from run.php before r8-render.php (helpers check(), t(), hook_fired_outside_tx()).
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
use WPAffiliateCaptain\Payouts\Corrections;
use WPAffiliateCaptain\Payouts\PayoutService;
use WPAffiliateCaptain\Payouts\ProfileRepository;
use WPAffiliateCaptain\Privacy\Privacy;
use WPAffiliateCaptain\Promotion\Creatives;

echo "R15 L3 domain actions after COMMIT (AFF-WF-02)\n";
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

// Every do_action() of the run is recorded by the test bootstrap as array( tag, args, in transaction ).
$l3_reset = static function () {
	$GLOBALS['test_fired'] = array();
};
$l3_fired = static function ( $tag ) {
	return array_values( array_filter( $GLOBALS['test_fired'], static function ( $f ) use ( $tag ) { return $f[0] === $tag; } ) );
};
$l3_args = static function ( $tag ) use ( $l3_fired ) {
	return array_map( static function ( $f ) { return $f[1]; }, $l3_fired( $tag ) );
};
$l3_order = static function ( array $tags ) {
	return array_values( array_filter( array_map( static function ( $f ) { return $f[0]; }, $GLOBALS['test_fired'] ), static function ( $t ) use ( $tags ) { return in_array( $t, $tags, true ); } ) );
};
$l3_count = static function () use ( $wpdb ) {
	return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . t( 'commissions' ) );
};

/* ---------------------------------------------------------------------------------------------
 * Partner and sale: wpac_affiliate_created, wpac_affiliate_attributed, wpac_commission_created
 * after the COMMIT, in booking order.
 * ------------------------------------------------------------------------------------------- */
$l3_reset();
$l3_a = $l3_partner( 'a' );
check( 'L3 partner created: wpac_affiliate_created( id, status ) outside a transaction', array( array( array( $l3_a, 'active' ) ), true ), array( $l3_args( 'wpac_affiliate_created' ), hook_fired_outside_tx( 'wpac_affiliate_created' ) ) );
$l3_reset();
$l3_r   = $l3_sale( $l3_a, 'l3-1', 50000 );
$l3_cid = (int) $l3_r['commission_ids'][0];
check( 'L3 sale: wpac_affiliate_attributed then wpac_commission_created, both outside a transaction', array( array( 'wpac_affiliate_attributed', 'wpac_commission_created' ), true, true, array( array( $l3_cid ) ) ), array( $l3_order( array( 'wpac_affiliate_attributed', 'wpac_commission_created' ) ), hook_fired_outside_tx( 'wpac_affiliate_attributed' ), hook_fired_outside_tx( 'wpac_commission_created' ), $l3_args( 'wpac_commission_created' ) ) );
check( 'L3 wpac_affiliate_attributed( order, partner, method, source )', array( array( 'l3-1', $l3_a, 'code', 'test' ) ), $l3_args( 'wpac_affiliate_attributed' ) );

/* A listener that reports the sale again from the action never books a second commission. ---- */
$l3_dup  = null;
$l3_echo = static function ( $order_id ) use ( &$l3_dup, $l3_a, $l3_sale ) {
	if ( 'l3-2' === $order_id ) {
		$l3_dup = $l3_sale( $l3_a, 'l3-2', 30000 ); // Re-report from the action.
	}
};
add_action( 'wpac_affiliate_attributed', $l3_echo, 20, 1 );
$l3_before = $l3_count();
$l3_sale( $l3_a, 'l3-2', 30000 );
check( 'L3 sale reported again from a listener of wpac_affiliate_attributed: duplicate, exactly one commission booked', array( 'duplicate', $l3_before + 1 ), array( is_array( $l3_dup ) ? $l3_dup['status'] : 'error', $l3_count() ) );
unset( $GLOBALS['test_hooks']['wpac_affiliate_attributed'][20] );

/* ---------------------------------------------------------------------------------------------
 * Post-commit guarantee: transition() inside an outer transaction (it nests) fired its status
 * hook inside that transaction; now it waits for the COMMIT and is dropped on rollback.
 * ------------------------------------------------------------------------------------------- */
$l3_reset();
$l3_was = (string) Ledger::find( $l3_cid )->status;
Ledger::transition( $l3_cid, 'approved' );
check( 'L3 status change: wpac_commission_approved( id, old ) once, outside a transaction', array( array( array( $l3_cid, $l3_was ) ), true ), array( array_map( static function ( $a ) { return array_slice( $a, 0, 2 ); }, $l3_args( 'wpac_commission_approved' ) ), hook_fired_outside_tx( 'wpac_commission_approved' ) ) );
$l3_c2 = (int) $l3_sale( $l3_a, 'l3-3', 20000 )['commission_ids'][0];
$l3_was2 = (string) Ledger::find( $l3_c2 )->status;
$l3_reset();
$l3_mid = -1;
Db::transaction(
	static function () use ( $l3_c2, &$l3_mid, $l3_fired ) {
		Ledger::transition( $l3_c2, 'approved' );
		$l3_mid = count( $l3_fired( 'wpac_commission_approved' ) );
	}
);
check( 'L3 transition inside an outer transaction: wpac_commission_approved only after COMMIT', array( 0, true, array( array( $l3_c2, $l3_was2 ) ) ), array( $l3_mid, hook_fired_outside_tx( 'wpac_commission_approved' ), array_map( static function ( $a ) { return array_slice( $a, 0, 2 ); }, $l3_args( 'wpac_commission_approved' ) ) ) );
$l3_c3 = (int) $l3_sale( $l3_a, 'l3-4', 20000 )['commission_ids'][0];
$l3_reset();
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
check( 'L3 rollback: no status hook, no wpac_commission_created, nothing booked', array( array(), array(), $l3_before, 'pending' ), array( $l3_fired( 'wpac_commission_approved' ), $l3_fired( 'wpac_commission_created' ), $l3_count(), in_array( (string) Ledger::find( $l3_c3 )->status, array( 'pending', 'review' ), true ) ? 'pending' : (string) Ledger::find( $l3_c3 )->status ) );
// Payout paid inside an outer transaction: paid hooks wait for the COMMIT as well.
$l3_p0 = PayoutService::create_for_affiliate( $l3_a, gmdate( 'Y-m-d' ), 'EUR' );
$l3_reset();
$l3_mid = -1;
Db::transaction(
	static function () use ( $l3_p0, &$l3_mid, $l3_fired ) {
		PayoutService::mark_paid( $l3_p0 );
		$l3_mid = count( $l3_fired( 'wpac_payout_paid' ) ) + count( $l3_fired( 'wpac_commission_paid' ) );
	}
);
check( 'L3 mark_paid inside an outer transaction: wpac_payout_paid/wpac_commission_paid after COMMIT', array( true, 0, true, true, array( array( $l3_p0 ) ) ), array( $l3_p0 > 0, $l3_mid, hook_fired_outside_tx( 'wpac_payout_paid' ), hook_fired_outside_tx( 'wpac_commission_paid' ), $l3_args( 'wpac_payout_paid' ) ) );

/* ---------------------------------------------------------------------------------------------
 * Parallel sale and refund: the refund arrives first (parked), the sale books, the queue applies
 * the refund. The actions keep the booking order: attribution, commission, then the adjustment.
 * ------------------------------------------------------------------------------------------- */
$l3_reset();
$l3_flow = array( 'wpac_affiliate_attributed', 'wpac_commission_created', 'wpac_commission_adjusted' );
$l3_pr   = wpac_record_refund( array( 'source' => 'l3src', 'order_id' => 'par-1', 'refund_id' => 'r1', 'items' => array( array( 'item_id' => '1', 'net_minor' => 2000 ) ), 'reason' => 'Teilerstattung Käufer Kunz' ) );
check( 'L3 refund before its sale: parked, no action yet', array( 'parked', array() ), array( is_array( $l3_pr ) ? $l3_pr['status'] : 'error', $l3_order( $l3_flow ) ) );
wpac_record_sale( array( 'source' => 'l3src', 'order_id' => 'par-1', 'currency' => 'EUR', 'partner_code' => CodeRepository::main_code( $l3_a ), 'netted_refund_ids' => array(), 'items' => array( array( 'item_id' => '1', 'net_minor' => 10000 ) ), 'customer' => array( 'user_id' => 0, 'email' => 'l3-par@example.de' ) ) );
$wpdb->query( 'UPDATE ' . t( 'jobs' ) . " SET next_attempt_at = '2000-01-01 00:00:00' WHERE idempotency_key LIKE '%:par-1:%'" );
Jobs::run_due();
$l3_seen = $l3_order( $l3_flow );
check( 'L3 parallel sale/refund: attributed, then the commission, the adjustment last, all outside a transaction', array( 'wpac_affiliate_attributed', 'wpac_commission_created', 'wpac_commission_adjusted', 1, true ), array( $l3_seen ? $l3_seen[0] : '', isset( $l3_seen[1] ) ? $l3_seen[1] : '', $l3_seen ? end( $l3_seen ) : '', count( $l3_fired( 'wpac_commission_adjusted' ) ), hook_fired_outside_tx( 'wpac_commission_adjusted' ) ) );
// The same refund again: unchanged, no second action (nothing booked).
$l3_reset();
wpac_record_refund( array( 'source' => 'l3src', 'order_id' => 'par-1', 'refund_id' => 'r1', 'items' => array( array( 'item_id' => '1', 'net_minor' => 2000 ) ) ) );
check( 'L3 refund reported again: no action', array(), $l3_order( $l3_flow ) );

/* ---------------------------------------------------------------------------------------------
 * Partner status, creatives, code requests, payouts, correction credit note, erasure.
 * ------------------------------------------------------------------------------------------- */
$l3_reset();
AffiliateRepository::set_status( $l3_a, 'paused', 'Prüfung' );
AffiliateRepository::set_status( $l3_a, 'active' );
check( 'L3 wpac_affiliate_status_changed( id, new, old ) twice, outside a transaction', array( array( array( $l3_a, 'paused', 'active' ), array( $l3_a, 'active', 'paused' ) ), true ), array( $l3_args( 'wpac_affiliate_status_changed' ), hook_fired_outside_tx( 'wpac_affiliate_status_changed' ) ) );
$l3_reset();
$l3_mid = -1;
Db::transaction(
	static function () use ( $l3_a, &$l3_mid, $l3_fired ) {
		AffiliateRepository::set_status( $l3_a, 'paused', 'Prüfung' );
		$l3_mid = count( $l3_fired( 'wpac_affiliate_status_changed' ) );
	}
);
check( 'L3 set_status inside an outer transaction: wpac_affiliate_status_changed only after COMMIT', array( 0, true ), array( $l3_mid, hook_fired_outside_tx( 'wpac_affiliate_status_changed' ) ) );
AffiliateRepository::set_status( $l3_a, 'active' );
$l3_reset();
$l3_cr_data = array( 'title' => 'L3 Banner', 'type' => 'text', 'content' => 'Jetzt buchen: {link}', 'attachment_id' => 0, 'landing_url' => '', 'disclosure' => '', 'group_id' => 0 );
$l3_cr = Creatives::save_draft( $l3_cr_data, 0, 'neu' );
Creatives::approve( $l3_cr );
$l3_cc = $l3_args( 'wpac_creative_created' );
$l3_cu = $l3_args( 'wpac_creative_updated' );
check( 'L3 wpac_creative_created (draft) and wpac_creative_updated (approved), outside a transaction', array( 'draft', 'approved', true, true ), array( $l3_cc ? $l3_cc[0][1]['status'] : '', $l3_cu ? $l3_cu[0][1]['status'] : '', hook_fired_outside_tx( 'wpac_creative_created' ), hook_fired_outside_tx( 'wpac_creative_updated' ) ) );
$l3_b  = $l3_partner( 'b' );
$l3_reset();
$l3_rq = CodeRequests::request( AffiliateRepository::find( $l3_b ), 'l3wunsch' );
$l3_ca = $l3_args( 'wpac_code_requested' );
check( 'L3 wpac_code_requested( partner, code, row ) outside a transaction', array( true, $l3_b, (int) $l3_rq, true ), array( is_int( $l3_rq ), $l3_ca ? $l3_ca[0][0] : 0, $l3_ca ? $l3_ca[0][2] : 0, hook_fired_outside_tx( 'wpac_code_requested' ) ) );
CodeRequests::reject( (int) $l3_rq, 'Name einer Person' );
$l3_rj = $l3_args( 'wpac_code_request_rejected' );
check( 'L3 wpac_code_request_rejected( partner, code, reason, row ): fourth argument is the row of the request', array( 1, $l3_b, 'Name einer Person', (int) $l3_rq, true ), array( count( $l3_rj ), $l3_rj ? $l3_rj[0][0] : 0, $l3_rj ? $l3_rj[0][2] : '', $l3_rj ? $l3_rj[0][3] : 0, hook_fired_outside_tx( 'wpac_code_request_rejected' ) ) );
// Payout created, paid; correction credit note; cancelled payout.
$l3_c4 = (int) $l3_sale( $l3_b, 'l3-b1', 100000 )['commission_ids'][0];
Ledger::transition( $l3_c4, 'approved' );
$l3_reset();
$l3_p1 = PayoutService::create_for_affiliate( $l3_b, gmdate( 'Y-m-d' ), 'EUR' );
check( 'L3 wpac_payout_created( payout, partner ) outside a transaction', array( array( array( $l3_p1, $l3_b ) ), true ), array( $l3_args( 'wpac_payout_created' ), hook_fired_outside_tx( 'wpac_payout_created' ) ) );
PayoutService::mark_paid( $l3_p1 );
$l3_reset();
$l3_k  = Corrections::create( $l3_p1, 12000, 'Provisionssatz korrigiert', 'l3-korr', 10000 );
$l3_ce = $l3_args( 'wpac_correction_generated' );
check( 'L3 wpac_correction_generated( correction, path ) once, outside a transaction', array( true, 1, $l3_k, true ), array( is_int( $l3_k ), count( $l3_ce ), $l3_ce ? $l3_ce[0][0] : 0, hook_fired_outside_tx( 'wpac_correction_generated' ) ) );
$l3_c5 = (int) $l3_sale( $l3_b, 'l3-b2', 100000 )['commission_ids'][0];
Ledger::transition( $l3_c5, 'approved' );
$l3_p2 = PayoutService::create_for_affiliate( $l3_b, gmdate( 'Y-m-d' ), 'EUR' );
$l3_reset();
PayoutService::cancel( $l3_p2, 'Test' );
check( 'L3 wpac_payout_cancelled( payout ) outside a transaction', array( array( array( $l3_p2 ) ), true ), array( $l3_args( 'wpac_payout_cancelled' ), hook_fired_outside_tx( 'wpac_payout_cancelled' ) ) );
$l3_reset();
$l3_ma = wpac_create_adjustment( $l3_b, -500, 'EUR', 'Abzug wegen Rückfrage', array( 'reference' => 'team:7' ) );
check( 'L3 manual booking: wpac_commission_created( id ) once, outside a transaction', array( true, array( array( is_array( $l3_ma ) ? (int) $l3_ma['id'] : 0 ) ), true ), array( is_array( $l3_ma ), $l3_args( 'wpac_commission_created' ), hook_fired_outside_tx( 'wpac_commission_created' ) ) );
// Privacy erasure: wpac_affiliate_erased carries only the ID and whether part was deferred.
$l3_e = $l3_partner( 'erase' );
$l3_reset();
Privacy::erase_partner( AffiliateRepository::find( $l3_e ) );
$l3_er = $l3_args( 'wpac_affiliate_erased' );
check( 'L3 erasure: wpac_affiliate_erased( id, bool ) once, outside a transaction', array( 1, $l3_e, true, true ), array( count( $l3_er ), $l3_er ? $l3_er[0][0] : 0, $l3_er && is_bool( $l3_er[0][1] ), hook_fired_outside_tx( 'wpac_affiliate_erased' ) ) );

$GLOBALS['test_fired'] = array();
update_option( 'wpac_settings', $l3_keep );
Settings::flush_cache();
