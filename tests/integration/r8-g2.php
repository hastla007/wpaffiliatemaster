<?php
/**
 * Review round 8, package G2: attribution, lifetime links, fraud rules, partner codes.
 * Included from run.php (uses its helpers check(), sale(), t(), q(), za_set()).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Codes\CodeRepository;
use WPAffiliateCaptain\Commissions\Ledger;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Payouts\ProfileRepository;
use WPAffiliateCaptain\Tracking\AttributionResolver;
use WPAffiliateCaptain\Tracking\CustomerLinks;
use WPAffiliateCaptain\Tracking\FraudCheck;

echo "R8-G2 attribution, lifetime links, fraud, codes\n";

$g2_settings = get_option( 'wpac_settings' );
za_set(
	array(
		'lifetime_enabled'        => 1,
		'lifetime_days'           => 0,
		'lifetime_rate'           => '',
		'self_referral'           => 'block',
		'coupon_priority'         => 0,
		'fraud_max_orders_day'    => 0,
		'fraud_max_order_minor'   => 0,
		'fraud_min_click_seconds' => 0,
		'fraud_max_refund_rate'   => 0,
		'fraud_check_domains'     => 0,
	)
);
CustomerLinks::register_hooks();

/** Sale with full control over customer, code, coupons and touch. */
function g2_sale( $order, array $p ) {
	$nets  = isset( $p['nets'] ) ? $p['nets'] : array( 10000 );
	$items = array();
	foreach ( $nets as $i => $net ) {
		$items[] = array( 'item_id' => (string) ( $i + 1 ), 'product_id' => 50 + $i, 'name' => 'G2 ' . ( $i + 1 ), 'net_minor' => $net );
	}
	return wpac_record_sale(
		array(
			'source'       => 'test',
			'order_id'     => (string) $order,
			'currency'     => isset( $p['currency'] ) ? $p['currency'] : 'EUR',
			'partner_code' => isset( $p['code'] ) ? $p['code'] : '',
			'coupon_codes' => isset( $p['coupons'] ) ? $p['coupons'] : array(),
			'touch'        => isset( $p['touch'] ) ? $p['touch'] : null,
			'affiliate_id' => isset( $p['affiliate_id'] ) ? $p['affiliate_id'] : 0,
			'items'        => $items,
			'customer'     => isset( $p['customer'] ) ? $p['customer'] : array( 'user_id' => 0, 'email' => 'g2@example.de' ),
		)
	);
}
function g2_links( $aff = 0 ) {
	global $wpdb;
	return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . t( 'customer_links' ) . ( $aff ? ' WHERE affiliate_id = ' . (int) $aff : '' ) );
}
function g2_attr( $order ) {
	$a = Ledger::attribution_for( 'test', (string) $order );
	return $a ? (int) $a->affiliate_id : 0;
}

$g2a  = AffiliateRepository::create( array( 'name' => 'G2 Anna', 'email' => 'g2a@example.de', 'status' => 'active' ) );
$g2b  = AffiliateRepository::create( array( 'name' => 'G2 Bert', 'email' => 'g2b@example.de', 'status' => 'active' ) );
$g2x  = AffiliateRepository::create( array( 'name' => 'G2 Xaver', 'email' => 'g2x@example.de', 'status' => 'active' ) );
$g2ca = CodeRepository::main_code( $g2a );
$g2cb = CodeRepository::main_code( $g2b );
$g2cx = CodeRepository::main_code( $g2x );
q( 'DELETE FROM ' . t( 'customer_links' ) );

