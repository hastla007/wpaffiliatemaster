<?php
/**
 * Integration checks of audit panel round 13, part D (privacy): Art. 15 export without third-party
 * data (K8), persistent WPAC_CUSTOMER_KEY change state (K9), coupon unlink (M2), pseudonymised
 * commissions after the attribution purge (M3), log masking (N1), tax history and retained audit
 * details (N2), customer erasure (N3) and the application purge (N4).
 * Included from tests/integration/run.php before r8-render.php.
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Affiliates\Notifications;
use WPAffiliateCaptain\Codes\CodeRepository;
use WPAffiliateCaptain\Commissions\Ledger;
use WPAffiliateCaptain\Core\Audit;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\Log;
use WPAffiliateCaptain\Core\Monitor;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Integrations\WooCommerce\CouponLink;
use WPAffiliateCaptain\Payouts\ProfileRepository;
use WPAffiliateCaptain\Privacy\Privacy;
use WPAffiliateCaptain\Privacy\Restriction;
use WPAffiliateCaptain\Privacy\Retention;
use WPAffiliateCaptain\Tracking\CustomerLinks;

echo "R13 D: export, customer key, coupon unlink, pseudonymisation, log masking, erasure\n";

global $wpdb;
$r13d_root     = dirname( __DIR__, 2 );
$r13d_src      = static function ( $f ) use ( $r13d_root ) {
	return (string) file_get_contents( $r13d_root . '/' . $f );
};
$r13d_settings = get_option( 'wpac_settings' );
$r13d_set      = static function ( array $values ) {
	update_option( 'wpac_settings', array_merge( get_option( 'wpac_settings' ), $values ) );
	Settings::flush_cache();
};
$r13d_export   = static function ( $email ) {
	$data  = array();
	$pages = 0;
	do {
		$page = Privacy::export( $email, ++$pages );
		$data = array_merge( $data, $page['data'] );
	} while ( ! $page['done'] && $pages < 20 );
	return $data;
};
$r13d_set( array( 'operator_address' => "X 1\n12345 Y", 'operator_vat_id' => 'DE123456789', 'statement_retention_years' => 10, 'retention_hold' => 0 ) );

/* R13-K8: the Art. 15 export carries no third-party data and no internal notes. ------------- */
$k8_a = AffiliateRepository::create( array( 'name' => 'K8 Partner', 'email' => 'r13d-k8@example.de', 'status' => 'active' ) );
ProfileRepository::save( $k8_a, array( 'tax_status' => 'private', 'legal_name' => 'K8 Partner', 'address' => "Weg 1\n24103 Kiel", 'country' => 'DE', 'account_holder' => 'K8 Partner', 'iban' => 'DE89370400440532013000' ) );
$k8_sale = wpac_record_sale( array( 'source' => 'test', 'order_id' => 'r13d-901', 'currency' => 'EUR', 'partner_code' => CodeRepository::main_code( $k8_a ), 'items' => array( array( 'item_id' => '1', 'product_id' => 10, 'name' => 'Kurs', 'net_minor' => 10000 ) ), 'customer' => array( 'user_id' => 0, 'email' => 'kunde-r13d-901@example.de' ) ) );
wpac_record_refund( array( 'source' => 'test', 'order_id' => 'r13d-901', 'refund_id' => 'r13d-r1', 'type' => 'refund', 'reason' => 'Kundin Erika Mustermann, Tel. 0170-1234567, Ware beschaedigt', 'full' => true ) );
$k8_cid = (int) $k8_sale['commission_ids'][0];
$wpdb->insert( t( 'commission_events' ), array( 'commission_id' => $k8_cid, 'event' => 'created', 'from_status' => '', 'to_status' => 'review', 'reason' => 'review: self_referral,same_ip', 'created_at' => Db::now() ) );
$wpdb->insert( t( 'commission_events' ), array( 'commission_id' => $k8_cid, 'event' => 'approved', 'from_status' => 'review', 'to_status' => 'approved', 'reason' => 'Intern: Kunde Max Muster angerufen', 'created_at' => Db::now() ) );
$wpdb->insert( t( 'commission_events' ), array( 'commission_id' => $k8_cid, 'event' => 'rejected', 'from_status' => 'approved', 'to_status' => 'rejected', 'reason' => 'Bestellung storniert', 'created_at' => Db::now() ) );
Audit::log( 'payout_profile.iban_shared', 'affiliate', $k8_a, array( 'partners' => array( 98765 ) ) );
Audit::log( 'attribution.flagged', 'affiliate', $k8_a, array( 'source' => 'test', 'order_id' => 'r13d-geheim-4711', 'flags' => array( 'self_referral' ) ) );
Audit::log( 'affiliate.status', 'affiliate', $k8_a, array( 'from' => 'pending', 'to' => 'active', 'reason' => 'Willkommen' ) );
Audit::log( 'payout_profile.save_failed', 'affiliate', $k8_a, array( 'error' => "Duplicate entry 'geheim-k8@example.org'" ) );
$k8_data   = $r13d_export( 'r13d-k8@example.de' );
$k8_flat   = wp_json_encode( $k8_data, JSON_UNESCAPED_UNICODE );
$k8_groups = array_unique( array_column( $k8_data, 'group_id' ) );
$k8_hits   = array();
foreach ( $k8_data as $k8_item ) {
	foreach ( $k8_item['data'] as $k8_d ) {
		if ( false !== strpos( is_scalar( $k8_d['value'] ) ? (string) $k8_d['value'] : wp_json_encode( $k8_d['value'] ), 'Mustermann' ) ) {
			$k8_hits[] = $k8_item['group_id'] . ' | ' . $k8_d['name'];
		}
	}
}
check( 'R13-K8 repro: the refund reason with the customer name appears nowhere in the export', array(), $k8_hits );
check(
	'R13-K8 refund row shows its type (as in the partner area), the refund event a neutral label',
	array( true, true, true ),
	array( false !== strpos( $k8_flat, 'Correction (Refund)' ), false !== strpos( $k8_flat, '"Reason","value":"Refund"' ), in_array( 'wpac-commission-events', $k8_groups, true ) )
);
check(
	'R13-K8 fraud markers and internal notes become neutral labels; a rejection reason stays (as in the partner area)',
	array( false, true, false, true, true ),
	array( false !== strpos( $k8_flat, 'same_ip' ), false !== strpos( $k8_flat, 'Checked by the operator before approval' ), false !== strpos( $k8_flat, 'Max Muster' ), false !== strpos( $k8_flat, 'Internal note (not shown)' ), false !== strpos( $k8_flat, 'Bestellung storniert' ) )
);
check(
	'R13-K8 audit entries: positive list (no iban_shared/other partner IDs, no attribution.flagged, no DB errors), allowed fields kept',
	array( false, false, false, false, false, true, true ),
	array( false !== strpos( $k8_flat, 'iban_shared' ), false !== strpos( $k8_flat, '98765' ), false !== strpos( $k8_flat, 'attribution.flagged' ), false !== strpos( $k8_flat, 'r13d-geheim-4711' ), false !== strpos( $k8_flat, 'geheim-k8' ), false !== strpos( $k8_flat, 'Willkommen' ), isset( Privacy::audit_export_fields()['affiliate.status'] ) )
);
check(
	'R13-K8 IBAN masked to the last 4 digits with a note where the complete details are; decision documented',
	array( false, true, true, true ),
	array( false !== strpos( $k8_flat, 'DE89370400440532013000' ), false !== strpos( $k8_flat, 'DE •••• •••• 3000' ), false !== strpos( $k8_flat, 'masked in this file' ), false !== strpos( $r13d_src( 'docs/HANDBOOK.md' ), 'reverses the "full IBAN"' ) )
);
check( 'R13-K8 payment details of another method masked', 'Pa•••@paypal.example', ProfileRepository::mask_details( 'Paul.Partner@paypal.example' ) );

