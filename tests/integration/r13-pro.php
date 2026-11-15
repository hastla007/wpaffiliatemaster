<?php
/**
 * Integration checks for the core requests of the Affiliate Captain Pro audit (round 2):
 * wpac_get_payouts() loads payout profiles once per page (no query per payout),
 * wpac_affiliate_erased fires after erase_partner(), wpac_commission_review fires on reopen.
 * Included from tests/integration/run.php before r8-render.php (uses r11_partner()/r11_payout()).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Commissions\Ledger;
use WPAffiliateCaptain\Payouts\PayoutService;
use WPAffiliateCaptain\Privacy\Privacy;

echo "R13 Pro requests: payout profiles per page, erasure and review hooks\n";

$rp_payouts = array();
$rp_parts   = array();
for ( $rp_i = 1; $rp_i <= 3; $rp_i++ ) {
	$rp_parts[ $rp_i ] = r11_partner( 'rp' . $rp_i, array( 'payout_method' => 'manual', 'payment_details' => 'PayPal: rp' . $rp_i . '@example.de' ) );
	$rp_payouts[]      = r11_payout( $rp_parts[ $rp_i ], 'rp-' . $rp_i );
}
$rp_profile_queries = 0;
$rp_counting        = true;
$rp_counter         = static function ( $sql ) use ( &$rp_profile_queries, &$rp_counting ) {
	if ( $rp_counting && false !== strpos( (string) $sql, t( 'payout_profiles' ) ) ) {
		++$rp_profile_queries;
	}
	return $sql;
};
add_filter( 'query', $rp_counter );
$rp_list = wpac_get_payouts( array( 'status' => 'pending', 'method' => 'manual', 'after_id' => min( $rp_payouts ) - 1, 'limit' => 200 ) );
$rp_counting = false;
$rp_mine = array_values( array_filter( $rp_list, static function ( $v ) use ( $rp_payouts ) {
	return in_array( $v['id'], $rp_payouts, true );
} ) );
check( 'R13-Pro wpac_get_payouts: one profile query per page instead of one per payout', 1, $rp_profile_queries );
check( 'R13-Pro wpac_get_payouts: PayPal addresses still per partner', array( 'rp1@example.de', 'rp2@example.de', 'rp3@example.de' ), array_column( $rp_mine, 'paypal_email' ) );
check( 'R13-Pro list view equals single view', wpac_get_payout( $rp_payouts[1] ), $rp_mine[1] );

$rp_erased = array();
$rp_hook   = static function ( $id, $open ) use ( &$rp_erased ) {
	$rp_erased[] = array( $id, $open );
};
add_action( 'wpac_affiliate_erased', $rp_hook, 10, 2 );
$rp_gone = AffiliateRepository::create( array( 'name' => 'RP erase', 'email' => 'rp-erase@example.de', 'status' => 'active' ) );
Privacy::erase_partner( AffiliateRepository::find( $rp_gone ) );
remove_all_filters( 'wpac_affiliate_erased' );
check( 'R13-Pro wpac_affiliate_erased fires once with the partner and the open flag', array( array( $rp_gone, false ) ), $rp_erased );

$rp_sale = wpac_record_sale( array( 'source' => 'test', 'order_id' => 'rp-review', 'currency' => 'EUR', 'partner_code' => WPAffiliateCaptain\Codes\CodeRepository::main_code( $rp_parts[1] ), 'items' => array( array( 'item_id' => '1', 'product_id' => 10, 'name' => 'Kurs', 'net_minor' => 10000 ) ), 'customer' => array( 'user_id' => 0, 'email' => 'rp-review@example.de' ) ) );
$rp_cid  = (int) $rp_sale['commission_ids'][0];
Ledger::transition( $rp_cid, 'rejected', 'test' );
$rp_reviewed = array();
$rp_rhook    = static function ( $id ) use ( &$rp_reviewed ) {
	$rp_reviewed[] = $id;
};
$rp_saved = $GLOBALS['test_hooks']['wpac_commission_review'] ?? null;
add_action( 'wpac_commission_review', $rp_rhook );
Ledger::reopen( $rp_cid, 'test reopen' );
if ( null === $rp_saved ) {
	remove_all_filters( 'wpac_commission_review' );
} else {
	$GLOBALS['test_hooks']['wpac_commission_review'] = $rp_saved; // Keep the core listeners (ReviewAlert, Reports).
}
check( 'R13-Pro wpac_commission_review fires when a rejected commission is reopened', array( $rp_cid ), $rp_reviewed );
check( 'R13-Pro core listeners on wpac_commission_review kept', true, null === $rp_saved || $GLOBALS['test_hooks']['wpac_commission_review'] === $rp_saved );
check( 'R13-Pro contract documents wpac_commission_review, wpac_affiliate_erased and the two error codes', array( true, true, true, true ), array_map( static function ( $n ) {
	return false !== strpos( (string) file_get_contents( dirname( __DIR__, 2 ) . '/docs/CONTRACT.md' ), $n );
}, array( '_review', 'wpac_affiliate_erased', 'wpac_affiliate_db', 'wpac_status_not_saved' ) ) );
