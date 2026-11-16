<?php
/**
 * Package L2 (suite integration Form Captain / Review Captain, 15.11.2026):
 * - L2-DEDUP: one commission per order across the sales sources (WooCommerce with a partner
 *   coupon of Voucher Captain, Pass Captain replays, WooCommerce order with Pass tickets,
 *   the same external order via REST /sales and the PHP contract).
 * - L2-PII: partner-facing outputs (partner REST /me and /me/commissions, partner CSV, partner
 *   mails, the events of a sale) never carry the buyer's e-mail, name or address. The rendered
 *   partner area is checked in r15-l2-render.php (needs the template stubs of r8-render.php).
 * Included from run.php before r8-render.php (helpers check(), t(), ZA_Order/ZA_Item of run.php,
 * WP_REST_Request of ze-import-rest.php, wc_get_coupon_id_by_code() of r8-g2.php).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Affiliates\Notifications;
use WPAffiliateCaptain\Codes\CodeRepository;
use WPAffiliateCaptain\Commissions\Ledger;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Integrations\WooCommerce\OrderSync;
use WPAffiliateCaptain\Portal\Portal;
use WPAffiliateCaptain\Rest\RestApi;

echo "R15 L2 suite integration: one commission per order, no buyer data for partners\n";
global $wpdb;

$l2_keep_settings = get_option( 'wpac_settings' );
$l2_keep_user     = $GLOBALS['test_user'];
$l2_keep_caps     = isset( $GLOBALS['test_caps'] ) ? $GLOBALS['test_caps'] : null;
$l2_keep_orders   = isset( $GLOBALS['za_orders'] ) ? $GLOBALS['za_orders'] : array();
unset( $GLOBALS['test_caps'] );
update_option( 'wpac_settings', array_merge( get_option( 'wpac_settings' ), array( 'coupon_priority' => 0, 'renewal_commission' => 0 ) ) );
Settings::flush_cache();

/** WooCommerce order with coupons and buyer data (billing getters as in WC_Order). */
class R15L2_Order extends ZA_Order {
	public $coupons = array();
	public $buyer   = array();
	public function get_coupon_codes() { return $this->coupons; }
	public function get_customer_id() { return 0; }
	public function get_billing_email() { return $this->buyer['email'] ?? ''; }
	public function get_billing_first_name() { return $this->buyer['first'] ?? ''; }
	public function get_billing_last_name() { return $this->buyer['last'] ?? ''; }
	public function get_billing_address_1() { return $this->buyer['address'] ?? ''; }
	public function get_billing_postcode() { return $this->buyer['postcode'] ?? ''; }
	public function get_billing_phone() { return $this->buyer['phone'] ?? ''; }
}

$l2_buyer = array(
	'email'    => 'erika.kaeuferin-l2@example.de',
	'first'    => 'Erika',
	'last'     => 'Kaeuferin-L2',
	'address'  => 'Fliederweg 9b',
	'postcode' => '24939',
	'phone'    => '0461-998877',
);
// Strings that must never reach a partner (e-mail, its local part, surname, street, phone).
$l2_needles = array( 'erika.kaeuferin-l2', 'kaeuferin-l2@', 'Kaeuferin-L2', 'Fliederweg', '0461-998877', 'Erika Kaeuferin' );
$l2_leaks   = static function ( $haystack ) use ( $l2_needles ) {
	$text = is_string( $haystack ) ? $haystack : (string) json_encode( $haystack, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR );
	$hit  = array();
	foreach ( $l2_needles as $n ) {
		if ( false !== stripos( $text, $n ) ) {
			$hit[] = $n;
		}
	}
	return $hit;
};
$l2_order = static function ( $id, array $coupons = array() ) use ( $l2_buyer ) {
	$o          = new R15L2_Order( $id );
	$o->coupons = $coupons;
	$o->buyer   = $l2_buyer;
	$GLOBALS['za_orders'][ (int) $id ] = $o;
	return $o;
};
// Bookings of an order: attributions and sale commission rows (ZA_Order has one line item).
$l2_bookings = static function ( $source, $order ) use ( $wpdb ) {
	return array(
		(int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . t( 'attributions' ) . ' WHERE source = %s AND source_order_id = %s', $source, (string) $order ) ),
		(int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . t( 'commissions' ) . " WHERE source = %s AND source_order_id = %s AND kind = 'sale'", $source, (string) $order ) ),
	);
};
$l2_partner_of = static function ( $source, $order ) {
	$a = Ledger::attribution_for( $source, (string) $order );
	return $a ? (int) $a->affiliate_id : 0;
};
$l2_status = static function ( $r ) {
	return is_wp_error( $r ) ? $r->get_error_code() : ( is_array( $r ) ? $r['status'] : 'none' );
};

