<?php
/**
 * Round 15, fix agent s (security and money, R15-S1 to R15-S6): partner codes and shop coupons
 * of the same name, password confirmation of money-creating actions and its rate limit, requested
 * codes in code_status, recruiter check. The cart behaviour (R15-S2, R15-S5) and the device check
 * of the recruiter (R15-S6) run with WooCommerce and session stand-ins in r15-s-isolated.php in
 * their own process; the admin flows in r15-s-render.php. Included from run.php before
 * r8-render.php.
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Admin\Reauth;
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Affiliates\Registration;
use WPAffiliateCaptain\Codes\CodeRepository;
use WPAffiliateCaptain\Codes\CodeRequests;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Integrations\WooCommerce\CouponLink;
use WPAffiliateCaptain\Tracking\RateLimiter;

echo "R15 s: security and money (R15-S1 to R15-S6)\n";
global $wpdb;
$s_root = dirname( __DIR__, 2 );
$s_src  = static function ( $file ) use ( $s_root ) {
	return (string) file_get_contents( $s_root . '/' . $file );
};
$s_err  = static function ( $r ) {
	return is_wp_error( $r ) ? $r->get_error_code() : $r;
};
$s_settings = get_option( 'wpac_settings' );
update_option( 'wpac_settings', array_merge( (array) $s_settings, array( 'code_requests' => 1, 'coupon_priority' => 1, 'fraud_check_domains' => 0 ) ) );
Settings::flush_cache();

// ---------------------------------------------------------------------------------------------
// R15-S1: a chosen partner code does not take over a later shop coupon of the same name.
$s_mallory = AffiliateRepository::create( array( 'name' => 'Mallory S15', 'email' => 'mallory.s15@example.de', 'status' => 'active' ) );
$s_bob     = AffiliateRepository::create( array( 'name' => 'Bob S15', 'email' => 'bob.s15@example.de', 'status' => 'active' ) );
foreach ( array( $s_mallory, $s_bob ) as $s_id ) {
	if ( ! CodeRepository::main_code( $s_id ) ) {
		CodeRepository::create( $s_id, CodeRepository::random_code() );
	}
}
AffiliateRepository::clear_cache( $s_mallory );
$s_welcome = $s_err( CodeRequests::request( AffiliateRepository::find( $s_mallory ), 'WELCOME10' ) );
$s_req     = CodeRequests::request( AffiliateRepository::find( $s_mallory ), 'MALLORY77' );
$s_app     = CodeRequests::approve( (int) $s_req, array() );
$GLOBALS['test_wc_coupons']['mallory77'] = 5101; // Later: the shop's own coupon with that name, not linked.
$s_bob_code = CodeRepository::main_code( $s_bob );
wpac_record_sale( array( 'source' => 'test', 'order_id' => 'r15s-1', 'currency' => 'EUR', 'coupon_codes' => array( 'mallory77' ), 'touch' => array( 'code' => $s_bob_code, 'method' => 'link' ), 'items' => array( array( 'item_id' => '1', 'product_id' => 10, 'name' => 'X', 'net_minor' => 10000 ) ), 'customer' => array( 'user_id' => 0, 'email' => 'kunde.s15@example.de' ) ) );
check(
	'R15-S1 repro: a code chosen through a code request does not credit an unlinked shop coupon of the same name; the order goes to the link (Bob)',
	array( 'wpac_code_reserved', 'MALLORY77', null, $s_bob, 'link' ),
	array( $s_welcome, is_array( $s_app ) ? $s_app['code'] : $s_err( $s_app ), apply_filters( 'wpac_coupon_affiliate', null, 'mallory77' ), (int) $wpdb->get_var( $wpdb->prepare( 'SELECT affiliate_id FROM ' . t( 'attributions' ) . " WHERE source = 'test' AND source_order_id = %s", 'r15s-1' ) ), $wpdb->get_var( $wpdb->prepare( 'SELECT method FROM ' . t( 'attributions' ) . " WHERE source = 'test' AND source_order_id = %s", 'r15s-1' ) ) )
);
// A generated code still counts for a coupon with its name (with and without hyphen), a custom
// code of the admin does not; a linked coupon always does.
$s_custom = (int) CodeRepository::create( $s_bob, 'BOBSPEZIAL' );
$s_linked = (int) CodeRepository::create( $s_bob, 'BOBLINK', array( 'coupon_code' => 'bob-sommer' ) );
$GLOBALS['test_wc_coupons']['bob-sommer'] = 5102;
check(
	'R15-S1 fallback "coupon = partner code" only for generated codes; a custom code needs a link',
	array( $s_bob, $s_bob, null, true, false, false ),
	array(
		apply_filters( 'wpac_coupon_affiliate', null, strtolower( $s_bob_code ) )['affiliate_id'] ?? null,
		apply_filters( 'wpac_coupon_affiliate', null, str_replace( '-', '', strtolower( $s_bob_code ) ) )['affiliate_id'] ?? null,
		apply_filters( 'wpac_coupon_affiliate', null, 'bobspezial' ),
		CodeRepository::generated( 'SHOP-K7Q3-MX2P' ),
		CodeRepository::generated( 'MALLORY77' ),
		CodeRepository::generated( 'SALE-2024' ), // "0" and "1" are not in the alphabet of generated codes.
	)
);
check(
	'R15-S1 reserved words of the shop\'s campaigns (also with digits/"-"/"_"), the generated form; other words with such a start stay possible',
	array( 'wpac_code_reserved', 'wpac_code_reserved', 'wpac_code_reserved', 'wpac_code_reserved', 'wpac_code_reserved', 'wpac_code_reserved', 'SALEMA10', 'TEAM_ANNA', 'VIPER' ),
	array(
		$s_err( CodeRepository::check_new_code( 'Newsletter', $s_mallory, 0, 20 ) ),
		$s_err( CodeRepository::check_new_code( 'SALE-2027', $s_mallory, 0, 20 ) ),
		$s_err( CodeRepository::check_new_code( 'blackfriday_24', $s_mallory, 0, 20 ) ),
		$s_err( CodeRepository::check_new_code( 'SOMMER25', $s_mallory, 0, 20 ) ),
		$s_err( CodeRepository::check_new_code( 'ABCD-EFGH', $s_mallory, 0, 20 ) ),
		$s_err( CodeRepository::check_new_code( 'ANNA-ABCD-EFGH', $s_mallory, 0, 20 ) ),
		$s_err( CodeRepository::check_new_code( 'salema10', $s_mallory, 0, 20 ) ),
		$s_err( CodeRepository::check_new_code( 'team_anna', $s_mallory, 0, 20 ) ), // "TEAM-ANNA" has the generated form (4+4).
		$s_err( CodeRepository::check_new_code( 'viper', $s_mallory, 0, 20 ) ),
	)
);
// Warning on the coupon screen and after saving a coupon whose code equals a partner code.
$s_open = CodeRequests::request( AffiliateRepository::find( $s_bob ), 'BOBNEU' );
$s_msgs = array(
	CouponLink::name_clash( 'mallory77' ),
	CouponLink::name_clash( strtolower( $s_bob_code ) ),
	CouponLink::name_clash( 'bobneu' ),
	CouponLink::name_clash( 'nobody-has-this' ),
	CouponLink::name_clash( 'müller' ),
);
$GLOBALS['test_caps'] = array( 'edit_shop_coupons' ); // A shop manager without partner rights: no partner name.
$s_msgs[] = CouponLink::name_clash( 'mallory77' );
unset( $GLOBALS['test_caps'] );
check(
	'R15-S1 warning for a coupon with a partner\'s code: chosen code (not credited), generated code (credited), open request (cannot be approved); partner name only with partner rights',
	array( true, true, true, '', '', true ),
	array(
		false !== strpos( $s_msgs[0], 'equals the partner code MALLORY77 of partner "Mallory S15", but the coupon is not linked' ),
		false !== strpos( $s_msgs[1], 'generated partner code ' . $s_bob_code . ' of partner "Bob S15". Orders with this coupon are credited to that partner.' ),
		false !== strpos( $s_msgs[2], 'has requested the partner code BOBNEU' ),
		$s_msgs[3],
		$s_msgs[4],
		false !== strpos( $s_msgs[5], 'of partner "#' . $s_mallory . '"' ),
	)
);
if ( ! class_exists( 'WC_Admin_Meta_Boxes' ) ) {
	class WC_Admin_Meta_Boxes {
		public static function add_error( $text ) { $GLOBALS['s_meta_errors'][] = $text; }
	}
}
if ( ! class_exists( 'S15_Coupon' ) ) {
	class S15_Coupon {
		private $code;
		public function __construct( $code ) { $this->code = $code; }
		public function get_code() { return $this->code; }
	}
}
$GLOBALS['s_meta_errors'] = array();
CouponLink::coupon_saved_check( 5101, new S15_Coupon( 'mallory77' ) );
CouponLink::coupon_saved_check( 5102, new S15_Coupon( 'bob-sommer' ) ); // Linked: no warning.
CouponLink::coupon_saved_check( 5103, new S15_Coupon( 'summer-shop' ) );
check(
	'R15-S1 saving a coupon warns once for the unlinked partner code (hook after coupon_save), not for linked or unrelated coupons',
	array( 1, true, true ),
	array( count( $GLOBALS['s_meta_errors'] ), false !== strpos( (string) ( $GLOBALS['s_meta_errors'][0] ?? '' ), 'MALLORY77' ), false !== strpos( $s_src( 'src/Integrations/WooCommerce.php' ), "'woocommerce_coupon_options_save', array( CouponLink::class, 'coupon_saved_check' ), 20, 2 )" ) )
);

// ---------------------------------------------------------------------------------------------
// R15-S6 (part 1): a requested code cannot be activated through code_status.
$s_set_req   = CodeRepository::set_status( (int) $s_open, 'active' );
$s_set_block = CodeRepository::set_status( (int) $s_open, 'blocked' );
$s_set_ok    = CodeRepository::set_status( $s_custom, 'blocked' );
$s_set_none  = CodeRepository::set_status( 99999999, 'active' );
check(
	'R15-S6 set_status() refuses a requested code (it stays requested, nothing logged), works for other codes',
	array( false, false, 'requested', true, 'blocked', false, 0 ),
	array( $s_set_req, $s_set_block, CodeRepository::find( (int) $s_open )->status, $s_set_ok, CodeRepository::find( $s_custom )->status, $s_set_none, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . t( 'audit' ) . " WHERE action IN ('code.active','code.blocked') AND object_id = %d", (int) $s_open ) ) )
);
CodeRequests::withdraw( AffiliateRepository::find( $s_bob ) );

// R15-S6 (part 2): the recruiter's WordPress account address counts as the recruiter.
$s_rec = AffiliateRepository::create( array( 'name' => 'Rolf S15', 'email' => 'rolf.s15@example.de', 'status' => 'active', 'user_id' => 815001 ) );
$GLOBALS['test_users'][815001] = (object) array( 'ID' => 815001, 'user_email' => 'rolf.privat@example.org', 'display_name' => 'Rolf', 'user_login' => 'rolf' );
check(
	'R15-S6 recruiter_valid(): not the partner\'s e-mail, not the e-mail of the partner\'s WordPress account (compared like the self-referral check), another address is fine',
	array( false, false, true ),
	array( Registration::recruiter_valid( $s_rec, 'Rolf.S15@example.de', 0 ), Registration::recruiter_valid( $s_rec, 'ROLF.privat@example.org', 0 ), Registration::recruiter_valid( $s_rec, 'neu.s15@example.org', 0 ) )
);
unset( $GLOBALS['test_users'][815001] );

// ---------------------------------------------------------------------------------------------
// R15-S3: password confirmation for the actions that create money.
$s_post = $_POST;
$s_key  = static function ( $do, array $post ) {
	$_POST = $post;
	return Reauth::action_key( $do );
};
$s_keys = array(
	$s_key( 'commission_status', array( 'to' => 'approved', 'ids' => array( '1' ) ) ),
	$s_key( 'commission_status', array( 'to' => 'rejected', 'ids' => array( '1' ) ) ),
	$s_key( 'commission_status', array( 'to' => 'review', 'id' => '1' ) ),
	$s_key( 'affiliate_update', array( 'id' => (string) $s_bob, 'email' => 'bob.neu.s15@example.de', 'name' => 'Bob' ) ),
	$s_key( 'affiliate_update', array( 'id' => (string) $s_bob, 'email' => 'BOB.S15@example.de', 'name' => 'Bob' ) ), // Same address.
	$s_key( 'affiliate_update', array( 'id' => (string) $s_bob, 'name' => 'Bob' ) ),
	$s_key( 'commission_manual', array() ),
);
$_POST = $s_post;
check(
	'R15-S3 action keys: approving commissions and changing a partner\'s e-mail address have their own keys',
	array( 'commission_status_approved', 'commission_status', 'commission_status', 'affiliate_email_change', 'affiliate_update', 'affiliate_update', 'commission_manual' ),
	$s_keys
);
$s_req_map = array();
foreach ( array( 'commission_manual', 'commission_status_approved', 'payout_run', 'payout_request_process', 'balance_writeoff', 'repayment', 'affiliate_email_change', 'commission_status', 'affiliate_update', 'payout_request_reject', 'dunning_send' ) as $s_k ) {
	$s_req_map[ $s_k ] = Reauth::required( $s_k );
}
check(
	'R15-S3 money-creating actions and the e-mail change need the confirmation; rejecting, other partner changes, dunning not',
	array( 'commission_manual' => true, 'commission_status_approved' => true, 'payout_run' => true, 'payout_request_process' => true, 'balance_writeoff' => true, 'repayment' => true, 'affiliate_email_change' => true, 'commission_status' => false, 'affiliate_update' => false, 'payout_request_reject' => false, 'dunning_send' => false ),
	$s_req_map
);
check(
	'R15-S3 every protected action has a readable name (confirmation page, audit filter)',
	array(),
	array_values( array_filter( Reauth::ACTIONS, static function ( $k ) { return Reauth::action_label( $k ) === $k; } ) )
);

// ---------------------------------------------------------------------------------------------
// R15-S4: the rate limiter can take back a hit (only failed attempts count).
$s_rl = array();
for ( $s_i = 0; $s_i < 2; $s_i++ ) {
	$s_rl[] = RateLimiter::allow_for( 'r15s_test', 'subject-a', 2, 900 );
}
RateLimiter::refund_for( 'r15s_test', 'subject-a', 900 ); // The second attempt succeeded.
$s_rl[] = RateLimiter::allow_for( 'r15s_test', 'subject-a', 2, 900 );
$s_rl[] = RateLimiter::allow_for( 'r15s_test', 'subject-a', 2, 900 );
RateLimiter::refund_for( 'r15s_test', 'subject-b', 900 ); // Nothing counted: stays at 0, no error.
$s_rl[] = RateLimiter::allow_for( 'r15s_test', 'subject-b', 1, 900 );
$s_rl[] = RateLimiter::allow( 'r15s_ip', 1, 900 );
RateLimiter::refund( 'r15s_ip', 900 );
$s_rl[] = RateLimiter::allow( 'r15s_ip', 1, 900 );
$s_rl[] = RateLimiter::allow( 'r15s_ip', 1, 900 );
check( 'R15-S4 refund_for()/refund() take back one hit of the window (never below 0)', array( true, true, true, false, true, true, true, false ), $s_rl );
check(
	'R15-S4 Reauth::handle() counts before the check and takes the hit back after a successful confirmation',
	array( true, true ),
	array(
		false !== strpos( $s_src( 'src/Admin/Reauth.php' ), "RateLimiter::refund_for( 'reauth', \$uid, self::ATTEMPT_WINDOW );" ),
		strpos( $s_src( 'src/Admin/Reauth.php' ), 'self::second_factor( $user );' ) < strpos( $s_src( 'src/Admin/Reauth.php' ), 'RateLimiter::refund_for(' ),
	)
);

// ---------------------------------------------------------------------------------------------
// Documentation.
check(
	'R15-s documentation: handbook (coupon fallback, reserved words, one partner coupon, confirmation, recruiter), contract (new filters), translations',
	array( true, true, true, true, true, true, true, true ),
	array(
		false !== strpos( $s_src( 'docs/HANDBOOK.md' ), 'only a generated partner code' ),
		false !== strpos( $s_src( 'docs/HANDBOOK.md' ), 'WELCOME' ),
		false !== strpos( $s_src( 'docs/HANDBOOK.md' ), 'Only one partner coupon per order' ),
		false !== strpos( $s_src( 'docs/HANDBOOK.md' ), 'Approve commissions' ),
		false !== strpos( $s_src( 'docs/CONTRACT.md' ), '`wpac_single_partner_coupon`' ),
		false !== strpos( $s_src( 'docs/CONTRACT.md' ), '`wpac_is_self_recruitment`' ),
		false !== strpos( $s_src( 'languages/de_DE.r15-s.json' ), 'Only one partner coupon can be used per order. Please remove the coupon %s first.' ),
		false !== strpos( $s_src( 'languages/de_DE.r15-s.json' ), 'Create payouts' ),
	)
);

update_option( 'wpac_settings', $s_settings );
Settings::flush_cache();

// Cart behaviour and device check (own process: WooCommerce stand-ins would clash with other files' stubs).
$s_prefix = getenv( 'WPAC_TEST_DB_PREFIX' );
putenv( 'WPAC_TEST_DB_PREFIX=r15s_' );
$s_out = array();
exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/r15-s-isolated.php' ) . ' 2>&1', $s_out, $s_rc );
putenv( false === $s_prefix ? 'WPAC_TEST_DB_PREFIX' : 'WPAC_TEST_DB_PREFIX=' . $s_prefix );
$s_iso = json_decode( (string) end( $s_out ), true );
if ( ! is_array( $s_iso ) || 0 !== $s_rc ) {
	check( 'R15 s isolated checks ran', 'json', implode( "\n", $s_out ) );
} else {
	foreach ( $s_iso as $s_name => $s_pair ) {
		check( $s_name, $s_pair[0], $s_pair[1] );
	}
}
