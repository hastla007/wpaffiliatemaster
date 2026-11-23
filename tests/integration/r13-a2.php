<?php
/**
 * Audit round 13, fix agent a2 (money): taxable totals of credit notes, annual overview and §19
 * warning without repayments/write-offs (R13-K2), deleted refunds booked back from the stored
 * refund rows as reinstatements (R13-K3/K5), partial refunds against open increases (R13-K4),
 * refund deletions outside the admin screen (R13-M7), a correction form sent again with another
 * amount (R13-M9), legacy corrections, negative parts, VAT rate keys and failed cancellation
 * documents in the annual overview (R13-N8).
 * Included from run.php before r8-render.php (helpers check(), t(), q() and the G3 WooCommerce
 * stand-ins come from there).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Codes\CodeRepository;
use WPAffiliateCaptain\Commissions\Balance;
use WPAffiliateCaptain\Commissions\Dunning;
use WPAffiliateCaptain\Commissions\Ledger;
use WPAffiliateCaptain\Commissions\RuleRepository;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\Money;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Integrations\WooCommerce\OrderSync;
use WPAffiliateCaptain\Payouts\Corrections;
use WPAffiliateCaptain\Payouts\Pdf;
use WPAffiliateCaptain\Payouts\PayoutService;
use WPAffiliateCaptain\Payouts\ProfileRepository;
use WPAffiliateCaptain\Payouts\Statement;
use WPAffiliateCaptain\Payouts\TaxHistory;
use WPAffiliateCaptain\Payouts\Vat;

echo "R13 A2 taxable totals, deleted refunds, open increases, corrections\n";
global $wpdb;
$b2_keep = get_option( 'wpac_settings' );
update_option( 'wpac_settings', array_merge( $b2_keep, array( 'min_payout_minor' => 100, 'self_billing' => 1, 'vat_rate' => '19', 'operator_country' => 'DE', 'operator_address' => "Hafen 1\n20457 Hamburg", 'operator_vat_id' => 'DE999999999', 'debtor_name' => 'Shop GmbH', 'terminated_open' => 'pay', 'vies_check' => 0, 'maintenance_mode' => 0 ) ) );
Settings::flush_cache();
unset( $GLOBALS['test_caps'] );

$b2_partner = static function ( $slug, array $profile ) use ( $wpdb ) {
	$id = AffiliateRepository::create( array( 'name' => 'B2 ' . $slug, 'email' => 'b2-' . $slug . '@example.de', 'status' => 'active' ) );
	ProfileRepository::save( $id, $profile + array( 'legal_name' => 'B2 ' . $slug, 'address' => "Kai 5\n24103 Kiel", 'country' => 'DE', 'account_holder' => 'B2 ' . $slug, 'iban' => 'DE89370400440532013000' ) );
	$wpdb->query( 'UPDATE ' . t( 'payout_profiles' ) . " SET updated_at = '2020-01-01 00:00:00' WHERE affiliate_id = " . (int) $id );
	return $id;
};
$b2_earn = static function ( $aff, $order, $net, $approve = true ) {
	$r = wpac_record_sale( array( 'source' => 'test', 'order_id' => (string) $order, 'currency' => 'EUR', 'partner_code' => CodeRepository::main_code( $aff ), 'items' => array( array( 'item_id' => '1', 'product_id' => 10, 'name' => 'Kurs', 'net_minor' => $net, 'quantity' => 1 ) ), 'customer' => array( 'user_id' => 0, 'email' => 'b2-kunde-' . $order . '@example.de' ) ) );
	if ( $approve ) {
		foreach ( (array) $r['commission_ids'] as $cid ) {
			Ledger::transition( (int) $cid, 'approved' );
		}
	}
	return (int) $r['commission_ids'][0];
};
$b2_sum = static function ( $order, $source = 'test' ) use ( $wpdb ) {
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(amount_minor),0) FROM ' . t( 'commissions' ) . " WHERE source = %s AND source_order_id = %s AND status <> 'rejected'", $source, (string) $order ) );
};
$b2_refund = static function ( $order, $refund_id, $net = 0, $type = 'refund' ) {
	$p = array( 'source' => 'test', 'order_id' => (string) $order, 'refund_id' => $refund_id, 'type' => $type, 'reason' => 'test' );
	if ( $net ) {
		$p['items'] = array( array( 'item_id' => '1', 'net_minor' => $net ) );
	} else {
		$p['full'] = true;
	}
	return wpac_record_refund( $p );
};
$b2_increase = static function ( $order, $net ) {
	return Ledger::record_increase( array( 'source' => 'test', 'order_id' => (string) $order, 'currency' => 'EUR', 'items' => array( array( 'item_id' => '1', 'product_id' => 10, 'name' => 'Kurs', 'net_minor' => $net, 'quantity' => 1 ) ), 'reason' => 'raised' ) );
};
$b2_in = static function ( $pdf, $text ) {
	return false !== strpos( $pdf, Pdf::literal( $text ) );
};
$b2_has = static function ( $pdf, $text ) {
	return false !== strpos( $pdf, substr( Pdf::literal( $text ), 1, -1 ) );
};

/* R13-K3: delete a refund (typo), enter it again, cancel the order: nothing left, nothing more. */
$b2_q = $b2_partner( 'k3', array( 'tax_status' => 'business', 'vat_id' => 'DE123456789' ) );
$b2_earn( $b2_q, 'b2-k3', 100000 );
$b2_bal = Balance::get( $b2_q, 'EUR' );
$b2_refund( 'b2-k3', 'b2-typo', 60000 );
$b2_rev = Ledger::reverse_refund( 'test', 'b2-k3', 'b2-typo', 'Refund deleted' );
$b2_row = $b2_rev['commission_ids'] ? Ledger::find( (int) $b2_rev['commission_ids'][0] ) : null;
check( 'R13-K3 deleted refund: one reinstatement of exactly the refund, approved at once, no hold', array( 'booked', 6000, 'reinstatement', 'approved', 60000 ), array( $b2_rev['status'], $b2_rev['amount_minor'], $b2_row ? $b2_row->kind : '', $b2_row ? $b2_row->status : '', $b2_row ? (int) $b2_row->base_minor : 0 ) );
check( 'R13-K3 reversing the same deletion again books nothing', array( 'already', array() ), array( Ledger::reverse_refund( 'test', 'b2-k3', 'b2-typo', 'again' )['status'], Ledger::reverse_refund( 'test', 'b2-k3', 'b2-typo', 'again' )['commission_ids'] ) );
$b2_refund( 'b2-k3', 'b2-right', 60000 );
check( 'R13-K3 refund entered again: 40 € left', 4000, $b2_sum( 'b2-k3' ) );
$b2_refund( 'b2-k3', 'b2-cancel', 0, 'cancellation' );
check( 'R13-K3 full cancellation keeps the reinstatement (not an open increase): order at 0, account −100 €', array( 0, -10000, 'approved', array() ), array( $b2_sum( 'b2-k3' ), Balance::get( $b2_q, 'EUR' ) - $b2_bal, $b2_row ? Ledger::find( (int) $b2_row->id )->status : '', Balance::verify( $b2_q, 'EUR' ) ) );
check( 'R13-K3 a refund deleted after the full reversal gives nothing back', 'skipped', Ledger::reverse_refund( 'test', 'b2-k3', 'b2-right', 'late' )['status'] );
check( 'R13-K3 reinstatements count as corrections (fraud check, reports)', true, false !== strpos( (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Tracking/FraudCheck.php' ), "'chargeback','reinstatement'" ) && false !== strpos( (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Reports/Reports.php' ), "'reinstatement'" ) );
// Pending sale: the refund row of a deleted refund is rejected (no balance effect).
$b2_pc = $b2_earn( $b2_q, 'b2-k3p', 100000, false );
$b2_pr = $b2_refund( 'b2-k3p', 'b2-k3p-r', 20000 );
$b2_rv = Ledger::reverse_refund( 'test', 'b2-k3p', 'b2-k3p-r', 'Refund deleted' );
check( 'R13-K3 pending sale: the refund row is rejected instead', array( 'booked', 2000, 'rejected', 10000 ), array( $b2_rv['status'], $b2_rv['amount_minor'], Ledger::find( (int) $b2_pr['adjustment_ids'][0] )->status, $b2_sum( 'b2-k3p' ) ) );

