<?php
/**
 * AC-2 (suite specification Z-09, P-2/P-4): the three domain actions of docs/CONTRACT.md that had
 * no test of their own fire with their documented arguments, once, after the COMMIT:
 * wpac_commission_rejected, wpac_commission_pending and wpac_code_request_approved.
 * The check that the documented actions match the actions in the code is in tests/run.php ("AC-2").
 * Included from run.php after r15-l3.php (helpers check(), t(), hook_fired_outside_tx()).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Codes\CodeRepository;
use WPAffiliateCaptain\Codes\CodeRequests;
use WPAffiliateCaptain\Commissions\Ledger;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\Settings;

echo "R16 AC-2 wpac_commission_rejected, wpac_commission_pending, wpac_code_request_approved\n";

$ac2_keep = get_option( 'wpac_settings' );
update_option( 'wpac_settings', array_merge( $ac2_keep, array( 'maintenance_mode' => 0 ) ) );
Settings::flush_cache();
unset( $GLOBALS['test_caps'] );
$GLOBALS['test_user'] = 1;

$ac2_partner = static function ( $slug ) {
	$id = (int) AffiliateRepository::create( array( 'name' => 'AC2 Partner ' . $slug, 'email' => 'ac2-' . $slug . '@example.de', 'status' => 'active' ) );
	if ( ! CodeRepository::main_code( $id ) ) {
		CodeRepository::create( $id, CodeRepository::random_code() );
	}
	return $id;
};
$ac2_sale = static function ( $aff, $order, array $extra = array() ) {
	$r = wpac_record_sale( array_merge( array( 'source' => 'test', 'order_id' => (string) $order, 'currency' => 'EUR', 'partner_code' => CodeRepository::main_code( $aff ), 'items' => array( array( 'item_id' => '1', 'product_id' => 10, 'name' => 'Kurs', 'net_minor' => 40000 ) ), 'customer' => array( 'user_id' => 0, 'email' => 'ac2-kaeufer-' . $order . '@example.de' ) ), $extra ) );
	return is_array( $r ) && ! empty( $r['commission_ids'] ) ? (int) $r['commission_ids'][0] : 0;
};
$ac2_args = static function ( $tag ) {
	$out = array();
	foreach ( $GLOBALS['test_fired'] as $f ) {
		if ( $f[0] === $tag ) {
			$out[] = $f[1];
		}
	}
	return $out;
};

$ac2_a = $ac2_partner( 'a' );

/* wpac_commission_rejected( int $commission_id ): manual rejection of a pending sale commission. */
$ac2_c1 = $ac2_sale( $ac2_a, 'ac2-1' );
$GLOBALS['test_fired'] = array();
$ac2_ok = Ledger::reject( $ac2_c1, 'Testbestellung' );
check( 'AC-2 Ledger::reject: wpac_commission_rejected( id ) once, outside a transaction', array( true, 'rejected', array( array( $ac2_c1 ) ), true ), array( true === $ac2_ok, (string) Ledger::find( $ac2_c1 )->status, $ac2_args( 'wpac_commission_rejected' ), hook_fired_outside_tx( 'wpac_commission_rejected' ) ) );
// Inside an outer transaction the action waits for its COMMIT; after a rollback it never fires.
$ac2_c2 = $ac2_sale( $ac2_a, 'ac2-2' );
$GLOBALS['test_fired'] = array();
try {
	Db::transaction(
		static function () use ( $ac2_c2 ) {
			Ledger::reject( $ac2_c2, 'Rollback-Test' );
			throw new \RuntimeException( 'rollback' );
		}
	);
} catch ( \RuntimeException $e ) {
	unset( $e );
}
check( 'AC-2 rejection rolled back: no wpac_commission_rejected, commission still pending', array( array(), 'pending' ), array( $ac2_args( 'wpac_commission_rejected' ), (string) Ledger::find( $ac2_c2 )->status ) );

/* wpac_commission_pending( int $commission_id ): a commission under review is approved while its
 * hold period is still running, so it goes back to "pending" (not yet "approved"). */
$ac2_c3 = $ac2_sale( $ac2_a, 'ac2-3', array( 'review' => true, 'hold_until' => gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS ) ) );
$ac2_st = (string) Ledger::find( $ac2_c3 )->status;
$GLOBALS['test_fired'] = array();
$ac2_ok = Ledger::approve( $ac2_c3, 'Prüfung bestanden' );
check( 'AC-2 review approved during the hold period: wpac_commission_pending( id ) once, outside a transaction, no wpac_commission_approved', array( 'review', true, 'pending', array( array( $ac2_c3 ) ), true, array() ), array( $ac2_st, true === $ac2_ok, (string) Ledger::find( $ac2_c3 )->status, $ac2_args( 'wpac_commission_pending' ), hook_fired_outside_tx( 'wpac_commission_pending' ), $ac2_args( 'wpac_commission_approved' ) ) );

/* wpac_code_request_approved( int $affiliate_id, string $code, int $row_id, bool $make_main ). */
$ac2_b  = $ac2_partner( 'b' );
$ac2_rq = CodeRequests::request( AffiliateRepository::find( $ac2_b ), 'ac2wunsch' );
$GLOBALS['test_fired'] = array();
$ac2_ap = CodeRequests::approve( (int) $ac2_rq, array( 'make_main' => true ) );
$ac2_ca = $ac2_args( 'wpac_code_request_approved' );
check( 'AC-2 CodeRequests::approve( make_main ): wpac_code_request_approved( partner, code, row, true ) once, outside a transaction', array( true, true, 1, $ac2_b, 'string', (int) $ac2_rq, true, true ), array( is_int( $ac2_rq ), ! is_wp_error( $ac2_ap ), count( $ac2_ca ), $ac2_ca ? $ac2_ca[0][0] : 0, $ac2_ca ? gettype( $ac2_ca[0][1] ) : '', $ac2_ca ? $ac2_ca[0][2] : 0, $ac2_ca ? $ac2_ca[0][3] : null, hook_fired_outside_tx( 'wpac_code_request_approved' ) ) );
check( 'AC-2 wpac_code_request_approved passes the approved code, now the main code of the partner', array( true, true ), array( $ac2_ca && 0 === strcasecmp( 'ac2wunsch', (string) $ac2_ca[0][1] ), $ac2_ca && 0 === strcasecmp( (string) CodeRepository::main_code( $ac2_b ), (string) $ac2_ca[0][1] ) ) );
// Without make_main the fourth argument is false.
$ac2_rq2 = CodeRequests::request( AffiliateRepository::find( $ac2_b ), 'ac2zweit' );
$GLOBALS['test_fired'] = array();
CodeRequests::approve( (int) $ac2_rq2 );
$ac2_ca = $ac2_args( 'wpac_code_request_approved' );
check( 'AC-2 CodeRequests::approve without make_main: fourth argument false', array( 1, (int) $ac2_rq2, false ), array( count( $ac2_ca ), $ac2_ca ? $ac2_ca[0][2] : 0, $ac2_ca ? $ac2_ca[0][3] : null ) );

$GLOBALS['test_fired'] = array();
update_option( 'wpac_settings', $ac2_keep );
Settings::flush_cache();
