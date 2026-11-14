<?php
/**
 * R8 package G7: privacy export/erasure, retention of ended partnerships, CSV import, program
 * export, streamed CSV exports and the audit log.
 * Included from run.php (helpers check(), t(), q() come from there).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Admin\Pages\ImportExportPage;
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Codes\CodeRepository;
use WPAffiliateCaptain\Core\Audit;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\PrivateFile;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Migration\PartnerImport;
use WPAffiliateCaptain\Migration\ProgramExport;
use WPAffiliateCaptain\Privacy\Privacy;
use WPAffiliateCaptain\Privacy\Retention;

echo "R8 G7 privacy export, retention, import, export, audit log\n";
global $wpdb;
$g7_keep = get_option( 'wpac_settings' );
update_option( 'wpac_settings', array_merge( $g7_keep, array( 'maintenance_mode' => 0, 'currency' => 'EUR' ) ) );
Settings::flush_cache();
if ( ! function_exists( 'wp_lostpassword_url' ) ) { function wp_lostpassword_url( $r = '' ) { return 'http://example.test/wp-login.php?action=lostpassword'; } }
$g7_subjects = static function () {
	return array_map( static function ( $m ) { return (string) $m[1]; }, $GLOBALS['test_mails'] );
};

/* R5-W18: private temporary files ------------------------------------------------------------- */
$g7_tmp = PrivateFile::create( 'g7-test' );
check( 'R5-W18 private temp file: 0600, outside the WordPress folder, deleted at the end of the request', array( '0600', false, true ), array( substr( sprintf( '%o', fileperms( $g7_tmp ) ), -4 ), 0 === strpos( realpath( $g7_tmp ), realpath( ABSPATH ) ), in_array( $g7_tmp, PrivateFile::tracked(), true ) ) );
PrivateFile::release( $g7_tmp );
check( 'R5-W18 released file is gone', false, file_exists( $g7_tmp ) );

/* R5-W19, R6-QA10: program export -------------------------------------------------------------- */
$g7_aff = AffiliateRepository::create( array( 'name' => 'G7 Export', 'email' => 'g7-export@example.de', 'status' => 'active' ) );
$wpdb->insert( t( 'payouts' ), array( 'number' => 'GS-G7-1', 'affiliate_id' => $g7_aff, 'period_end' => gmdate( 'Y-m-d' ), 'currency' => 'EUR', 'status' => 'paid', 'statement_file' => 'GS-G7-1-secretrandom123.pdf', 'note' => 'x";=HYPERLINK("http://evil")', 'created_at' => Db::now() ) );
$g7_before_tracked = count( PrivateFile::tracked() );
$g7_zip            = tempnam( sys_get_temp_dir(), 'g7zip' );
ProgramExport::write( $g7_zip, false );
$g7_z = new ZipArchive();
$g7_z->open( $g7_zip );
$g7_payouts = (string) $g7_z->getFromName( 'payouts.csv' );
check( 'R5-W19 export without tax data hides the credit note file names', array( false, true ), array( false !== strpos( $g7_payouts, 'secretrandom123' ), (bool) preg_match( '/GS-G7-1;[^\n]*;yes;/', $g7_payouts ) ) );
$g7_cells = array();
foreach ( explode( "\n", $g7_payouts ) as $g7_line ) {
	if ( false !== strpos( $g7_line, 'GS-G7-1' ) ) {
		$g7_cells = str_getcsv( $g7_line, ';', '"', '' );
	}
}
check( 'R6-QA10 a quote with backslash cannot open a new formula cell', array( true, array() ), array( in_array( 'x";=HYPERLINK("http://evil")', $g7_cells, true ), array_values( array_filter( $g7_cells, static function ( $c ) { return '' !== $c && '=' === $c[0]; } ) ) ) );
check( 'R5-W18 program export leaves no temporary files behind', $g7_before_tracked, count( PrivateFile::tracked() ) );
$g7_z->close();
@unlink( $g7_zip );