/* R13-K4: partial refunds of a raised line take back the open increase first. */
$b2_i = $b2_partner( 'k4', array( 'tax_status' => 'business', 'vat_id' => 'DE123456789' ) );
$b2_earn( $b2_i, 'b2-k4a', 100000 );
$b2_earn( $b2_i, 'b2-k4b', 100000 );
$b2_earn( $b2_i, 'b2-k4c', 100000 );
$b2_pi = PayoutService::create_for_affiliate( $b2_i, gmdate( 'Y-m-d' ), 'EUR' );
PayoutService::mark_paid( $b2_pi );
$b2_bal = Balance::get( $b2_i, 'EUR' );
// (a) money r7: refund of the raised part only → no reversal of paid money, increase gone.
$b2_inc = $b2_increase( 'b2-k4a', 200000 );
$b2_iid = (int) $b2_inc['commission_ids'][0];
$GLOBALS['test_fired'] = array();
$b2_r   = $b2_refund( 'b2-k4a', 'b2-k4a-r', 100000 );
$b2_rej = array_values( array_filter( $GLOBALS['test_fired'], static function ( $f ) { return 'wpac_commission_rejected' === $f[0]; } ) );
check( 'R13-K4 / contract 1.7.0: the rejected increase is reported with its previous status', array( array( $b2_iid, 'pending' ) ), array_map( static function ( $f ) { return $f[1]; }, $b2_rej ) );
check( 'R13-K4 refund of 1,000 € on a line raised to 2,000 €: the open increase is rejected, paid money stays', array( array(), 'rejected', 10000, 0 ), array( $b2_r['adjustment_ids'], Ledger::find( $b2_iid )->status, $b2_sum( 'b2-k4a' ), Balance::get( $b2_i, 'EUR' ) - $b2_bal ) );
check( 'R13-K4 the same refund reported again changes nothing', array( array(), 10000 ), array( $b2_refund( 'b2-k4a', 'b2-k4a-r', 100000 )['adjustment_ids'], $b2_sum( 'b2-k4a' ) ) );
// (b) qa r2: refund of the whole raised line → only what was paid is reversed.
$b2_inc = $b2_increase( 'b2-k4b', 200000 );
$b2_iid = (int) $b2_inc['commission_ids'][0];
$GLOBALS['test_fired'] = array();
$b2_r   = $b2_refund( 'b2-k4b', 'b2-k4b-r', 200000 );
$b2_rej = array_values( array_filter( $GLOBALS['test_fired'], static function ( $f ) { return 'wpac_commission_rejected' === $f[0]; } ) );
check( 'R13-K4 / contract 1.7.0: whole raised line refunded, the increase is reported as rejected from pending', array( array( $b2_iid, 'pending' ) ), array_map( static function ( $f ) { return $f[1]; }, $b2_rej ) );
$b2_adj = $b2_r['adjustment_ids'] ? Ledger::find( (int) $b2_r['adjustment_ids'][0] ) : null;
check( 'R13-K4 refund of the whole raised line: −100 € approved (never more than paid), increase rejected', array( -10000, 'approved', 'rejected', -10000 ), array( $b2_adj ? (int) $b2_adj->amount_minor : 0, $b2_adj ? $b2_adj->status : '', Ledger::find( $b2_iid )->status, Balance::get( $b2_i, 'EUR' ) - $b2_bal ) );
Ledger::transition( $b2_iid, 'rejected', 'x' );
check( 'R13-K4 the order sums to 0, account consistent', array( 0, array() ), array( $b2_sum( 'b2-k4b' ), Balance::verify( $b2_i, 'EUR' ) ) );
// (c) refund smaller than the open increase: the increase is lowered, booked base kept.
$b2_inc = $b2_increase( 'b2-k4c', 200000 );
$b2_iid = (int) $b2_inc['commission_ids'][0];
$b2_r   = $b2_refund( 'b2-k4c', 'b2-k4c-r', 40000 );
$b2_io  = Ledger::find( $b2_iid );
$b2_is  = json_decode( (string) $b2_io->rule_snapshot, true );
check( 'R13-K4 refund of 400 € on an open increase of 1,000 €: increase lowered to 600 € / 60 €, nothing reversed', array( array(), 'pending', 60000, 6000, 100000 ), array( $b2_r['adjustment_ids'], $b2_io->status, (int) $b2_io->base_minor, (int) $b2_io->amount_minor, (int) $b2_is['booked_base'] ) );
check( 'R13-K4 the same line raised to 2,000 € again is no new increase (booked base unchanged)', array( 'unchanged', 16000 ), array( $b2_increase( 'b2-k4c', 160000 )['status'], $b2_sum( 'b2-k4c' ) ) );
check( 'R13-K4 a further raise is booked from the lowered state', array( 'booked', 1000 ), ( static function ( $r ) { return array( $r['status'], (int) $r['amount_minor'] ); } )( $b2_increase( 'b2-k4c', 170000 ) ) );
check( 'R13-K4 accounts consistent', array(), Balance::verify( $b2_i, 'EUR' ) );