/* R13-K9: a changed WPAC_CUSTOMER_KEY stays reported until confirmed or no old link is left. --- */
$k9_links = t( 'customer_links' );
q( 'DROP TEMPORARY TABLE IF EXISTS r13d_links_backup' );
q( "CREATE TEMPORARY TABLE r13d_links_backup AS SELECT * FROM {$k9_links}" );
$k9_old_key = get_option( 'wpac_customer_key' );
$k9_old_fp  = get_option( CustomerLinks::CONSTANT_FP );
$k9_old_chg = get_option( CustomerLinks::KEY_CHANGED );
q( "DELETE FROM {$k9_links}" );
delete_option( CustomerLinks::KEY_CHANGED );
delete_option( 'wpac_customer_key' );
// The constant (defined in r12-e.php) is in use with links made with it.
update_option( CustomerLinks::CONSTANT_FP, CustomerLinks::fingerprint( WPAC_CUSTOMER_KEY ) );
CustomerLinks::reset_key_cache();
check( 'R13-K9 fingerprint is an HMAC with the site salt, not a plain sha256', array( hash_hmac( 'sha256', WPAC_CUSTOMER_KEY, wp_salt( 'auth' ) ), false ), array( CustomerLinks::fingerprint( WPAC_CUSTOMER_KEY ), hash( 'sha256', WPAC_CUSTOMER_KEY ) === CustomerLinks::fingerprint( WPAC_CUSTOMER_KEY ) ) );
// Fingerprint of earlier releases (plain sha256) is still recognised and upgraded.
update_option( CustomerLinks::CONSTANT_FP, hash( 'sha256', WPAC_CUSTOMER_KEY ) );
CustomerLinks::link( 1, CustomerLinks::hashes( 0, 'r13d-k9-new@example.de' ), 'test', 'r13d-k9-0' );
q( "DELETE FROM {$k9_links}" );
CustomerLinks::reset_key_cache();
check( 'R13-K9 legacy sha256 fingerprint: no warning, upgraded to the HMAC form', array( false, CustomerLinks::fingerprint( WPAC_CUSTOMER_KEY ) ), array( CustomerLinks::key_status()['missing'], get_option( CustomerLinks::CONSTANT_FP ) ) );
// Links made with an earlier constant (repro of priv/sec/ops).
$k9_earlier = 'an earlier constant 0123456789abcdefghijklmnop';
q( $wpdb->prepare( "INSERT INTO {$k9_links} (customer_hash, affiliate_id, source, source_order_id, created_at, expires_at) VALUES (%s, 1, 'test', 'r13d-k9-1', %s, NULL)", hash_hmac( 'sha256', 'e:r13d-kunde@example.de', $k9_earlier ), Db::now() ) );
update_option( CustomerLinks::CONSTANT_FP, CustomerLinks::fingerprint( $k9_earlier ) );
$k9_status = array();
for ( $k9_i = 1; $k9_i <= 3; $k9_i++ ) {
	CustomerLinks::reset_key_cache(); // Next request.
	$k9_status[] = CustomerLinks::key_status()['missing'];
}
$k9_checks = Monitor::checks();
check( 'R13-K9 repro: requests 1, 2 and 3 all warn; the earlier fingerprint is not overwritten; Site Health reports it', array( array( true, true, true ), CustomerLinks::fingerprint( $k9_earlier ), 'WPAC_CUSTOMER_KEY was removed or changed' ), array( $k9_status, get_option( CustomerLinks::CONSTANT_FP ), isset( $k9_checks['customer_key'] ) ? $k9_checks['customer_key'][1] : null ) );
// New links (made with the new constant) do not end the warning while old ones remain.
CustomerLinks::link( 1, CustomerLinks::hashes( 0, 'r13d-k9-neu@example.de' ), 'test', 'r13d-k9-2' );
CustomerLinks::reset_key_cache();
check( 'R13-K9 new link with the new key: still warns, the change counts 1 old link', array( true, 1, true ), array( CustomerLinks::key_status()['missing'], CustomerLinks::key_change()['links'], null !== CustomerLinks::find( CustomerLinks::hashes( 0, 'r13d-k9-neu@example.de' ) ) ) );
// The old links are gone (expired / purged): the warning ends by itself, the new key becomes the reference.
q( "DELETE FROM {$k9_links} WHERE source_order_id = 'r13d-k9-1'" );
CustomerLinks::reset_key_cache();
check( 'R13-K9 no old links left: warning ends, fingerprint of the new key stored, state cleared', array( false, CustomerLinks::fingerprint( WPAC_CUSTOMER_KEY ), false ), array( CustomerLinks::key_status()['missing'], get_option( CustomerLinks::CONSTANT_FP ), get_option( CustomerLinks::KEY_CHANGED ) ) );
// Admin confirmation (with deleting the old links): only links that existed when the change was noticed count as old.
q( "DELETE FROM {$k9_links}" );
q( $wpdb->prepare( "INSERT INTO {$k9_links} (customer_hash, affiliate_id, source, source_order_id, created_at, expires_at) VALUES (%s, 1, 'test', 'r13d-k9-3', %s, NULL)", hash_hmac( 'sha256', 'e:r13d-kunde@example.de', $k9_earlier ), Db::now() ) );
update_option( CustomerLinks::CONSTANT_FP, CustomerLinks::fingerprint( $k9_earlier ) );
CustomerLinks::reset_key_cache();
$k9_before = CustomerLinks::key_status()['missing'];
CustomerLinks::link( 1, CustomerLinks::hashes( 0, 'r13d-k9-neu2@example.de' ), 'test', 'r13d-k9-4' );
$k9_del = CustomerLinks::confirm_key_change( true );
CustomerLinks::reset_key_cache();
check(
	'R13-K9 admin confirms: old links deleted, new links kept, warning gone, audited; action routed with MANAGE_PROGRAM',
	array( true, 1, false, 0, 1, CustomerLinks::fingerprint( WPAC_CUSTOMER_KEY ), true, true ),
	array( $k9_before, $k9_del, CustomerLinks::key_status()['missing'], (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$k9_links} WHERE source_order_id = 'r13d-k9-3'" ), (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$k9_links} WHERE source_order_id = 'r13d-k9-4'" ), get_option( CustomerLinks::CONSTANT_FP ), (bool) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . t( 'audit' ) . " WHERE action = 'customer_links.key_confirmed'" ), false !== strpos( $r13d_src( 'src/Admin/Admin.php' ), "'customer_key_confirm' => array( IntegrationsPage::class, Capabilities::MANAGE_PROGRAM )" ) )
);
// Key generation race: the other request inserted its key after this one read the (missing) option.
$k9_db = static function () use ( $wpdb ) {
	return $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'wpac_customer_key' ) );
};
delete_option( 'wpac_customer_key' );
$k9_winner = str_repeat( 'b7', 32 );
$wpdb->insert( $wpdb->options, array( 'option_name' => 'wpac_customer_key', 'option_value' => $k9_winner, 'autoload' => 'no' ) ); // The other request.
$k9_gen = new ReflectionMethod( CustomerLinks::class, 'generate_key' );
$k9_gen->setAccessible( true );
check( 'R13-K9 concurrent key generation: the insert does not overwrite, both use the winner\'s key', array( $k9_winner, $k9_winner ), array( $k9_gen->invoke( null ), $k9_db() ) );
delete_option( 'wpac_customer_key' );
$k9_first = $k9_gen->invoke( null );
check( 'R13-K9 first generation stores its key', array( $k9_first, 64 ), array( $k9_db(), strlen( (string) $k9_first ) ) );
check( 'R13-K9 state option removed on uninstall', true, false !== strpos( $r13d_src( 'uninstall.php' ), "'wpac_customer_key_changed'" ) );
// Restore.
q( "DELETE FROM {$k9_links}" );
q( "INSERT INTO {$k9_links} SELECT * FROM r13d_links_backup" );
q( 'DROP TEMPORARY TABLE r13d_links_backup' );
foreach ( array( CustomerLinks::CONSTANT_FP => $k9_old_fp, CustomerLinks::KEY_CHANGED => $k9_old_chg, 'wpac_customer_key' => $k9_old_key ) as $k9_opt => $k9_val ) {
	delete_option( $k9_opt );
	if ( false !== $k9_val ) {
		update_option( $k9_opt, $k9_val );
	}
}
CustomerLinks::reset_key_cache();

