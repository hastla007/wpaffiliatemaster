<?php
/**
 * Integration checks of audit panel round 12, part E: ARIA table roles of the mobile portal
 * tables (N6), cached unfiltered list totals (N10), WPAC_CUSTOMER_KEY (N21) and the partner
 * erasure vs. the retention of booking-relevant audit entries and the tax data history (N22).
 * Included from tests/integration/run.php before r8-render.php.
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Commissions\Ledger;
use WPAffiliateCaptain\Core\Audit;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\Monitor;
use WPAffiliateCaptain\Core\PublicApi;
use WPAffiliateCaptain\Payouts\ProfileRepository;
use WPAffiliateCaptain\Payouts\TaxHistory;
use WPAffiliateCaptain\Privacy\Privacy;
use WPAffiliateCaptain\Privacy\Retention;
use WPAffiliateCaptain\Tracking\CustomerLinks;

echo "R12 E: portal table roles, cached list totals, customer key, erasure vs. retention\n";

global $wpdb;
$r12e_root = dirname( __DIR__, 2 );
$r12e_src  = static function ( $f ) use ( $r12e_root ) {
	return (string) file_get_contents( $r12e_root . '/' . $f );
};
$r12e_settings = get_option( 'wpac_settings' );

/* R12-N6: tables with the mobile card layout keep their semantics through ARIA roles. -------- */
$r12e_portal = $r12e_src( 'src/Portal/Portal.php' );
preg_match_all( '/<(table|thead|tbody|tr|th|td)\b[^>]*>/', $r12e_portal, $r12e_tags );
$r12e_missing = array();
foreach ( $r12e_tags[0] as $r12e_tag ) {
	if ( false === strpos( $r12e_tag, 'role="' ) ) {
		$r12e_missing[] = $r12e_tag;
	}
}
check( 'R12-N6 every portal table element carries an ARIA role (table/rowgroup/row/columnheader/rowheader/cell)', array(), $r12e_missing );
check( 'R12-N6 all five portal tables with role="table"', 5, substr_count( $r12e_portal, '<table class="wpac-table" role="table">' ) );
check( 'R12-N6 column headers and cells with their roles', array( true, true, true ), array( false !== strpos( $r12e_portal, '<th scope="col" role="columnheader">' ), false !== strpos( $r12e_portal, '<th scope="row" role="rowheader"' ), false !== strpos( $r12e_portal, '<td role="cell" data-label=' ) ) );
check( 'R12-N6 mobile CSS still stacks the rows (visual card layout kept)', true, false !== strpos( $r12e_src( 'assets/css/portal.css' ), '.wpac-table tr { display: block;' ) );
check( 'R12-N6 terms links in the portal announce the new tab', 3, substr_count( $r12e_portal, "esc_html__( '(opens in a new tab)', 'affiliate-captain' )" ) );