$l2_a = (int) AffiliateRepository::create( array( 'name' => 'L2 Voucher-Partner', 'email' => 'l2-voucher@example.de', 'status' => 'active' ) );
$l2_b = (int) AffiliateRepository::create( array( 'name' => 'L2 Direkt-Partner', 'email' => 'l2-direkt@example.de', 'status' => 'active' ) );
$l2_c = (int) AffiliateRepository::create( array( 'name' => 'L2 Kampagnen-Partner', 'email' => 'l2-kampagne@example.de', 'status' => 'active' ) );

/* ---------------------------------------------------------------------------------------------
 * (a) WooCommerce order with a partner coupon of Voucher Captain (wpac_coupon_affiliate, prio 20).
 * ------------------------------------------------------------------------------------------- */
// Voucher Captain's adapter as shipped (src/Compat/AffiliateCaptain.php): priority 20, an earlier
// answer (the direct coupon link of Affiliate Captain at priority 10) is passed through unchanged.
$l2_vc_campaigns = array(
	'l2-vc-sommer' => CodeRepository::main_code( $l2_a ),
	'l2-direkt'    => CodeRepository::main_code( $l2_c ), // The same coupon is also in a VC campaign of partner C.
);
$l2_vc_calls = 0;
add_filter(
	'wpac_coupon_affiliate',
	static function ( $resolved, $coupon ) use ( $l2_vc_campaigns, &$l2_vc_calls ) {
		++$l2_vc_calls;
		if ( null !== $resolved ) {
			return $resolved;
		}
		$code = $l2_vc_campaigns[ strtolower( (string) $coupon ) ] ?? '';
		$r    = '' !== $code ? wpac_resolve_partner_code( $code, array( 'context' => 'coupon', 'source' => 'voucher-captain' ) ) : null;
		return $r ? $r : $resolved;
	},
	20,
	2
);

$l2_order( 915001, array( 'l2-vc-sommer' ) );
// payment_complete, processing and completed all call sync_order() for the same order.
OrderSync::sync_order( 915001 );
OrderSync::sync_order( 915001 );
OrderSync::sync_order( 915001 );
check( 'L2-DEDUP-a coupon of a Voucher Captain campaign: booked once for the campaign partner', array( array( 1, 1 ), $l2_a, true ), array( $l2_bookings( 'woocommerce', '915001' ), $l2_partner_of( 'woocommerce', '915001' ), $l2_vc_calls > 0 ) );
$l2_replay = $l2_status( wpac_record_sale( OrderSync::payload( $GLOBALS['za_orders'][915001] ) ) );
check( 'L2-DEDUP-a replay of the same WooCommerce payload is a duplicate', array( 'duplicate', array( 1, 1 ) ), array( $l2_replay, $l2_bookings( 'woocommerce', '915001' ) ) );

// Direct coupon link in Affiliate Captain (partner B) wins over the VC campaign (partner C).
CodeRepository::create( $l2_b, 'L2DIREKT', array( 'coupon_code' => 'l2-direkt' ) );
$GLOBALS['test_wc_coupons']['l2-direkt'] = 915900;
$l2_order( 915002, array( 'l2-direkt' ) );
OrderSync::sync_order( 915002 );
OrderSync::sync_order( 915002 );
check( 'L2-DEDUP-a direct coupon link wins over the Voucher Captain filter at priority 20, booked once', array( array( 1, 1 ), $l2_b, 0 ), array( $l2_bookings( 'woocommerce', '915002' ), $l2_partner_of( 'woocommerce', '915002' ), $l2_partner_of( 'woocommerce', '915002' ) === $l2_c ? 1 : 0 ) );