/* R13-K5 + R13-M7: deleted WooCommerce refunds (fixed rate per piece; hooks outside the admin). */
class R13A2_Refund extends G3_Refund {
	public $parent = 0;
	public function get_parent_id() { return $this->parent; }
}
$b2_w = AffiliateRepository::create( array( 'name' => 'B2 Woo', 'email' => 'b2-woo@example.de', 'status' => 'active' ) );
RuleRepository::save( RuleRepository::sanitize( array( 'scope' => 'affiliate', 'scope_id' => $b2_w, 'target' => 'all', 'rate_type' => 'fixed', 'rate' => '5', 'priority' => 0, 'active' => 1 ) ), 0, 'b2' );
$b2_item      = new G3_Item( '100.00' );
$b2_item->qty = 2;
$b2_o         = new G3_Order( 1313001, array( 1 => $b2_item ), '100.00', array( '_wpac_partner_code' => CodeRepository::main_code( $b2_w ) ) );
$GLOBALS['za_orders'][1313001] = $b2_o;
OrderSync::sync_order( 1313001 );
$b2_ri      = new G3_Item( '-50.00', '0.00', array( '_refunded_item_id' => 1 ) );
$b2_ri->qty = -1;
$b2_ref     = new R13A2_Refund( 1313101, '50.00', array( $b2_ri ) );
$b2_ref->parent                = 1313001;
$b2_o->refunds[]               = $b2_ref;
$GLOBALS['za_orders'][1313101] = $b2_ref;
OrderSync::sync_refund( 1313001, 1313101 );
check( 'R13-K5 setup: 2 pieces at 5 €, one refunded', 500, $b2_sum( 1313001, 'woocommerce' ) );
// Deleted through the CRUD classes (REST, ERP): parent captured before, booked after the deletion.
check( 'R13-M7 pre-delete filter keeps its value', null, OrderSync::before_refund_delete( null, $b2_ref ) );
$b2_o->refunds = array();
unset( $GLOBALS['za_orders'][1313101] );
OrderSync::refund_gone( 1313101 );
check( 'R13-K5 fixed rate per piece: the deleted refund is booked back exactly, with an order note', array( 1000, true ), array( $b2_sum( 1313001, 'woocommerce' ), false !== strpos( (string) end( $b2_o->notes ), 'was booked again' ) ) );
$b2_notes = count( $b2_o->notes );
OrderSync::order_refund_deleted( 1313001, 1313101 ); // WooCommerce 11.3+ hook for the same deletion.
OrderSync::refund_deleted( 1313101, 1313001 );       // Admin screen hook.
check( 'R13-M7 the other hooks for the same deletion book nothing and add no note', array( 1000, $b2_notes ), array( $b2_sum( 1313001, 'woocommerce' ), count( $b2_o->notes ) ) );
OrderSync::refund_deleted( 1313199, 1313001 );
check( 'R13-K5 a deleted refund without a deduction: an order note anyway', true, false !== strpos( (string) end( $b2_o->notes ), 'no partner commission had been deducted' ) );
// Refunds deleted together with their order give nothing back (the order reversal follows).
$b2_ref2         = new R13A2_Refund( 1313102, '50.00', array( $b2_ri ) );
$b2_ref2->parent = 1313001;
$b2_o->refunds[] = $b2_ref2;
$GLOBALS['za_orders'][1313102] = $b2_ref2;
OrderSync::sync_refund( 1313001, 1313102 );
OrderSync::order_deleting( 1313001 );
OrderSync::before_refund_delete( null, $b2_ref2 );
OrderSync::refund_gone( 1313102 );
check( 'R13-M7 refund deleted with its order: no reinstatement', 500, $b2_sum( 1313001, 'woocommerce' ) );
$b2_src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Integrations/WooCommerce.php' );
check(
	'R13-M7 hooks: WooCommerce 11.3 (order ID first), CRUD filter, delete action, classic posts',
	array( true, true, true, true, true ),
	array(
		false !== strpos( $b2_src, "add_action( 'woocommerce_order_refund_deleted', array( OrderSync::class, 'order_refund_deleted' ), 10, 2 );" ),
		false !== strpos( $b2_src, "add_filter( 'woocommerce_pre_delete_order_refund', array( OrderSync::class, 'before_refund_delete' ), PHP_INT_MAX, 2 );" ),
		false !== strpos( $b2_src, "add_action( 'woocommerce_delete_order_refund', array( OrderSync::class, 'refund_gone' ) );" ),
		false !== strpos( $b2_src, "add_action( 'before_delete_post', array( OrderSync::class, 'before_post_delete' ), 0 );" ),
		false !== strpos( $b2_src, "add_action( 'woocommerce_before_delete_order', array( OrderSync::class, 'order_deleting' ), 0 );" ),
	)
);

