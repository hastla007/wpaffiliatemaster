<?php
/**
 * Integration checks of work package K3 (R6-CC5): storage abstraction for credit note and
 * cancellation PDFs – local folder (unchanged), S3-compatible object storage (SigV4 without SDK),
 * everything through the store. Included from tests/integration/run.php; the checks that need
 * WPAC_S3_* constants or run uninstall.php start r15-k3-isolated.php in own processes.
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Commissions\Ledger;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Payouts\PayoutService;
use WPAffiliateCaptain\Payouts\ProfileRepository;
use WPAffiliateCaptain\Payouts\Statement;
use WPAffiliateCaptain\Payouts\Storage\LocalStore;
use WPAffiliateCaptain\Payouts\Storage\S3Store;

echo "R15-K3 statement storage (local / S3)\n";

global $wpdb;
require_once __DIR__ . '/r15-k3-fake-s3.php';

$k3_n       = 0;
$k3_partner = static function () use ( &$k3_n ) {
	++$k3_n;
	$id = AffiliateRepository::create( array( 'name' => 'K3 Partner ' . $k3_n, 'email' => 'k3-' . $k3_n . '@example.de', 'status' => 'active' ) );
	ProfileRepository::save( $id, array( 'tax_status' => 'private', 'legal_name' => 'K3 Partner ' . $k3_n, 'address' => "Weg {$k3_n}\n24103 Kiel", 'country' => 'DE', 'account_holder' => 'K3 Partner', 'iban' => 'DE89370400440532013000' ) );
	return $id;
};
$k3_payout = static function () use ( $k3_partner, &$k3_n ) {
	$aff = $k3_partner();
	$s   = sale( 'k3-' . $k3_n, WPAffiliateCaptain\Codes\CodeRepository::main_code( $aff ), array( 100000 ) );
	Ledger::transition( (int) $s['commission_ids'][0], 'approved' );
	return (int) PayoutService::create_for_affiliate( $aff, gmdate( 'Y-m-d' ), 'EUR' );
};
$k3_cfg = array(
	'endpoint'   => 'https://objects.example.test',
	'region'     => 'eu-central-1',
	'bucket'     => 'k3-bucket',
	'key'        => 'AKIDK3TESTKEY',
	'secret'     => 'k3-very-secret-value',
	'prefix'     => '/wpac/',
	'path_style' => true,
);
$k3_s3     = new S3Store( $k3_cfg );
$k3_secret = static function () {
	foreach ( $GLOBALS['k3_s3_log'] as $req ) {
		if ( false !== strpos( wp_json_encode( $req ), 'k3-very-secret-value' ) ) {
			return true;
		}
	}
	return false;
};
$k3_events = array();
add_action(
	'wpac_statement_generated',
	static function ( $id, $path ) use ( &$k3_events ) {
		$k3_events[ (int) $id ] = array( (string) $path, is_readable( (string) $path ) ? hash_file( 'sha256', (string) $path ) : '' );
	},
	5,
	2
);

/* LocalStore: same folder, protection files and file names as before. ------------------------ */
// Earlier files may have left a wpac_statement_dir filter: the default folder is checked without it.
$k3_dir_filters = $GLOBALS['test_hooks']['wpac_statement_dir'] ?? null;
unset( $GLOBALS['test_hooks']['wpac_statement_dir'] );
// Folder of earlier versions: WPAC_STATEMENT_DIR (defined by run.php's ZD checks) or uploads.
$k3_uploads = defined( 'WPAC_STATEMENT_DIR' ) ? rtrim( (string) WPAC_STATEMENT_DIR, '/' ) : sys_get_temp_dir() . '/wpac-uploads/wpac-statements';
$k3_p1      = PayoutService::find( $k3_payout() );
check(
	'R6-CC5 LocalStore is the default and keeps the folder of earlier versions',
	array( 'local', $k3_uploads, $k3_uploads, true ),
	array( Statement::store()->id(), Statement::dir(), Statement::store()->label(), Statement::local_store() )
);
check(
	'R6-CC5 LocalStore: deny rules and index.php unchanged, PDF in the folder under its stored name',
	array( "Require all denied\nDeny from all\n", "<?php\n// Silence.\n", true, $k3_p1->statement_hash ),
	array( (string) file_get_contents( $k3_uploads . '/.htaccess' ), (string) file_get_contents( $k3_uploads . '/index.php' ), (bool) preg_match( '/^' . preg_quote( $k3_p1->number, '/' ) . '-[A-Za-z0-9]{16}\.pdf$/', $k3_p1->statement_file ), hash_file( 'sha256', $k3_uploads . '/' . $k3_p1->statement_file ) )
);
check( 'R6-CC5 LocalStore: listeners get the stored file itself (as before)', array( $k3_uploads . '/' . $k3_p1->statement_file, $k3_p1->statement_hash ), $k3_events[ (int) $k3_p1->id ] ?? null );
$k3_local = new LocalStore();
check(
	'R6-CC5 LocalStore get/exists/path/list match the file system',
	array( $k3_p1->statement_hash, true, $k3_uploads . '/' . $k3_p1->statement_file, true, null, false ),
	array( hash( 'sha256', (string) $k3_local->get( $k3_p1->statement_file ) ), $k3_local->exists( $k3_p1->statement_file ), Statement::path( $k3_p1->statement_file ), in_array( $k3_p1->statement_file, $k3_local->list_older_than( time() + 10 ), true ), $k3_local->get( '../wp-config.php' ), $k3_local->exists( '.htaccess' ) )
);
$k3_t = Statement::test_store();
check( 'R6-CC5 connection test of the local folder writes, reads and deletes a probe', array( true, true, true, true, array() ), array( $k3_t['ok'], $k3_t['write'], $k3_t['read'], $k3_t['delete'], glob( $k3_uploads . '/wpac-probe-*' ) ) );