/* R12-N10: unfiltered commission totals cached by MAX(id), filtered totals exact. ----------- */
$r12e_aff = AffiliateRepository::create( array( 'name' => 'R12E Partner', 'email' => 'r12e-partner@example.de', 'status' => 'active' ) );
$r12e_add = static function ( $aff, $key, $created = null ) use ( $wpdb ) {
	$when = null === $created ? Db::now() : $created;
	$wpdb->insert( t( 'commissions' ), array( 'affiliate_id' => $aff, 'source' => 'test', 'source_order_id' => $key, 'kind' => 'sale', 'description' => 'x', 'currency' => 'EUR', 'amount_minor' => 100, 'status' => 'pending', 'idempotency_key' => $key, 'created_at' => $when, 'updated_at' => $when ) );
	return (int) $wpdb->insert_id;
};
$r12e_c1 = $r12e_add( $r12e_aff, 'r12e-1' );
$r12e_c2 = $r12e_add( $r12e_aff, 'r12e-2' );
delete_option( '_t_wpac_list_totals' );
$r12e_real = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . t( 'commissions' ) );
$r12e_t1   = Ledger::query( array( 'limit' => 1, 'count' => 'cached' ) )[1];
check( 'R12-N10 unfiltered cached total is exact and stored in one transient', array( $r12e_real, true ), array( $r12e_t1, is_array( get_transient( 'wpac_list_totals' ) ) && 1 === count( get_transient( 'wpac_list_totals' ) ) ) );
$r12e_c3 = $r12e_add( $r12e_aff, 'r12e-3' );
check( 'R12-N10 a new commission counts at once (MAX(id) in the key)', $r12e_t1 + 1, Ledger::query( array( 'limit' => 1, 'count' => 'cached' ) )[1] );
// Deleting an older row does not change MAX(id): the unfiltered total stays cached briefly …
q( 'DELETE FROM ' . t( 'commissions' ) . ' WHERE id = ' . $r12e_c1 );
check( 'R12-N10 unfiltered total served from the cache (deletion shows up late)', $r12e_t1 + 1, Ledger::query( array( 'limit' => 1, 'count' => 'cached' ) )[1] );
// … while filtered totals stay exact.
check( 'R12-N10 filtered total stays exact (no cache)', 2, Ledger::query( array( 'affiliate_id' => $r12e_aff, 'limit' => 1, 'count' => 'cached' ) )[1] );
check( 'R12-N10 without count=cached the total is exact', $r12e_t1, Ledger::query( array( 'limit' => 1 ) )[1] );
$r12e_api = PublicApi::get_commissions( array( 'per_page' => 1 ) );
check( 'R12-N10 PublicApi::get_commissions() unfiltered: cached total', $r12e_t1 + 1, is_array( $r12e_api ) ? $r12e_api['total'] : $r12e_api );
$r12e_api = PublicApi::get_commissions( array( 'per_page' => 1, 'affiliate_id' => $r12e_aff ) );
check( 'R12-N10 PublicApi::get_commissions() filtered: exact total', 2, is_array( $r12e_api ) ? $r12e_api['total'] : $r12e_api );
check( 'R12-N10 commissions list opts in; the audit list reuses the same helper', array( true, true ), array( false !== strpos( $r12e_src( 'src/Admin/Tables/CommissionsTable.php' ), "'count'   => 'cached'" ), false !== strpos( $r12e_src( 'src/Core/Audit.php' ), "Paged::cached_total( Db::table( 'audit' )" ) ) );
q( 'DELETE FROM ' . t( 'commissions' ) . ' WHERE id IN (' . $r12e_c2 . ',' . $r12e_c3 . ')' );

/* R12-N21: WPAC_CUSTOMER_KEY only while no customer links exist (or they were made with it). - */
$r12e_links = t( 'customer_links' );
q( 'DROP TEMPORARY TABLE IF EXISTS r12e_links_backup' );
q( "CREATE TEMPORARY TABLE r12e_links_backup AS SELECT * FROM {$r12e_links}" );
$r12e_old_key = get_option( 'wpac_customer_key' );
$r12e_old_fp  = get_option( CustomerLinks::CONSTANT_FP );
$r12e_stored  = str_repeat( 'a1', 32 );
update_option( 'wpac_customer_key', $r12e_stored );
delete_option( CustomerLinks::CONSTANT_FP );
q( "DELETE FROM {$r12e_links}" );
CustomerLinks::reset_key_cache();
CustomerLinks::link( $r12e_aff, CustomerLinks::hashes( 0, 'r12e-kunde@example.de' ), 'test', 'r12e-link-1' );
if ( ! defined( 'WPAC_CUSTOMER_KEY' ) ) {
	define( 'WPAC_CUSTOMER_KEY', 'r12e-constant-key-0123456789abcdefghijklmnop' );
}
CustomerLinks::reset_key_cache();
check( 'R12-N21 links exist: constant ignored, stored key stays', array( 'source' => 'option', 'ignored' => true, 'missing' => false ), CustomerLinks::key_status() );
check( 'R12-N21 hashes still made with the stored key (existing links found)', array( hash_hmac( 'sha256', 'e:r12e-kunde@example.de', $r12e_stored ), true ), array( CustomerLinks::hashes( 0, 'r12e-kunde@example.de' )[0], null !== CustomerLinks::find( CustomerLinks::hashes( 0, 'r12e-kunde@example.de' ) ) ) );
$r12e_checks = Monitor::checks();
check( 'R12-N21 Site Health reports the ignored constant', array( 'recommended', 'WPAC_CUSTOMER_KEY is ignored' ), isset( $r12e_checks['customer_key'] ) ? array( $r12e_checks['customer_key'][0], $r12e_checks['customer_key'][1] ) : null );
q( "DELETE FROM {$r12e_links}" );
CustomerLinks::reset_key_cache();
check( 'R12-N21 no links: the constant takes over, the generated key leaves the database', array( array( 'source' => 'constant', 'ignored' => false, 'missing' => false ), false, hash( 'sha256', WPAC_CUSTOMER_KEY ) ), array( CustomerLinks::key_status(), get_option( 'wpac_customer_key' ), get_option( CustomerLinks::CONSTANT_FP ) ) );
check( 'R12-N21 hashes made with the constant', hash_hmac( 'sha256', 'e:r12e-kunde@example.de', WPAC_CUSTOMER_KEY ), CustomerLinks::hashes( 0, 'r12e-kunde@example.de' )[0] );
CustomerLinks::link( $r12e_aff, CustomerLinks::hashes( 0, 'r12e-kunde@example.de' ), 'test', 'r12e-link-2' );
CustomerLinks::reset_key_cache();
$r12e_checks = Monitor::checks();
check( 'R12-N21 links made with the constant: it stays in use, no warning, no stored key', array( 'constant', false, true ), array( CustomerLinks::key_status()['source'], isset( $r12e_checks['customer_key'] ), false === get_option( 'wpac_customer_key' ) ) );
update_option( CustomerLinks::CONSTANT_FP, hash( 'sha256', 'an earlier constant' ) );
CustomerLinks::reset_key_cache();
$r12e_checks = Monitor::checks();
check( 'R12-N21 links made with an earlier constant: warning "removed or changed"', array( true, 'WPAC_CUSTOMER_KEY was removed or changed' ), array( CustomerLinks::key_status()['missing'], isset( $r12e_checks['customer_key'] ) ? $r12e_checks['customer_key'][1] : null ) );
check( 'R12-N21 documented in README and HANDBOOK; fingerprint removed on uninstall', array( true, true, true ), array( false !== strpos( $r12e_src( 'README.md' ), "define( 'WPAC_CUSTOMER_KEY'" ), false !== strpos( $r12e_src( 'docs/HANDBOOK.md' ), "define( 'WPAC_CUSTOMER_KEY'" ), false !== strpos( $r12e_src( 'uninstall.php' ), "'wpac_customer_key_constant'" ) ) );
// Restore: the earlier links with the earlier stored key; the constant is ignored from now on.
q( "DELETE FROM {$r12e_links}" );
q( "INSERT INTO {$r12e_links} SELECT * FROM r12e_links_backup" );
q( 'DROP TEMPORARY TABLE r12e_links_backup' );
delete_option( CustomerLinks::CONSTANT_FP );
if ( false !== $r12e_old_fp ) {
	update_option( CustomerLinks::CONSTANT_FP, $r12e_old_fp );
}
if ( false !== $r12e_old_key ) {
	update_option( 'wpac_customer_key', $r12e_old_key );
} else {
	update_option( 'wpac_customer_key', $r12e_stored );
}
CustomerLinks::reset_key_cache();