/* R13-K2: repayments and write-offs are no turnover (money r4/r5). */
$b2_m = $b2_partner( 'mix', array( 'tax_status' => 'business', 'vat_id' => 'DE123456789' ) );
$b2_past   = gmdate( 'Y-m-d H:i:s', time() - 60 * DAY_IN_SECONDS );
$b2_change = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
$b2_m1     = $b2_earn( $b2_m, 'b2-m1', 100000 );
q( 'UPDATE ' . t( 'commissions' ) . " SET created_at = '{$b2_past}' WHERE id = " . (int) $b2_m1 );
$b2_earn( $b2_m, 'b2-m2', 50000 );
update_option( 'wpac_tax_history_' . $b2_m, array( array( 'until' => $b2_change, 'tax_status' => 'small_business', 'country' => 'DE', 'vat_id' => '', 'tax_number' => '12/345/67890' ) ) );
$b2_p1  = PayoutService::create_for_affiliate( $b2_m, gmdate( 'Y-m-d' ), 'EUR' );
PayoutService::mark_paid( $b2_p1 );
$b2_gs1 = Statement::build( PayoutService::find( $b2_p1 ) );
Corrections::create( $b2_p1, 4000, 'Satz', 'b2-m-k1', 5000, 'standard|19' );
Corrections::create( $b2_p1, 0, 'Storno', 'b2-m-k2', 10000, 'small_business|0' );
check( 'R13-K2 setup: the partner owes 111.90 €', -11190, Balance::get( $b2_m, 'EUR' ) );
Dunning::record_repayment( $b2_m, 'EUR', 11190, 'Überweisung' ); // Settles the balance (settlement document).
$b2_so  = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . t( 'payouts' ) . ' WHERE affiliate_id = %d ORDER BY id DESC LIMIT 1', $b2_m ) );
$b2_so  = $b2_so && (int) $b2_so->id !== (int) $b2_p1 ? $b2_so : null;
$b2_spd = $b2_so ? Statement::build( $b2_so ) : '';
check(
	'R13-K2 settlement document: no "Net amount", the repayment and the corrections on lines of their own',
	array( false, true, true, true ),
	array( $b2_has( $b2_spd, 'Net amount' ), $b2_has( $b2_spd, 'Repayments and write-offs (not taxable)' ), $b2_in( $b2_spd, Money::format( 11190, 'EUR' ) ), $b2_in( $b2_spd, Money::format( -11190, 'EUR' ) ) )
);
check( 'R13-K2/N8 long total label wrapped (does not run into the amount column)', array( false, true, true ), array( $b2_has( $b2_spd, 'Correction credit notes settled (gross, VAT shown there)' ), $b2_has( $b2_spd, 'Correction credit notes' ), $b2_has( $b2_spd, 'VAT shown there' ) ) );
$b2_ann = Statement::annual( $b2_m, (int) wp_date( 'Y' ) );
check(
	'R13-K2 annual overview: totals 40.00 / 7.60 / 47.60 (not 151.90 / 7.60 / 159.50), repayment shown apart',
	array( true, true, true, false, true ),
	array( $b2_in( $b2_ann, Money::format( 4000, 'EUR' ) ), $b2_in( $b2_ann, Money::format( 760, 'EUR' ) ), $b2_in( $b2_ann, Money::format( 4760, 'EUR' ) ), $b2_in( $b2_ann, Money::format( 15190, 'EUR' ) ), $b2_has( $b2_ann, 'Repayments and write-offs (not taxable)' ) )
);
check( 'R13-K2 the first credit note (nothing untaxed on it) is built as before', $b2_gs1, Statement::build( PayoutService::find( $b2_p1 ) ) );
// §19 warning: small business with 120,000 €, corrected to 0, then repaid / written off.
$b2_sb_warn = static function ( $aff ) {
	foreach ( PayoutService::small_business_warnings() as $w ) {
		if ( (int) $w[0] === (int) $aff ) {
			return $w;
		}
	}
	return array();
};
foreach ( array( 'repay', 'writeoff' ) as $b2_how ) {
	$b2_sb = $b2_partner( 'sb-' . $b2_how, array( 'tax_status' => 'small_business', 'tax_number' => '12/345/67890' ) );
	$b2_earn( $b2_sb, 'b2-sb-' . $b2_how, 120000000 );
	$b2_ps = PayoutService::create_for_affiliate( $b2_sb, gmdate( 'Y-m-d' ), 'EUR' );
	PayoutService::mark_paid( $b2_ps );
	$b2_w1 = $b2_sb_warn( $b2_sb );
	Corrections::create( $b2_ps, 0, 'Fake-Bestellungen', 'b2-sb-k-' . $b2_how, 12000000 );
	if ( 'repay' === $b2_how ) {
		Dunning::record_repayment( $b2_sb, 'EUR', 12000000, 'zurück' ); // Settles the balance.
	} else {
		Ledger::write_off( $b2_sb, 'EUR', 'uneinbringlich' );
	}
	check( 'R13-K2 §19 warning gone after correction to 0 and ' . $b2_how . ' (no false alarm)', array( 'current', array() ), array( $b2_w1 ? $b2_w1[4] : '', $b2_sb_warn( $b2_sb ) ) );
}