// R7-FR1: a guest order with a foreign e-mail never binds that customer – also not at 0 €.
g2_sale( 'g2-1', array( 'code' => $g2ca, 'nets' => array( 0 ), 'customer' => array( 'user_id' => 0, 'email' => 'ceo@grosskunde.de' ) ) );
g2_sale( 'g2-2', array( 'code' => $g2ca, 'nets' => array( 1000 ), 'customer' => array( 'user_id' => 0, 'email' => 'ceo@grosskunde.de' ) ) );
$g2r = g2_sale( 'g2-3', array( 'nets' => array( 900000 ), 'customer' => array( 'user_id' => 0, 'email' => 'ceo@grosskunde.de' ) ) );
check( 'R7-FR1 guest orders (0 € and paid) create no lifetime link; the victim\'s order stays unattributed', array( 0, 'unattributed' ), array( g2_links(), $g2r['status'] ) );

// Customer account: user ID and the (verified) account e-mail are linked, only with a commission.
$GLOBALS['test_userdata'][501] = (object) array( 'ID' => 501, 'user_email' => 'kunde501@example.de' );
$GLOBALS['test_userdata'][502] = (object) array( 'ID' => 502, 'user_email' => 'kunde502@example.de' );
$GLOBALS['test_userdata'][503] = (object) array( 'ID' => 503, 'user_email' => 'kunde503@example.de' );
g2_sale( 'g2-4', array( 'code' => $g2ca, 'nets' => array( 0 ), 'customer' => array( 'user_id' => 503, 'email' => 'kunde503@example.de' ) ) );
check( 'R7-FR1 no link for an order without commission (0 €)', 0, g2_links() );
g2_sale( 'g2-5', array( 'code' => $g2ca, 'customer' => array( 'user_id' => 501, 'email' => 'kunde501@example.de' ) ) );
g2_sale( 'g2-6', array( 'code' => $g2ca, 'customer' => array( 'user_id' => 502, 'email' => 'fremd@example.de' ) ) );
check( 'R7-FR1 account order links user + account e-mail; foreign billing e-mail only the account', array( 2, 1, 0 ), array( count( CustomerLinks::for_customer( 501, 'kunde501@example.de' ) ), count( CustomerLinks::for_customer( 502, '' ) ), count( CustomerLinks::for_customer( 0, 'fremd@example.de' ) ) ) );
g2_sale( 'g2-7', array( 'customer' => array( 'user_id' => 0, 'email' => 'kunde501@example.de' ) ) );
check( 'R7-FR1 later guest order with the verified account e-mail is a lifetime sale', $g2a, g2_attr( 'g2-7' ) );
check( 'R7-FR1 admin removes a customer binding', array( 2, array() ), array( CustomerLinks::unlink_customer( 501, 'kunde501@example.de', 'test' ), CustomerLinks::for_customer( 501, 'kunde501@example.de' ) ) );
check( 'R7-FR1 removal is audited', 1, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . t( 'audit' ) . " WHERE action = 'customer_links.unlinked'" ) );

// R7-AD10 / R5-W38: terminated partner loses the customers; a paused partner's link does not hide another partner's.
q( 'DELETE FROM ' . t( 'customer_links' ) );
$GLOBALS['test_userdata'][510] = (object) array( 'ID' => 510, 'user_email' => 'kunde510@example.de' );
$g2h = CustomerLinks::hashes( 510, 'kunde510@example.de' );
$wpdb->insert( t( 'customer_links' ), array( 'affiliate_id' => $g2x, 'customer_hash' => $g2h[0], 'source' => 'test', 'source_order_id' => 'old-1', 'created_at' => Db::now( time() - 100 ) ) );
$wpdb->insert( t( 'customer_links' ), array( 'affiliate_id' => $g2b, 'customer_hash' => $g2h[1], 'source' => 'test', 'source_order_id' => 'old-2', 'created_at' => Db::now() ) );
AffiliateRepository::set_status( $g2x, 'paused', 'Pause' );
g2_sale( 'g2-8', array( 'customer' => array( 'user_id' => 510, 'email' => 'kunde510@example.de' ) ) );
check( 'R5-W38 link of a paused partner does not hide the active partner\'s link', $g2b, g2_attr( 'g2-8' ) );
AffiliateRepository::set_status( $g2x, 'active', '' );
$g2pre = g2_links( $g2b );
AffiliateRepository::set_status( $g2b, 'terminated', 'Vertrag beendet' );
check( 'R5-W38 terminating a partner removes the partner\'s customer links', array( true, 0 ), array( $g2pre > 0, g2_links( $g2b ) ) );
AffiliateRepository::set_status( $g2b, 'active', 'Reaktiviert' );
za_set( array( 'lifetime_enabled' => 0 ) );
CustomerLinks::purge_expired();
check( 'R7-AD10 lifetime switched off → the daily clean-up deletes all links (also lifetime_days = 0)', 0, g2_links() );
za_set( array( 'lifetime_enabled' => 1 ) );

