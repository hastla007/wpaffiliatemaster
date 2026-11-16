<?php
/**
 * Integration checks of R7-PU5: "Create coupon for partner" on the partner page – a new
 * WooCommerce coupon (CRUD) is created and linked to a partner code in one step; existing and
 * already linked coupons are refused, and a coupon whose link fails is deleted again.
 * Included from tests/integration/run.php (uses its check() helper and database).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Codes\CodeRepository;
use WPAffiliateCaptain\Integrations\WooCommerce\CouponLink;

echo "R10 PU5 create coupon for partner\n";

// Minimal WC_Coupon stand-in (CRUD): save() registers the code for wc_get_coupon_id_by_code() (stub in r8-g2.php).
if ( ! class_exists( 'WC_Coupon', false ) ) {
	class WC_Coupon {
		public $id    = 0;
		public $props = array();
		public function __construct( $id = 0 ) {
			// Loading an existing coupon by ID (R13-M2: its meta is read and written).
			if ( is_numeric( $id ) && (int) $id && isset( $GLOBALS['test_wc_coupon_data'][ (int) $id ] ) ) {
				$this->id    = (int) $id;
				$this->props = $GLOBALS['test_wc_coupon_data'][ (int) $id ];
			}
		}
		public function __call( $name, $args ) {
			if ( 0 === strpos( $name, 'set_' ) ) {
				$this->props[ substr( $name, 4 ) ] = $args[0];
			}
			if ( 'get_meta' === $name ) {
				return $GLOBALS['test_wc_coupon_meta'][ $this->id ][ $args[0] ] ?? '';
			}
			if ( 'update_meta_data' === $name && $this->id ) {
				$GLOBALS['test_wc_coupon_meta'][ $this->id ][ $args[0] ] = $args[1];
			}
			if ( 'delete_meta_data' === $name ) {
				unset( $GLOBALS['test_wc_coupon_meta'][ $this->id ][ $args[0] ] );
			}
			return null;
		}
		public function get_id() { return $this->id; }
		public function get_code() { return isset( $this->props['code'] ) ? $this->props['code'] : ''; }
		public function save() {
			if ( ! empty( $GLOBALS['test_wc_coupon_fail'] ) ) {
				return 0;
			}
			$GLOBALS['test_wc_coupon_seq'] = ( $GLOBALS['test_wc_coupon_seq'] ?? 9000 ) + 1;
			$this->id                      = $GLOBALS['test_wc_coupon_seq'];
			$GLOBALS['test_wc_coupons'][ strtolower( trim( $this->get_code() ) ) ] = $this->id;
			$GLOBALS['test_wc_coupon_data'][ $this->id ]                          = $this->props;
			return $this->id;
		}
		public function delete( $force = false ) {
			unset( $GLOBALS['test_wc_coupons'][ strtolower( trim( $this->get_code() ) ) ], $GLOBALS['test_wc_coupon_data'][ $this->id ] );
			$GLOBALS['test_wc_coupon_deleted'][] = $this->id;
			return true;
		}
	}
}
$GLOBALS['test_wc_coupons']        = array();
$GLOBALS['test_wc_coupon_data']    = array();
$GLOBALS['test_wc_coupon_deleted'] = array();

$pu5_root   = dirname( __DIR__, 2 );
$pu5_src    = static function ( $file ) use ( $pu5_root ) {
	return (string) file_get_contents( $pu5_root . '/' . $file );
};
$pu5_audits = static function ( $action ) use ( $wpdb ) {
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . t( 'audit' ) . ' WHERE action = %s', $action ) );
};
$pu5_err    = static function ( $r ) {
	return is_wp_error( $r ) ? $r->get_error_code() : 'ok';
};

$pu5_a     = AffiliateRepository::create( array( 'name' => 'Paula Fünf', 'email' => 'paula5@example.de', 'status' => 'active' ) );
$pu5_b     = AffiliateRepository::create( array( 'name' => 'Bernd Fünf', 'email' => 'bernd5@example.de', 'status' => 'active' ) );
$pu5_main  = CodeRepository::main_code( $pu5_a );
$pu5_mrow  = CodeRepository::find_by_code( $pu5_main );
$pu5_brow  = CodeRepository::find_by_code( CodeRepository::main_code( $pu5_b ) );
$pu5_valid = array( 'discount_type' => 'percent', 'amount' => '10' );

check( 'R7-PU5 coupon creation is offered while WooCommerce (WC_Coupon) is available', true, CouponLink::can_create() );

// Success: empty coupon code = the selected partner code; linked and attributed, audit-logged.
$pu5_before = $pu5_audits( 'coupon.created' );
$pu5_ok     = CouponLink::create_for_partner( $pu5_a, $pu5_valid + array( 'code_id' => (int) $pu5_mrow->id, 'coupon_code' => '', 'usage_limit' => 3, 'individual_use' => true ) );
$pu5_props  = is_array( $pu5_ok ) ? $GLOBALS['test_wc_coupon_data'][ $pu5_ok['coupon_id'] ] : array();
check(
	'R7-PU5 coupon created via WC CRUD with the partner code, linked to the chosen code, audit-logged',
	array( $pu5_main, 'percent', '10', 3, true, null, (int) $pu5_mrow->id, strtolower( $pu5_main ), $pu5_a, $pu5_before + 1 ),
	array(
		$pu5_props['code'] ?? null,
		$pu5_props['discount_type'] ?? null,
		$pu5_props['amount'] ?? null,
		$pu5_props['usage_limit'] ?? null,
		$pu5_props['individual_use'] ?? null,
		array_key_exists( 'date_expires', $pu5_props ) ? $pu5_props['date_expires'] : 'missing',
		$pu5_ok['code_id'] ?? null,
		CodeRepository::find( (int) $pu5_mrow->id )->coupon_code,
		apply_filters( 'wpac_coupon_affiliate', null, strtoupper( $pu5_main ) )['affiliate_id'] ?? null,
		$pu5_audits( 'coupon.created' ),
	)
);

// Refusals: nothing is created in WooCommerce.
CodeRepository::create( $pu5_b, 'PU5STALE', array( 'coupon_code' => 'pu5stale' ) );
$pu5_count = count( $GLOBALS['test_wc_coupons'] );
$GLOBALS['test_caps'] = array();
$pu5_forbidden        = CouponLink::create_for_partner( $pu5_a, $pu5_valid + array( 'coupon_code' => 'pu5nocap' ) );
unset( $GLOBALS['test_caps'] );
check(
	'R7-PU5 existing coupon, coupon linked to another partner, foreign code, bad input and missing capability are refused',
	array( 'wpac_coupon_exists', 'wpac_coupon_taken', 'wpac_code_foreign', 'wpac_coupon_amount', 'wpac_coupon_amount', 'wpac_coupon_amount', 'wpac_coupon_expiry', 'wpac_coupon_expiry', 'wpac_coupon_empty', 'wpac_not_found', 'wpac_forbidden', $pu5_count ),
	array(
		$pu5_err( CouponLink::create_for_partner( $pu5_a, $pu5_valid + array( 'code_id' => 0, 'coupon_code' => ' ' . strtolower( $pu5_main ) ) ) ),
		$pu5_err( CouponLink::create_for_partner( $pu5_a, $pu5_valid + array( 'coupon_code' => 'PU5Stale' ) ) ),
		$pu5_err( CouponLink::create_for_partner( $pu5_a, $pu5_valid + array( 'code_id' => (int) $pu5_brow->id, 'coupon_code' => 'pu5fremd' ) ) ),
		$pu5_err( CouponLink::create_for_partner( $pu5_a, array( 'coupon_code' => 'pu5x', 'amount' => '0' ) ) ),
		$pu5_err( CouponLink::create_for_partner( $pu5_a, array( 'coupon_code' => 'pu5x', 'amount' => '150', 'discount_type' => 'percent' ) ) ),
		$pu5_err( CouponLink::create_for_partner( $pu5_a, array( 'coupon_code' => 'pu5x', 'amount' => 'zehn' ) ) ),
		$pu5_err( CouponLink::create_for_partner( $pu5_a, $pu5_valid + array( 'coupon_code' => 'pu5x', 'expires' => '2020-01-01' ) ) ),
		$pu5_err( CouponLink::create_for_partner( $pu5_a, $pu5_valid + array( 'coupon_code' => 'pu5x', 'expires' => '2030-02-30' ) ) ),
		$pu5_err( CouponLink::create_for_partner( $pu5_a, $pu5_valid + array( 'code_id' => 0, 'coupon_code' => '' ) ) ),
		$pu5_err( CouponLink::create_for_partner( 999999, $pu5_valid + array( 'coupon_code' => 'pu5x' ) ) ),
		$pu5_err( $pu5_forbidden ),
		count( $GLOBALS['test_wc_coupons'] ),
	)
);

// New partner code derived from the coupon; fixed cart discount with comma, expiry, limit.
$pu5_exp = gmdate( 'Y-m-d', time() + 30 * DAY_IN_SECONDS );
$pu5_new = CouponLink::create_for_partner( $pu5_a, array( 'code_id' => 0, 'coupon_code' => 'Größe-PU5', 'discount_type' => 'fixed_cart', 'amount' => '12,50', 'expires' => $pu5_exp, 'usage_limit' => 5 ) );
$pu5_nrow  = is_array( $pu5_new ) ? CodeRepository::find( $pu5_new['code_id'] ) : null;
$pu5_props = is_array( $pu5_new ) ? $GLOBALS['test_wc_coupon_data'][ $pu5_new['coupon_id'] ] : array();
check(
	'R7-PU5 code 0 creates a new partner code for the coupon (fixed cart, comma amount, expiry)',
	array( 'GROESSE-PU5', 'größe-pu5', $pu5_a, 'fixed_cart', '12.5', $pu5_exp, 5, false ),
	array( $pu5_nrow->code ?? null, $pu5_nrow->coupon_code ?? null, (int) ( $pu5_nrow->affiliate_id ?? 0 ), $pu5_props['discount_type'] ?? null, $pu5_props['amount'] ?? null, $pu5_props['date_expires'] ?? null, $pu5_props['usage_limit'] ?? null, $pu5_props['individual_use'] ?? null )
);

// A failed save in WooCommerce: error, no link.
$GLOBALS['test_wc_coupon_fail'] = true;
$pu5_sf                         = CouponLink::create_for_partner( $pu5_a, $pu5_valid + array( 'code_id' => 0, 'coupon_code' => 'pu5savefail' ) );
unset( $GLOBALS['test_wc_coupon_fail'] );
check( 'R7-PU5 failed coupon save: error, no partner code linked', array( 'wpac_coupon_save', null ), array( $pu5_err( $pu5_sf ), CodeRepository::find_by_coupon( 'pu5savefail' ) ) );

// The link fails (database refuses the update): the new coupon is deleted again.
$pu5_extra = CodeRepository::create( $pu5_a, 'PU5LINK' );
$pu5_real  = $GLOBALS['wpdb'];
$pu5_fail  = new class( $pu5_real ) extends Test_WPDB {
	public function __construct( $inner ) {
		foreach ( get_object_vars( $inner ) as $k => $v ) {
			$this->$k = $v;
		}
	}
	public function update( $table, $data, $where, $format = null, $wf = null ) {
		return false !== strpos( $table, 'partner_codes' ) ? false : parent::update( $table, $data, $where, $format, $wf );
	}
};
$GLOBALS['wpdb'] = $pu5_fail;
$wpdb            = $pu5_fail;
$pu5_lf          = CouponLink::create_for_partner( $pu5_a, $pu5_valid + array( 'code_id' => (int) $pu5_extra, 'coupon_code' => 'pu5linkfail' ) );
$GLOBALS['wpdb'] = $pu5_real;
$wpdb            = $pu5_real;
check(
	'R7-PU5 failed link: coupon deleted again, code unchanged, no audit entry',
	array( 'wpac_coupon_link', 0, 1, '', $pu5_before + 2 ),
	array( $pu5_err( $pu5_lf ), (int) ( $GLOBALS['test_wc_coupons']['pu5linkfail'] ?? 0 ), count( $GLOBALS['test_wc_coupon_deleted'] ), CodeRepository::find( (int) $pu5_extra )->coupon_code, $pu5_audits( 'coupon.created' ) )
);

// Admin wiring: own route with the partner capability (nonce checked in dispatch()); form only with WooCommerce.
check(
	'R7-PU5 admin route, handler and WooCommerce-gated form',
	array( true, true, true ),
	array(
		false !== strpos( $pu5_src( 'src/Admin/Admin.php' ), "'code_coupon_create' => array( AffiliatesPage::class, Capabilities::MANAGE_AFFILIATES )" ),
		false !== strpos( $pu5_src( 'src/Admin/Pages/AffiliatesPage.php' ), "CouponLink::create_for_partner( \$id, \$input )" ),
		false !== strpos( $pu5_src( 'src/Admin/Pages/AffiliatesPage.php' ), "if ( \\WPAffiliateCaptain\\Integrations\\WooCommerce\\CouponLink::can_create() ) {" ),
	)
);