/* R13-M2: a removed coupon link must not come back (and block the code) on the next coupon save. */
$m2_a  = AffiliateRepository::create( array( 'name' => 'M2 Partner', 'email' => 'r13d-m2@example.de', 'status' => 'active' ) );
$m2_wc = new WC_Coupon();
$m2_wc->set_code( 'r13dm2coupon' );
$m2_wc->save();
$m2_code = (int) CodeRepository::create( $m2_a, 'R13DM2' );
CodeRepository::set_coupon( $m2_code, 'r13dm2coupon' );
CouponLink::remember( $m2_code, 'r13dm2coupon' );
$m2_meta = $GLOBALS['test_wc_coupon_meta'][ $m2_wc->get_id() ][ CouponLink::META_CODE_ID ] ?? null;
// Unlink on the partner page (what AffiliatesPage::handle('code_coupon') does with an empty coupon).
CodeRepository::set_coupon( $m2_code, '' );
CouponLink::forget_code( $m2_code, 'r13dm2coupon' );
$_POST = array( '_wpac_coupon_nonce' => 'x', 'wpac_coupon_affiliate' => '' );
CouponLink::coupon_save( $m2_wc->get_id(), new WC_Coupon( $m2_wc->get_id() ) );
$m2_row = CodeRepository::find( $m2_code );
check( 'R13-M2 repro: unlink, then save the coupon → the partner code stays active and unlinked, the meta is gone', array( $m2_code, 'active', '', null ), array( (int) $m2_meta, $m2_row->status, (string) $m2_row->coupon_code, $GLOBALS['test_wc_coupon_meta'][ $m2_wc->get_id() ][ CouponLink::META_CODE_ID ] ?? null ) );
// Even with a stale meta (unlinked by an older version) the row is not followed.
$GLOBALS['test_wc_coupon_meta'][ $m2_wc->get_id() ][ CouponLink::META_CODE_ID ] = $m2_code;
CouponLink::coupon_save( $m2_wc->get_id(), new WC_Coupon( $m2_wc->get_id() ) );
$m2_row = CodeRepository::find( $m2_code );
check( 'R13-M2 rename-follow only for rows with a coupon: stale meta does not re-link or block the code; partner page forgets the meta', array( 'active', '', true ), array( $m2_row->status, (string) $m2_row->coupon_code, false !== strpos( $r13d_src( 'src/Admin/Pages/AffiliatesPage.php' ), 'CouponLink::forget_code(' ) ) );
$_POST = array();

