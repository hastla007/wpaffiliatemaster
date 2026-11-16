<?php
/**
 * Round 15, fix agent p (privacy and performance, R15-P1 … R15-P7): sub-ID grouping bound to the
 * visits, object storage clean-up on uninstall and for deleted sites, privacy text and record of
 * processing (object storage, recruiter), automatic coupon in export and erasure, "keep data"
 * keeps the code choices. Included from tests/integration/run.php (uses its check() helper and
 * database); checks with S3 constants, multisite or WooCommerce stand-ins and uninstall.php run in
 * r15-p-isolated.php in own processes.
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Codes\CodeRepository;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Integrations\WooCommerce\AutoCoupon;
use WPAffiliateCaptain\Privacy\Privacy;
use WPAffiliateCaptain\Reports\Reports;

echo "R15 p: privacy and performance\n";

global $wpdb;
$p15_root = dirname( __DIR__, 2 );
$p15_src  = static function ( $f ) use ( $p15_root ) {
	return (string) file_get_contents( $p15_root . '/' . $f );
};
if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
	function wp_add_privacy_policy_content( $plugin, $html ) { $GLOBALS['g4_policy'] = $html; }
}

/* R15-P1: the threshold is bound to the visits; a sub-ID never disappears only when its reader buys. */
$p1_aff  = AffiliateRepository::create( array( 'name' => 'P1 Newsletter', 'email' => 'p1-news@example.de', 'status' => 'active' ) );
$p1_code = CodeRepository::main_code( $p1_aff );
foreach ( array( 'reader-1', 'reader-2', 'reader-3' ) as $p1_sub ) {
	$wpdb->insert( Db::table( 'visits' ), array( 'affiliate_id' => $p1_aff, 'code_id' => 0, 'campaign' => 'news', 'sub_id' => $p1_sub, 'created_at' => Db::now( time() - 300 ) ) );
}
for ( $i = 0; $i < 4; $i++ ) {
	$wpdb->insert( Db::table( 'visits' ), array( 'affiliate_id' => $p1_aff, 'code_id' => 0, 'campaign' => 'news', 'sub_id' => 'banner', 'created_at' => Db::now( time() - 300 - $i ) ) );
}
$p1_from = gmdate( 'Y-m-d', time() - DAY_IN_SECONDS );
$p1_to   = gmdate( 'Y-m-d', time() + DAY_IN_SECONDS );
$p1_show = static function ( array $rows ) {
	$out = array();
	foreach ( $rows as $r ) {
		$out[ ! empty( $r['other'] ) ? 'OTHER' : $r['campaign'] . '/' . $r['sub_id'] ] = array( $r['visits'], $r['orders'] );
	}
	return $out;
};
$p1_before = $p1_show( Reports::partner_campaigns( $p1_aff, $p1_from, $p1_to, 3 ) );
wpac_record_sale( array( 'source' => 'p15', 'order_id' => 'p1-1', 'currency' => 'EUR', 'items' => array( array( 'item_id' => '1', 'product_id' => 1, 'name' => 'K', 'net_minor' => 10000 ) ), 'customer' => array( 'user_id' => 0, 'email' => 'p1-buyer@example.de' ), 'touch' => array( 'code' => $p1_code, 'method' => 'link', 'ts' => time(), 'campaign' => 'news', 'sub_id' => 'reader-2' ) ) );
$p1_after = $p1_show( Reports::partner_campaigns( $p1_aff, $p1_from, $p1_to, 3 ) );
check(
	'R15-P1 sub-IDs with 1 visit are in "Other" already before the purchase; after it the visible rows are the same (the buyer cannot be singled out)',
	array(
		array( 'news/banner' => array( 4, 0 ), 'OTHER' => array( 3, 0 ) ),
		array( 'news/banner' => array( 4, 0 ), 'OTHER' => array( 3, 1 ) ),
		array_keys( $p1_before ),
	),
	array( $p1_before, $p1_after, array_keys( $p1_after ) )
);
// A row with enough visits and one sale goes to "Other" as before (R12-L5); the admin view is unchanged.
wpac_record_sale( array( 'source' => 'p15', 'order_id' => 'p1-2', 'currency' => 'EUR', 'items' => array( array( 'item_id' => '1', 'product_id' => 1, 'name' => 'K', 'net_minor' => 10000 ) ), 'customer' => array( 'user_id' => 0, 'email' => 'p1-buyer2@example.de' ), 'touch' => array( 'code' => $p1_code, 'method' => 'link', 'ts' => time(), 'campaign' => 'news', 'sub_id' => 'banner' ) ) );
$p1_rows = Reports::partner_campaigns( $p1_aff, $p1_from, $p1_to, 3 );
check(
	'R15-P1 row with 4 visits and 1 sale combined too; label names visits and sales; admin report shows every row',
	array( array( 'OTHER' => array( 7, 2 ) ), true, 4 ),
	array( $p1_show( $p1_rows ), false !== strpos( (string) end( $p1_rows )['label'], 'fewer than 3 visits or sales' ), count( Reports::partner_campaigns( $p1_aff, $p1_from, $p1_to ) ) )
);
check(
	'R15-P1 partner area hint and record of processing describe the visit threshold (no "campaign and sub ID" per order)',
	array( true, false, true ),
	array(
		false !== strpos( $p15_src( 'src/Portal/Portal.php' ), 'fewer than %1$d link visits or fewer than %1$d sales' ),
		false !== strpos( $p15_src( 'docs/RECORD-OF-PROCESSING.md' ), 'status, campaign and sub ID (partner area' ),
		false !== strpos( $p15_src( 'docs/RECORD-OF-PROCESSING.md' ), 'fewer than 3 visits or with 1–2 sales' ),
	)
);