/* SigV4: the published AWS examples (S3 API reference, "Signature calculation: header-based auth"). */
$k3_e   = hash( 'sha256', '' );
$k3_sig = S3Store::sign( 'GET', '/test.txt', array(), array( 'host' => 'examplebucket.s3.amazonaws.com', 'range' => 'bytes=0-9', 'x-amz-content-sha256' => $k3_e, 'x-amz-date' => '20130524T000000Z' ), $k3_e, '20130524T000000Z', 'us-east-1', 'AKIAIOSFODNN7EXAMPLE', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY' );
check(
	'R6-CC5 SigV4 AWS test vector GET object (canonical request hash, signature, header)',
	array( '7344ae5b7ee6c3e7e6b0fe0640412a37625d1fbfff95c48bbb2dc43964946972', 'f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41', 'AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request, SignedHeaders=host;range;x-amz-content-sha256;x-amz-date, Signature=f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41' ),
	array( hash( 'sha256', $k3_sig['canonical_request'] ), $k3_sig['signature'], $k3_sig['authorization'] )
);
$k3_h   = hash( 'sha256', 'Welcome to Amazon S3.' );
$k3_sig = S3Store::sign( 'PUT', S3Store::encode_path( '/test$file.text' ), array(), array( 'host' => 'examplebucket.s3.amazonaws.com', 'date' => 'Fri, 24 May 2013 00:00:00 GMT', 'x-amz-content-sha256' => $k3_h, 'x-amz-date' => '20130524T000000Z', 'x-amz-storage-class' => 'REDUCED_REDUNDANCY' ), $k3_h, '20130524T000000Z', 'us-east-1', 'AKIAIOSFODNN7EXAMPLE', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY' );
check( 'R6-CC5 SigV4 AWS test vector PUT object (encoded key)', '98ad721746da40c64f1a55b78f14c238d841ea1380cd77a1b5971af0ece108bd', $k3_sig['signature'] );
$k3_sig = S3Store::sign( 'GET', '/', array( 'prefix' => 'J', 'max-keys' => '2' ), array( 'host' => 'examplebucket.s3.amazonaws.com', 'x-amz-content-sha256' => $k3_e, 'x-amz-date' => '20130524T000000Z' ), $k3_e, '20130524T000000Z', 'us-east-1', 'AKIAIOSFODNN7EXAMPLE', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY' );
check( 'R6-CC5 SigV4 AWS test vector list objects (sorted query)', '34b48302e7b5fa45bde8084f4b7868a86f0a534bc59db6670ed5711ef69dc6f7', $k3_sig['signature'] );

/* S3Store against the mocked HTTP layer (pre_http_request). -------------------------------- */
$GLOBALS['k3_s3_log'] = array();
$k3_put               = $k3_s3->put( 'GS-K3-1.pdf', '%PDF-k3' );
$k3_req               = $GLOBALS['k3_s3_log'][0];
check(
	'R6-CC5 S3 put: path-style URL with prefix, signed, PDF content type, no public ACL',
	array( true, 'PUT', 'https://objects.example.test/k3-bucket/wpac/GS-K3-1.pdf', 'application/pdf', true, false, false ),
	array( $k3_put, $k3_req[0], $k3_req[1], $k3_req[2]['content-type'] ?? '', 0 === strpos( $k3_req[2]['authorization'], 'AWS4-HMAC-SHA256 Credential=AKIDK3TESTKEY/' . gmdate( 'Ymd' ) . '/eu-central-1/s3/aws4_request, SignedHeaders=content-type;host;x-amz-content-sha256;x-amz-date, Signature=' ), isset( $k3_req[2]['x-amz-acl'] ), isset( $k3_req[2]['x-amz-server-side-encryption'] ) )
);
check(
	'R6-CC5 S3 get/exists/delete through the mock',
	array( '%PDF-k3', true, null, false, true, false, true, '' ),
	array( $k3_s3->get( 'GS-K3-1.pdf' ), $k3_s3->exists( 'GS-K3-1.pdf' ), $k3_s3->get( 'GS-missing.pdf' ), $k3_s3->exists( 'GS-missing.pdf' ), $k3_s3->delete( 'GS-K3-1.pdf' ), $k3_s3->exists( 'GS-K3-1.pdf' ), $k3_s3->delete( 'GS-K3-1.pdf' ), $k3_s3->last_error() )
);
$k3_vh = new S3Store( array( 'region' => 'eu-central-1', 'bucket' => 'k3-vh', 'key' => 'AKIDK3', 'secret' => 'k3-very-secret-value', 'sse' => 'AES256' ) );
$GLOBALS['k3_s3_log'] = array();
$k3_vh->put( 'GS K3+2.pdf', 'x' );
check(
	'R6-CC5 S3 virtual-hosted URL on AWS by region, key encoded, server-side encryption header',
	array( 'https://k3-vh.s3.eu-central-1.amazonaws.com/GS%20K3%2B2.pdf', 'AES256', 'x' ),
	array( $GLOBALS['k3_s3_log'][0][1], $GLOBALS['k3_s3_log'][0][2]['x-amz-server-side-encryption'] ?? '', $k3_vh->get( 'GS K3+2.pdf' ) )
);
$GLOBALS['k3_s3_objects'] = array();
foreach ( array( 'GS-a.pdf', 'GS-b.pdf', 'GS-c.pdf', 'GS-new.pdf' ) as $k3_i => $k3_name ) {
	$k3_s3->put( $k3_name, 'x' );
}
$GLOBALS['k3_s3_objects']['https://objects.example.test/k3-bucket/wpac/GS-new.pdf'][1] = time() + 3600;
$GLOBALS['k3_s3_objects']['https://objects.example.test/k3-bucket/other/GS-foreign.pdf'] = array( 'x', 0 );
$GLOBALS['k3_s3_objects']['https://objects.example.test/k3-bucket/wpac/site-2/GS-sub.pdf'] = array( 'x', 0 );
$GLOBALS['k3_s3_page'] = 2;
$k3_list               = $k3_s3->list_older_than( time() + 60 );
$GLOBALS['k3_s3_page'] = 1000;
sort( $k3_list );
check( 'R6-CC5 S3 list: pages followed, only own prefix, only older objects', array( 'GS-a.pdf', 'GS-b.pdf', 'GS-c.pdf' ), $k3_list );
$GLOBALS['k3_s3_fail'] = 500;
check( 'R6-CC5 S3 failure: put false, reason with status and code, never the secret', array( false, 'S3 PUT: HTTP 500 InternalError', false ), array( $k3_s3->put( 'GS-x.pdf', 'x' ), $k3_s3->last_error(), false !== strpos( $k3_s3->last_error(), 'secret' ) ) );
$GLOBALS['k3_s3_fail'] = 0;
check( 'R6-CC5 S3 requests never carry the secret (URL or headers)', false, $k3_secret() );
$GLOBALS['k3_s3_objects'] = array();

/* Everything through the store: generation, mail attachment, download, rebuild, retention. ---- */
$k3_filter = static function () use ( $k3_s3 ) {
	return $k3_s3;
};
add_filter( 'wpac_statement_store', $k3_filter );
check( 'R6-CC5 filter wpac_statement_store supplies the store; no folder check needed', array( 's3', false, null ), array( Statement::store()->id(), Statement::local_store(), Statement::publicly_accessible() ) );
$k3_p2   = PayoutService::find( $k3_payout() );
$k3_obj  = 'https://objects.example.test/k3-bucket/wpac/' . $k3_p2->statement_file;
$k3_temp = $k3_events[ (int) $k3_p2->id ] ?? array( '', '' );
check(
	'R6-CC5 S3: credit note stored as object only, hash kept, nothing in the local folder',
	array( true, $k3_p2->statement_hash, false ),
	array( '' !== (string) $k3_p2->statement_file, isset( $GLOBALS['k3_s3_objects'][ $k3_obj ] ) ? hash( 'sha256', $GLOBALS['k3_s3_objects'][ $k3_obj ][0] ) : '', is_file( $k3_uploads . '/' . $k3_p2->statement_file ) )
);
check(
	'R6-CC5 S3: mail attachment is a private temporary copy (same bytes, removed at the end of the request)',
	array( $k3_p2->statement_hash, false, true ),
	array( $k3_temp[1], $k3_temp[0] === $k3_uploads . '/' . $k3_p2->statement_file, in_array( $k3_temp[0], WPAffiliateCaptain\Core\PrivateFile::tracked(), true ) )
);
check( 'R6-CC5 S3: download reads the object', $k3_p2->statement_hash, hash( 'sha256', (string) Statement::stored_bytes( $k3_p2 ) ) );
unset( $GLOBALS['k3_s3_objects'][ $k3_obj ] );
$k3_bytes = Statement::stored_bytes( PayoutService::find( $k3_p2->id ) );
check( 'R6-CC5 S3: missing object rebuilt byte for byte and stored again', array( $k3_p2->statement_hash, true ), array( hash( 'sha256', (string) $k3_bytes ), isset( $GLOBALS['k3_s3_objects'][ $k3_obj ] ) ) );
$GLOBALS['k3_s3_objects'][ $k3_obj ][0] = 'tampered';
check( 'R6-CC5 S3: a changed object fails the integrity check', false, Statement::stored_bytes( PayoutService::find( $k3_p2->id ) ) );
$GLOBALS['k3_s3_objects'][ $k3_obj ][0] = (string) $k3_bytes;
// Not migrated yet: a document still in the local folder of this server is found as well.
check( 'R6-CC5 S3: document not migrated yet is still read from the local folder', $k3_p1->statement_hash, hash( 'sha256', (string) Statement::stored_bytes( PayoutService::find( $k3_p1->id ) ) ) );

// Store failure: generation waits with the job backoff, logs, Site Health warns; number kept.
$GLOBALS['k3_s3_fail'] = 503;
$k3_aff  = $k3_partner();
$k3_s    = sale( 'k3-fail', WPAffiliateCaptain\Codes\CodeRepository::main_code( $k3_aff ), array( 100000 ) );
Ledger::transition( (int) $k3_s['commission_ids'][0], 'approved' );
$k3_p3   = PayoutService::find( PayoutService::create_for_affiliate( $k3_aff, gmdate( 'Y-m-d' ), 'EUR' ) );
$k3_mon  = WPAffiliateCaptain\Core\Monitor::checks();
check(
	'R6-CC5 store failure: no file, number kept, retry with backoff, Site Health critical with the reason',
	array( '', true, true, 'critical', true, 'critical', 's3', 'S3 PUT: HTTP 503 InternalError' ),
	array( (string) $k3_p3->statement_file, '' !== (string) $k3_p3->number, isset( Statement::waiting()[ (int) $k3_p3->id ] ), $k3_mon['statement_store'][0] ?? '', false !== strpos( $k3_mon['statement_store'][2] ?? '', 'HTTP 503' ), $k3_mon['statements'][0] ?? '', Statement::store_error()['store'] ?? '', Statement::store_error()['error'] ?? '' )
);
$k3_number = (string) $k3_p3->number;
$GLOBALS['k3_s3_fail'] = 0;
$k3_retry = get_option( Statement::RETRY_OPTION );
$k3_retry[ (int) $k3_p3->id ]['next'] = 0;
update_option( Statement::RETRY_OPTION, $k3_retry );
Statement::generate( (int) $k3_p3->id ); // What the hourly retry (generate_missing_statements()) calls once the pause is over.
$k3_p3 = PayoutService::find( $k3_p3->id );
check(
	'R6-CC5 store back: the retry writes the credit note with the same number, warning cleared',
	array( true, $k3_number, false, null, false ),
	array( '' !== (string) $k3_p3->statement_file && isset( $GLOBALS['k3_s3_objects'][ 'https://objects.example.test/k3-bucket/wpac/' . $k3_p3->statement_file ] ), (string) $k3_p3->number, isset( Statement::waiting()[ (int) $k3_p3->id ] ), Statement::store_error(), isset( WPAffiliateCaptain\Core\Monitor::checks()['statement_store'] ) )
);

// Cancellation document through the store.
PayoutService::cancel( (int) $k3_p3->id, 'K3 Test' );
$k3_p3 = PayoutService::find( $k3_p3->id );
check( 'R6-CC5 S3: cancellation document stored as object', array( true, $k3_p3->storno_hash ), array( '' !== (string) $k3_p3->storno_file, hash( 'sha256', (string) ( $GLOBALS['k3_s3_objects'][ 'https://objects.example.test/k3-bucket/wpac/' . $k3_p3->storno_file ][0] ?? '' ) ) ) );

// Correction credit note through the store (generation, mail copy, download, rebuild).
PayoutService::mark_paid( (int) $k3_p2->id );
$k3_corr_events = array();
add_action(
	'wpac_correction_generated',
	static function ( $id, $path ) use ( &$k3_corr_events ) {
		$k3_corr_events[ (int) $id ] = is_readable( (string) $path ) ? hash_file( 'sha256', (string) $path ) : '';
	},
	5,
	2
);
$k3_net  = (int) PayoutService::find( $k3_p2->id )->net_minor;
$k3_kid  = WPAffiliateCaptain\Payouts\Corrections::create( (int) $k3_p2->id, $k3_net + 1000, 'K3 Korrektur', 'k3-corr-1', $k3_net );
$k3_kg   = is_wp_error( $k3_kid ) ? null : WPAffiliateCaptain\Payouts\Corrections::find( $k3_kid );
$k3_kobj = $k3_kg ? 'https://objects.example.test/k3-bucket/wpac/' . $k3_kg->file : '';
check(
	'R6-CC5 S3: correction credit note stored as object only, mail copy with the same bytes',
	array( true, true, false, true ),
	array( $k3_kg && '' !== (string) $k3_kg->file, $k3_kg && isset( $GLOBALS['k3_s3_objects'][ $k3_kobj ] ) && hash_equals( (string) $k3_kg->hash, hash( 'sha256', $GLOBALS['k3_s3_objects'][ $k3_kobj ][0] ) ), $k3_kg && is_file( $k3_uploads . '/' . $k3_kg->file ), $k3_kg && ( $k3_corr_events[ (int) $k3_kg->id ] ?? '' ) === (string) $k3_kg->hash )
);
if ( $k3_kg ) {
	unset( $GLOBALS['k3_s3_objects'][ $k3_kobj ] );
	$k3_kb = WPAffiliateCaptain\Payouts\Corrections::stored_bytes( $k3_kg );
	check( 'R6-CC5 S3: missing correction object rebuilt byte for byte and stored again', array( (string) $k3_kg->hash, true ), array( hash( 'sha256', (string) $k3_kb ), isset( $GLOBALS['k3_s3_objects'][ $k3_kobj ] ) ) );
}
check( 'R6-CC5 S3: the per-node sweep of the local folder is skipped (one shared copy)', null, WPAffiliateCaptain\Payouts\StatementSweep::scheduled() );

// Retention purge through the store (central for all app servers); a failing delete is retried.
// Old documents of earlier test files are shifted out of the period meanwhile (later files use them).
$k3_shift_p = array_map( 'intval', (array) $wpdb->get_col( 'SELECT id FROM ' . Db::table( 'payouts' ) . " WHERE statement_deleted_at IS NULL AND created_at < '2020-01-01'" ) );
$k3_shift_k = array_map( 'intval', (array) $wpdb->get_col( 'SELECT id FROM ' . Db::table( WPAffiliateCaptain\Payouts\Corrections::TABLE ) . " WHERE file_deleted_at IS NULL AND created_at < '2020-01-01'" ) );
if ( $k3_shift_p ) {
	$wpdb->query( 'UPDATE ' . Db::table( 'payouts' ) . ' SET created_at = DATE_ADD(created_at, INTERVAL 100 YEAR), storno_at = DATE_ADD(storno_at, INTERVAL 100 YEAR) WHERE id IN (' . implode( ',', $k3_shift_p ) . ')' );
}
if ( $k3_shift_k ) {
	$wpdb->query( 'UPDATE ' . Db::table( WPAffiliateCaptain\Payouts\Corrections::TABLE ) . ' SET created_at = DATE_ADD(created_at, INTERVAL 100 YEAR) WHERE id IN (' . implode( ',', $k3_shift_k ) . ')' );
}
$wpdb->query( 'UPDATE ' . Db::table( 'payouts' ) . " SET created_at = '2000-01-31 00:00:00', paid_at = IF(paid_at IS NULL, NULL, '2000-01-31 00:00:00'), storno_at = IF(storno_at IS NULL, NULL, '2000-02-01 00:00:00') WHERE id IN (" . (int) $k3_p2->id . ',' . (int) $k3_p3->id . ')' );
if ( $k3_kg ) {
	$wpdb->query( 'UPDATE ' . Db::table( WPAffiliateCaptain\Payouts\Corrections::TABLE ) . " SET created_at = '2000-03-01 00:00:00' WHERE id = " . (int) $k3_kg->id );
}
$GLOBALS['k3_s3_fail'] = 500;
$k3_before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::table( 'payouts' ) . ' WHERE statement_deleted_at IS NULL AND id IN (' . (int) $k3_p2->id . ',' . (int) $k3_p3->id . ')' );
Statement::purge_expired();
check( 'R6-CC5 retention: a failed delete in the store is not marked as deleted (retried)', array( 2, 2 ), array( $k3_before, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::table( 'payouts' ) . ' WHERE statement_deleted_at IS NULL AND id IN (' . (int) $k3_p2->id . ',' . (int) $k3_p3->id . ')' ) ) );
$GLOBALS['k3_s3_fail'] = 0;
@file_put_contents( $k3_uploads . '/' . $k3_p2->statement_file, (string) $k3_bytes ); // A local copy left on this node.
Statement::purge_expired();
check(
	'R6-CC5 retention purge deletes the objects (statement and cancellation) and the local copies',
	array( false, false, false, 2 ),
	array( isset( $GLOBALS['k3_s3_objects'][ $k3_obj ] ), isset( $GLOBALS['k3_s3_objects'][ 'https://objects.example.test/k3-bucket/wpac/' . $k3_p3->storno_file ] ), is_file( $k3_uploads . '/' . $k3_p2->statement_file ), (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::table( 'payouts' ) . ' WHERE statement_deleted_at IS NOT NULL AND id IN (' . (int) $k3_p2->id . ',' . (int) $k3_p3->id . ')' ) )
);

if ( $k3_kg ) {
	$k3_kg2 = WPAffiliateCaptain\Payouts\Corrections::find( $k3_kg->id );
	check( 'R6-CC5 retention purge deletes the correction object through the store', array( false, true ), array( isset( $GLOBALS['k3_s3_objects'][ $k3_kobj ] ), (bool) $k3_kg2->file_deleted_at ) );
}
if ( $k3_shift_p ) {
	$wpdb->query( 'UPDATE ' . Db::table( 'payouts' ) . ' SET created_at = DATE_SUB(created_at, INTERVAL 100 YEAR), storno_at = DATE_SUB(storno_at, INTERVAL 100 YEAR) WHERE id IN (' . implode( ',', $k3_shift_p ) . ')' );
}
if ( $k3_shift_k ) {
	$wpdb->query( 'UPDATE ' . Db::table( WPAffiliateCaptain\Payouts\Corrections::TABLE ) . ' SET created_at = DATE_SUB(created_at, INTERVAL 100 YEAR) WHERE id IN (' . implode( ',', $k3_shift_k ) . ')' );
}

// Migration of local documents: dry run, then copy + verify + delete local; corrupt copies stay.
$GLOBALS['k3_s3_log'] = array();
$k3_dry = Statement::migrate( $k3_s3, true );
// The documents of earlier test files are put back afterwards (later files may read them).
$k3_keep = array();
foreach ( $k3_dry['files'] as $k3_name ) {
	$k3_keep[ Statement::path( $k3_name ) ] = (string) file_get_contents( Statement::path( $k3_name ) );
}
check( 'R6-CC5 migrate dry run: lists the local documents, sends nothing, deletes nothing', array( true, array(), true ), array( in_array( $k3_p1->statement_file, $k3_dry['files'], true ) && $k3_dry['found'] >= 1, $GLOBALS['k3_s3_log'], is_file( $k3_uploads . '/' . $k3_p1->statement_file ) ) );
check( 'R6-CC5 migrate: documents not in the store are reported as misplaced', true, in_array( $k3_p1->statement_file, array_column( Statement::misplaced(), 1 ), true ) );
$k3_bad = $k3_payout(); // Generated into the store; put a corrupt local copy next to it.
$k3_pb  = PayoutService::find( $k3_bad );
@file_put_contents( $k3_uploads . '/' . $k3_pb->statement_file, 'corrupt' );
$k3_mig = Statement::migrate( $k3_s3 );
check(
	'R6-CC5 migrate: copied after hash verification and removed locally; a corrupt local file stays',
	array( true, false, $k3_p1->statement_hash, true, true ),
	array( $k3_mig['copied'] >= 1, is_file( $k3_uploads . '/' . $k3_p1->statement_file ), hash( 'sha256', (string) $k3_s3->get( $k3_p1->statement_file ) ), $k3_mig['failed'] >= 1, is_file( $k3_uploads . '/' . $k3_pb->statement_file ) )
);
@unlink( $k3_uploads . '/' . $k3_pb->statement_file );
foreach ( $k3_keep as $k3_path => $k3_bytes_kept ) {
	if ( ! is_file( $k3_path ) ) {
		@file_put_contents( $k3_path, $k3_bytes_kept );
	}
}
$k3_cli = WPAffiliateCaptain\Cli\Command::migrate_statements( 's3', true );
check( 'R6-CC5 wp wpac statements migrate without WPAC_S3_* constants refuses (names the constants)', array( true, true ), array( is_wp_error( $k3_cli ), is_wp_error( $k3_cli ) && false !== strpos( $k3_cli->get_error_message(), 'WPAC_S3_BUCKET' ) ) );
$k3_t = Statement::test_store();
check( 'R6-CC5 connection test against S3: write, read, delete; probe gone', array( true, 's3', array() ), array( $k3_t['ok'], $k3_t['store'], array_values( array_filter( array_keys( $GLOBALS['k3_s3_objects'] ), static function ( $u ) { return false !== strpos( $u, 'wpac-probe-' ); } ) ) ) );
$GLOBALS['k3_s3_fail'] = 403;
$k3_t = Statement::test_store();
$GLOBALS['k3_s3_fail'] = 0;
check( 'R6-CC5 connection test failure names the step and the reason, never the secret', array( false, false, 'S3 PUT: HTTP 403 InternalError', false ), array( $k3_t['ok'], $k3_t['write'], $k3_t['error'], false !== strpos( wp_json_encode( $k3_t ), 'k3-very-secret-value' ) ) );
remove_all_filters( 'wpac_statement_store' );
delete_option( Statement::STORE_ERROR_OPTION );
check( 'R6-CC5 without the filter the local folder is active again', 'local', Statement::store()->id() );
if ( null !== $k3_dir_filters ) {
	$GLOBALS['test_hooks']['wpac_statement_dir'] = $k3_dir_filters;
}

/* Constants, fallback with notice, uninstall through the store: own processes. --------------- */
foreach ( array( 'full', 'partial' ) as $k3_mode ) {
	$k3_prefix = getenv( 'WPAC_TEST_DB_PREFIX' );
	putenv( 'WPAC_TEST_DB_PREFIX=k3' . substr( $k3_mode, 0, 1 ) . '_' );
	$k3_out = array();
	exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/r15-k3-isolated.php' ) . ' ' . escapeshellarg( $k3_mode ) . ' 2>&1', $k3_out );
	putenv( false === $k3_prefix ? 'WPAC_TEST_DB_PREFIX' : 'WPAC_TEST_DB_PREFIX=' . $k3_prefix );
	$k3_res = json_decode( (string) end( $k3_out ), true );
	if ( ! is_array( $k3_res ) ) {
		check( 'R6-CC5 isolated checks ran (' . $k3_mode . ')', 'json', implode( "\n", $k3_out ) );
		continue;
	}
	foreach ( $k3_res as $k3_name => $k3_pair ) {
		check( $k3_name, $k3_pair[0], $k3_pair[1] );
	}
}