/* R12-N22: erasure keeps booking-relevant audit entries and the tax history in the retention. */
update_option( 'wpac_settings', array_merge( get_option( 'wpac_settings' ), array( 'statement_retention_years' => 10, 'retention_hold' => 0 ) ) );
$r12e_era = AffiliateRepository::create( array( 'name' => 'R12E Erased', 'email' => 'r12e-erased@example.de', 'status' => 'active' ) );
$r12e_ec  = $r12e_add( $r12e_era, 'r12e-era-1' );
TaxHistory::record_profile( $r12e_era, array( 'tax_status' => 'business', 'country' => 'DE', 'vat_id' => 'DE123456789', 'tax_number' => '' ) );
TaxHistory::set_pending( $r12e_era, array( 'tax_status' => 'private' ), array( 'tax_status' => 'business' ) );
Audit::log( 'payout_profile.tax_changed', 'affiliate', $r12e_era, array( 'old' => array( 'vat_id' => 'DE111' ), 'new' => array( 'vat_id' => 'DE222' ) ) );
Audit::log( 'payout_request.rejected', 'affiliate', $r12e_era, array( 'reason' => 'Bitte erst Rechnung' ) );
Audit::log( 'payout_profile.updated', 'affiliate', $r12e_era, array( 'tax_status' => 'business' ) );
$r12e_old_entry = (int) $wpdb->insert_id;
q( 'UPDATE ' . t( 'audit' ) . " SET created_at = '2001-06-01 00:00:00' WHERE id = " . $r12e_old_entry ); // Past the retention.
Audit::log( 'affiliate.note', 'affiliate', $r12e_era, array( 'text' => 'personal remark' ) );
Audit::log( 'agreement.accepted', 'affiliate', $r12e_era, array( 'version' => '1' ) );
$r12e_details = static function ( $action ) use ( $wpdb, $r12e_era ) {
	return $wpdb->get_var( $wpdb->prepare( 'SELECT details FROM ' . t( 'audit' ) . ' WHERE object_type = %s AND object_id = %d AND action = %s ORDER BY id DESC LIMIT 1', 'affiliate', $r12e_era, $action ) );
};
$r12e_msgs = Privacy::erase_partner( AffiliateRepository::find( $r12e_era ) );
check(
	'R12-N22 erasure empties free-text details but keeps booking-relevant entries within the retention unchanged',
	array( null, true, true, null, true ),
	array(
		$r12e_details( 'affiliate.note' ),
		false !== strpos( (string) $r12e_details( 'payout_profile.tax_changed' ), 'DE222' ),
		false !== strpos( (string) $r12e_details( 'payout_request.rejected' ), 'Bitte erst Rechnung' ),
		$wpdb->get_var( 'SELECT details FROM ' . t( 'audit' ) . ' WHERE id = ' . $r12e_old_entry ),
		null !== $r12e_details( 'agreement.accepted' ),
	)
);
$r12e_joined = implode( ' | ', $r12e_msgs );
check( 'R12-N22 eraser response reports the retained entries and the tax history with the reason', array( true, true ), array( false !== strpos( $r12e_joined, '2 change log entries about payout details or payout requests' ), false !== strpos( $r12e_joined, 'history of the partner\'s tax data' ) ) );
ProfileRepository::delete( $r12e_era );
check( 'R12-N22 ProfileRepository::delete keeps the tax history within the retention, drops the pending request', array( 1, null ), array( count( TaxHistory::profile( $r12e_era ) ), TaxHistory::pending( $r12e_era ) ) );
$r12e_none = AffiliateRepository::create( array( 'name' => 'R12E No bookings', 'email' => 'r12e-none@example.de', 'status' => 'active' ) );
TaxHistory::record_profile( $r12e_none, array( 'tax_status' => 'business', 'country' => 'DE', 'vat_id' => 'DE987654321', 'tax_number' => '' ) );
ProfileRepository::delete( $r12e_none );
check( 'R12-N22 without bookings the tax history is deleted at once', array( array(), false ), array( TaxHistory::profile( $r12e_none ), TaxHistory::retained( $r12e_none ) ) );
update_option( 'wpac_settings', array_merge( get_option( 'wpac_settings' ), array( 'statement_retention_years' => 0 ) ) );
check( 'R12-N22 "keep forever": the tax history of a partner with bookings is always retained', true, TaxHistory::retained( $r12e_era ) );
update_option( 'wpac_settings', array_merge( get_option( 'wpac_settings' ), array( 'statement_retention_years' => 10 ) ) );
// The partner's records expire: the remaining details and the history go.
q( 'UPDATE ' . t( 'commissions' ) . " SET created_at = '2001-01-01 00:00:00', updated_at = '2001-01-01 00:00:00' WHERE id = " . $r12e_ec );
check( 'R12-N22 records past the retention: history no longer retained', array( true, false ), array( Retention::partner_expired( $r12e_era ), TaxHistory::retained( $r12e_era ) ) );
Retention::scrub_ledger_texts( $r12e_era );
check( 'R12-N22 after the retention the booking-relevant details and the tax history are deleted', array( null, null, array() ), array( $r12e_details( 'payout_profile.tax_changed' ), $r12e_details( 'payout_request.rejected' ), TaxHistory::profile( $r12e_era ) ) );
check( 'R12-N22 purge_audit and the erasure share one list of booking-relevant actions', array( true, 7 ), array( false !== strpos( $r12e_src( 'src/Core/Cron.php' ), 'Audit::financial_sql()' ), substr_count( Audit::financial_sql(), "%%'" ) ) );
$r12e_fin = $wpdb->get_col( $wpdb->prepare( 'SELECT action FROM ' . t( 'audit' ) . ' WHERE object_type = %s AND object_id = %d AND ' . Audit::financial_sql() . ' ORDER BY action', 'affiliate', $r12e_era ) );
check( 'R12-N22 financial_sql() matches payout_profile.* and payout_request.* only (of these entries)', array( 'payout_profile.tax_changed', 'payout_profile.updated', 'payout_request.rejected' ), array_values( array_unique( $r12e_fin ) ) );
q( 'DELETE FROM ' . t( 'commissions' ) . ' WHERE id = ' . $r12e_ec );
update_option( 'wpac_erasure_pending', array_values( array_diff( array_map( 'intval', (array) get_option( 'wpac_erasure_pending', array() ) ), array( $r12e_era ) ) ), false );
update_option( 'wpac_settings', $r12e_settings );