// Two partner coupons on one order (VC coupon first, direct link second): still one booking.
$l2_order( 915003, array( 'l2-vc-sommer', 'l2-direkt' ) );
OrderSync::sync_order( 915003 );
OrderSync::sync_order( 915003 );
check( 'L2-DEDUP-a two partner coupons on one order: one attribution, one sale row (first coupon wins)', array( array( 1, 1 ), $l2_a ), array( $l2_bookings( 'woocommerce', '915003' ), $l2_partner_of( 'woocommerce', '915003' ) ) );

/* ---------------------------------------------------------------------------------------------
 * (b) Pass Captain order via the contract and its replay.
 * ------------------------------------------------------------------------------------------- */
$l2_pass = array(
	'contract'     => '1.0.0',
	'source'       => 'pass-captain',
	'order_id'     => '915101',
	'currency'     => 'EUR',
	'partner_code' => CodeRepository::main_code( $l2_c ),
	'partner_code_method' => 'code',
	'customer'     => array( 'user_id' => 0, 'email' => $l2_buyer['email'] ),
	'items'        => array( array( 'item_id' => '1', 'product_id' => 0, 'sku' => 'PASS-E12-T3', 'name' => 'Regatta-Ticket', 'quantity' => 2, 'net_minor' => 8000 ) ),
);
$l2_p1 = wpac_record_sale( $l2_pass );
$l2_p2 = wpac_record_sale( $l2_pass );
$l2_p3 = wpac_record_sale( array_merge( $l2_pass, array( 'order_id' => 915101, 'idempotency_key' => 'pass-webhook-retry-2' ) ) ); // Int ID, other event ID.
do_action( 'wpac_v1_record_sale', $l2_pass ); // Hook variant without a return value.
check( 'L2-DEDUP-b Pass Captain order: created once, every replay (same payload, int ID, other idempotency_key, hook) is a duplicate', array( 'created', 'duplicate', 'duplicate', array( 1, 1 ), $l2_c ), array( $l2_status( $l2_p1 ), $l2_status( $l2_p2 ), $l2_status( $l2_p3 ), $l2_bookings( 'pass-captain', '915101' ), $l2_partner_of( 'pass-captain', '915101' ) ) );
$l2_p4 = wpac_record_sale( array_merge( $l2_pass, array( 'partner_code' => CodeRepository::main_code( $l2_a ) ) ) );
check( 'L2-DEDUP-b replay with another partner code does not move or add the booking', array( 'duplicate', $l2_c, array( 1, 1 ) ), array( $l2_status( $l2_p4 ), $l2_partner_of( 'pass-captain', '915101' ), $l2_bookings( 'pass-captain', '915101' ) ) );

/* ---------------------------------------------------------------------------------------------
 * (c) WooCommerce order that also carries Pass tickets: Pass Captain skips it (CONTRACT.md,
 *     "Mitgelieferte Adapter"), Affiliate Captain books it as a WooCommerce order.
 * ------------------------------------------------------------------------------------------- */