/* R13-M3: commissions past the retention lose order number, product and texts. -------------- */
$m3_a = AffiliateRepository::create( array( 'name' => 'M3 Partner', 'email' => 'r13d-m3@example.de', 'status' => 'active' ) );
foreach ( array( 'r13d-1001' => 'paid', 'r13d-1002' => 'pending' ) as $m3_o => $m3_st ) {
	q( $wpdb->prepare( 'INSERT INTO ' . t( 'attributions' ) . " (source,source_order_id,affiliate_id,method,sub_id,created_at) VALUES ('woocommerce',%s,%d,'link','newsletter-anna','2012-03-01 00:00:00')", $m3_o, $m3_a ) );
	q( $wpdb->prepare( 'INSERT INTO ' . t( 'commissions' ) . " (affiliate_id,source,source_order_id,kind,description,product_ref,currency,amount_minor,status,idempotency_key,created_at,updated_at) VALUES (%d,'woocommerce',%s,'sale','Produkt X','SKU-X','EUR',100,%s,%s,'2012-03-01','2012-03-02')", $m3_a, $m3_o, $m3_st, 'sale:woocommerce:' . $m3_o . ':1' ) );
	$m3_id = (int) $wpdb->insert_id;
	$wpdb->insert( t( 'commission_events' ), array( 'commission_id' => $m3_id, 'event' => 'created', 'from_status' => '', 'to_status' => $m3_st, 'reason' => 'refund: Kunde Anna Alt', 'created_at' => '2012-03-01 00:00:00' ) );
}
$r13d_set( array( 'retention_hold' => 1 ) );
Retention::purge_attributions();
check( 'R13-M3 legal hold: nothing pseudonymised', 'r13d-1001', (string) $wpdb->get_var( 'SELECT source_order_id FROM ' . t( 'commissions' ) . " WHERE idempotency_key = 'sale:woocommerce:r13d-1001:1'" ) );
$r13d_set( array( 'retention_hold' => 0, 'statement_retention_years' => 0 ) );
check( 'R13-M3 "keep forever": nothing pseudonymised', 0, Retention::pseudonymise_commissions() );
$r13d_set( array( 'statement_retention_years' => 10 ) );
Retention::purge_attributions();
$m3_rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, source_order_id, description, product_ref, idempotency_key, amount_minor, status FROM ' . t( 'commissions' ) . ' WHERE affiliate_id = %d ORDER BY id', $m3_a ) );
check(
	'R13-M3 repro: paid order past the retention → order number pseudonymised, product/description emptied, key hashed; open order untouched',
	array( Retention::pseudonym( 'woocommerce:r13d-1001' ), '', '', Retention::pseudonym_key( 'sale:woocommerce:r13d-1001:1' ), '100', 'r13d-1002', 'Produkt X' ),
	array( $m3_rows[0]->source_order_id, $m3_rows[0]->description, $m3_rows[0]->product_ref, $m3_rows[0]->idempotency_key, (string) $m3_rows[0]->amount_minor, $m3_rows[1]->source_order_id, $m3_rows[1]->description )
);
check( 'R13-M3 reasons of the status changes deleted with it; attribution of the pseudonymised order gone', array( '', 'refund: Kunde Anna Alt', null ), array( (string) $wpdb->get_var( 'SELECT reason FROM ' . t( 'commission_events' ) . ' WHERE commission_id = ' . (int) $m3_rows[0]->id ), (string) $wpdb->get_var( 'SELECT reason FROM ' . t( 'commission_events' ) . ' WHERE commission_id = ' . (int) $m3_rows[1]->id ), Ledger::attribution_for( 'woocommerce', 'r13d-1001' ) ) );
$m3_again = wpac_record_sale( array( 'source' => 'woocommerce', 'order_id' => 'r13d-1001', 'currency' => 'EUR', 'partner_code' => CodeRepository::main_code( $m3_a ), 'items' => array( array( 'item_id' => '1', 'net_minor' => 5000 ) ), 'customer' => array( 'user_id' => 0, 'email' => 'm3@example.de' ) ) );
check( 'R13-M3 a re-delivered sale still dedupes (hashed key / pseudonym); find_by_key finds the hashed key', array( 'duplicate', (int) $m3_rows[0]->id, 1 ), array( $m3_again['status'], (int) Ledger::find_by_key( 'sale:woocommerce:r13d-1001:1' )->id, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . t( 'commissions' ) . ' WHERE affiliate_id = %d', $m3_a ) ) - 1 ) );
check( 'R13-M3 idempotent: a second run changes nothing', array( 0, $m3_rows[0]->idempotency_key ), array( Retention::pseudonymise_commissions(), (string) $wpdb->get_var( 'SELECT idempotency_key FROM ' . t( 'commissions' ) . ' WHERE id = ' . (int) $m3_rows[0]->id ) ) );
check( 'R13-M3 record of processing and privacy text describe the pseudonymisation', array( true, true ), array( false !== strpos( $r13d_src( 'docs/VERARBEITUNGSVERZEICHNIS.md' ), 'Provisionszeilen der Bestellung pseudonymisiert' ), false !== strpos( $r13d_src( 'src/Privacy/Privacy.php' ), 'order number pseudonymised, product and booking texts deleted' ) ) );

