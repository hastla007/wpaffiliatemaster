<?php
/**
 * Audit round 12 (part B): coupon rights and links, statement sweep (marker, corrections, cron
 * only), partner CSV, retention of attributions and applications, customer erasure, Art. 15
 * export, uninstall lists, registration transients, VIES and restriction, log masking.
 * Included from run.php (helpers check(), sale(), t()).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Affiliates\Notifications;
use WPAffiliateCaptain\Affiliates\Registration;
use WPAffiliateCaptain\Codes\CodeRepository;
use WPAffiliateCaptain\Commissions\Ledger;
use WPAffiliateCaptain\Core\Cron;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\Log;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Integrations\WooCommerce\CouponLink;
use WPAffiliateCaptain\Payouts\Corrections;
use WPAffiliateCaptain\Payouts\ProfileRepository;
use WPAffiliateCaptain\Payouts\StatementSweep;
use WPAffiliateCaptain\Payouts\TaxHistory;
use WPAffiliateCaptain\Payouts\Vies;
use WPAffiliateCaptain\Portal\Portal;
use WPAffiliateCaptain\Privacy\Privacy;
use WPAffiliateCaptain\Privacy\Restriction;
use WPAffiliateCaptain\Privacy\Retention;

echo "R12 B coupons, sweep, retention, privacy, uninstall\n";
global $wpdb;

$r12b_root     = dirname( __DIR__, 2 );
$r12b_src      = static function ( $file ) use ( $r12b_root ) {
	return (string) file_get_contents( $r12b_root . '/' . $file );
};
$r12b_err      = static function ( $r ) {
	return is_wp_error( $r ) ? $r->get_error_code() : 'ok';
};
$r12b_settings = get_option( 'wpac_settings' );
update_option(
	'wpac_settings',
	array_merge(
		(array) $r12b_settings,
		array(
			'operator_country'          => 'DE',
			'statement_retention_years' => 8,
			'retention_hold'            => 0,
			'maintenance_mode'          => 0,
			'vies_check'                => 0,
		)
	)
);
Settings::flush_cache();

// ---------------------------------------------------------------------------------------------
// R12-K4: coupons only with the WooCommerce right; blocked/expired codes are not linked.
$k4_a     = AffiliateRepository::create( array( 'name' => 'K4 Partner', 'email' => 'k4@example.de', 'status' => 'active' ) );
$k4_main  = CodeRepository::find_by_code( CodeRepository::main_code( $k4_a ) );
$k4_valid = array( 'discount_type' => 'percent', 'amount' => '10' );
$GLOBALS['test_caps'] = array( 'wpac_manage_affiliates' );
$k4_nocap = array( CouponLink::can_create(), $r12b_err( CouponLink::create_for_partner( $k4_a, $k4_valid + array( 'coupon_code' => 'k4free' ) ) ) );
$GLOBALS['test_caps'] = array( 'wpac_manage_affiliates', 'publish_shop_coupons' );
$k4_withcap = CouponLink::can_create();
unset( $GLOBALS['test_caps'] );
check( 'R12-K4 partner right alone: no coupon form, creation refused; with publish_shop_coupons allowed', array( false, 'wpac_forbidden', true, 0 ), array( $k4_nocap[0], $k4_nocap[1], $k4_withcap, (int) wc_get_coupon_id_by_code( 'k4free' ) ) );

$k4_blocked = CodeRepository::create( $k4_a, 'K4BLOCKED' );
CodeRepository::set_status( (int) $k4_blocked, 'blocked' );
$k4_expired = CodeRepository::create( $k4_a, 'K4EXPIRED' );
$wpdb->query( 'UPDATE ' . t( 'partner_codes' ) . " SET expires_at = '2001-01-01 00:00:00' WHERE id = " . (int) $k4_expired );
$k4_used = CodeRepository::create( $k4_a, 'K4USED', array( 'usage_limit' => 1 ) );
$wpdb->query( 'UPDATE ' . t( 'partner_codes' ) . ' SET usage_count = 1 WHERE id = ' . (int) $k4_used );
check(
	'R12-K4 blocked, expired and used-up partner codes are not linked to a new coupon; nothing created',
	array( 'wpac_code_unusable', 'wpac_code_unusable', 'wpac_code_unusable', 0, 0 ),
	array(
		$r12b_err( CouponLink::create_for_partner( $k4_a, $k4_valid + array( 'code_id' => (int) $k4_blocked, 'coupon_code' => 'k4blk' ) ) ),
		$r12b_err( CouponLink::create_for_partner( $k4_a, $k4_valid + array( 'code_id' => (int) $k4_expired, 'coupon_code' => 'k4exp' ) ) ),
		$r12b_err( CouponLink::create_for_partner( $k4_a, $k4_valid + array( 'code_id' => (int) $k4_used, 'coupon_code' => 'k4used' ) ) ),
		(int) wc_get_coupon_id_by_code( 'k4blk' ),
		(int) wc_get_coupon_id_by_code( 'k4exp' ),
	)
);
check(
	'R12-K4 code_usable(): active code yes; blocked/expired/used up no',
	array( true, false, false, false ),
	array( CouponLink::code_usable( $k4_main ), CouponLink::code_usable( CodeRepository::find( (int) $k4_blocked ) ), CouponLink::code_usable( CodeRepository::find( (int) $k4_expired ) ), CouponLink::code_usable( CodeRepository::find( (int) $k4_used ) ) )
);
$k4_form = $r12b_src( 'src/Admin/Pages/AffiliatesPage.php' );
check( 'R12-K4 the coupon form offers only usable codes', true, false !== strpos( $k4_form, '$c->batch || ! \\WPAffiliateCaptain\\Integrations\\WooCommerce\\CouponLink::code_usable( $c )' ) );
$k4_ok = CouponLink::create_for_partner( $k4_a, $k4_valid + array( 'code_id' => (int) $k4_main->id, 'coupon_code' => 'k4good' ) );
check( 'R12-K4 active code with the WooCommerce right: coupon created and linked', array( 'ok', 'k4good' ), array( $r12b_err( $k4_ok ), CodeRepository::find( (int) $k4_main->id )->coupon_code ) );

// R12-N14: a link whose WooCommerce coupon no longer exists credits nobody and shows as broken.
$n14_row = CodeRepository::find( (int) $k4_main->id );
check( 'R12-N14 existing coupon: link fine and resolves to the partner', array( 'ok', $k4_a ), array( CouponLink::link_state( $n14_row ), apply_filters( 'wpac_coupon_affiliate', null, 'K4GOOD' )['affiliate_id'] ?? null ) );
unset( $GLOBALS['test_wc_coupons']['k4good'] ); // Coupon deleted (or renamed) in WooCommerce.
check( 'R12-N14 deleted coupon: link broken, a coupon code with that text credits nobody', array( 'missing', null ), array( CouponLink::link_state( $n14_row ), apply_filters( 'wpac_coupon_affiliate', null, 'K4GOOD' ) ) );
check( 'R12-N14 broken link is shown on the partner page', true, false !== strpos( $k4_form, "'ok' !== \\WPAffiliateCaptain\\Integrations\\WooCommerce\\CouponLink::link_state( \$c )" ) );
check( 'R12-N14 links remember the coupon ID (meta on the coupon)', array( true, true ), array( false !== strpos( $r12b_src( 'src/Integrations/WooCommerce/CouponLink.php' ), 'META_CODE_ID' ), false !== strpos( $k4_form, 'CouponLink::remember(' ) ) );

// ---------------------------------------------------------------------------------------------
// R12-K5 / R12-M20: sweep marker without file names, correction credit notes, cron only.
$k5_dir = sys_get_temp_dir() . '/wpac-r12b-' . getmypid();
@mkdir( $k5_dir, 0777, true );
remove_all_filters( 'wpac_statement_dir' );
add_filter( 'wpac_statement_dir', static function () use ( $k5_dir ) { return $k5_dir; } );
$k5_aff    = AffiliateRepository::create( array( 'name' => 'K5 Partner', 'email' => 'k5@example.de', 'status' => 'active' ) );
$k5_live   = 'KG-2026-9001-Ab3cd5ef7gh9jk1L.pdf';
$k5_gone   = 'KG-2026-9002-Zy8xw6vu4ts2rq0P.pdf';
$k5_alien  = 'foreign-9003-qwertzuiopasdfgh.pdf';
file_put_contents( $k5_dir . '/' . $k5_live, '%PDF-1.4 live correction' );
file_put_contents( $k5_dir . '/' . $k5_gone, '%PDF-1.4 deleted correction' );
file_put_contents( $k5_dir . '/' . $k5_alien, '%PDF-1.4 unknown' );
$k5_row = static function ( $n, $file, $hash, $deleted ) use ( $wpdb, $k5_aff ) {
	$wpdb->insert(
		t( 'payout_corrections' ),
		array(
			'payout_id'       => 0,
			'number'          => 'KG-R12B-' . $n,
			'affiliate_id'    => $k5_aff,
			'currency'        => 'EUR',
			'request_key'     => 'r12b-' . $n . '-' . getmypid(),
			'file'            => $file,
			'hash'            => $hash,
			'file_deleted_at' => $deleted ? '2030-01-01 00:00:00' : null,
			'created_at'      => Db::now(),
		)
	);
	return (int) $wpdb->insert_id;
};
$k5_live_id = $k5_row( 1, $k5_live, hash_file( 'sha256', $k5_dir . '/' . $k5_live ), false );
$k5_gone_id = $k5_row( 2, '', hash_file( 'sha256', $k5_dir . '/' . $k5_gone ), true );
@unlink( $k5_dir . '/' . StatementSweep::MARKER );
$k5_stats  = StatementSweep::run( 0 );
$k5_marker = (string) file_get_contents( $k5_dir . '/' . StatementSweep::MARKER );
check(
	'R12-K5 sweep: copy of a deleted correction credit note removed, live correction kept (not unknown), unknown file kept',
	array( 1, false, true, true, array( $k5_dir . '/' . $k5_alien ) ),
	array( $k5_stats['deleted'], is_file( $k5_dir . '/' . $k5_gone ), is_file( $k5_dir . '/' . $k5_live ), is_file( $k5_dir . '/' . $k5_alien ), array_values( array_filter( $k5_stats['unknown'], static function ( $p ) use ( $k5_dir ) { return 0 === strpos( $p, $k5_dir . '/' ); } ) ) )
);
$k5_state = json_decode( $k5_marker, true );
check(
	'R12-K5 marker holds only hashes: no path, no file name',
	array( false, false, false, true ),
	array( false !== strpos( $k5_marker, $k5_alien ), false !== strpos( $k5_marker, 'foreign' ), false !== strpos( $k5_marker, $k5_dir ), isset( $k5_state['unknown'] ) && array() !== $k5_state['unknown'] && ! array_filter( array_keys( $k5_state['unknown'] ), static function ( $k ) { return ! preg_match( '/^[0-9a-f]{64}$/', (string) $k ); } ) )
);
check( 'R12-K5 remembered unknown file (hashed key) is not hashed again', 0, StatementSweep::run( 0 )['candidates'] );
// Entries of an older marker keyed by path are dropped, not kept.
file_put_contents( $k5_dir . '/' . StatementSweep::MARKER, wp_json_encode( array( 'last' => time(), 'unknown' => array( $k5_dir . '/' . $k5_alien => array( 'sig' => 'x', 'at' => time() ) ) ) ) );
StatementSweep::run( 0 );
check( 'R12-K5 old marker entries with paths are replaced by hashes', false, false !== strpos( (string) file_get_contents( $k5_dir . '/' . StatementSweep::MARKER ), 'foreign' ) );

// Corrections::stored_bytes(): a stale object of a correction deleted meanwhile removes the local copy.
$k5_stale = Corrections::find( $k5_live_id );
$wpdb->query( 'UPDATE ' . t( 'payout_corrections' ) . " SET file = '', file_deleted_at = '2030-01-01 00:00:00' WHERE id = " . $k5_live_id );
check( 'R12-K5 stored_bytes() of a correction deleted after the retention period: nothing served, local copy deleted', array( null, false ), array( Corrections::stored_bytes( $k5_stale ), is_file( $k5_dir . '/' . $k5_live ) ) );
file_put_contents( $k5_dir . '/' . $k5_live, '%PDF-1.4 live correction' );
$k5_stale->file_deleted_at = '2030-01-01 00:00:00';
check( 'R12-K5 stored_bytes() with file_deleted_at on the object: copy deleted', array( null, false ), array( Corrections::stored_bytes( $k5_stale ), is_file( $k5_dir . '/' . $k5_live ) ) );
check( 'R12-K5 folder probe also tests a dotfile', true, false !== strpos( $r12b_src( 'src/Payouts/Statement.php' ), "'.wpac-probe-'" ) );

// R12-M20: no sweep in visitor requests; hourly cron with a time budget; budget covers the listing.
unset( $GLOBALS['test_hooks']['shutdown'] );
StatementSweep::register_hooks();
$m20_default = ! empty( $GLOBALS['test_hooks']['shutdown'] );
add_filter( 'wpac_statement_sweep_on_requests', static function () { return true; } );
StatementSweep::register_hooks();
$m20_opt_in = ! empty( $GLOBALS['test_hooks']['shutdown'] );
remove_all_filters( 'wpac_statement_sweep_on_requests' );
unset( $GLOBALS['test_hooks']['shutdown'] );
check( 'R12-M20 no shutdown sweep by default; only behind the filter', array( false, true ), array( $m20_default, $m20_opt_in ) );
touch( $k5_dir . '/' . StatementSweep::MARKER, time() - 7 * HOUR_IN_SECONDS );
$m20_budget = null;
add_filter( 'wpac_statement_sweep_budget', static function ( $b, $context = '' ) use ( &$m20_budget ) { $m20_budget = array( $b, $context ); return $b; }, 10, 2 );
$m20_run  = StatementSweep::scheduled();
$m20_again = StatementSweep::scheduled();
remove_all_filters( 'wpac_statement_sweep_budget' );
check( 'R12-M20 hourly cron sweeps when due (20 s budget, context cron), then waits for the interval', array( true, array( 20.0, 'cron' ), null ), array( is_array( $m20_run ), $m20_budget, $m20_again ) );
check( 'R12-M20 hourly cron runs the sweep step', true, false !== strpos( $r12b_src( 'src/Core/Cron.php' ), "'hourly.statement_sweep', array( '\\WPAffiliateCaptain\\Payouts\\StatementSweep', 'scheduled' )" ) );
file_put_contents( $k5_dir . '/zz-second-foreign-0002.pdf', '%PDF-1.4 second foreign' ); // R13-M10: one file per sweep always.
check( 'R12-M20 a used-up budget stops the sweep (listing included) without deleting', array( false, 0 ), array( StatementSweep::run( 0.000001, true )['complete'], StatementSweep::run( 0.000001, true )['deleted'] ) );
$m20_sweep = $r12b_src( 'src/Payouts/StatementSweep.php' );
check( 'R12-M20/R13-M10 sweep reads names and hashes once along the primary key (no IN() list per chunk)', array( false, true ), array( false !== strpos( $m20_sweep, ' IN ({$marks})' ), false !== strpos( $m20_sweep, 'WHERE id > %d AND ( {$where} ) ORDER BY id ASC LIMIT %d' ) ) );
check( 'R12-M20 documentation: no request sweep, system cron on every server', array( true, true, false ), array( false !== strpos( $r12b_src( 'docs/HANDBOOK.md' ), 'wpac_statement_sweep_on_requests' ), false !== strpos( $r12b_src( 'README.md' ), 'Besucheraufrufe lösen keinen Sweep aus' ), false !== strpos( $r12b_src( 'README.md' ), 'bei eigenen Aufrufen' ) ) );
remove_all_filters( 'wpac_statement_dir' );
foreach ( (array) glob( $k5_dir . '/{,.}*', GLOB_BRACE ) as $k5_f ) {
	if ( is_file( $k5_f ) ) {
		@unlink( $k5_f );
	}
}
@rmdir( $k5_dir );

// ---------------------------------------------------------------------------------------------
// R12-M12: partner CSV shows only the kind label for non-sale rows.
$m12_a    = AffiliateRepository::create( array( 'name' => 'M12 Partner', 'email' => 'm12@example.de', 'status' => 'active' ) );
$m12_sale = sale( 'r12b-m12-1', CodeRepository::main_code( $m12_a ), array( 10000 ) );
Ledger::manual_adjustment( $m12_a, 'EUR', -500, 'Chargeback Kunde Müller' );
list( $m12_head, $m12_rows ) = Portal::export_rows( AffiliateRepository::find( $m12_a ), 'commissions', array( 'status' => '', 'from' => '', 'to' => '' ) );
$m12_products = array();
foreach ( $m12_rows as $m12_r ) {
	$m12_products[ $m12_r[2] ] = $m12_r[3];
}
check( 'R12-M12 CSV: sale keeps the product, the manual booking shows only its label', array( 'Kurs 1', Ledger::kind_label( 'adjustment' ), false ), array( $m12_products['sale'] ?? null, $m12_products['adjustment'] ?? null, false !== strpos( implode( '|', $m12_products ), 'Müller' ) ) );

// ---------------------------------------------------------------------------------------------
// R12-M13: attributions are deleted after the retention period of their order's bookings.
$m13_a   = AffiliateRepository::create( array( 'name' => 'M13 Partner', 'email' => 'm13@example.de', 'status' => 'active' ) );
$m13_old = sale( 'r12b-m13-old', CodeRepository::main_code( $m13_a ), array( 10000 ) );
$m13_new = sale( 'r12b-m13-young', CodeRepository::main_code( $m13_a ), array( 10000 ) );
$m13_opn = sale( 'r12b-m13-open', CodeRepository::main_code( $m13_a ), array( 10000 ) );
$wpdb->query( 'UPDATE ' . t( 'attributions' ) . " SET created_at = '2001-03-01 10:00:00' WHERE source = 'test' AND source_order_id IN ('r12b-m13-old','r12b-m13-young','r12b-m13-open')" );
$wpdb->query( 'UPDATE ' . t( 'commissions' ) . " SET updated_at = '2001-04-01 10:00:00', status = 'approved', payout_id = 999999 WHERE source = 'test' AND source_order_id = 'r12b-m13-old'" );
$wpdb->query( 'UPDATE ' . t( 'commissions' ) . " SET updated_at = '2001-04-01 10:00:00' WHERE source = 'test' AND source_order_id = 'r12b-m13-open'" ); // Still pending: open.
$m13_has = static function ( $order ) {
	return null !== Ledger::attribution_for( 'test', $order );
};
update_option( 'wpac_settings', array_merge( get_option( 'wpac_settings' ), array( 'retention_hold' => 1 ) ) );
Settings::flush_cache();
$m13_hold = Retention::purge_attributions();
update_option( 'wpac_settings', array_merge( get_option( 'wpac_settings' ), array( 'retention_hold' => 0 ) ) );
Settings::flush_cache();
check( 'R12-M13 legal hold: no attribution deleted', array( 0, true ), array( $m13_hold, $m13_has( 'r12b-m13-old' ) ) );
$m13_n = Retention::purge_attributions();
check(
	'R12-M13 attribution past retention with settled commissions deleted; young or open order kept; commission amounts stay',
	array( true, false, true, true, 1 ),
	array( $m13_n >= 1, $m13_has( 'r12b-m13-old' ), $m13_has( 'r12b-m13-young' ), $m13_has( 'r12b-m13-open' ), (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . t( 'commissions' ) . " WHERE source = 'test' AND source_order_id = 'r12b-m13-old'" ) )
);
check( 'R12-M13 daily step and record of processing activities', array( true, true ), array( isset( Cron::daily_steps()['attributions'] ), false !== strpos( $r12b_src( 'docs/VERARBEITUNGSVERZEICHNIS.md' ), 'danach tägliche Löschung' ) ) );

// ---------------------------------------------------------------------------------------------
// R12-M14: applications that were never decided are anonymised after the configured period.
$m14_old   = AffiliateRepository::create( array( 'name' => 'M14 Alt', 'email' => 'm14-alt@example.de', 'status' => 'pending' ) );
$m14_young = AffiliateRepository::create( array( 'name' => 'M14 Neu', 'email' => 'm14-neu@example.de', 'status' => 'pending' ) );
$wpdb->query( 'UPDATE ' . t( 'affiliates' ) . " SET updated_at = '2020-01-01 00:00:00' WHERE id = " . (int) $m14_old );
AffiliateRepository::clear_cache();
check( 'R12-M14 default period 365 days; setting kept within limits', array( 365, 36500 ), array( (int) Settings::get( 'pending_application_days' ), Settings::INT_MAX['pending_application_days'] ) );
update_option( 'wpac_settings', array_merge( get_option( 'wpac_settings' ), array( 'pending_application_days' => 0 ) ) );
Settings::flush_cache();
$m14_off = Privacy::purge_pending_applications();
update_option( 'wpac_settings', array_merge( get_option( 'wpac_settings' ), array( 'pending_application_days' => 365 ) ) );
Settings::flush_cache();
$m14_n   = Privacy::purge_pending_applications();
AffiliateRepository::clear_cache();
$m14_row = AffiliateRepository::find( $m14_old );
check(
	'R12-M14 0 = never; old undecided application anonymised and closed as rejected, a young one kept',
	array( 0, 1, 'rejected', 'privacy_erasure', 'deleted-' . $m14_old . '@invalid.invalid', 'pending' ),
	array( $m14_off, $m14_n, $m14_row->status, $m14_row->status_reason, $m14_row->email, AffiliateRepository::find( $m14_young )->status )
);
check( 'R12-M14 daily step, privacy policy text and record of processing activities', array( true, true, true ), array( isset( Cron::daily_steps()['pending_applications'] ), false !== strpos( $r12b_src( 'src/Privacy/Privacy.php' ), 'partner applications that were never decided' ), false !== strpos( Privacy::ropa(), 'nie entschiedene Bewerbungen 365 Tage' ) ) );

// ---------------------------------------------------------------------------------------------
// R12-M15: customer erasure removes the referral snapshot and reports what is retained.
class R12B_Order {
	public $id;
	public $meta;
	public function __construct( $id, $meta = array() ) { $this->id = $id; $this->meta = $meta; }
	public function get_id() { return $this->id; }
	public function get_meta( $k ) { return isset( $this->meta[ $k ] ) ? $this->meta[ $k ] : ''; }
	public function update_meta_data( $k, $v ) { $this->meta[ $k ] = $v; }
	public function delete_meta_data( $k ) { unset( $this->meta[ $k ] ); }
	public function save_meta_data() { $GLOBALS['r12b_saved'][] = $this->id; }
}
if ( ! function_exists( 'wc_get_orders' ) ) {
	// Only answers for the orders this file registers (other files see no orders, as before).
	function wc_get_orders( $q ) {
		$out = array();
		foreach ( (array) ( $GLOBALS['r12b_orders'] ?? array() ) as $id => $email ) {
			if ( isset( $q['billing_email'] ) && $q['billing_email'] === $email ) {
				$out[] = $id;
			}
		}
		return $out;
	}
}
$m15_a     = AffiliateRepository::create( array( 'name' => 'M15 Partner', 'email' => 'm15@example.de', 'status' => 'active' ) );
$m15_touch = array( 'code' => CodeRepository::main_code( $m15_a ), 'method' => 'link', 'campaign' => 'sommer', 'sub_id' => 'abc', 'visit_id' => 5 );
$GLOBALS['za_orders'][12901] = new R12B_Order( 12901, array( '_wpac_touch' => $m15_touch ) );
$GLOBALS['za_orders'][12902] = new R12B_Order( 12902, array( '_wpac_touch' => $m15_touch ) );
$GLOBALS['r12b_orders']      = array( 12901 => 'm15kunde@example.de', 12902 => 'm15kunde@example.de' );
wpac_record_sale( array( 'source' => 'woocommerce', 'order_id' => '12901', 'currency' => 'EUR', 'partner_code' => CodeRepository::main_code( $m15_a ), 'items' => array( array( 'item_id' => '1', 'net_minor' => 5000 ) ), 'customer' => array( 'user_id' => 0, 'email' => 'm15kunde@example.de' ) ) );
$m15_res = Privacy::erase( 'm15kunde@example.de' );
check(
	'R12-M15 customer erasure: referral snapshot removed from the orders, attribution retained and reported with a reason',
	array( true, true, '', '', true, true ),
	array( $m15_res['items_removed'], $m15_res['items_retained'], $GLOBALS['za_orders'][12901]->get_meta( '_wpac_touch' ), $GLOBALS['za_orders'][12902]->get_meta( '_wpac_touch' ), null !== Ledger::attribution_for( 'woocommerce', '12901' ), false !== strpos( implode( ' ', $m15_res['messages'] ), 'retained as a booking record' ) )
);
$m15_none = Privacy::erase( 'niemand-r12b@example.de' );
check( 'R12-M15 nobody: nothing removed, nothing retained', array( false, false, array() ), array( $m15_none['items_removed'], $m15_none['items_retained'], $m15_none['messages'] ) );
unset( $GLOBALS['za_orders'][12901], $GLOBALS['za_orders'][12902], $GLOBALS['r12b_orders'] );

// ---------------------------------------------------------------------------------------------
// R12-M16: Art. 15 export complete and payment details in full.
$m16_a = AffiliateRepository::create( array( 'name' => 'M16 Partner', 'email' => 'm16@example.de', 'status' => 'active' ) );
ProfileRepository::save( $m16_a, array( 'tax_status' => 'private', 'legal_name' => 'M16 Partner', 'address' => "Weg 1\n24103 Kiel", 'country' => 'DE', 'account_holder' => 'M16 Partner', 'iban' => 'DE89370400440532013000' ) );
TaxHistory::record_profile( $m16_a, array( 'tax_status' => 'business', 'country' => 'AT', 'vat_id' => 'ATU12345678' ) );
Notifications::set_opt_out( $m16_a, 'commission', true );
$m16_sale = sale( 'r12b-m16-1', CodeRepository::main_code( $m16_a ), array( 10000 ) );
$wpdb->insert( t( 'commission_events' ), array( 'commission_id' => (int) $m16_sale['commission_ids'][0], 'event' => 'rejected', 'from_status' => 'pending', 'to_status' => 'rejected', 'reason' => 'Storno durch Kunde M16', 'created_at' => Db::now() ) );
$m16_data  = array();
$m16_pages = 0;
do {
	$m16_page  = Privacy::export( 'm16@example.de', ++$m16_pages );
	$m16_data  = array_merge( $m16_data, $m16_page['data'] );
} while ( ! $m16_page['done'] && $m16_pages < 20 );
$m16_groups = array_unique( array_column( $m16_data, 'group_id' ) );
$m16_flat   = wp_json_encode( $m16_data, JSON_UNESCAPED_UNICODE );
check(
	'R12-M16 export: change log, earlier tax data, commission reasons, mail opt-out, full IBAN',
	array( true, true, true, true, true, true, true ),
	array(
		in_array( 'wpac-audit', $m16_groups, true ),
		in_array( 'wpac-tax-history', $m16_groups, true ) && false !== strpos( $m16_flat, 'ATU12345678' ),
		in_array( 'wpac-commission-events', $m16_groups, true ) && false !== strpos( $m16_flat, 'Storno durch Kunde M16' ),
		false !== strpos( $m16_flat, 'Unsubscribed from these e-mails' ) && false !== strpos( $m16_flat, 'commission' ),
		false === strpos( $m16_flat, 'DE89370400440532013000' ) && false !== strpos( $m16_flat, '3000' ), // R13-K8: masked now.
		false !== strpos( $m16_flat, 'payout_profile' ) || false !== strpos( $m16_flat, 'affiliate.' ),
		false !== strpos( $r12b_src( 'docs/HANDBOOK.md' ), 'reverses the "full IBAN"' ),
	)
);

// ---------------------------------------------------------------------------------------------
// R12-M17 / R12-N18: uninstall lists complete and in sync with Core\Cron.
$m17_un   = $r12b_src( 'uninstall.php' );
$m17_cron = $r12b_src( 'src/Core/Cron.php' );
preg_match( '/function unschedule\(\)\s*\{(.*?)\n\t\}/s', $m17_cron, $m17_body );
preg_match_all( '/([\\\\A-Za-z]+)::([A-Z_]+)/', $m17_body[1] ?? '', $m17_refs, PREG_SET_ORDER );
$m17_hooks = array();
foreach ( $m17_refs as $m17_ref ) {
	$m17_class = 'self' === $m17_ref[1] ? Cron::class : ( false === strpos( $m17_ref[1], '\\' ) ? 'WPAffiliateCaptain\\Core\\' . $m17_ref[1] : ltrim( $m17_ref[1], '\\' ) );
	if ( defined( $m17_class . '::' . $m17_ref[2] ) ) {
		$m17_hooks[] = constant( $m17_class . '::' . $m17_ref[2] );
	}
}
preg_match( '/function wpac_uninstall_hooks\(\)\s*\{\s*return array\((.*?)\);/s', $m17_un, $m17_list );
preg_match_all( "/'([a-z_]+)'/", $m17_list[1] ?? '', $m17_un_hooks );
$m17_missing = array_values( array_diff( array_unique( $m17_hooks ), $m17_un_hooks[1] ) );
check( 'R12-M17 uninstall unschedules every hook Core\\Cron::unschedule() knows', array( true, array() ), array( count( $m17_hooks ) >= 14, $m17_missing ) );
$m17_want = array( "'wpac_shared_iban'", "'wpac_mail_optout'", "'wpac_correction_retry'", "'wpac_broadcast'", "'wpac_audit_per_page'", "'wpac_commissions_per_page'", "'wpac_creatives_per_page'", "'wpac_partners_per_page'", "'wpac_payouts_per_page'" );
check( 'R12-M17 uninstall deletes the per-partner options and the list screen preferences', array(), array_values( array_filter( $m17_want, static function ( $s ) use ( $m17_un ) { return false === strpos( $m17_un, $s ); } ) ) );
check( 'R12-M17 constants match the option names', array( 'wpac_shared_iban', 'wpac_mail_optout', 'wpac_correction_retry', 'wpac_broadcast' ), array( ProfileRepository::SHARED_IBAN_OPTION, Notifications::OPTOUT_OPTION, Corrections::RETRY_OPTION, Notifications::BROADCAST_OPTION ) );
check( 'R12-N18 order notes and order meta stay with the shop: documented', array( true, true ), array( false !== strpos( $m17_un, 'WooCommerce orders belong to the shop' ), false !== strpos( $r12b_src( 'docs/HANDBOOK.md' ), 'WooCommerce orders are never changed by the uninstall' ) ) );

// ---------------------------------------------------------------------------------------------
// R12-M18: registration transients in their own namespace, strict token format.
set_transient( 'wpac_form_settings_1', array( 'values' => array( 'wpac_name' => 'Admin-Daten' ) ) );
$m18_token = 'Ab3De5Gh7Jk9Mn1Pq3St';
set_transient( Registration::KEPT_PREFIX . $m18_token, array( 'values' => array( 'wpac_name' => 'Anna' ) ) );
check(
	'R12-M18 kept entries only by a 20-character token under the own prefix; admin transients are unreachable',
	array( 'wpac_regkeep_', array(), array(), 'Anna', array() ),
	array( Registration::KEPT_PREFIX, Registration::kept_entries( 'settings_1' ), Registration::kept_entries( '../wpac_form_settings_1' ), Registration::kept_entries( $m18_token )['values']['wpac_name'] ?? null, Registration::kept_entries( $m18_token . 'x' ) )
);

// ---------------------------------------------------------------------------------------------
// R12-N17: the periodic VIES check skips restricted partners.
$n17_free = AffiliateRepository::create( array( 'name' => 'N17 Frei', 'email' => 'n17-frei@example.de', 'status' => 'active' ) );
$n17_rest = AffiliateRepository::create( array( 'name' => 'N17 Gesperrt', 'email' => 'n17-rest@example.de', 'status' => 'active' ) );
foreach ( array( $n17_free, $n17_rest ) as $n17_id ) {
	ProfileRepository::save( $n17_id, array( 'tax_status' => 'private', 'legal_name' => 'N17 Partner', 'address' => "Weg 1\n24103 Kiel", 'country' => 'DE', 'account_holder' => 'N17', 'iban' => 'DE89370400440532013000' ) );
	$wpdb->update( t( 'payout_profiles' ), array( 'vat_id' => 'ATU1234567' . ( $n17_id % 10 ) ), array( 'affiliate_id' => $n17_id ) );
	delete_option( 'wpac_vies_' . $n17_id );
}
// Only the restricted partner is a candidate (the scheduler stub of the tests ignores arguments).
$n17_others = $wpdb->get_results( 'SELECT affiliate_id, vat_id FROM ' . t( 'payout_profiles' ) . " WHERE vat_id <> '' AND affiliate_id <> " . (int) $n17_rest );
$wpdb->query( 'UPDATE ' . t( 'payout_profiles' ) . " SET vat_id = '' WHERE affiliate_id <> " . (int) $n17_rest );
Restriction::add( $n17_rest, Restriction::REQUEST );
add_filter( 'wpac_vies_enabled', static function () { return true; } );
$n17_saved_sched = $GLOBALS['test_scheduled'] ?? array();
$n17_scheduled   = static function () {
	return in_array( Vies::HOOK, $GLOBALS['test_scheduled'] ?? array(), true );
};
$GLOBALS['test_scheduled'] = array();
Vies::recheck_stale();
$n17_restricted = $n17_scheduled();
Restriction::remove( $n17_rest, Restriction::REQUEST, false );
$GLOBALS['test_scheduled'] = array();
Vies::recheck_stale();
$n17_free_again = $n17_scheduled();
remove_all_filters( 'wpac_vies_enabled' );
$GLOBALS['test_scheduled'] = $n17_saved_sched;
foreach ( $n17_others as $n17_o ) {
	$wpdb->update( t( 'payout_profiles' ), array( 'vat_id' => $n17_o->vat_id ), array( 'affiliate_id' => (int) $n17_o->affiliate_id ) );
}
check( 'R12-N17 VIES re-check not scheduled while the partner is restricted, again once the restriction ends', array( false, true ), array( $n17_restricted, $n17_free_again ) );

// ---------------------------------------------------------------------------------------------
// R12-N19: e-mail addresses in logs are masked.
check(
	'R12-N19 log masking of e-mail addresses',
	array( "Duplicate entry 'a***@***.de' for key", 'Invalid address: (to): m***@***.com; ok', 'no address here' ),
	array( Log::mask( "Duplicate entry 'anna.berg@example.de' for key" ), Log::mask( 'Invalid address: (to): max+test@mail.example.com; ok' ), Log::mask( 'no address here' ) )
);
check( 'R12-N19 masking applied to every log line, documented', array( true, true ), array( false !== strpos( $r12b_src( 'src/Core/Log.php' ), '$line = self::mask(' ), false !== strpos( $r12b_src( 'docs/HANDBOOK.md' ), 'e-mail addresses in logged exception messages and mailer errors are masked' ) ) );

update_option( 'wpac_settings', $r12b_settings );
Settings::flush_cache();
