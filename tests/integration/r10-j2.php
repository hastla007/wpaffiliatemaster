<?php
/**
 * Integration checks of work package J2: "Create coupon for partner" in the admin (R7-PU5) –
 * coupon template in the settings, WooCommerce coupon via the WC_Coupon API, link to a partner
 * code, guards and rollback. Included from tests/integration/run.php (uses its check() helper).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Admin\Admin;
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Codes\CodeRepository;
use WPAffiliateCaptain\Codes\PartnerCoupon;
use WPAffiliateCaptain\Core\Capabilities;
use WPAffiliateCaptain\Core\Settings;

echo "J2 partner coupons\n";

global $wpdb;
$j2_root = dirname( __DIR__, 2 );

// Without WooCommerce (no WC_Coupon class yet) the action is disabled with a reason.
$j2_aff = AffiliateRepository::create( array( 'name' => 'Jana Coupon', 'email' => 'jana-coupon@example.de', 'status' => 'active' ) );
foreach ( CodeRepository::for_affiliate( $j2_aff ) as $j2_auto ) {
	CodeRepository::set_status( (int) $j2_auto->id, 'blocked' ); // Random code of the new partner.
}
$j2_c1  = CodeRepository::create( $j2_aff, 'J2JANA' );
$j2_off = PartnerCoupon::create( $j2_aff, array() );
check( 'R7-PU5 without WooCommerce: not available, create refused', array( false, 'wpac_no_woocommerce' ), array( PartnerCoupon::available(), is_wp_error( $j2_off ) ? $j2_off->get_error_code() : '' ) );

// WC_Coupon stub: records the properties set through the API; save() registers the code for
// wc_get_coupon_id_by_code() (stub in r8-g2.php), delete( false ) moves it to the trash.
if ( ! function_exists( 'wc_get_coupon_id_by_code' ) ) {
	function wc_get_coupon_id_by_code( $code ) { $lc = mb_strtolower( trim( (string) $code ), 'UTF-8' ); return isset( $GLOBALS['wc_test_coupons'][ $lc ] ) ? (int) $GLOBALS['wc_test_coupons'][ $lc ] : 0; }
}
if ( ! class_exists( 'WC_Coupon' ) ) {
	class WC_Coupon {
		public static $next = 9000;
		public static $all = array();
		public static $after_save = null;
		public $id = 0;
		public $props = array();
		public $meta = array();
		public $status = 'publish';
		public function __call( $name, $args ) {
			if ( 0 === strpos( $name, 'set_' ) ) {
				$this->props[ substr( $name, 4 ) ] = $args[0];
				return;
			}
			throw new BadMethodCallException( $name );
		}
		public function set_code( $code ) { $this->props['code'] = $code; }
		public function set_discount_type( $t ) {
			if ( ! in_array( $t, array( 'percent', 'fixed_cart', 'fixed_product' ), true ) ) {
				throw new Exception( 'Invalid discount type' );
			}
			$this->props['discount_type'] = $t;
		}
		public function set_amount( $a ) { $this->props['amount'] = $a; }
		public function set_individual_use( $v ) { $this->props['individual_use'] = $v; }
		public function set_usage_limit_per_user( $v ) { $this->props['usage_limit_per_user'] = $v; }
		public function set_date_expires( $v ) { $this->props['date_expires'] = $v; }
		public function set_free_shipping( $v ) { $this->props['free_shipping'] = $v; }
		public function set_minimum_amount( $v ) { $this->props['minimum_amount'] = $v; }
		public function set_exclude_sale_items( $v ) { $this->props['exclude_sale_items'] = $v; }
		public function set_description( $v ) { $this->props['description'] = $v; }
		public function set_product_ids( $v ) { $this->props['product_ids'] = $v; }
		public function update_meta_data( $k, $v ) { $this->meta[ $k ] = $v; }
		public function save() {
			$this->id = ++self::$next;
			self::$all[ $this->id ] = $this;
			$GLOBALS['wc_test_coupons'][ mb_strtolower( (string) $this->props['code'], 'UTF-8' ) ] = $this->id;
			if ( self::$after_save ) {
				call_user_func( self::$after_save, $this );
			}
			return $this->id;
		}
		public function delete( $force = false ) {
			$this->status = $force ? 'deleted' : 'trash';
			unset( $GLOBALS['wc_test_coupons'][ mb_strtolower( (string) $this->props['code'], 'UTF-8' ) ] );
			return true;
		}
	}
}
check( 'R7-PU5 with WooCommerce: available', true, PartnerCoupon::available() );

// Template: defaults, settings validation, tab.
$j2_def = Settings::defaults();
check(
	'R7-PU5 coupon template defaults (10 % percent, individual use, once per customer, no expiry)',
	array( 'percent', '10', 1, 1, 0, 0, '', 0 ),
	array( $j2_def['coupon_tpl_discount_type'], $j2_def['coupon_tpl_amount'], $j2_def['coupon_tpl_individual_use'], $j2_def['coupon_tpl_usage_limit_per_user'], $j2_def['coupon_tpl_expiry_days'], $j2_def['coupon_tpl_free_shipping'], $j2_def['coupon_tpl_minimum_amount'], $j2_def['coupon_tpl_exclude_sale_items'] )
);
$j2_san = Settings::sanitize( array( 'coupon_tpl_discount_type' => 'fixed_cart', 'coupon_tpl_amount' => '5,50', 'coupon_tpl_minimum_amount' => 'viel', 'coupon_tpl_expiry_days' => '30', 'coupon_tpl_free_shipping' => '1' ), $j2_err );
check(
	'R7-PU5 template settings validated (amount parsed, invalid minimum spend rejected, unchecked flag off)',
	array( 'fixed_cart', '5.50', '', 30, 1, 0, true ),
	array( $j2_san['coupon_tpl_discount_type'], $j2_san['coupon_tpl_amount'], $j2_san['coupon_tpl_minimum_amount'], $j2_san['coupon_tpl_expiry_days'], $j2_san['coupon_tpl_free_shipping'], $j2_san['coupon_tpl_individual_use'], isset( $j2_err['coupon_tpl_minimum_amount'] ) )
);
Settings::sanitize( array( 'coupon_tpl_discount_type' => 'percent', 'coupon_tpl_amount' => '150' ), $j2_err );
$j2_err150 = isset( $j2_err['coupon_tpl_amount'] );
Settings::sanitize( array( 'coupon_tpl_discount_type' => 'percent', 'coupon_tpl_amount' => '0' ), $j2_err );
check( 'R7-PU5 template: 150 % and 0 without free shipping rejected', array( true, true ), array( $j2_err150, isset( $j2_err['coupon_tpl_amount'] ) ) );
check( 'R7-PU5 template fields are on the Promotion tab', array(), array_values( array_diff( array_keys( PartnerCoupon::defaults() ), WPAffiliateCaptain\Admin\Pages\SettingsPage::tab_keys()['promotion'] ) ) );
update_option( 'wpac_settings', array_merge( (array) get_option( 'wpac_settings' ), array( 'coupon_tpl_amount' => '15', 'coupon_tpl_expiry_days' => 60, 'coupon_tpl_minimum_amount' => '25.00' ) ) );
Settings::flush_cache();

// Suggestion: the partner code in upper case, unique.
check( 'R7-PU5 suggested coupon code = partner code', 'J2JANA', PartnerCoupon::suggest_code( $j2_aff ) );

// Create: linked to the chosen code, template values, audit entry.
$j2_before = time();
$j2_res    = PartnerCoupon::create( $j2_aff, array( 'code_id' => $j2_c1 ) );
$j2_wc     = is_array( $j2_res ) ? WC_Coupon::$all[ $j2_res['coupon_id'] ] : null;
check(
	'R7-PU5 coupon created from the template and linked to the partner code',
	array( 'J2JANA', $j2_c1, 'j2jana', 'percent', '15', true, 1, '25.00', false, false, $j2_aff, true ),
	$j2_wc ? array(
		$j2_res['coupon_code'],
		$j2_res['code_id'],
		CodeRepository::find( $j2_c1 )->coupon_code,
		$j2_wc->props['discount_type'],
		$j2_wc->props['amount'],
		$j2_wc->props['individual_use'],
		$j2_wc->props['usage_limit_per_user'],
		$j2_wc->props['minimum_amount'],
		$j2_wc->props['free_shipping'],
		$j2_wc->props['exclude_sale_items'],
		$j2_wc->meta[ PartnerCoupon::META_AFFILIATE ],
		abs( $j2_wc->props['date_expires'] - ( $j2_before + 60 * DAY_IN_SECONDS ) ) < 5,
	) : $j2_res
);
check( 'R7-PU5 coupon resolves to the partner and is audited', array( $j2_aff, 1 ), array( apply_filters( 'wpac_coupon_affiliate', null, 'J2JANA' )['affiliate_id'] ?? null, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . t( 'audit' ) . " WHERE action = 'code.coupon_created' AND object_id = {$j2_aff}" ) ) );
check( 'R7-PU5 next suggestion is unique', 'J2JANA2', PartnerCoupon::suggest_code( $j2_aff ) );

// No duplicate coupon codes: existing WooCommerce coupon, or a code already linked.
$j2_dup = PartnerCoupon::create( $j2_aff, array( 'coupon_code' => 'j2jana', 'code_id' => 0 ) );
$j2_c2  = CodeRepository::create( $j2_aff, 'J2SECOND' );
CodeRepository::set_coupon( $j2_c2, 'J2VERWAIST' ); // Linked, but no WooCommerce coupon (deleted later).
$j2_dup2 = PartnerCoupon::create( $j2_aff, array( 'coupon_code' => 'J2Verwaist', 'code_id' => 0 ) );
check( 'R7-PU5 duplicate coupon codes refused', array( 'wpac_coupon_exists', 'wpac_coupon_taken' ), array( is_wp_error( $j2_dup ) ? $j2_dup->get_error_code() : '', is_wp_error( $j2_dup2 ) ? $j2_dup2->get_error_code() : '' ) );

// Per-coupon override, filter, new partner code.
$j2_filter = static function ( $args, $aff, $coupon ) {
	$args['product_ids'] = array( 42 );
	return $args;
};
add_filter( 'wpac_partner_coupon_args', $j2_filter, 10, 3 );
$j2_res2 = PartnerCoupon::create( $j2_aff, array( 'coupon_code' => 'Jana-Sommer', 'code_id' => 0, 'discount_type' => 'fixed_cart', 'amount' => '7,50', 'individual_use' => 0, 'expiry_days' => '0', 'minimum_amount' => '' ) );
remove_filter( 'wpac_partner_coupon_args', $j2_filter, 10 );
$j2_wc2  = is_array( $j2_res2 ) ? WC_Coupon::$all[ $j2_res2['coupon_id'] ] : null;
$j2_row2 = is_array( $j2_res2 ) ? CodeRepository::find( $j2_res2['code_id'] ) : null;
check(
	'R7-PU5 per-coupon override, wpac_partner_coupon_args filter and new partner code',
	array( 'fixed_cart', '7.50', false, false, '', array( 42 ), 'JANA-SOMMER', 'jana-sommer', $j2_aff ),
	$j2_wc2 ? array( $j2_wc2->props['discount_type'], $j2_wc2->props['amount'], $j2_wc2->props['individual_use'], isset( $j2_wc2->props['date_expires'] ), $j2_wc2->props['minimum_amount'], $j2_wc2->props['product_ids'], $j2_row2->code, $j2_row2->coupon_code, (int) $j2_row2->affiliate_id ) : $j2_res2
);
$j2_bad = PartnerCoupon::create( $j2_aff, array( 'coupon_code' => 'J2BAD', 'amount' => '120' ) );
check( 'R7-PU5 invalid override refused, no coupon created', array( 'wpac_coupon_args', 0 ), array( is_wp_error( $j2_bad ) ? $j2_bad->get_error_code() : '', wc_get_coupon_id_by_code( 'J2BAD' ) ) );

// Guards: other partner's code, inactive/restricted partner, capability.
$j2_other = AffiliateRepository::create( array( 'name' => 'Otto Coupon', 'email' => 'otto-coupon@example.de', 'status' => 'active' ) );
$j2_oc    = CodeRepository::create( $j2_other, 'J2OTTO' );
$j2_g1    = PartnerCoupon::create( $j2_aff, array( 'coupon_code' => 'J2FREMD', 'code_id' => $j2_oc ) );
AffiliateRepository::set_status( $j2_other, 'paused', 'Pause' );
$j2_g2 = PartnerCoupon::create( $j2_other, array( 'coupon_code' => 'J2PAUSE' ) );
AffiliateRepository::set_status( $j2_other, 'active', '' );
WPAffiliateCaptain\Privacy\Restriction::add( $j2_other, WPAffiliateCaptain\Privacy\Restriction::REQUEST, 'Art. 18' );
$j2_g3 = PartnerCoupon::create( $j2_other, array( 'coupon_code' => 'J2RESTR' ) );
WPAffiliateCaptain\Privacy\Restriction::remove( $j2_other, WPAffiliateCaptain\Privacy\Restriction::REQUEST );
$j2_caps              = $GLOBALS['test_caps'] ?? null;
$GLOBALS['test_caps'] = array( Capabilities::MANAGE_AFFILIATES ); // Partner manager without coupon rights.
$j2_g4                = PartnerCoupon::create( $j2_other, array( 'coupon_code' => 'J2NOCAP' ) );
if ( null === $j2_caps ) {
	unset( $GLOBALS['test_caps'] );
} else {
	$GLOBALS['test_caps'] = $j2_caps;
}
$j2_code = static function ( $r ) {
	return is_wp_error( $r ) ? $r->get_error_code() : 'ok';
};
check(
	'R7-PU5 guards: foreign code, paused and restricted partner, missing coupon capability',
	array( 'wpac_code_invalid', 'wpac_partner_inactive', 'wpac_partner_inactive', 'wpac_forbidden', 0 ),
	array( $j2_code( $j2_g1 ), $j2_code( $j2_g2 ), $j2_code( $j2_g3 ), $j2_code( $j2_g4 ), count( array_filter( array( 'j2fremd', 'j2pause', 'j2restr', 'j2nocap' ), 'wc_get_coupon_id_by_code' ) ) )
);

// Rollback: another code gets the coupon while it is created → coupon trashed, link undone.
$j2_c3            = CodeRepository::create( $j2_other, 'J2RACE' );
WC_Coupon::$after_save = static function ( $c ) use ( $j2_aff ) {
	CodeRepository::create( $j2_aff, 'J2PARALLEL', array( 'coupon_code' => $c->props['code'] ) );
};
$j2_race               = PartnerCoupon::create( $j2_other, array( 'coupon_code' => 'J2RACE', 'code_id' => $j2_c3 ) );
WC_Coupon::$after_save = null;
$j2_trashed            = array_values( array_filter( WC_Coupon::$all, static function ( $c ) { return 'J2RACE' === $c->props['code']; } ) );
check(
	'R7-PU5 rollback when the link fails: coupon in the trash, code without coupon, audited',
	array( 'wpac_coupon_link_failed', 'trash', '', 1 ),
	array( $j2_code( $j2_race ), $j2_trashed ? $j2_trashed[0]->status : '', CodeRepository::find( $j2_c3 )->coupon_code, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . t( 'audit' ) . " WHERE action = 'code.coupon_create_failed' AND object_id = {$j2_other}" ) )
);

// Linkable codes: only active, non-batch codes without coupon.
CodeRepository::create( $j2_aff, 'J2FREI' );
CodeRepository::set_status( CodeRepository::create( $j2_aff, 'J2BLOCK' ), 'blocked' );
CodeRepository::create_batch( $j2_aff, 1, 'J2B' );
check( 'R7-PU5 linkable codes: only active non-batch codes without coupon', array( 'J2FREI' ), array_values( wp_list_pluck( PartnerCoupon::linkable_codes( $j2_aff ), 'code' ) ) );

// Admin: action routed with the partner manager capability (nonce checked by dispatch()), form
// on the partner page, success notice with the edit link.
$j2_admin = (string) file_get_contents( $j2_root . '/src/Admin/Admin.php' );
$j2_page  = (string) file_get_contents( $j2_root . '/src/Admin/Pages/AffiliatesPage.php' );
check(
	'R7-PU5 admin action routed (partner manager), form uses the nonce form, success links to the coupon',
	array( 1, 1, 1 ),
	array(
		preg_match( "/'partner_coupon_create'\s*=>\s*array\(\s*AffiliatesPage::class,\s*Capabilities::MANAGE_AFFILIATES\s*\)/", $j2_admin ),
		preg_match( "/Admin::form_open\(\s*'partner_coupon_create'\s*\)/", $j2_page ),
		preg_match( '/PartnerCoupon::edit_url\(/', $j2_page ),
	)
);
set_transient( 'wpac_notice_' . get_current_user_id(), array( 'message' => 'Coupon created', 'type' => 'success', 'details' => array(), 'link' => array( 'url' => PartnerCoupon::edit_url( 9001 ), 'label' => 'Edit coupon' ) ) );
ob_start();
Admin::notice();
$j2_notice = ob_get_clean();
check( 'R7-PU5 notice shows the edit link', 1, preg_match( '#<a href="[^"]*post\.php\?post=9001&(amp;)?(\#038;)?action=edit">Edit coupon</a>#', $j2_notice ) );
