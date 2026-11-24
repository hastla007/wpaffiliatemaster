<?php
/**
 * Fix round 24.11.2026 (fops): dunning stages kept in the history, nothing after a final
 * reminder, lock and form token against double execution, repayment booked only once (P2-12).
 * Included from run.php right before r8-render.php.
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Commissions\Dunning;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\Settings;

echo "R24 fops: dunning stages, locks and repeated repayments (P2-12)\n";

global $wpdb;
$r24_saved = get_option( 'wpac_settings' );
$r24_set   = static function ( array $values ) use ( $r24_saved ) {
	update_option( 'wpac_settings', array_merge( is_array( $r24_saved ) ? $r24_saved : array(), $values ) );
	Settings::flush_cache();
};
$r24_ct  = Db::table( 'commissions' );
$r24_new = static function ( $tag, $minor = -5000 ) use ( $wpdb, $r24_ct ) {
	$aff = AffiliateRepository::create( array( 'name' => 'R24 ' . $tag, 'email' => 'r24-' . $tag . '@example.de', 'status' => 'active' ) );
	$wpdb->insert( $r24_ct, array( 'affiliate_id' => $aff, 'source' => 'test', 'source_order_id' => 'r24-' . $tag, 'kind' => 'refund', 'currency' => 'EUR', 'amount_minor' => $minor, 'status' => 'approved', 'idempotency_key' => 'r24-' . $tag, 'created_at' => Db::now(), 'updated_at' => Db::now() ) );
	WPAffiliateCaptain\Commissions\Balance::post( $aff, 'EUR', 'approved', $minor, (int) $wpdb->insert_id, 0, 'test' );
	AffiliateRepository::set_status( $aff, 'terminated', 'Ende' );
	return $aff;
};
$r24_code = static function ( $r ) {
	return is_wp_error( $r ) ? $r->get_error_code() : $r;
};
$r24_stages = static function ( $aff ) {
	return array_map( static function ( $h ) { return isset( $h['stage'] ) ? $h['stage'] : '?'; }, Dunning::history( $aff, 'EUR' ) );
};
$r24_bank = array( 'debtor_name' => 'Firma GmbH', 'debtor_iban' => 'DE89370400440532013000', 'dunning_days' => 14, 'dunning_final_days' => 7 );

// Three stages sent (the last one "final"), then the setting is raised to 5: nothing more.
$r24_set( $r24_bank + array( 'dunning_levels' => 3 ) );
$r24_a = $r24_new( 'a' );
$r24_r = array( Dunning::remind( $r24_a, 'EUR' ), Dunning::remind( $r24_a, 'EUR' ), Dunning::remind( $r24_a, 'EUR' ) );
$r24_set( $r24_bank + array( 'dunning_levels' => 5 ) );
$GLOBALS['test_mails'] = array();
check(
	'P2-12 stage kind stored; after a final reminder nothing more, even when dunning_levels is raised to 5',
	array( array( 1, 2, 3 ), array( 'request', 'reminder', 'final' ), 'wpac_dunning_done', 0, null, 'final' ),
	array( $r24_r, $r24_stages( $r24_a ), $r24_code( Dunning::remind( $r24_a, 'EUR' ) ), count( $GLOBALS['test_mails'] ), Dunning::next_stage( Dunning::history( $r24_a, 'EUR' ) ), Dunning::stage( 3, Dunning::history( $r24_a, 'EUR' ) ) )
);

// Setting lowered to the stages sent (no final reminder yet): done, marked "ended without final".
$r24_set( $r24_bank + array( 'dunning_levels' => 5 ) );
$r24_b = $r24_new( 'b' );
Dunning::remind( $r24_b, 'EUR' );
Dunning::remind( $r24_b, 'EUR' );
$r24_set( $r24_bank + array( 'dunning_levels' => 2 ) );
check(
	'P2-12 dunning_levels lowered to the sent stages: no further stage, ended without final reminder (only then)',
	array( 'wpac_dunning_done', array( 'request', 'reminder' ), true, false ),
	array( $r24_code( Dunning::remind( $r24_b, 'EUR' ) ), $r24_stages( $r24_b ), Dunning::ended_without_final( Dunning::history( $r24_b, 'EUR' ) ), Dunning::ended_without_final( Dunning::history( $r24_a, 'EUR' ) ) )
);

// Old history entries without a stored kind are judged by the current setting (as before).
$r24_set( $r24_bank + array( 'dunning_levels' => 3 ) );
check(
	'P2-12 legacy entries without kind: judged by the setting; next stage after legacy level 2 is the final one',
	array( 'request', 'reminder', 'final', array( 'level' => 3, 'stage' => 'final' ) ),
	array(
		Dunning::entry_stage( array( 'level' => 1 ) ),
		Dunning::entry_stage( array( 'level' => 2 ) ),
		Dunning::entry_stage( array( 'level' => 3 ) ),
		Dunning::next_stage( array( array( 'level' => 1 ), array( 'level' => 2 ) ) ),
	)
);

// Form submitted twice: the second call (same expected level) sends nothing.
$r24_c = $r24_new( 'c' );
$GLOBALS['test_mails'] = array();
$r24_c1 = Dunning::remind( $r24_c, 'EUR', 1 );
$r24_c2 = Dunning::remind( $r24_c, 'EUR', 1 );
check(
	'P2-12 payment request form submitted twice (expected level 1): stage 1 sent once, second call refused',
	array( 1, 'wpac_dunning_sent', 1, 1 ),
	array( $r24_c1, $r24_code( $r24_c2 ), count( Dunning::history( $r24_c, 'EUR' ) ), count( $GLOBALS['test_mails'] ) )
);

// Parallel request: while the lock of partner and currency is held, nothing is sent or booked.
$r24_lock  = 'wpac_lock_dunning_' . (int) $r24_c . '_EUR';
$r24_owner = Db::lock( $r24_lock, 120 );
$GLOBALS['test_mails'] = array();
$r24_par = array( $r24_code( Dunning::remind( $r24_c, 'EUR' ) ), $r24_code( Dunning::record_repayment( $r24_c, 'EUR', 100, 'parallel' ) ), count( $GLOBALS['test_mails'] ), count( Dunning::history( $r24_c, 'EUR' ) ) );
Db::unlock( $r24_lock, $r24_owner );
check( 'P2-12 lock held by a parallel request: remind() and record_repayment() refused, nothing sent or booked', array( 'wpac_dunning_locked', 'wpac_dunning_locked', 0, 1 ), $r24_par );
check( 'P2-12 lock released after remind(): the next stage can be sent', 2, Dunning::remind( $r24_c, 'EUR', 2 ) );

// Repayment with the same bank reference and amount twice: booked once.
$r24_d   = $r24_new( 'd', -10000 );
$r24_cnt = static function ( $aff ) use ( $wpdb, $r24_ct ) {
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$r24_ct} WHERE affiliate_id = %d AND kind = 'repayment'", $aff ) );
};
$r24_p1 = Dunning::record_repayment( $r24_d, 'EUR', 5000, 'Bank ref 4711' );
$r24_p2 = Dunning::record_repayment( $r24_d, 'EUR', 5000, 'Bank ref 4711' );
check(
	'P2-12 repayment 50 € "Bank ref 4711" entered twice: booked once, second refused, balance -50 €',
	array( true, 'wpac_repayment_repeat', 1, -5000 ),
	array( is_int( $r24_p1 ) && $r24_p1 > 0, $r24_code( $r24_p2 ), $r24_cnt( $r24_d ), Dunning::open( $r24_d, 'EUR' ) )
);
// Same form token twice (different note): booked once. Another reference: a second payment.
$r24_e  = $r24_new( 'e', -10000 );
$r24_t1 = Dunning::record_repayment( $r24_e, 'EUR', 2000, 'Ref A', 'tok123abc' );
$r24_t2 = Dunning::record_repayment( $r24_e, 'EUR', 3000, 'Ref B', 'tok123abc' );
$r24_t3 = Dunning::record_repayment( $r24_e, 'EUR', 2000, 'Ref C', 'tok456def' );
check(
	'P2-12 form token used twice: second booking refused; another reference with a new token is a second payment',
	array( true, 'wpac_repayment_repeat', true, 2, -6000 ),
	array( is_int( $r24_t1 ), $r24_code( $r24_t2 ), is_int( $r24_t3 ), $r24_cnt( $r24_e ), Dunning::open( $r24_e, 'EUR' ) )
);
// Outside the window the same reference and amount can be booked again (e.g. a monthly instalment).
$wpdb->query( $wpdb->prepare( "UPDATE {$r24_ct} SET created_at = %s WHERE affiliate_id = %d AND kind = 'repayment'", gmdate( 'Y-m-d H:i:s', time() - Dunning::REPAYMENT_REPEAT_WINDOW - 5 ), $r24_d ) );
check( 'P2-12 same reference and amount after the repeat window: booked (settles the balance)', array( true, 0 ), array( is_int( Dunning::record_repayment( $r24_d, 'EUR', 5000, 'Bank ref 4711' ) ), Dunning::open( $r24_d, 'EUR' ) ) );

update_option( 'wpac_settings', $r24_saved );
Settings::flush_cache();