/* R15-P4 / R15-P5: privacy text and record of processing. ------------------------------------- */
$p4_settings = get_option( 'wpac_settings' );
update_option( 'wpac_settings', array_merge( (array) $p4_settings, array( 'cookie_mode' => 'consent', 'use_wc_session' => 0 ) ) );
Settings::flush_cache();
$p4_policy = static function () {
	unset( $GLOBALS['g4_policy'] );
	Privacy::policy_text();
	return (string) ( $GLOBALS['g4_policy'] ?? '' );
};
$p4_local     = $p4_policy();
$p4_ropa_loc  = Privacy::ropa( 'en' );
$p4_ropa_loc2 = Privacy::ropa( 'de' );
$p4_mem       = new class() implements WPAffiliateCaptain\Payouts\Storage\StatementStore {
	public function id() { return 'mem'; }
	public function label() { return 'memory'; }
	public function put( $name, $bytes ) { return true; }
	public function get( $name ) { return null; }
	public function exists( $name ) { return false; }
	public function delete( $name, $hash = '' ) { return true; }
	public function list_older_than( $timestamp ) { return array(); }
	public function last_error() { return ''; }
};
$p4_keep_filters = $GLOBALS['test_hooks']['wpac_statement_store'] ?? null;
add_filter( 'wpac_statement_store', static function () use ( $p4_mem ) { return $p4_mem; } );
$p4_remote  = $p4_policy();
$p4_ropa_en = Privacy::ropa( 'en' );
$p4_ropa_de = Privacy::ropa( 'de' );
remove_all_filters( 'wpac_statement_store' );
if ( null !== $p4_keep_filters ) {
	$GLOBALS['test_hooks']['wpac_statement_store'] = $p4_keep_filters;
}
check(
	'R15-P4 privacy text: local folder – no third-country transfer; object storage – processor named with a placeholder, no "not transferred to third countries"',
	array( true, false, false, true, true ),
	array(
		false !== strpos( $p4_local, 'Data is not transferred to third countries by this plugin.' ),
		false !== strpos( $p4_local, 'object storage provider' ),
		false !== strpos( $p4_remote, 'Data is not transferred to third countries' ),
		false !== strpos( $p4_remote, 'stored with an object storage provider on our behalf (Art. 28 GDPR)' ),
		false !== strpos( $p4_remote, 'to be completed by the shop operator' ),
	)
);
check(
	'R15-P4 record of processing: object storage provider among the recipients only with object storage (English and German), no placeholder left',
	array( false, false, true, true, false ),
	array(
		false !== strpos( $p4_ropa_loc, 'object storage provider' ),
		false !== strpos( $p4_ropa_loc2, 'Objektspeicher-Anbieter' ),
		false !== strpos( $p4_ropa_en, 'processor storing the credit note PDFs: object storage provider' ),
		false !== strpos( $p4_ropa_de, 'Objektspeicher-Anbieter' ),
		false !== strpos( $p4_ropa_en . $p4_ropa_de . $p4_ropa_loc, '{document_storage}' ),
	)
);
check(
	'R15-P5 privacy text names the recruiter purpose (partner ID, referral of the visit); record of processing too; admin change of the recruiter in the Art. 15 export',
	array( true, true, true, true, array( 'from', 'to' ), array( 'status', 'referred_by' ) ),
	array(
		false !== strpos( $p4_local, 'we store which partner recruited you (partner ID)' ),
		false !== strpos( $p4_local, 'the referral described above is also used to record which partner recruited you' ),
		false !== strpos( $p15_src( 'docs/RECORD-OF-PROCESSING.md' ), 'record which partner recruited a new partner' ),
		false !== strpos( $p15_src( 'docs/VERARBEITUNGSVERZEICHNIS.md' ), 'welcher Partner einen neuen Partner geworben hat' ),
		Privacy::audit_export_fields()['affiliate.referred_by'] ?? null,
		Privacy::audit_export_fields()['affiliate.created'] ?? null,
	)
);
// The recruiter change appears in the partner's export (only partner IDs).
$p5_a = AffiliateRepository::create( array( 'name' => 'P5 Werber', 'email' => 'p5-werber@example.de', 'status' => 'active' ) );
$p5_b = AffiliateRepository::create( array( 'name' => 'P5 Neu', 'email' => 'p5-neu@example.de', 'status' => 'active' ) );
AffiliateRepository::set_referred_by( $p5_b, $p5_a );
$p5_export = wp_json_encode( Privacy::export( 'p5-neu@example.de', 1 ) );
check( 'R15-P5 export of the recruited partner lists the recruiter change (affiliate.referred_by, IDs only)', array( true, false ), array( false !== strpos( $p5_export, 'affiliate.referred_by' ), false !== strpos( $p5_export, 'P5 Werber' ) ) );
update_option( 'wpac_settings', $p4_settings );
Settings::flush_cache();

