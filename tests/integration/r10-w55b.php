<?php
/**
 * R5-W55b: follow-ups of the correction credit notes – program export list and the retention
 * check of deleted multisite sites. Included from run.php directly after r10-w55.php (uses its
 * corrections $w55_kg, $w55_krc and payout $w55_p; helpers check(), t() come from run.php).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Migration\ProgramExport;
use WPAffiliateCaptain\Payouts\Corrections;
use WPAffiliateCaptain\Payouts\PayoutService;

echo "R10 W55b correction credit notes in the program export and the retention check\n";
global $wpdb;

/* Program export: payout_corrections.csv ------------------------------------------------------ */
// A correction whose texts look like spreadsheet formulas.
$wpdb->insert(
	t( 'payout_corrections' ),
	array(
		'payout_id'        => (int) $w55_p,
		'number'           => '=KG-INJECT',
		'affiliate_id'     => (int) $w55_kg->affiliate_id,
		'currency'         => 'EUR',
		'base_number'      => '+GS-1',
		'prev_net_minor'   => 12000,
		'prev_vat_minor'   => 2280,
		'net_minor'        => 11000,
		'vat_minor'        => 2090,
		'vat_mode'         => 'standard',
		'vat_rate'         => '19',
		'reason'           => '=HYPERLINK("http://evil.example","x")',
		'request_key'      => 'w55b-inject',
		'profile_snapshot' => '{"legal_name":"W55b Geheim GmbH"}',
		'file'             => 'w55b-secret-name.pdf',
		'created_at'       => gmdate( 'Y-m-d H:i:s' ),
	)
);
$w55b_inject = (int) $wpdb->insert_id;

$w55b_read = static function ( $tax ) {
	$zip = tempnam( sys_get_temp_dir(), 'wpacw55b' );
	$res = ProgramExport::write( $zip, $tax );
	$z   = new ZipArchive();
	$z->open( $zip );
	$csv    = (string) $z->getFromName( 'payout_corrections.csv' );
	$readme = (string) $z->getFromName( 'README.txt' );
	$z->close();
	@unlink( $zip );
	// Cells may span lines (addresses in the operator snapshot): parse as a stream.
	$fh = fopen( 'php://memory', 'w+' );
	fwrite( $fh, preg_replace( '/^\xEF\xBB\xBF/', '', $csv ) );
	rewind( $fh );
	$header = (array) fgetcsv( $fh, 0, ';', '"', '' );
	$rows   = array();
	while ( false !== ( $cells = fgetcsv( $fh, 0, ';', '"', '' ) ) ) {
		if ( count( $cells ) === count( $header ) ) {
			$rows[ (string) $cells[1] ] = array_combine( $header, $cells );
		}
	}
	fclose( $fh );
	return array( $res, $header, $rows, $csv, $readme );
};

list( $w55b_counts, $w55b_head, $w55b_rows, $w55b_csv, $w55b_readme ) = $w55b_read( false );
$w55b_total = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . t( 'payout_corrections' ) );
check( 'R5-W55b export lists the correction credit notes (one row each, documented columns)', array( true, $w55b_total, ProgramExport::CORRECTION_COLUMNS, false ), array( is_array( $w55b_counts ) && isset( $w55b_counts['payout_corrections.csv'] ), is_array( $w55b_counts ) ? $w55b_counts['payout_corrections.csv'] : -1, $w55b_head, in_array( 'request_key', $w55b_head, true ) ) );

$w55b_po = PayoutService::find( $w55_p );
$w55b_r  = isset( $w55b_rows[ $w55_kg->number ] ) ? $w55b_rows[ $w55_kg->number ] : array();
check(
	'R5-W55b row (PDF purged by the retention check of r10-w55): number, payout number, corrected credit note, partner, before/after/difference net, VAT, gross',
	array( $w55_kg->number, (string) $w55b_po->number, (string) $w55b_po->number, (string) $w55_kg->affiliate_id, 'EUR', '10000', '1900', '11900', '12000', '2280', '14280', '2000', '380', '2380', '19', 'Provisionssatz 12 % statt 10 %', 'deleted' ),
	array( $w55b_r['number'] ?? null, $w55b_r['payout_number'] ?? null, $w55b_r['base_number'] ?? null, $w55b_r['affiliate_id'] ?? null, $w55b_r['currency'] ?? null, $w55b_r['prev_net_minor'] ?? null, $w55b_r['prev_vat_minor'] ?? null, $w55b_r['prev_gross_minor'] ?? null, $w55b_r['net_minor'] ?? null, $w55b_r['vat_minor'] ?? null, $w55b_r['gross_minor'] ?? null, $w55b_r['diff_net_minor'] ?? null, $w55b_r['diff_vat_minor'] ?? null, $w55b_r['diff_gross_minor'] ?? null, $w55b_r['vat_rate'] ?? null, $w55b_r['reason'] ?? null, $w55b_r['file_status'] ?? null )
);
$w55b_d = isset( $w55b_rows[ $w55_krc->number ] ) ? $w55b_rows[ $w55_krc->number ] : array();
check( 'R5-W55b PDF deleted after the retention period is flagged', array( 'deleted', true, '' ), array( $w55b_d['file_status'] ?? null, '' !== ( $w55b_d['file_deleted_at'] ?? '' ), $w55b_d['file'] ?? null ) );