/* R13-N1: log masking before JSON, Unicode-aware, also for the job queue and DB errors. ------ */
check(
	'R13-N1 Unicode-aware masking (umlauts, IDN, cyrillic, address literal, JSON-free), DB duplicate values removed',
	array( "Duplicate entry 'a***@***.de' for key", 'j***@***.de', 'а***@***.рф', 'a***@***', "Duplicate entry '***' for key 'slug'" ),
	array( Log::mask( "Duplicate entry 'anna@bücher.de' for key" ), Log::mask( 'jörg.müller@example.de' ), Log::mask( 'анна@пример.рф' ), Log::mask( 'anna@[192.168.0.1]' ), Log::mask_error( "Duplicate entry 'jane-doe' for key 'slug'" ) )
);
$n1_file = tempnam( sys_get_temp_dir(), 'r13d' );
$n1_prev = ini_set( 'error_log', $n1_file );
Log::error( 'Mail failed', array( 'to' => 'jörg.müller@bücher.de', 'nested' => array( 'анна@пример.рф' ) ) );
ini_set( 'error_log', (string) $n1_prev );
$n1_line = (string) file_get_contents( $n1_file );
@unlink( $n1_file );
check( 'R13-N1 log line: context masked before encoding (no readable name, no \\u escape left)', array( false, false, false, true ), array( false !== strpos( $n1_line, 'ller' ), false !== strpos( $n1_line, 'bücher' ), false !== strpos( $n1_line, '\\u' ), false !== strpos( $n1_line, 'j***@***.de' ) ) );
check( 'R13-N1 job queue last_error and the partner status DB error are masked', array( true, true ), array( false !== strpos( $r13d_src( 'src/Core/Jobs.php' ), "Log::mask_error( Log::plain( \$e->getMessage() ) )" ), false !== strpos( $r13d_src( 'src/Affiliates/AffiliateRepository.php' ), "Log::mask_error( (string) \$wpdb->last_error )" ) ) );