// R7-TR4: link under review becomes active only after the approval.
q( 'DELETE FROM ' . t( 'customer_links' ) );
$GLOBALS['test_userdata'][520] = (object) array( 'ID' => 520, 'user_email' => 'kunde520@example.de' );
za_set( array( 'fraud_max_order_minor' => 5000 ) );
$g2r = g2_sale( 'g2-9', array( 'code' => $g2ca, 'nets' => array( 20000 ), 'customer' => array( 'user_id' => 520, 'email' => 'kunde520@example.de' ) ) );
za_set( array( 'fraud_max_order_minor' => 0 ) );
$g2pending = array( Ledger::find( $g2r['commission_ids'][0] )->status, count( CustomerLinks::for_customer( 520, 'kunde520@example.de' ) ), CustomerLinks::find_all( CustomerLinks::hashes( 520, '' ) ) );
Ledger::approve( $g2r['commission_ids'][0], 'ok' );
check( 'R7-TR4 link waits during review and is active after approval', array( 'review', 2, array(), 2 ), array_merge( $g2pending, array( count( CustomerLinks::find_all( CustomerLinks::hashes( 520, 'kunde520@example.de' ) ) ) ) ) );

// R7-TR10: a self-referral link of partner X does not cost the lifetime partner A the order.
g2_sale( 'g2-10', array( 'code' => $g2cx, 'customer' => array( 'user_id' => 520, 'email' => 'g2x@example.de' ) ) );
check( 'R7-TR10 self-referral candidate skipped, lifetime partner keeps the order', array( $g2a, 'lifetime' ), array( g2_attr( 'g2-10' ), (string) Ledger::attribution_for( 'test', 'g2-10' )->method ) );
$g2r = g2_sale( 'g2-11', array( 'code' => $g2cx, 'customer' => array( 'user_id' => 0, 'email' => 'g2x@example.de' ) ) );
check( 'R7-TR10 without another candidate the self-referral is still blocked', array( 'unattributed', 'self_referral' ), array( $g2r['status'], $g2r['reason'] ) );