/* R13-M9: a correction form sent again with another amount. */
$b2_c  = $b2_partner( 'm9', array( 'tax_status' => 'business', 'vat_id' => 'DE123456789' ) );
$b2_earn( $b2_c, 'b2-m9', 100000 );
$b2_pc = PayoutService::create_for_affiliate( $b2_c, gmdate( 'Y-m-d' ), 'EUR' );
PayoutService::mark_paid( $b2_pc );
$b2_k  = Corrections::create( $b2_pc, 9000, 'Satz', 'b2-m9-key', 10000 );
$b2_k2 = Corrections::create( $b2_pc, 8000, 'Satz', 'b2-m9-key', 10000 );
$b2_ko = Corrections::find( (int) $b2_k );
check( 'R13-M9 same key, same amount: the issued correction', (int) $b2_k, Corrections::create( $b2_pc, 9000, 'Satz', 'b2-m9-key', 10000 ) );
check( 'R13-M9 same key, other amount: "already issued with …", nothing booked', array( 'wpac_already', true, true, 1 ), array( is_wp_error( $b2_k2 ) ? $b2_k2->get_error_code() : $b2_k2, is_wp_error( $b2_k2 ) && false !== strpos( $b2_k2->get_error_message(), (string) $b2_ko->number ), is_wp_error( $b2_k2 ) && false !== strpos( $b2_k2->get_error_message(), Money::format( 9000, 'EUR' ) ), count( Corrections::for_payout( $b2_pc ) ) ) );