/* R13-N2: retained payout audit details pseudonymised, correct message with "keep forever". -- */
$n2_a = AffiliateRepository::create( array( 'name' => 'N2 Partner', 'email' => 'r13d-n2@example.de', 'status' => 'active' ) );
sale( 'r13d-n2-1', CodeRepository::main_code( $n2_a ), array( 10000 ) );
Audit::log( 'payout_profile.tax_changed', 'affiliate', $n2_a, array( 'old' => array( 'vat_id' => 'DE123456789', 'tax_number' => '12/345/67890', 'legal_name' => 'Nina Zwei' ), 'new' => array( 'vat_id' => 'DE987654321' ) ) );
Audit::log( 'payout_profile.iban_shared', 'affiliate', $n2_a, array( 'partners' => array( 4711 ) ) );
Audit::log( 'payout_profile.save_failed', 'affiliate', $n2_a, array( 'error' => 'Duplicate entry' ) );
$r13d_set( array( 'statement_retention_years' => 0 ) );
$n2_msgs    = implode( ' | ', Privacy::erase_partner( AffiliateRepository::find( $n2_a ) ) );
$n2_details = static function ( $action ) use ( $wpdb, $n2_a ) {
	return $wpdb->get_var( $wpdb->prepare( 'SELECT details FROM ' . t( 'audit' ) . ' WHERE object_type = %s AND object_id = %d AND action = %s ORDER BY id DESC LIMIT 1', 'affiliate', $n2_a, $action ) );
};
$n2_tax = (string) $n2_details( 'payout_profile.tax_changed' );
$n2_dec = json_decode( $n2_tax, true );
check(
	'R13-N2 retained tax change: tax IDs masked, names removed; iban_shared and save_failed not retained',
	array( Privacy::mask_tax_id( 'DE123456789' ), Privacy::mask_tax_id( 'DE987654321' ), false, false, false, null, null ),
	array( $n2_dec['old']['vat_id'] ?? null, $n2_dec['new']['vat_id'] ?? null, false !== strpos( $n2_tax, 'DE123456789' ), false !== strpos( $n2_tax, '67890' ), false !== strpos( $n2_tax, 'Nina' ), $n2_details( 'payout_profile.iban_shared' ), $n2_details( 'payout_profile.save_failed' ) )
);
check( 'R13-N2 eraser message with "keep forever": no "deleted afterwards" promise', array( true, false ), array( false !== strpos( $n2_msgs, 'not deleted automatically while the retention period is set to "keep forever"' ), false !== strpos( $n2_msgs, 'their details are deleted afterwards' ) || false !== strpos( $n2_msgs, 'its details are deleted afterwards' ) ) );
$r13d_set( array( 'statement_retention_years' => 10 ) );
check( 'R13-N2 tax ID mask', array( 'DE•••789', '•••' ), array( Privacy::mask_tax_id( 'DE 123 456 789' ), Privacy::mask_tax_id( '1234' ) ) );