// R7-FR3: billing name/address compared with the partner's payout profile.
check(
	'R7-FR3 profile match: name + postcode, street + postcode; name alone or other postcode no match',
	array( true, true, false, false ),
	array(
		AttributionResolver::matches_profile( array( 'name' => 'Xaver Müller', 'postcode' => '12345' ), array( 'Xaver Mueller' ), "Seestraße 5\n12345 Berlin" ),
		AttributionResolver::matches_profile( array( 'name' => 'Frau Y', 'address' => 'Seestr. 5', 'postcode' => '12345' ), array( 'Xaver Mueller' ), "Seestraße 5\n12345 Berlin" ),
		AttributionResolver::matches_profile( array( 'name' => 'Xaver Mueller', 'postcode' => '' ), array( 'Xaver Mueller' ), "Seestraße 5\n12345 Berlin" ),
		AttributionResolver::matches_profile( array( 'name' => 'Xaver Mueller', 'postcode' => '54321' ), array( 'Xaver Mueller' ), "Seestraße 5\n12345 Berlin" ),
	)
);
ProfileRepository::save( $g2x, array( 'tax_status' => 'private', 'legal_name' => 'Xaver Mueller', 'address' => "Seestraße 5\n12345 Berlin", 'country' => 'DE', 'account_holder' => 'Xaver Mueller', 'iban' => 'DE02120300000000202051' ) );
$g2r = g2_sale( 'g2-12', array( 'code' => $g2cx, 'customer' => array( 'user_id' => 0, 'email' => 'zweit@example.de', 'name' => 'Xaver Müller', 'address' => 'Seestr. 5', 'postcode' => '12345' ) ) );
check( 'R7-FR3 guest order with second address of the partner is a self-referral', array( 'unattributed', 'self_referral' ), array( $g2r['status'], $g2r['reason'] ) );
check( 'R7-FR3 billing name/address never stored in the retry queue', array( 'user_id' => 0 ), WPAffiliateCaptain\Sales\ContractApi::strip_personal_data( array( 'customer' => array( 'user_id' => 0, 'email' => 'a@b.de', 'name' => 'X', 'address' => 'Y', 'postcode' => '1' ) ) )['customer'] );
ProfileRepository::save( $g2b, array( 'tax_status' => 'private', 'legal_name' => 'Bert Zwei', 'address' => "Weg 1\n99999 Ort", 'country' => 'DE', 'account_holder' => 'Bert Zwei', 'iban' => 'DE02 1203 0000 0000 2020 51' ) );
check( 'R7-FR3 the same IBAN at two partners is reported for both', array( array( $g2x ), array( $g2b ), true ), array( ProfileRepository::shared_iban( $g2b ), ProfileRepository::shared_iban( $g2x ), (bool) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . t( 'audit' ) . " WHERE action = 'payout_profile.iban_shared'" ) ) );
$g2r = g2_sale( 'g2-13', array( 'code' => $g2cb, 'customer' => array( 'user_id' => 0, 'email' => 'k13@example.de' ) ) );
check( 'R7-FR3 orders of a partner with a shared IBAN go to review', array( 'review', 'shared_iban' ), array( Ledger::find( $g2r['commission_ids'][0] )->status, (string) Ledger::attribution_for( 'test', 'g2-13' )->note ) );
ProfileRepository::save( $g2b, array( 'tax_status' => 'private', 'legal_name' => 'Bert Zwei', 'address' => "Weg 1\n99999 Ort", 'country' => 'DE', 'account_holder' => 'Bert Zwei', 'iban' => 'GB82WEST12345698765432' ) );
check( 'R7-FR3 a changed IBAN clears the report on both sides', array( array(), array() ), array( ProfileRepository::shared_iban( $g2b ), ProfileRepository::shared_iban( $g2x ) ) );

// R7-FR4: partner coupon beating another partner's link is flagged.
$g2cc = AffiliateRepository::create( array( 'name' => 'G2 Coupon', 'email' => 'g2c@example.de', 'status' => 'active' ) );
CodeRepository::create( $g2cc, 'G2SOMMER', array( 'coupon_code' => 'g2sommer' ) );
za_set( array( 'coupon_priority' => 1 ) );
g2_sale( 'g2-14', array( 'coupons' => array( 'g2sommer' ), 'touch' => array( 'code' => $g2ca, 'method' => 'link', 'ts' => time() - 3600 ), 'customer' => array( 'user_id' => 0, 'email' => 'k14@example.de' ) ) );
za_set( array( 'coupon_priority' => 0 ) );
$g2at = Ledger::attribution_for( 'test', 'g2-14' );
check( 'R7-FR4 coupon over another partner\'s link → review "touch_overridden"', array( $g2cc, 'touch_overridden' ), array( (int) $g2at->affiliate_id, (string) $g2at->note ) );