/* R5-W25: keyset batches ---------------------------------------------------------------------- */
for ( $i = 1; $i <= 5; $i++ ) {
	$wpdb->insert( t( 'commissions' ), array( 'affiliate_id' => $g7_aff, 'source' => 'test', 'source_order_id' => 'g7-k' . $i, 'kind' => 'sale', 'description' => 'x', 'currency' => 'EUR', 'amount_minor' => 100, 'status' => 'pending', 'idempotency_key' => 'g7-k' . $i, 'created_at' => Db::now(), 'updated_at' => Db::now() ) );
}
$g7_ids = array();
foreach ( \WPAffiliateCaptain\Core\Paged::each( array( \WPAffiliateCaptain\Commissions\Ledger::class, 'query' ), array( 'affiliate_id' => $g7_aff ), 2 ) as $g7_r ) {
	$g7_ids[] = (int) $g7_r->id;
}
list( , $g7_total ) = \WPAffiliateCaptain\Commissions\Ledger::query( array( 'affiliate_id' => $g7_aff, 'limit' => 1 ) );
check( 'R5-W25 streamed export reads all rows in ID batches (no OFFSET, no duplicates)', array( 5, 5, true ), array( (int) $g7_total, count( array_unique( $g7_ids ) ), $g7_ids === array_values( array_reverse( array_values( array_unique( $g7_ids ) ) ) ) || $g7_ids === array_values( array_unique( $g7_ids ) ) ) );

/* R5-W20, R5-W21: imported partners and e-mails -------------------------------------------------- */
$GLOBALS['test_users'][ 9701 ] = (object) array( 'ID' => 9701, 'user_email' => 'g7-kunde@example.de', 'user_login' => 'g7kunde' );
$g7_csv = "name;email;status;codes\n"
	. "G7 Aktiv;g7-aktiv@example.de;active;G7AKTIV\n"
	. "G7 Pause;g7-pause@example.de;paused;\n"
	. "G7 Ende;g7-ende@example.de;terminated;\n"
	. "G7 Abgelehnt;g7-abgelehnt@example.de;rejected;\n"
	. "G7 Kunde;g7-kunde@example.de;active;G7KUNDE\n";