// Pass Captain's reporting rule (Adapter::reportable_order(), on_paid()): orders whose channel or
// payment provider is in wpcap_affiliate_skip_channels (default "woocommerce") are not reported.
$l2_pass_on_paid = static function ( $pass_order ) {
	$skip = (array) apply_filters( 'wpcap_affiliate_skip_channels', array( 'woocommerce' ) );
	if ( in_array( $pass_order['channel'], $skip, true ) || in_array( $pass_order['payment_provider'], $skip, true ) ) {
		return null;
	}
	return wpac_record_sale( $pass_order['payload'] );
};
$l2_order( 915201, array( 'l2-vc-sommer' ) ); // WC order: Pass ticket line + Voucher Captain partner coupon.
OrderSync::sync_order( 915201 );
$l2_pass_order = array(
	'channel'          => 'woocommerce',
	'payment_provider' => 'woocommerce',
	// What Pass Captain would send if it did not skip: its own order number, not the WC order ID.
	'payload'          => array_merge( $l2_pass, array( 'order_id' => '77201', 'coupon_codes' => array( 'l2-vc-sommer' ), 'partner_code' => '' ) ),
);
$l2_c1 = $l2_pass_on_paid( $l2_pass_order );
OrderSync::sync_order( 915201 ); // Later status change (completed).
check( 'L2-DEDUP-c WC order with Pass ticket and partner coupon: Pass skips it, exactly one booking (WooCommerce)', array( null, array( 1, 1 ), array( 0, 0 ), $l2_a ), array( $l2_c1, $l2_bookings( 'woocommerce', '915201' ), $l2_bookings( 'pass-captain', '77201' ), $l2_partner_of( 'woocommerce', '915201' ) ) );
// The guard is on the source side: the ledger deduplicates per source + order ID only. A Pass
// order reported under its own number is a different order for Affiliate Captain – which is why
// Pass Captain must keep skipping WooCommerce orders (documented in CONTRACT.md).
$l2_pass_order['channel'] = 'native';
$l2_pass_order['payment_provider'] = 'stripe';
$l2_c2 = $l2_pass_on_paid( $l2_pass_order );
check( 'L2-DEDUP-c a native Pass order (own checkout) is reported by Pass Captain', array( 'created', array( 1, 1 ) ), array( $l2_status( $l2_c2 ), $l2_bookings( 'pass-captain', '77201' ) ) );

/* ---------------------------------------------------------------------------------------------
 * (d) The same external order via REST /sales and the PHP contract.
 * ------------------------------------------------------------------------------------------- */
RestApi::routes();
$l2_rest_sales = $GLOBALS['test_routes']['wpac/v1/sales']['callback'] ?? null;
$l2_ext        = array(
	'contract'     => '1.0.0',
	'source'       => 'learnboating',
	'order_id'     => 'LB-915301',
	'currency'     => 'EUR',
	'partner_code' => CodeRepository::main_code( $l2_b ),
	'customer'     => array( 'user_id' => 0, 'email' => $l2_buyer['email'] ),
	'items'        => array( array( 'item_id' => 'k1', 'product_id' => 0, 'sku' => 'SBF-SEE', 'name' => 'SBF See', 'quantity' => 1, 'net_minor' => 49900 ) ),
);
$l2_rest_data = static function ( $res ) {
	if ( is_wp_error( $res ) ) {
		return $res->get_error_code();
	}
	$d = $res instanceof WP_REST_Response ? $res->data : $res;
	return is_array( $d ) && isset( $d['status'] ) ? $d['status'] : 'none';
};
$GLOBALS['test_caps'] = array( 'manage_options', WPAffiliateCaptain\Core\Capabilities::MANAGE_PROGRAM );
$l2_d1 = $l2_rest_sales ? $l2_rest_data( $l2_rest_sales( new WP_REST_Request( $l2_ext ) ) ) : 'no route';
unset( $GLOBALS['test_caps'] );
$l2_d2 = wpac_record_sale( $l2_ext );
$l2_d3 = wpac_record_sale( array_merge( $l2_ext, array( 'source' => 'LearnBoating', 'order_id' => ' LB-915301 ' ) ) ); // Case and spaces normalized.
check( 'L2-DEDUP-d REST /sales first, then the PHP contract (also LearnBoating/" LB-915301 "): one booking', array( 'created', 'duplicate', 'duplicate', array( 1, 1 ) ), array( $l2_d1, $l2_status( $l2_d2 ), $l2_status( $l2_d3 ), $l2_bookings( 'learnboating', 'LB-915301' ) ) );
$l2_ext['order_id'] = 'LB-915302';
$l2_d4 = wpac_record_sale( $l2_ext );
$GLOBALS['test_caps'] = array( 'manage_options', WPAffiliateCaptain\Core\Capabilities::MANAGE_PROGRAM );
$l2_d5 = $l2_rest_sales ? $l2_rest_data( $l2_rest_sales( new WP_REST_Request( $l2_ext ) ) ) : 'no route';
unset( $GLOBALS['test_caps'] );
check( 'L2-DEDUP-d PHP contract first, then REST /sales: the REST call reports a duplicate, one booking', array( 'created', 'duplicate', array( 1, 1 ) ), array( $l2_status( $l2_d4 ), $l2_d5, $l2_bookings( 'learnboating', 'LB-915302' ) ) );