$w55b_i = isset( $w55b_rows["'=KG-INJECT"] ) ? $w55b_rows["'=KG-INJECT"] : array();
check(
	'R5-W55b CSV injection: formula-like cells prefixed, plain negative differences stay numeric',
	array( "'=KG-INJECT", "'+GS-1", "'=HYPERLINK(\"http://evil.example\",\"x\")", '-1000', '-190', '-1190', false ),
	array( $w55b_i['number'] ?? null, $w55b_i['base_number'] ?? null, $w55b_i['reason'] ?? null, $w55b_i['diff_net_minor'] ?? null, $w55b_i['diff_vat_minor'] ?? null, $w55b_i['diff_gross_minor'] ?? null, (bool) preg_match( '/(^|;)=HYPERLINK/m', $w55b_csv ) )
);
check(
	'R5-W55b without tax data: no tax snapshot, VAT treatment or stored file name',
	array( '', '', 'yes', 'stored', false, false, true ),
	array( $w55b_i['profile_snapshot'] ?? null, $w55b_i['vat_mode'] ?? null, $w55b_i['file'] ?? null, $w55b_i['file_status'] ?? null, false !== strpos( $w55b_csv, 'W55b Geheim' ), false !== strpos( $w55b_csv, 'w55b-secret-name' ), false !== strpos( $w55b_readme, 'correction credit notes' ) )
);

list( , , $w55b_rows_tax, , $w55b_readme_tax ) = $w55b_read( true );
$w55b_t = isset( $w55b_rows_tax["'=KG-INJECT"] ) ? $w55b_rows_tax["'=KG-INJECT"] : array();
check(
	'R5-W55b with tax data: tax snapshot, VAT treatment and file name included',
	array( '{"legal_name":"W55b Geheim GmbH"}', 'standard', 'w55b-secret-name.pdf', 'standard', true ),
	array( $w55b_t['profile_snapshot'] ?? null, $w55b_t['vat_mode'] ?? null, $w55b_t['file'] ?? null, $w55b_rows_tax[ $w55_kg->number ]['vat_mode'] ?? null, false !== strpos( $w55b_readme_tax, 'payout_corrections.csv' ) )
);
$wpdb->delete( t( 'payout_corrections' ), array( 'id' => $w55b_inject ) );

// Empty table: header row only.
$w55b_wt = new ReflectionMethod( ProgramExport::class, 'write_table' );
$w55b_wt->setAccessible( true );
$wpdb->query( 'CREATE TEMPORARY TABLE w55b_keep SELECT * FROM ' . t( 'payout_corrections' ) );
$wpdb->query( 'DELETE FROM ' . t( 'payout_corrections' ) );
$w55b_tmp = tempnam( sys_get_temp_dir(), 'wpacw55b' );
$w55b_n   = $w55b_wt->invoke( null, 'payout_corrections', $w55b_tmp, false );
$w55b_ec  = (string) file_get_contents( $w55b_tmp );
@unlink( $w55b_tmp );
$wpdb->query( 'INSERT INTO ' . t( 'payout_corrections' ) . ' SELECT * FROM w55b_keep' );
$wpdb->query( 'DROP TEMPORARY TABLE w55b_keep' );
check( 'R5-W55b empty corrections table: header row only', array( 0, implode( ';', ProgramExport::CORRECTION_COLUMNS ) ), array( $w55b_n, trim( preg_replace( '/^\xEF\xBB\xBF/', '', $w55b_ec ) ) ) );
check( 'R5-W55b corrections restored after the empty-table check', $w55b_total - 1, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . t( 'payout_corrections' ) ) );

/* Retention check of an already deleted multisite site ---------------------------------------- */
$w55b_pre = $wpdb->base_prefix . '955_';
$wpdb->query( "DROP TABLE IF EXISTS {$w55b_pre}wpac_payouts, {$w55b_pre}wpac_payout_corrections" );
$wpdb->query( "CREATE TABLE {$w55b_pre}wpac_payouts ( id int, created_at datetime NULL, storno_at datetime NULL )" );
$wpdb->query( "INSERT INTO {$w55b_pre}wpac_payouts VALUES ( 1, '2000-01-01 00:00:00', NULL )" );
if ( ! function_exists( 'esc_sql' ) ) {
	function esc_sql( $s ) { return $GLOBALS['wpdb']->db->real_escape_string( (string) $s ); }
}
$w55b_ret = new ReflectionMethod( WPAffiliateCaptain\Core\Installer::class, 'deleted_site_retained' );
$w55b_ret->setAccessible( true );
$w55b_entry = array( 'years' => 10, 'hold' => 0 );
$w55b_no_table = $w55b_ret->invoke( null, $w55b_pre, $w55b_entry );
$wpdb->query( "CREATE TABLE {$w55b_pre}wpac_payout_corrections ( id int, created_at datetime NOT NULL )" );
$wpdb->query( "INSERT INTO {$w55b_pre}wpac_payout_corrections VALUES ( 1, '" . gmdate( 'Y-m-d H:i:s' ) . "' )" );
$w55b_recent = $w55b_ret->invoke( null, $w55b_pre, $w55b_entry );
$w55b_recent_default = $w55b_ret->invoke( null, $w55b_pre, null );
$wpdb->query( "UPDATE {$w55b_pre}wpac_payout_corrections SET created_at = '2000-01-01 00:00:00'" );
$w55b_old = $w55b_ret->invoke( null, $w55b_pre, $w55b_entry );
$w55b_hold = $w55b_ret->invoke( null, $w55b_pre, array( 'years' => 10, 'hold' => 1 ) );
$wpdb->query( "DROP TABLE IF EXISTS {$w55b_pre}wpac_payouts, {$w55b_pre}wpac_payout_corrections" );
check(
	'R5-W55b deleted site: a recent correction credit note keeps the records although all payouts are old',
	array( false, true, true, false, true ),
	array( $w55b_no_table, $w55b_recent, $w55b_recent_default, $w55b_old, $w55b_hold )
);
