<?php
/**
 * Audit round 13 (part E): statement sweep with hash maps and cursor, sweep errors in the cron
 * and its place in the hourly run, index migration with lock timeout and online indexes, report
 * cache per report, audit order filter and bounded search, cached template scan with nested
 * references, attribution clean-up cursor, small compatibility and CI items.
 * Included from run.php (helpers check(), q(), t(), sale()).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Codes\CodeRepository;
use WPAffiliateCaptain\Core\Audit;
use WPAffiliateCaptain\Core\Capabilities;
use WPAffiliateCaptain\Core\Cron;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\Installer;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Payouts\StatementSweep;
use WPAffiliateCaptain\Privacy\Retention;
use WPAffiliateCaptain\Promotion\Landing;
use WPAffiliateCaptain\Reports\Reports;

echo "R13 E sweep, migration, caches, audit, retention, CI\n";
global $wpdb;

$r13e_root = dirname( __DIR__, 2 );
$r13e_src  = static function ( $file ) use ( $r13e_root ) {
	return (string) file_get_contents( $r13e_root . '/' . $file );
};

// ---------------------------------------------------------------------------------------------
// R13-M10: sweep loads referenced names/hashes once, continues at a cursor (hash in the marker).
$m10_dir = sys_get_temp_dir() . '/wpac-r13e-' . getmypid();
@mkdir( $m10_dir, 0777, true );
remove_all_filters( 'wpac_statement_dir' );
add_filter( 'wpac_statement_dir', static function () use ( $m10_dir ) { return $m10_dir; } );
$m10_aff = AffiliateRepository::create( array( 'name' => 'M10 Partner', 'email' => 'm10@example.de', 'status' => 'active' ) );
$m10_n   = 0;
$m10_row = static function ( $file, $hash, $deleted ) use ( $wpdb, $m10_aff, &$m10_n ) {
	++$m10_n;
	$wpdb->insert(
		t( 'payout_corrections' ),
		array(
			'payout_id'       => 0,
			'number'          => 'R13E-' . $m10_n . '-' . getmypid(),
			'affiliate_id'    => $m10_aff,
			'currency'        => 'EUR',
			'request_key'     => 'r13e-' . $m10_n . '-' . getmypid(),
			'file'            => $file,
			'hash'            => $hash,
			'file_deleted_at' => $deleted ? '2030-01-01 00:00:00' : null,
			'created_at'      => Db::now(),
		)
	);
};
file_put_contents( $m10_dir . '/a-live-r13e.pdf', '%PDF-1.4 live' );
$m10_row( 'a-live-r13e.pdf', hash_file( 'sha256', $m10_dir . '/a-live-r13e.pdf' ), false );
for ( $i = 0; $i < 6; $i++ ) {
	file_put_contents( sprintf( '%s/b-unknown-%02d.pdf', $m10_dir, $i ), '%PDF-1.4 unknown ' . $i );
}
for ( $i = 0; $i < 3; $i++ ) {
	$f = sprintf( '%s/zz-gone-%02d.pdf', $m10_dir, $i );
	file_put_contents( $f, '%PDF-1.4 deleted copy ' . $i );
	$m10_row( '', hash_file( 'sha256', $f ), true );
}
@unlink( $m10_dir . '/' . StatementSweep::MARKER );
$m10_marker = static function () use ( $m10_dir ) {
	return json_decode( (string) file_get_contents( $m10_dir . '/' . StatementSweep::MARKER ), true );
};
$m10_r1 = StatementSweep::run( 0.000001 );
$m10_s1 = $m10_marker();
$m10_r2 = StatementSweep::run( 0.000001 );
// (Files of the earlier statement folder of the test run are swept too: compare by order only.)
check(
	'R13-M10 tiny budget: one file per sweep, the next sweep continues after it (not at the front)',
	array( 1, false, true, 1, 1, true ),
	array( $m10_r1['candidates'], $m10_r1['complete'], isset( $m10_s1['cursor'] ) && (bool) preg_match( '/^[0-9a-f]{64}$/', $m10_s1['cursor'] ), count( $m10_r1['unknown'] ), count( $m10_r2['unknown'] ), strcmp( basename( (string) end( $m10_r2['unknown'] ) ), basename( (string) end( $m10_r1['unknown'] ) ) ) > 0 )
);
check( 'R13-M10 the cursor in the marker is a hash, never a file name', array( false, false ), array( false !== strpos( (string) file_get_contents( $m10_dir . '/' . StatementSweep::MARKER ), 'b-unknown' ), false !== strpos( (string) file_get_contents( $m10_dir . '/' . StatementSweep::MARKER ), $m10_dir ) ) );
for ( $i = 0; $i < 60 && ( is_file( $m10_dir . '/zz-gone-02.pdf' ) || is_file( $m10_dir . '/zz-gone-00.pdf' ) ); $i++ ) {
	StatementSweep::run( 0.000001 );
}
check( 'R13-M10 files at the end of the alphabet are reached with a tiny budget; live and unknown files stay', array( false, false, false, true, true ), array( is_file( $m10_dir . '/zz-gone-00.pdf' ), is_file( $m10_dir . '/zz-gone-01.pdf' ), is_file( $m10_dir . '/zz-gone-02.pdf' ), is_file( $m10_dir . '/a-live-r13e.pdf' ), is_file( $m10_dir . '/b-unknown-05.pdf' ) ) );
// A complete sweep clears the cursor; the queries do not grow with the number of files.
$m10_full = StatementSweep::run( 0, true );
check( 'R13-M10 complete sweep: cursor cleared, nothing deleted twice, live file not hashed', array( true, '', 0, false ), array( $m10_full['complete'], $m10_marker()['cursor'], $m10_full['deleted'], in_array( $m10_dir . '/a-live-r13e.pdf', $m10_full['unknown'], true ) ) );
check( 'R13-M10 no IN() list per file chunk any more', array( false, false ), array( false !== strpos( $r13e_src( 'src/Payouts/StatementSweep.php' ), 'IN ({$marks})' ), false !== strpos( $r13e_src( 'src/Payouts/StatementSweep.php' ), 'array_chunk' ) ) );

// R13-N11: a failing sweep counts as a failed cron step (and only is swallowed in requests).
touch( $m10_dir . '/' . StatementSweep::MARKER, time() - 7 * HOUR_IN_SECONDS );
add_filter( 'wpac_statement_sweep_budget', static function () { throw new \RuntimeException( 'r13e sweep broken' ); } );
$n11_thrown = '';
try {
	StatementSweep::scheduled();
} catch ( \RuntimeException $e ) {
	$n11_thrown = $e->getMessage();
}
$n11_request = 'x';
try {
	StatementSweep::maybe_run();
	$n11_request = 'swallowed';
} catch ( \Throwable $e ) {
	$n11_request = 'thrown';
}
// Stuck migration (schema 12 stored): bookings wait, the sweep still runs and its error is recorded.
$n11_ver = get_option( Installer::DB_OPTION );
update_option( Installer::DB_OPTION, 12 );
Cron::hourly();
update_option( Installer::DB_OPTION, $n11_ver );
remove_all_filters( 'wpac_statement_sweep_budget' );
$n11_fail = Cron::failures();
check(
	'R13-N11 sweep error: thrown in the cron, swallowed at the end of a request, recorded as failed step also during a stuck migration',
	array( 'r13e sweep broken', 'swallowed', true ),
	array( $n11_thrown, $n11_request, isset( $n11_fail['hourly.statement_sweep'] ) && false !== strpos( $n11_fail['hourly.statement_sweep']['error'], 'r13e sweep broken' ) )
);
touch( $m10_dir . '/' . StatementSweep::MARKER, time() - 7 * HOUR_IN_SECONDS );
update_option( Installer::DB_OPTION, 12 );
Cron::hourly();
update_option( Installer::DB_OPTION, $n11_ver );
check( 'R13-N11 successful sweep in a stuck migration clears the failure; marker renewed', array( false, true ), array( isset( Cron::failures()['hourly.statement_sweep'] ), time() - StatementSweep::last_run() < 60 ) );
$n12_cron = $r13e_src( 'src/Core/Cron.php' );
$n12_body = substr( $n12_cron, strpos( $n12_cron, 'public static function hourly()' ), 2500 );
check( 'R13-N12 the sweep is the last step of the hourly run (after jobs, approvals, documents)', true, strrpos( $n12_body, 'self::statement_sweep();' ) > strpos( $n12_body, "'review'" ) );
remove_all_filters( 'wpac_statement_dir' );
foreach ( (array) glob( $m10_dir . '/{,.}*', GLOB_BRACE ) as $m10_f ) {
	if ( is_file( $m10_f ) ) {
		@unlink( $m10_f );
	}
}
@rmdir( $m10_dir );

// ---------------------------------------------------------------------------------------------
// R13-M8: schema changes wait at most lock_wait_timeout; a performance index that cannot be added
// is a warning (bookings go on) and is retried; online DDL.
$m8_audit = Db::table( 'audit' );
$m8_keys  = static function () use ( $wpdb, $m8_audit ) {
	return array_map( 'strtolower', (array) $wpdb->get_col( 'SHOW INDEX FROM ' . $m8_audit, 2 ) );
};
$m8_before_wait = (string) $wpdb->get_var( 'SELECT @@SESSION.lock_wait_timeout' );
q( 'ALTER TABLE ' . $m8_audit . ' DROP INDEX action_created' );
$m8_host = getenv( 'WPAC_TEST_DB_HOST' ) ?: 'localhost';
$m8_port = null;
if ( false !== strpos( $m8_host, ':' ) ) {
	list( $m8_host, $m8_port ) = explode( ':', $m8_host, 2 );
	$m8_port                   = (int) $m8_port;
}
$m8_other = new mysqli( $m8_host, getenv( 'WPAC_TEST_DB_USER' ) ?: 'wpac', getenv( 'WPAC_TEST_DB_PASS' ) ?: 'wpac', getenv( 'WPAC_TEST_DB_NAME' ) ?: 'wpac_test', $m8_port );
// An open transaction that has read the audit table holds its metadata lock.
$m8_other->query( 'START TRANSACTION' );
$m8_other->query( 'SELECT COUNT(*) FROM ' . $m8_audit );
add_filter( 'wpac_migration_lock_wait_timeout', static function () { return 1; } );
update_option( Installer::DB_OPTION, 12 );
delete_option( 'wpac_schema_problems' );
$m8_t0 = microtime( true );
Installer::install_schema();
$m8_took = microtime( true ) - $m8_t0;
$m8_warn = array_values( preg_grep( '/^missing index /', (array) get_option( 'wpac_schema_warnings' ) ) );
check(
	'R13-M8 index blocked by an open transaction: migration gives up after the lock timeout, schema counts as current, warning instead of blocker',
	array( true, 13, false, true, array() ),
	array( $m8_took < 8, (int) get_option( Installer::DB_OPTION ), in_array( 'action_created', $m8_keys(), true ), 1 === count( $m8_warn ) && 0 === strpos( $m8_warn[0], 'missing index ' . $m8_audit . '.action_created' ), Installer::verify_schema() )
);
check( 'R13-M8 the session lock_wait_timeout is restored afterwards', $m8_before_wait, (string) $wpdb->get_var( 'SELECT @@SESSION.lock_wait_timeout' ) );
check( 'R13-M8 schema_current() true: the hourly run books again', true, Installer::schema_current() );
$m8_other->query( 'ROLLBACK' );
$m8_other->close();
delete_transient( 'wpac_index_retry' );
Installer::maybe_retry_indexes();
check( 'R13-M8 retry adds the index online once the lock is gone; warning cleared', array( true, array() ), array( in_array( 'action_created', $m8_keys(), true ), array_values( preg_grep( '/^missing index /', (array) get_option( 'wpac_schema_warnings' ) ) ) ) );
Installer::maybe_retry_indexes(); // Nothing to do: no warning.
remove_all_filters( 'wpac_migration_lock_wait_timeout' );
q( 'ALTER TABLE ' . Db::table( 'commissions' ) . ' DROP INDEX source_order_id' );
Installer::install_schema();
check( 'R13-M8 missing index added online by the migration (no lock): no warning', array( true, array() ), array( in_array( 'source_order_id', array_map( 'strtolower', (array) $wpdb->get_col( 'SHOW INDEX FROM ' . Db::table( 'commissions' ), 2 ) ), true ), array_values( preg_grep( '/^missing index /', (array) get_option( 'wpac_schema_warnings' ) ) ) ) );
check( 'R13-M8 online DDL and lock timeout in the migration; documented', array( true, true, true ), array( false !== strpos( $r13e_src( 'src/Core/Installer.php' ), 'ALGORITHM=INPLACE, LOCK=NONE' ), false !== strpos( $r13e_src( 'src/Core/Installer.php' ), 'SET SESSION lock_wait_timeout = %d' ), false !== strpos( $r13e_src( 'docs/HANDBOOK.md' ), 'wpac_migration_lock_wait_timeout' ) ) );

// ---------------------------------------------------------------------------------------------
// R13-N12: one transient per report with a generation number; 9 alternating views all hit.
$n12_from  = gmdate( 'Y-m-01' );
$n12_views = array();
foreach ( array( 'partners', 'campaign', 'content_id' ) as $n12_kind ) {
	foreach ( array( '2026-01-01', '2026-02-01', '2026-03-01' ) as $n12_start ) {
		$n12_views[] = array( $n12_kind, $n12_start, gmdate( 'Y-m-d' ) );
	}
}
foreach ( $n12_views as $n12_v ) {
	Reports::cached( $n12_v[0], $n12_v[1], $n12_v[2] );
}
$n12_hits = 0;
foreach ( $n12_views as $n12_v ) {
	$n12_hits += is_array( get_transient( Reports::cache_name( $n12_v[0], $n12_v[1], $n12_v[2] ) ) ) ? 1 : 0;
}
$n12_gen = (int) get_option( Reports::CACHE_GEN );
Reports::flush_cache();
check( 'R13-N12 nine report views: nine own transients, all still cached; a booking raises the generation', array( 9, $n12_gen + 1, false ), array( $n12_hits, (int) get_option( Reports::CACHE_GEN ), get_transient( Reports::cache_name( 'partners', '2026-01-01', gmdate( 'Y-m-d' ) ) ) ) );

// R13-N12: the order filter finds a refund after a correction credit note (logged on the payout).
Audit::log( 'payout.refund_after_correction', 'payout', 4711, array( 'order' => '9131301' ) );
$n12_order = Audit::page( array( 'order' => '9131301' ) )[0];
check( 'R13-N12 audit order filter includes payout.refund_after_correction', array( 'payout.refund_after_correction' ), array_values( wp_list_pluck( $n12_order, 'action' ) ) );

// R13-N12: the free-text search covers 90 days unless a start date is given (filter, 0 = all).
Audit::log( 'r13e.search', 'setup', 0, array( 'marker' => 'r13e-old-needle' ) );
$wpdb->query( 'UPDATE ' . t( 'audit' ) . " SET created_at = '" . gmdate( 'Y-m-d H:i:s', time() - 200 * DAY_IN_SECONDS ) . "' WHERE action = 'r13e.search'" );
Audit::log( 'r13e.search2', 'setup', 0, array( 'marker' => 'r13e-new-needle' ) );
$n12_s_default = count( Audit::page( array( 'search' => 'needle' ) )[0] );
$n12_s_from    = count( Audit::page( array( 'search' => 'needle', 'from' => gmdate( 'Y-m-d', time() - 365 * DAY_IN_SECONDS ) ) )[0] );
add_filter( 'wpac_audit_search_days', static function () { return 0; } );
$n12_s_all = count( Audit::page( array( 'search' => 'r13e-old-needle' ) )[0] );
remove_all_filters( 'wpac_audit_search_days' );
check( 'R13-N12 search bounded to 90 days; a start date or the filter reaches older entries', array( 1, 2, 1, 90 ), array( $n12_s_default, $n12_s_from, $n12_s_all, Audit::search_days() ) );

// ---------------------------------------------------------------------------------------------
// R13-N10/N12: template scan finds nested attributes, parts in parts and synced patterns; cached.
if ( ! function_exists( 'get_post_type' ) ) {
	function get_post_type( $post = null ) {
		$p = get_post( $post );
		return $p && isset( $p->post_type ) ? $p->post_type : false;
	}
}
$GLOBALS['r12_block_theme'] = true;
$GLOBALS['_wp_current_template_content'] = '<!-- wp:template-part {"slug":"header","tagName":"header","style":{"spacing":{"padding":"1rem"}}} /--><!-- wp:post-content /-->';
$GLOBALS['r12_parts'] = array( 'r12theme//header' => '<!-- wp:paragraph --><p>Kopf</p><!-- /wp:paragraph --><!-- wp:template-part {"slug":"inner"} /-->', 'r12theme//inner' => '<!-- wp:wpac/partner-welcome /-->' );
Landing::flush_template_scan();
$n10_nested = Landing::block_template_has( 'wpac_partner_welcome', 'wpac/partner-welcome' );
$GLOBALS['r12_parts']['r12theme//inner'] = '<!-- wp:paragraph --><p>nichts</p><!-- /wp:paragraph -->';
$n10_cached = Landing::block_template_has( 'wpac_partner_welcome', 'wpac/partner-welcome' );
$GLOBALS['test_posts'][913131] = (object) array( 'ID' => 913131, 'post_type' => 'wp_template_part', 'post_content' => '', 'post_title' => 'inner', 'post_status' => 'publish', 'post_password' => '', 'post_author' => 1 );
Landing::maybe_flush_template_scan( 913131 );
$n10_after = Landing::block_template_has( 'wpac_partner_welcome', 'wpac/partner-welcome' );
$GLOBALS['test_posts'][913132] = (object) array( 'ID' => 913132, 'post_type' => 'wp_block', 'post_content' => 'Willkommen [wpac_partner_welcome]', 'post_title' => 'synced', 'post_status' => 'publish', 'post_password' => '', 'post_author' => 1 );
$GLOBALS['r12_parts']['r12theme//inner'] = '<!-- wp:block {"ref":913132} /-->';
Landing::maybe_flush_template_scan( 913132 );
$n10_synced = Landing::block_template_has( 'wpac_partner_welcome', 'wpac/partner-welcome' );
$n10_gen    = (int) get_option( Landing::TPL_GEN );
$GLOBALS['test_posts'][913133] = (object) array( 'ID' => 913133, 'post_type' => 'post', 'post_content' => '', 'post_title' => 'x', 'post_status' => 'publish', 'post_password' => '', 'post_author' => 1 );
Landing::maybe_flush_template_scan( 913133 );
check(
	'R13-N10/N12 template scan: nested attributes and a part in a part found; result cached per template until a part is saved; synced pattern found; ordinary posts do not renew it',
	array( true, true, false, true, $n10_gen ),
	array( $n10_nested, $n10_cached, $n10_after, $n10_synced, (int) get_option( Landing::TPL_GEN ) )
);
unset( $GLOBALS['_wp_current_template_content'], $GLOBALS['r12_block_theme'], $GLOBALS['test_posts'][913131], $GLOBALS['test_posts'][913132], $GLOBALS['test_posts'][913133] );
$GLOBALS['r12_parts'] = array();
check( 'R13-N10 classic themes: stylesheet enqueued in the head (template_redirect) where a block is shown', array( true, true ), array( false !== strpos( $r13e_src( 'src/Promotion/Landing.php' ), "'enqueue_head_styles' ), 3 )" ), false !== strpos( $r13e_src( 'src/Promotion/Landing.php' ), "'wpac_partner_targets'        => 'wpac/partner-targets'" ) ) );

// R13-N10: translated role name also in the role data (dropdowns) and after switch_to_blog().
$n10_roles = (object) array(
	'roles'      => array( Capabilities::TAX_ADVISOR_ROLE => array( 'name' => 'stored name', 'capabilities' => array() ) ),
	'role_names' => array( Capabilities::TAX_ADVISOR_ROLE => 'stored name' ),
);
Capabilities::translate_role_name( $n10_roles );
check( 'R13-N10 role name set in role_names and in roles[...][name]; hooked to wp_roles_init', array( Capabilities::TAX_ADVISOR_NAME, Capabilities::TAX_ADVISOR_NAME, true ), array( $n10_roles->role_names[ Capabilities::TAX_ADVISOR_ROLE ], $n10_roles->roles[ Capabilities::TAX_ADVISOR_ROLE ]['name'], false !== strpos( $r13e_src( 'src/Plugin.php' ), "add_action( 'wp_roles_init'" ) ) );

// R13-N10: a stored program currency stays valid when saving (e.g. IRT without WooCommerce).
$n10_keep = get_option( 'wpac_settings' );
update_option( 'wpac_settings', array_merge( (array) $n10_keep, array( 'currency' => 'IRT' ) ) );
Settings::flush_cache();
$n10_err = array();
$n10_out = Settings::sanitize( array( 'currency' => 'IRT' ), $n10_err );
update_option( 'wpac_settings', $n10_keep );
Settings::flush_cache();
check( 'R13-N10 saving keeps the stored currency IRT (no error); early currency check guarded before init', array( 'IRT', false, true ), array( $n10_out['currency'], isset( $n10_err['currency'] ), false !== strpos( $r13e_src( 'src/Sales/ContractApi.php' ), "! did_action( 'init' )" ) ) );

// ---------------------------------------------------------------------------------------------
// R13-N12: attribution clean-up in ID windows with a cursor (one window per call when out of time).
$n12_a   = AffiliateRepository::create( array( 'name' => 'N12 Partner', 'email' => 'n12-attr@example.de', 'status' => 'active' ) );
$n12_ord = array();
for ( $i = 0; $i < 4; $i++ ) {
	$n12_ord[] = 'r13e-attr-' . $i;
	sale( 'r13e-attr-' . $i, CodeRepository::main_code( $n12_a ), array( 10000 ) );
}
$n12_in = "'" . implode( "','", $n12_ord ) . "'";
$wpdb->query( 'UPDATE ' . t( 'attributions' ) . " SET created_at = '2001-03-01 10:00:00' WHERE source = 'test' AND source_order_id IN ({$n12_in})" );
$wpdb->query( 'UPDATE ' . t( 'commissions' ) . " SET updated_at = '2001-04-01 10:00:00', status = 'paid', payout_id = 999999 WHERE source = 'test' AND source_order_id IN ({$n12_in})" );
$n12_ids = array_map( 'intval', $wpdb->get_col( 'SELECT id FROM ' . t( 'attributions' ) . " WHERE source = 'test' AND source_order_id IN ({$n12_in}) ORDER BY id" ) );
// Other old attributions (earlier tests) may lie in front; only the windows from the cursor matter.
add_filter( 'wpac_attribution_purge_batch', static function () { return 1; } );
$n12_rp = new ReflectionProperty( Cron::class, 'deadline' );
$n12_rp->setAccessible( true );
$n12_rp->setValue( null, microtime( true ) - 1 ); // Out of time: one window per call.
update_option( Retention::ATTR_CURSOR, array( 'day' => gmdate( 'Y-m-d' ), 'id' => $n12_ids[0] ) );
$n12_d1 = Retention::purge_attributions();
$n12_c1 = get_option( Retention::ATTR_CURSOR );
$n12_d2 = Retention::purge_attributions();
$n12_c2 = get_option( Retention::ATTR_CURSOR );
$n12_rp->setValue( null, 0.0 );
$n12_rest = Retention::purge_attributions();
remove_all_filters( 'wpac_attribution_purge_batch' );
check(
	'R13-N12 attribution clean-up continues at the cursor window by window, then finishes and clears it',
	array( 1, $n12_ids[0] + 1, $n12_ids[0] + 2, true, 0, false ),
	array( $n12_d1, (int) $n12_c1['id'], (int) $n12_c2['id'], $n12_d2 + $n12_rest >= 3, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . t( 'attributions' ) . " WHERE source = 'test' AND source_order_id IN ({$n12_in})" ), get_option( Retention::ATTR_CURSOR ) )
);

// ---------------------------------------------------------------------------------------------
// R13-M4 / R13-N11: CI release guard, pre-release flag, CR in the notes, takeover upgrade run,
// deploy guard; documentation and uninstall lists.
$m4_ci  = $r13e_src( '.github/workflows/ci.yml' );
$m4_dep = $r13e_src( '.github/workflows/wordpress-org-deploy.yml' );
check(
	'R13-M4 main release refused when the tag exists without a release',
	array( true, true ),
	array( false !== strpos( $m4_ci, 'git rev-parse -q --verify "refs/tags/v$v"' ), false !== strpos( $m4_ci, '::error::Tag v$v exists without a release' ) )
);
check(
	'R13-N11 CI: pre-release flag, CR stripped in the notes, upgrade from v0.10.0 (takeover enforced), deploy only after tag/main push runs and never for pre-releases',
	array( true, true, true, true, true, true ),
	array( false !== strpos( $m4_ci, 'pre=(--prerelease)' ), false !== strpos( $m4_ci, "tr -d '\\r' < readme.txt | awk" ), false !== strpos( $m4_ci, 'from: [previous, v0.10.0]' ), false !== strpos( $r13e_src( 'tests/ci/switch-plugin.php' ), "getenv( 'WPAC_EXPECT_TAKEOVER' )" ), false !== strpos( $m4_dep, 'select(.event == "push" and (.headBranch == $ENV.TAG or .headBranch == "main"))' ), false !== strpos( $m4_dep, '.isDraft or .isPrerelease' ) )
);
check( 'R13-N11 settings text says "anonymised" like the handbook', array( true, false ), array( false !== strpos( $r13e_src( 'src/Admin/Pages/SettingsPage.php' ), 'never approved or rejected are anonymised after this period' ), false !== strpos( $r13e_src( 'src/Admin/Pages/SettingsPage.php' ), 'never approved or rejected are deleted after this period' ) ) );
$n12_un = $r13e_src( 'uninstall.php' );
check( 'R13-N12 uninstall removes the new options', array( true, true, true ), array( false !== strpos( $n12_un, "'wpac_reports_gen'" ), false !== strpos( $n12_un, "'wpac_tplscan_gen'" ), false !== strpos( $n12_un, "'wpac_attr_purge_cursor'" ) ) );