/* ---------------------------------------------------------------------------------------------
 * L2-PII: no buyer data in partner-facing outputs of a WooCommerce order with buyer data.
 * ------------------------------------------------------------------------------------------- */
update_option( 'wpac_settings', array_merge( get_option( 'wpac_settings' ), array( 'notify_commissions' => 1, 'report_partners' => 1 ) ) );
Settings::flush_cache();
$l2_user = 915777;
$l2_p    = (int) AffiliateRepository::create( array( 'name' => 'L2 Portal-Partner', 'email' => 'l2-portal@example.de', 'status' => 'active', 'user_id' => $l2_user ) );
$GLOBALS['test_users'][ $l2_user ] = (object) array( 'ID' => $l2_user, 'user_email' => 'l2-portal@example.de', 'display_name' => 'L2 Portal-Partner', 'user_login' => 'l2portal', 'roles' => array( 'customer' ) );
$GLOBALS['l2_portal_partner'] = $l2_p;
$GLOBALS['l2_portal_user']    = $l2_user;
$GLOBALS['l2_leaks']          = $l2_leaks;

$l2_mails = array();
$GLOBALS['test_hooks']['wpac_mail_sent'][10][] = array(
	static function ( $type, $mail, $ok, $context ) use ( &$l2_mails ) {
		$l2_mails[] = array( 'type' => $type, 'admin' => ! empty( $context['admin'] ), 'mail' => $mail );
	},
	4,
);
Notifications::flush_commissions(); // Commission notices of earlier orders of this file.
$l2_mails = array();
$l2_fired_from = count( $GLOBALS['test_fired'] ?? array() );

$l2_pii_order = $l2_order( 915401 );
$l2_pii_order->meta[ WPAffiliateCaptain\Integrations\WooCommerce::META_TOUCH ] = array( 'code' => CodeRepository::main_code( $l2_p ), 'method' => 'link', 'campaign' => 'herbst', 'ts' => time() );
OrderSync::sync_order( 915401 );
check( 'L2-PII control: the WooCommerce payload carries the buyer data the checks look for', true, count( $l2_leaks( OrderSync::payload( $l2_pii_order ) ) ) >= 4 );
check( 'L2-PII setup: WooCommerce order with buyer data booked for the portal partner', array( array( 1, 1 ), $l2_p ), array( $l2_bookings( 'woocommerce', '915401' ), $l2_partner_of( 'woocommerce', '915401' ) ) );
foreach ( $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . t( 'commissions' ) . " WHERE source = 'woocommerce' AND source_order_id = %s", '915401' ) ) as $l2_cid ) {
	Ledger::transition( (int) $l2_cid, 'approved' );
}

// Events of the sale (attribution, commission created/approved): IDs only, no buyer data.
$l2_events = array();
foreach ( array_slice( $GLOBALS['test_fired'] ?? array(), $l2_fired_from ) as $l2_f ) {
	if ( 0 === strpos( (string) $l2_f[0], 'wpac_' ) && ! in_array( $l2_f[0], array( 'wpac_mail_sent', 'wpac_mail_failed', 'wpac_contract_not_queued' ), true ) ) {
		$l2_events[ $l2_f[0] ] = $l2_f[1];
	}
}
check( 'L2-PII events of the sale fired (wpac_affiliate_attributed, wpac_commission_created, wpac_commission_approved)', array( true, true, true ), array( isset( $l2_events['wpac_affiliate_attributed'] ), isset( $l2_events['wpac_commission_created'] ), isset( $l2_events['wpac_commission_approved'] ) ) );
check( 'L2-PII events of the sale carry no buyer e-mail, name or address', array(), $l2_leaks( $l2_events ) );