/* R13-N8: VAT rate keys, negative parts, legacy corrections, failed cancellation documents. */
check( 'R13-N8 rate keys: 19 = 19.0 = 19.00, 7.5 stays', array( '19', '19', '19', '7.5', '0' ), array( Vat::rate_key( '19' ), Vat::rate_key( '19.0' ), Vat::rate_key( '19.00' ), Vat::rate_key( '7.50' ), Vat::rate_key( '0' ) ) );
$b2_rates = count( TaxHistory::rates() );
TaxHistory::record_rate( '19', '19.0' );
check( 'R13-N8 "19" → "19.0" is no change of the rate', $b2_rates, count( TaxHistory::rates() ) );
$b2_n  = $b2_partner( 'n8', array( 'tax_status' => 'business', 'vat_id' => 'DE123456789' ) );
$b2_earn( $b2_n, 'b2-n8a', 100000 );
$b2_earn( $b2_n, 'b2-n8b', 100000 );
$b2_pn = PayoutService::create_for_affiliate( $b2_n, gmdate( 'Y-m-d' ), 'EUR' );
q( 'UPDATE ' . t( 'payout_items' ) . " SET vat_rate = '19.0' WHERE payout_id = " . (int) $b2_pn . ' ORDER BY id ASC LIMIT 1' );
PayoutService::mark_paid( $b2_pn );
$b2_pno = PayoutService::find( $b2_pn );
check( 'R13-N8 lines with "19" and "19.0": one part, one VAT group on the document', array( array( 'standard|19' ), false ), array( array_keys( Corrections::parts( $b2_pno ) ), false !== strpos( Statement::build( $b2_pno ), ' % on ' ) ) );
// A part with a negative net (corrections of an earlier credit note) can be corrected below 0.
$b2_neg = Corrections::create( $b2_pn, -100, 'x', 'b2-n8-neg', 20000 );
check( 'R13-N8 negative net refused where the part is positive', 'wpac_amount', is_wp_error( $b2_neg ) ? $b2_neg->get_error_code() : $b2_neg );
q( 'UPDATE ' . t( 'payout_items' ) . ' SET amount_minor = -5000 WHERE payout_id = ' . (int) $b2_pn );
$b2_neg = Corrections::create( $b2_pn, -5000, 'Rückbuchung zu hoch', 'b2-n8-neg2', -10000 );
check( 'R13-N8 negative part corrected to a smaller negative net', array( true, -10000, -5000 ), is_wp_error( $b2_neg ) ? array( $b2_neg->get_error_message() ) : array( true, (int) Corrections::find( (int) $b2_neg )->prev_net_minor, (int) Corrections::find( (int) $b2_neg )->net_minor ) );
// Legacy correction (0.12, whole credit note) on a mixed credit note: not counted twice.
$b2_l  = $b2_partner( 'legacy', array( 'tax_status' => 'business', 'vat_id' => 'DE123456789' ) );
$b2_l1 = $b2_earn( $b2_l, 'b2-l1', 100000 );
q( 'UPDATE ' . t( 'commissions' ) . " SET created_at = '{$b2_past}' WHERE id = " . (int) $b2_l1 );
$b2_earn( $b2_l, 'b2-l2', 50000 );
update_option( 'wpac_tax_history_' . $b2_l, array( array( 'until' => $b2_change, 'tax_status' => 'small_business', 'country' => 'DE', 'vat_id' => '', 'tax_number' => '12/345/67890' ) ) );
$b2_pl = PayoutService::create_for_affiliate( $b2_l, gmdate( 'Y-m-d' ), 'EUR' );
PayoutService::mark_paid( $b2_pl );
$wpdb->insert( t( Corrections::TABLE ), array( 'payout_id' => $b2_pl, 'number' => 'KG-B2-LEGACY', 'affiliate_id' => $b2_l, 'currency' => 'EUR', 'base_number' => 'x', 'prev_net_minor' => 15000, 'prev_vat_minor' => 950, 'net_minor' => 14000, 'vat_minor' => 760, 'vat_mode' => 'standard', 'vat_rate' => '19', 'reason' => 'alt', 'commission_id' => 0, 'request_key' => 'b2-legacy', 'profile_snapshot' => '{}', 'operator_snapshot' => '{"name":"Shop GmbH"}', 'created_by' => 0, 'created_at' => Db::now() ) );
$b2_plo = PayoutService::find( $b2_pl );
$b2_lp  = Corrections::parts( $b2_plo );
check( 'R13-N8 legacy correction of a mixed credit note: its total replaces the parts (base 140, not 240), flagged', array( array( 'standard|19' ), 14000, true ), array( array_keys( $b2_lp ), Corrections::base( $b2_plo )[0], ! empty( $b2_lp['standard|19']['legacy'] ) ) );
ob_start();
WPAffiliateCaptain\Admin\Pages\PayoutsPage::correction_form( $b2_plo );
check( 'R13-N8 the correction form says so', true, false !== strpos( (string) ob_get_clean(), 'wpac-legacy-correction' ) );
// Cancellation of a credit note that was never written (document failing for good).
$b2_f = $b2_partner( 'storno', array( 'tax_status' => 'business', 'vat_id' => 'DE123456789' ) );
$b2_earn( $b2_f, 'b2-f1', 100000 );
$b2_pf = PayoutService::create_for_affiliate( $b2_f, gmdate( 'Y-m-d' ), 'EUR' );
q( 'UPDATE ' . t( 'payouts' ) . " SET status = 'cancelled', statement_file = '', statement_hash = '', storno_number = 'ST-B2-1', storno_at = '" . Db::now() . "' WHERE id = " . (int) $b2_pf );
$b2_fa = Statement::annual( $b2_f, (int) wp_date( 'Y' ) );
check( 'R13-N8 annual overview: no lone cancellation line for a credit note never issued', array( false, false ), array( $b2_has( $b2_fa, 'ST-B2-1' ), $b2_in( $b2_fa, Money::format( -11900, 'EUR' ) ) ) );

update_option( 'wpac_settings', $b2_keep );
Settings::flush_cache();