/* R13-N3: customer erasure removes partner code and manual assignment; keeps unpaid orders. -- */
class R13D_Order {
	public $id;
	public $meta;
	public $paid;
	public function __construct( $id, $meta, $paid ) { $this->id = $id; $this->meta = $meta; $this->paid = $paid; }
	public function get_id() { return $this->id; }
	public function get_meta( $k ) { return isset( $this->meta[ $k ] ) ? $this->meta[ $k ] : ''; }
	public function update_meta_data( $k, $v ) { $this->meta[ $k ] = $v; }
	public function delete_meta_data( $k ) { unset( $this->meta[ $k ] ); }
	public function save_meta_data() {}
	public function is_paid() { return $this->paid; }
	public function has_status( $s ) { return false; }
}
$n3_a    = AffiliateRepository::create( array( 'name' => 'N3 Partner', 'email' => 'r13d-n3@example.de', 'status' => 'active' ) );
$n3_meta = array( '_wpac_touch' => array( 'code' => 'X', 'method' => 'link' ), '_wpac_partner_code' => 'N3CODE', '_wpac_manual_affiliate' => $n3_a, '_wpac_manual_own' => 13901 );
$GLOBALS['za_orders'][13901] = new R13D_Order( 13901, $n3_meta, true );  // Attributed.
$GLOBALS['za_orders'][13902] = new R13D_Order( 13902, $n3_meta, false ); // Not paid yet, not attributed.
$GLOBALS['za_orders'][13903] = new R13D_Order( 13903, $n3_meta, true );  // Paid, never attributed.
$GLOBALS['r12b_orders']      = array( 13901 => 'r13d-n3kunde@example.de', 13902 => 'r13d-n3kunde@example.de', 13903 => 'r13d-n3kunde@example.de' );
wpac_record_sale( array( 'source' => 'woocommerce', 'order_id' => '13901', 'currency' => 'EUR', 'partner_code' => CodeRepository::main_code( $n3_a ), 'items' => array( array( 'item_id' => '1', 'net_minor' => 5000 ) ), 'customer' => array( 'user_id' => 0, 'email' => 'r13d-n3kunde@example.de' ) ) );
$n3_res = Privacy::erase( 'r13d-n3kunde@example.de' );
check(
	'R13-N3 attributed and settled orders lose referral, partner code and manual assignment; the unpaid order keeps them and is reported as retained',
	array( array(), array(), 4, true, true, true ),
	array( $GLOBALS['za_orders'][13901]->meta, $GLOBALS['za_orders'][13903]->meta, count( $GLOBALS['za_orders'][13902]->meta ), $n3_res['items_removed'], $n3_res['items_retained'], false !== strpos( implode( ' ', $n3_res['messages'] ), 'not paid yet' ) )
);
unset( $GLOBALS['za_orders'][13901], $GLOBALS['za_orders'][13902], $GLOBALS['za_orders'][13903], $GLOBALS['r12b_orders'] );