// Partner mails: commission notice and monthly summary. The harness registers no plugin hooks:
// hand the fired commission events to Notifications as register_hooks() does in WordPress.
foreach ( array_slice( $GLOBALS['test_fired'] ?? array(), $l2_fired_from ) as $l2_f ) {
	if ( 'wpac_commission_created' === $l2_f[0] ) {
		Notifications::commission_created( (int) $l2_f[1][0] );
	} elseif ( 'wpac_commission_approved' === $l2_f[0] ) {
		Notifications::commission_approved( (int) $l2_f[1][0] );
	}
}
Notifications::flush_commissions();
WPAffiliateCaptain\Reports\MonthlyMail::send( gmdate( 'Y-m' ), false, 0, 0, array( $l2_p ) );
$l2_partner_mails = array_values( array_filter( $l2_mails, static function ( $m ) { return ! $m['admin']; } ) );
check( 'L2-PII partner mails sent (commission notice, monthly summary)', array( 'commission', 'monthly_partner' ), array_values( array_unique( array_map( static function ( $m ) { return $m['type']; }, $l2_partner_mails ) ) ) );
check( 'L2-PII partner mails (recipient, subject, text, headers) carry no buyer data', array(), $l2_leaks( $l2_partner_mails ) );

// Partner REST API: /me and /me/commissions.
$GLOBALS['test_user'] = $l2_user;
$l2_me   = RestApi::is_partner() ? RestApi::me() : null;
$l2_list = RestApi::is_partner() ? $GLOBALS['test_routes']['wpac/v1/me/commissions']['callback']( new WP_REST_Request( array( 'page' => 1, 'per_page' => 50 ) ) ) : null;
$l2_list = $l2_list instanceof WP_REST_Response ? $l2_list->data : $l2_list;
$l2_pay  = RestApi::is_partner() ? $GLOBALS['test_routes']['wpac/v1/me/payouts']['callback']() : null;
check( 'L2-PII partner REST /me and /me/commissions answer for the partner (order listed)', array( true, true ), array( is_array( $l2_me ) && $l2_p === (int) $l2_me['id'], false !== strpos( (string) json_encode( $l2_list ), '915401' ) ) );
check( 'L2-PII partner REST /me, /me/commissions and /me/payouts carry no buyer data', array(), $l2_leaks( array( $l2_me, $l2_list, $l2_pay ) ) );

// Partner CSV export (commissions and payouts).
$l2_aff  = AffiliateRepository::find( $l2_p );
$l2_csv  = Portal::export_rows( $l2_aff, 'commissions', array( 'status' => '', 'from' => '', 'to' => '' ) );
$l2_csv2 = Portal::export_rows( $l2_aff, 'payouts', array( 'status' => '', 'from' => '', 'to' => '' ) );
check( 'L2-PII partner CSV lists the order', true, false !== strpos( (string) json_encode( $l2_csv ), '915401' ) );
check( 'L2-PII partner CSV (commissions, payouts) carries no buyer data', array(), $l2_leaks( array( $l2_csv, $l2_csv2 ) ) );

// Contract read API the Pro add-on and Workflow Captain use: no buyer data either.
check( 'L2-PII wpac_get_commissions()/wpac_get_affiliate() carry no buyer data', array(), $l2_leaks( array( wpac_get_commissions( array( 'affiliate_id' => $l2_p ) ), wpac_get_affiliate( $l2_p ) ) ) );

// The ledger itself stores no buyer data (attribution and commission rows of the order).
check(
	'L2-PII attribution and commission rows of the order store no buyer data',
	array(),
	$l2_leaks(
		array(
			$wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . t( 'attributions' ) . " WHERE source = 'woocommerce' AND source_order_id = %s", '915401' ), ARRAY_A ),
			$wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . t( 'commissions' ) . " WHERE source = 'woocommerce' AND source_order_id = %s", '915401' ), ARRAY_A ),
		)
	)
);

// Restore.
unset( $GLOBALS['test_hooks']['wpac_mail_sent'] );
unset( $GLOBALS['test_hooks']['wpac_coupon_affiliate'][20] );
$GLOBALS['test_user'] = $l2_keep_user;
if ( null !== $l2_keep_caps ) {
	$GLOBALS['test_caps'] = $l2_keep_caps;
}
$GLOBALS['za_orders'] = $l2_keep_orders;
update_option( 'wpac_settings', $l2_keep_settings );
Settings::flush_cache();