/* R15-P6: _wpac_auto_coupon in the customer export and erasure. ------------------------------- */
class P15_Order {
	public $id;
	public $meta;
	public function __construct( $id, $meta = array() ) { $this->id = $id; $this->meta = $meta; }
	public function get_id() { return $this->id; }
	public function get_meta( $k ) { return isset( $this->meta[ $k ] ) ? $this->meta[ $k ] : ''; }
	public function update_meta_data( $k, $v ) { $this->meta[ $k ] = $v; }
	public function delete_meta_data( $k ) { unset( $this->meta[ $k ] ); }
	public function save_meta_data() {}
}
$p6_a = AffiliateRepository::create( array( 'name' => 'P6 Partner', 'email' => 'p6@example.de', 'status' => 'active' ) );
$GLOBALS['za_orders'][15601] = new P15_Order( 15601, array( AutoCoupon::META_ORDER => array( 'coupon' => 'p6deal', 'affiliate_id' => $p6_a ) ) );
$GLOBALS['r12b_orders']      = array( 15601 => 'p6kunde@example.de' );
wpac_record_sale( array( 'source' => 'woocommerce', 'order_id' => '15601', 'currency' => 'EUR', 'partner_code' => CodeRepository::main_code( $p6_a ), 'items' => array( array( 'item_id' => '1', 'net_minor' => 5000 ) ), 'customer' => array( 'user_id' => 0, 'email' => 'p6kunde@example.de' ) ) );
$p6_export = Privacy::export( 'p6kunde@example.de', 1 );
$p6_item   = array_values( array_filter( $p6_export['data'], static function ( $i ) { return 'wpac-customer-auto-coupon' === $i['group_id']; } ) );
check(
	'R15-P6 customer export lists the automatically applied partner coupon of the order',
	array( 1, array( '#15601', 'p6deal', (string) $p6_a ) ),
	array( count( $p6_item ), isset( $p6_item[0] ) ? array_column( $p6_item[0]['data'], 'value' ) : array() )
);
$p6_res = Privacy::erase( 'p6kunde@example.de' );
check( 'R15-P6 customer erasure removes _wpac_auto_coupon from the attributed order', array( true, '' ), array( $p6_res['items_removed'], $GLOBALS['za_orders'][15601]->get_meta( AutoCoupon::META_ORDER ) ) );
unset( $GLOBALS['za_orders'][15601], $GLOBALS['r12b_orders'] );
check(
	'R15-P6 record of processing names _wpac_auto_coupon; docs of the new filters (P2/P3) in the contract and handbook; translations present',
	array( true, true, true, true, true ),
	array(
		false !== strpos( $p15_src( 'docs/RECORD-OF-PROCESSING.md' ), '`_wpac_auto_coupon`' ),
		false !== strpos( $p15_src( 'docs/CONTRACT.md' ), '`wpac_uninstall_store_budget`' ),
		false !== strpos( $p15_src( 'docs/CONTRACT.md' ), '`wpac_retained_purge_budget`' ),
		false !== strpos( $p15_src( 'docs/HANDBOOK.md' ), 'wpac_uninstall_store_left' ),
		false !== strpos( $p15_src( 'languages/de_DE.r15-p.json' ), 'Partnergutschein automatisch angewendet' ),
	)
);

/* Own processes: uninstall (delete / keep), deleted network sites, automatic coupon. ---------- */
foreach ( array( 'delete', 'keep', 'network', 'coupon' ) as $p15_mode ) {
	$p15_prefix = getenv( 'WPAC_TEST_DB_PREFIX' );
	putenv( 'WPAC_TEST_DB_PREFIX=p15' . substr( $p15_mode, 0, 1 ) . '_' );
	$p15_out = array();
	exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/r15-p-isolated.php' ) . ' ' . escapeshellarg( $p15_mode ) . ' 2>&1', $p15_out, $p15_rc );
	putenv( false === $p15_prefix ? 'WPAC_TEST_DB_PREFIX' : 'WPAC_TEST_DB_PREFIX=' . $p15_prefix );
	$p15_res = json_decode( (string) end( $p15_out ), true );
	if ( ! is_array( $p15_res ) || 0 !== $p15_rc ) {
		check( 'R15-P isolated checks ran (' . $p15_mode . ')', 'json', implode( "\n", $p15_out ) );
		continue;
	}
	foreach ( $p15_res as $p15_name => $p15_pair ) {
		check( $p15_name, $p15_pair[0], $p15_pair[1] );
	}
}