/* R13-N4: application purge: Art. 18, leftovers, batches until done. --------------------- */
$r13d_set( array( 'pending_application_days' => 365 ) );
$n4_ids = array();
for ( $n4_i = 0; $n4_i < 60; $n4_i++ ) {
	$n4_ids[] = AffiliateRepository::create( array( 'name' => 'N4 Applicant ' . $n4_i, 'email' => 'r13d-n4-' . $n4_i . '@example.de', 'status' => 'pending' ) );
}
q( 'UPDATE ' . t( 'affiliates' ) . " SET updated_at = '2020-01-01 00:00:00', landing_page_id = 77 WHERE id IN (" . implode( ',', array_map( 'intval', $n4_ids ) ) . ')' );
$n4_first      = (int) $n4_ids[0];
$n4_restricted = (int) $n4_ids[1];
Restriction::add( $n4_restricted, Restriction::REQUEST, 'legal claim' );
foreach ( array( 'wpac_vies_', 'wpac_prev_email_', 'wpac_public_change_', 'wpac_user_link_' ) as $n4_prefix ) {
	update_option( $n4_prefix . $n4_first, array( 'email' => 'x@example.de' ) );
}
add_option( 'wpac_dunning_' . $n4_first . '_EUR', array( 1 ) ); // Written to the table (found by LIKE).
Notifications::set_opt_out( $n4_first, 'commission', true );
AffiliateRepository::clear_cache();
$n4_n = Privacy::purge_pending_applications();
AffiliateRepository::clear_cache();
$n4_left = array();
foreach ( array( 'wpac_vies_', 'wpac_prev_email_', 'wpac_public_change_', 'wpac_user_link_', 'wpac_dunning_' ) as $n4_prefix ) {
	if ( false !== get_option( $n4_prefix . $n4_first . ( 'wpac_dunning_' === $n4_prefix ? '_EUR' : '' ) ) ) {
		$n4_left[] = $n4_prefix;
	}
}
check(
	'R13-N4 59 old applications anonymised in one run (no LIMIT 50 per day), the restricted one kept (Art. 18), leftovers removed',
	array( 59, 'pending', array(), false, 0 ),
	array( $n4_n, AffiliateRepository::find( $n4_restricted )->status, $n4_left, Notifications::opted_out( $n4_first, 'commission' ), (int) AffiliateRepository::find( $n4_first )->landing_page_id )
);
Restriction::remove( $n4_restricted, '', false );

update_option( 'wpac_settings', $r13d_settings );
Settings::flush_cache();