// R7-FR6: recommended thresholds for new installations; missing exchange rate is logged.
$g2rec = Settings::recommended_fraud( 'EUR' );
check( 'R7-FR6 new installations start with fraud thresholds', array( 50, 200000, 5, 30 ), array( $g2rec['fraud_max_orders_day'], $g2rec['fraud_max_order_minor'], $g2rec['fraud_min_click_seconds'], $g2rec['fraud_max_refund_rate'] ) );
za_set( array( 'fraud_max_order_minor' => 5000, 'exchange_rates' => '' ) );
delete_transient( 'wpac_fx_missing_jpy' );
FraudCheck::flags( array( 'currency' => 'JPY', 'items' => array( array( 'net_minor' => 999999 ) ), 'coupon_codes' => array(), 'customer' => array(), 'touch' => null, 'paid_at' => '' ), array( 'affiliate_id' => $g2a, 'method' => 'code' ) );
za_set( array( 'fraud_max_order_minor' => 0 ) );
check( 'R7-FR6 high-value rule without exchange rate is logged, not skipped silently', true, (bool) get_transient( 'wpac_fx_missing_jpy' ) );

// R7-DB10: refund rate figures cached per partner.
za_set( array( 'fraud_max_refund_rate' => 50 ) );
delete_transient( 'wpac_refund_stats_' . $g2a );
$g2s1 = FraudCheck::refund_stats( $g2a );
g2_sale( 'g2-15', array( 'code' => $g2ca, 'customer' => array( 'user_id' => 0, 'email' => 'k15@example.de' ) ) );
check( 'R7-DB10 refund figures are cached between checkouts', array( true, $g2s1 ), array( is_array( get_transient( 'wpac_refund_stats_' . $g2a ) ), FraudCheck::refund_stats( $g2a ) ) );
za_set( array( 'fraud_max_refund_rate' => 0 ) );

// R7-DB1: usage limit is enforced by a conditional UPDATE.
$g2lim = CodeRepository::create( $g2a, 'G2LIMIT', array( 'usage_limit' => 1 ) );
$g2r1  = g2_sale( 'g2-16', array( 'code' => 'G2LIMIT', 'customer' => array( 'user_id' => 0, 'email' => 'k16@example.de' ) ) );
check( 'R7-DB1 counting at the limit fails atomically', array( 'created', false, 1 ), array( $g2r1['status'], CodeRepository::increment_usage( $g2lim ), (int) CodeRepository::find( $g2lim )->usage_count ) );
// Simulate a parallel checkout that read the code before the first one counted it.
add_filter( 'wpac_resolve_partner_code', $g2stale = static function ( $r, $code ) use ( $g2lim, $g2a ) { return 'G2LIMIT' === $code ? array( 'affiliate_id' => $g2a, 'code_id' => $g2lim, 'code' => 'G2LIMIT' ) : $r; }, 5, 2 );
q( 'UPDATE ' . t( 'partner_codes' ) . " SET usage_count = 1 WHERE id = {$g2lim}" );
$g2r2 = g2_sale( 'g2-17', array( 'code' => 'G2LIMIT', 'customer' => array( 'user_id' => 0, 'email' => 'k17@example.de' ) ) );
remove_all_filters( 'wpac_resolve_partner_code' );
WPAffiliateCaptain\Codes\CodeRepository::register_hooks();
check( 'R7-DB1 an order that passed a stale limit check is not booked (no commission, count stays 1)', array( 'unattributed', 'code_limit', 0, 1 ), array( $g2r2['status'], $g2r2['reason'], (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . t( 'commissions' ) . " WHERE source_order_id = 'g2-17'" ), (int) CodeRepository::find( $g2lim )->usage_count ) );

// R6-QA9: non-ASCII coupons get a usable partner code; lossy normalization never matches another code.
check( 'R6-QA9 umlaut coupon → transliterated code, Cyrillic/€ → random code', array( 'GROESSE10', 'MUELLER', 1, 1 ), array( CodeRepository::code_for_coupon( 'größe10' ), CodeRepository::code_for_coupon( 'MÜLLER' ), preg_match( '/^[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/', CodeRepository::code_for_coupon( 'лето20' ) ), preg_match( '/^[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/', CodeRepository::code_for_coupon( '€10' ) ) ) );
CodeRepository::create( $g2b, 'MLLER' );
$g2cy = CodeRepository::create( $g2a, CodeRepository::code_for_coupon( 'лето20' ), array( 'coupon_code' => 'лето20' ) );
check( 'R6-QA9 Cyrillic coupon resolves; "müller" does not match the code "MLLER"', array( $g2a, null ), array( apply_filters( 'wpac_coupon_affiliate', null, 'ЛЕТО20' )['affiliate_id'] ?? null, apply_filters( 'wpac_coupon_affiliate', null, 'müller' ) ) );