$GLOBALS['test_mails'] = array();
$g7_run                = PartnerImport::run( $g7_csv, array( 'dry_run' => false, 'notify' => false ) );
$g7_kunde              = AffiliateRepository::find_by_email( 'g7-kunde@example.de' );
check( 'R5-W21 import without e-mails: nothing sent, all created', array( array(), 5 ), array( $GLOBALS['test_mails'], $g7_run['created'] ) );
check( 'R5-W21 suppressed account link is not recorded as sent (no pending link, no 10-minute lock)', array( false, false, true ), array( get_option( 'wpac_user_link_' . (int) $g7_kunde->id ), AffiliateRepository::user_link_pending( (int) $g7_kunde->id ), (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM ' . t( 'audit' ) . " WHERE action = 'affiliate.user_link_not_sent' AND object_id = %d", $g7_kunde->id ) ) ) );
check( 'R5-W21 the account link can be sent right away afterwards', array( true, 1 ), array( AffiliateRepository::resend_user_link( (int) $g7_kunde->id ), count( $GLOBALS['test_mails'] ) ) );
$g7_csv2 = "name;email;status\n"
	. "G7 Aktiv2;g7-aktiv2@example.de;active\n"
	. "G7 Pause2;g7-pause2@example.de;paused\n"
	. "G7 Ende2;g7-ende2@example.de;terminated\n"
	. "G7 Abgelehnt2;g7-abgelehnt2@example.de;rejected\n";
$GLOBALS['test_mails'] = array();
PartnerImport::run( $g7_csv2, array( 'dry_run' => false, 'notify' => true ) );
$g7_subj = $g7_subjects();
check( 'R5-W20 import with e-mails: only the welcome mail of the active partner, no approval/rejection/pause/termination mails', array( 1, true ), array( count( $g7_subj ), isset( $g7_subj[0] ) && false !== strpos( $g7_subj[0], 'Welcome to our partner program' ) ) );
check( 'R5-W20 imported statuses kept', array( 'paused', 'terminated', 'rejected' ), array( AffiliateRepository::find_by_email( 'g7-pause2@example.de' )->status, AffiliateRepository::find_by_email( 'g7-ende2@example.de' )->status, AffiliateRepository::find_by_email( 'g7-abgelehnt2@example.de' )->status ) );

/* R5-O10: side issues do not drop the partner ---------------------------------------------------- */
$GLOBALS['test_users'][ 9702 ] = (object) array( 'ID' => 9702, 'user_email' => 'jemand-anderes@example.de', 'user_login' => 'other' );
$g7_o10 = PartnerImport::run( "email;codes;user_id\ng7-o10@example.de;AB G7GUT;9702\n", array( 'dry_run' => false ) );
$g7_o10_aff = AffiliateRepository::find_by_email( 'g7-o10@example.de' );
check( 'R5-O10 too short code and foreign user_id are notes with a hint, the partner is imported with the good code', array( 'created', 2, array( 'G7GUT' ), true ), array( $g7_o10['rows'][2]['result'], count( $g7_o10['rows'][2]['notes'] ), array_map( static function ( $c ) { return $c->code; }, CodeRepository::for_affiliate( (int) $g7_o10_aff->id ) ), false !== strpos( implode( ' ', $g7_o10['rows'][2]['notes'] ), 'partner page' ) ) );

/* R5-W22: batches, resume, partial success --------------------------------------------------- */
$g7_state = PartnerImport::start( "email;status;codes\ng7-b1@example.de;active;G7B1\ng7-b2@example.de;active;G7B2\ng7-b3@example.de;active;G7B3\n", array( 'dry_run' => false ) );
$g7_state['started_at'] = Db::now( time() - 5 );
// An earlier request created the partner of line 3 and died before its codes and status.
AffiliateRepository::create( array( 'name' => 'G7 B2', 'email' => 'g7-b2@example.de', 'status' => 'pending' ) );
// Between check and import someone took the code of line 4.
CodeRepository::create( $g7_aff, 'G7B3', array( 'strict' => true ) );
check( 'R5-W22 a step stops when its time budget is used up and continues later', array( false, 1 ), array( PartnerImport::step( $g7_state, 0.000001 ), $g7_state['cursor'] ) );
while ( ! PartnerImport::step( $g7_state, 30 ) ) {}
$g7_rep = PartnerImport::report( $g7_state );
$g7_b2  = AffiliateRepository::find_by_email( 'g7-b2@example.de' );
check( 'R5-W22 interrupted row is completed (not "already exists"), code conflict = created with warning', array( 3, 0, 0, 1, 'active', array( 'G7B2' ) ), array( $g7_rep['created'], $g7_rep['skipped'], $g7_rep['failed'], $g7_rep['warnings'], $g7_b2->status, array_map( static function ( $c ) { return $c->code; }, CodeRepository::for_affiliate( (int) $g7_b2->id ) ) ) );
$g7_again = PartnerImport::run( "email;status\ng7-b1@example.de;active\n", array( 'dry_run' => false ) );
check( 'R5-W22 importing again reports "skipped", not an error', array( 0, 1, 0 ), array( $g7_again['created'], $g7_again['skipped'], $g7_again['failed'] ) );

/* R5-W24: one import at a time ---------------------------------------------------------------- */
$g7_owner = Db::lock( PartnerImport::LOCK, 300 );
$g7_busy  = PartnerImport::run( "email\ng7-lock@example.de\n", array( 'dry_run' => false ) );
Db::unlock( PartnerImport::LOCK, $g7_owner );
check( 'R5-W24 a second import while one runs is refused, nothing created', array( 'wpac_import_running', false ), array( is_wp_error( $g7_busy ) ? $g7_busy->get_error_code() : '', (bool) AffiliateRepository::find_by_email( 'g7-lock@example.de' ) ) );

/* R5-W23: import state in an encrypted private file, small transient ---------------------------- */
$g7_st = PartnerImport::start( "name;email\nG7 Gross;g7-gross@example.de\n", array( 'dry_run' => false ) );
$g7_ok = ImportExportPage::save_state( $g7_st, array( 'notify' => false, 'file' => 'x.csv', 'phase' => 'preview' ) );
$g7_meta = get_transient( 'wpac_import_' . get_current_user_id() );
$g7_load = ImportExportPage::load_state();
check( 'R5-W23 state saved to a private encrypted file, transient holds only path and hash', array( true, false, false, true, 'g7-gross@example.de' ), array( $g7_ok, isset( $g7_meta['rows'] ) || isset( $g7_meta['csv'] ), false !== strpos( (string) file_get_contents( $g7_meta['path'] ), 'g7-gross' ), 64 === strlen( (string) $g7_meta['hash'] ), $g7_load ? $g7_load[0]['rows'][2]['data']['email'] : '' ) );
file_put_contents( $g7_meta['path'], 'tampered' );
check( 'R5-W23 a replaced state file is refused', null, ImportExportPage::load_state() );
ImportExportPage::delete_state();
check( 'R5-W23 state file removed', false, file_exists( $g7_meta['path'] ) );

/* R7-AD9, R5-W26: complete and paged Art. 15 export ------------------------------------------- */
$g7_p = AffiliateRepository::create( array( 'name' => 'G7 Privat', 'email' => 'g7-privat@example.de', 'status' => 'active' ) );
$g7_values = array();
for ( $i = 0; $i < 520; $i++ ) {
	$g7_values[] = $wpdb->prepare( '(%s, %s, %d, %s, %s)', 'test', 'g7-at-' . $i, $g7_p, 'code', Db::now() );
}
q( 'INSERT INTO ' . t( 'attributions' ) . ' (source, source_order_id, affiliate_id, method, created_at) VALUES ' . implode( ',', $g7_values ) );
$wpdb->insert( t( 'payouts' ), array( 'number' => 'GS-G7-P', 'affiliate_id' => $g7_p, 'period_end' => gmdate( 'Y-m-d' ), 'currency' => 'EUR', 'status' => 'paid', 'created_at' => Db::now() ) );
$g7_pp = (int) $wpdb->insert_id;
update_option( \WPAffiliateCaptain\Payouts\Objections::OPTION, array_replace( \WPAffiliateCaptain\Payouts\Objections::all(), array( $g7_pp => array( 'affiliate_id' => $g7_p, 'at' => Db::now(), 'reason' => 'G7 Einwand Anschrift', 'status' => 'open' ) ) ), false );
update_option( 'wpac_vies_' . $g7_p, array( 'vat_id' => 'DE999999999', 'status' => 'valid', 'name' => 'G7 VIES GmbH', 'consultation' => 'WAPIAAAA', 'checked_at' => Db::now() ), false );
update_option( 'wpac_dunning_' . $g7_p . '_EUR', array( array( 'level' => 1, 'at' => Db::now(), 'amount' => -1234 ) ), false );
update_option( 'wpac_public_change_' . $g7_p, array( 'name' => 'G7 Neuer Name', 'website' => 'https://g7-neu.example', 'requested_at' => Db::now() ), false );
\WPAffiliateCaptain\Commissions\Balance::post( $g7_p, 'EUR', 'adjustment', 500, 0, 0, 'G7 Korrektur' );
$g7_pages = array();
$g7_all   = array();
for ( $page = 1; $page <= 5; $page++ ) {
	$g7_x       = Privacy::export( 'g7-privat@example.de', $page );
	$g7_pages[] = $g7_x['done'];
	$g7_all     = array_merge( $g7_all, $g7_x['data'] );
	if ( $g7_x['done'] ) {
		break;
	}
}
$g7_groups = array_count_values( wp_list_pluck( $g7_all, 'group_id' ) );
$g7_json   = wp_json_encode( $g7_all );
check( 'R5-W26 export is paged and complete (520 attributions over two pages, nothing cut off)', array( array( false, true ), 520 ), array( $g7_pages, isset( $g7_groups['wpac-attributions'] ) ? $g7_groups['wpac-attributions'] : 0 ) );
check( 'R7-AD9 export contains objections, VIES result, reminders, requested public change, balance and account entries', array( true, true, true, true, true, true ), array(
	false !== strpos( $g7_json, 'G7 Einwand Anschrift' ),
	false !== strpos( $g7_json, 'G7 VIES GmbH' ),
	isset( $g7_groups['wpac-dunning'] ),
	false !== strpos( $g7_json, 'G7 Neuer Name' ),
	isset( $g7_groups['wpac-balances'] ),
	isset( $g7_groups['wpac-balance-entries'] ) && false !== strpos( $g7_json, 'G7 Korrektur' ),
) );
// The test booking is not backed by commissions: remove it again (account consistency check).
q( 'DELETE FROM ' . t( 'balance_entries' ) . " WHERE affiliate_id = {$g7_p}" );
q( 'DELETE FROM ' . t( 'balances' ) . " WHERE affiliate_id = {$g7_p}" );
// Customer: two lifetime links (account and e-mail) and the attributed order.
$GLOBALS['test_users'][ 9703 ] = (object) array( 'ID' => 9703, 'user_email' => 'g7-shopper@example.de', 'user_login' => 'g7shopper' );
$g7_hashes = \WPAffiliateCaptain\Tracking\CustomerLinks::hashes( 9703, 'g7-shopper@example.de' );
$wpdb->insert( t( 'customer_links' ), array( 'affiliate_id' => $g7_p, 'customer_hash' => $g7_hashes[0], 'source' => 'woocommerce', 'source_order_id' => '97031', 'created_at' => Db::now() ) );
$wpdb->insert( t( 'customer_links' ), array( 'affiliate_id' => $g7_aff, 'customer_hash' => $g7_hashes[1], 'source' => 'woocommerce', 'source_order_id' => '97032', 'pending' => 1, 'created_at' => Db::now() ) );
$wpdb->insert( t( 'attributions' ), array( 'source' => 'woocommerce', 'source_order_id' => '97031', 'affiliate_id' => $g7_p, 'method' => 'code', 'campaign' => 'g7-camp', 'created_at' => Db::now() ) );
$g7_c       = Privacy::export( 'g7-shopper@example.de', 1 );
$g7_cgroups = array_count_values( wp_list_pluck( $g7_c['data'], 'group_id' ) );
check( 'R7-AD9 customers: all lifetime links (also pending) and the attributed orders', array( 2, 1, true ), array( isset( $g7_cgroups['wpac-customer-link'] ) ? $g7_cgroups['wpac-customer-link'] : 0, isset( $g7_cgroups['wpac-customer-attributions'] ) ? $g7_cgroups['wpac-customer-attributions'] : 0, $g7_c['done'] ) );

/* R5-W39: ended partnerships are deleted after the retention ------------------------------------ */
update_option( 'wpac_settings', array_merge( get_option( 'wpac_settings' ), array( 'statement_retention_years' => 8, 'operator_country' => 'DE', 'retention_hold' => 0, 'application_retention_days' => 30 ) ) );
Settings::flush_cache();
$g7_old  = AffiliateRepository::create( array( 'name' => 'G7 Alt Ohne', 'email' => 'g7-alt@example.de', 'status' => 'terminated' ) );
\WPAffiliateCaptain\Payouts\ProfileRepository::save( $g7_old, array( 'tax_status' => 'private', 'legal_name' => 'G7 Alt', 'address' => "Weg 1\n10115 Berlin", 'country' => 'DE', 'account_holder' => 'G7 Alt', 'iban' => 'DE89370400440532013000' ) );
$g7_book = AffiliateRepository::create( array( 'name' => 'G7 Alt Buchung', 'email' => 'g7-buchung@example.de', 'status' => 'terminated' ) );
$wpdb->insert( t( 'commissions' ), array( 'affiliate_id' => $g7_book, 'source' => 'test', 'source_order_id' => 'g7-old-1', 'kind' => 'sale', 'description' => 'x', 'currency' => 'EUR', 'amount_minor' => 100, 'status' => 'paid', 'idempotency_key' => 'g7-old-1', 'created_at' => Db::now(), 'updated_at' => Db::now() ) );
$g7_past = AffiliateRepository::create( array( 'name' => 'G7 Uralt', 'email' => 'g7-uralt@example.de', 'status' => 'terminated' ) );
$wpdb->insert( t( 'commissions' ), array( 'affiliate_id' => $g7_past, 'source' => 'test', 'source_order_id' => 'g7-old-2', 'kind' => 'sale', 'description' => 'x', 'currency' => 'EUR', 'amount_minor' => 100, 'status' => 'paid', 'idempotency_key' => 'g7-old-2', 'created_at' => '2005-01-01 00:00:00', 'updated_at' => '2005-01-01 00:00:00' ) );
q( 'UPDATE ' . t( 'affiliates' ) . " SET updated_at = '2005-06-01 00:00:00' WHERE id IN ({$g7_old}, {$g7_book}, {$g7_past})" );
AffiliateRepository::clear_cache();
$g7_n = Retention::purge_ended();
AffiliateRepository::clear_cache();
check( 'R5-W39 ended partners without bookings and with bookings past the retention are anonymised; recent bookings keep the data', array( true, false, 'G7 Alt Buchung', true, true ), array(
	$g7_n >= 2,
	(bool) \WPAffiliateCaptain\Payouts\ProfileRepository::get( $g7_old ),
	AffiliateRepository::find( $g7_book )->name,
	'privacy_erasure' === AffiliateRepository::find( $g7_past )->status_reason && false === strpos( AffiliateRepository::find( $g7_past )->email, 'uralt' ),
	! \WPAffiliateCaptain\Privacy\Restriction::is_restricted( $g7_past ),
) );
update_option( 'wpac_settings', array_merge( get_option( 'wpac_settings' ), array( 'retention_hold' => 1 ) ) );
Settings::flush_cache();
$g7_hold = AffiliateRepository::create( array( 'name' => 'G7 Halt', 'email' => 'g7-halt@example.de', 'status' => 'terminated' ) );
q( 'UPDATE ' . t( 'affiliates' ) . " SET updated_at = '2005-06-01 00:00:00' WHERE id = {$g7_hold}" );
check( 'R5-W39 nothing is deleted during a legal hold', 0, Retention::purge_ended() );

/* R5-W49: audit log by order, queue counts ----------------------------------------------------- */
Audit::log( 'refund.unknown_items', 'order', 0, array( 'source' => 'woocommerce', 'order_id' => '97777' ) );
$wpdb->insert( t( 'commissions' ), array( 'affiliate_id' => $g7_aff, 'source' => 'woocommerce', 'source_order_id' => '97777', 'kind' => 'sale', 'description' => 'x', 'currency' => 'EUR', 'amount_minor' => 100, 'status' => 'pending', 'idempotency_key' => 'g7-97777', 'created_at' => Db::now(), 'updated_at' => Db::now() ) );
Audit::log( 'commission.reopened', 'commission', (int) $wpdb->insert_id, array( 'reason' => 'g7' ) );
Audit::log( 'commission.reopened', 'commission', 999999, array( 'reason' => 'other order' ) );
$g7_audit = Audit::query( array( 'order' => '97777', 'limit' => 10 ) );
check( 'R5-W49 order events carry the order number as object ID; search by order finds order and commission events', array( 97777, array( 'commission.reopened', 'refund.unknown_items' ) ), array( (int) $wpdb->get_var( 'SELECT object_id FROM ' . t( 'audit' ) . " WHERE action = 'refund.unknown_items' ORDER BY id DESC LIMIT 1" ), array_values( array_unique( wp_list_pluck( $g7_audit, 'action' ) ) ) ) );
check( 'R5-W49 filter by action area and date', array( true, 0 ), array( count( Audit::query( array( 'action' => 'commission.', 'limit' => 500 ) ) ) >= 2, count( Audit::query( array( 'action' => 'commission.', 'to' => '2000-01-01' ) ) ) ) );
$g7_jobs = array();
for ( $i = 0; $i < 105; $i++ ) {
	$g7_jobs[] = $wpdb->prepare( '(%s, %s, %s, %s, %s)', 'sale', 'sale:woocommerce:g7j' . $i, 'failed', Db::now(), Db::now() );
}
q( 'INSERT INTO ' . t( 'jobs' ) . ' (type, idempotency_key, status, created_at, updated_at) VALUES ' . implode( ',', $g7_jobs ) );
check( 'R5-W49 failed jobs counted beyond the list limit; order taken from the job key', array( true, array( 'woocommerce', 'g7j3' ) ), array( \WPAffiliateCaptain\Core\Jobs::count( 'failed' ) >= 105, \WPAffiliateCaptain\Core\Jobs::order_of( (object) array( 'type' => 'sale', 'idempotency_key' => 'sale:woocommerce:g7j3' ) ) ) );
q( 'DELETE FROM ' . t( 'jobs' ) . " WHERE idempotency_key LIKE 'sale:woocommerce:g7j%'" );

update_option( 'wpac_settings', $g7_keep );
Settings::flush_cache();