// R7-PU5: coupon links are checked (exists in WooCommerce, not linked twice).
if ( ! function_exists( 'wc_get_coupon_id_by_code' ) ) {
	// Coupons created through the WC_Coupon stub of r10-j2.php are registered in $GLOBALS['wc_test_coupons'].
	function wc_get_coupon_id_by_code( $code ) { $lc = mb_strtolower( trim( (string) $code ), 'UTF-8' ); return isset( $GLOBALS['wc_test_coupons'][ $lc ] ) ? (int) $GLOBALS['wc_test_coupons'][ $lc ] : ( in_array( $code, array( 'g2sommer', 'g2neu' ), true ) ? 77 : 0 ); }
}
$g2own = CodeRepository::find_by_coupon( 'g2sommer' );
check(
	'R7-PU5 unknown coupon and double link are refused, own link and new coupon are fine',
	array( 'wpac_coupon_missing', 'wpac_coupon_taken', true, true ),
	array(
		CodeRepository::check_coupon( 'g2tippfehler' )->get_error_code(),
		CodeRepository::check_coupon( 'G2Sommer' )->get_error_code(),
		CodeRepository::check_coupon( 'g2sommer', (int) $g2own->id ),
		CodeRepository::check_coupon( 'g2neu' ),
	)
);

// R7-FR2: with the checkout field off, a posted partner code is ignored.
class G2_Order {
	public $meta = array();
	public function update_meta_data( $k, $v ) { $this->meta[ $k ] = $v; }
}
$_POST['wpac_partner_code'] = $g2cx;
$g2o                        = new G2_Order();
za_set( array( 'checkout_field' => 0 ) );
WPAffiliateCaptain\Integrations\WooCommerce\CheckoutField::classic_save( $g2o, array() );
$g2off = isset( $g2o->meta[ WPAffiliateCaptain\Integrations\WooCommerce::META_CODE ] );
za_set( array( 'checkout_field' => 1 ) );
WPAffiliateCaptain\Integrations\WooCommerce\CheckoutField::classic_save( $g2o, array() );
unset( $_POST['wpac_partner_code'] );
check( 'R7-FR2 posted code ignored while the checkout field is off', array( false, true ), array( $g2off, isset( $g2o->meta[ WPAffiliateCaptain\Integrations\WooCommerce::META_CODE ] ) ) );

// R5-O32: manual assignment to a paused/restricted partner is refused with a message.
if ( ! function_exists( 'wp_verify_nonce' ) ) {
	function wp_verify_nonce( $n, $a ) { return 1; }
}
$GLOBALS['za_orders'][7301] = new ZA_Order( 7301 );
AffiliateRepository::set_status( $g2x, 'paused', 'Pause' );
$_POST = array( '_wpac_order_nonce' => 'x', 'wpac_assign' => (string) $g2x, 'wpac_assign_reason' => 'Telefonbestellung' );
WPAffiliateCaptain\Integrations\WooCommerce\OrderMetaBox::save_meta_box( 7301 );
$_POST = array();
$g2n   = get_transient( 'wpac_order_notice_' . get_current_user_id() );
check( 'R5-O32 assignment to a paused partner refused, nothing stored', array( 'error', '' ), array( is_array( $g2n ) ? $g2n['type'] : '', $GLOBALS['za_orders'][7301]->get_meta( WPAffiliateCaptain\Integrations\WooCommerce::META_MANUAL ) ) );
AffiliateRepository::set_status( $g2x, 'active', '' );

update_option( 'wpac_settings', $g2_settings );
Settings::flush_cache();
