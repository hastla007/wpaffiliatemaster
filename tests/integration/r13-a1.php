<?php
/**
 * Integration checks of audit round 13, part a1 (concurrency):
 * R13-K1 (a transaction aborted by the database – deadlock, lock wait timeout, lost connection –
 * never reports success; locking reads and critical writes are checked; lock order of cancel()),
 * R13-M1 (order lock around payload and booking in OrderSync; items_changed deducts only refunds
 * the ledger knows), R13-N9 (busy PDF lock, cancel during generation, "execute" vs "reject" of a
 * payout request, Jobs::run() owner check, enqueue() of a running job).
 *
 * Real interleavings: a second mysqli connection holds row locks (lock wait timeout) or is made
 * to wait for a lock of this connection right before its next statement (deadlock, InnoDB picks
 * the lighter transaction – this one – as the victim). Test_WPDB runs in its real-wpdb mode
 * (errors return false/null instead of throwing) for these checks.
 * Included from tests/integration/run.php after r8-g3.php (uses its WooCommerce stand-ins).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Affiliates\AffiliateRepository;
use WPAffiliateCaptain\Codes\CodeRepository;
use WPAffiliateCaptain\Commissions\Balance;
use WPAffiliateCaptain\Commissions\Ledger;
use WPAffiliateCaptain\Core\Db;
use WPAffiliateCaptain\Core\IntegrationException;
use WPAffiliateCaptain\Core\Jobs;
use WPAffiliateCaptain\Integrations\WooCommerce\OrderSync;
use WPAffiliateCaptain\Payouts\PayoutRequests;
use WPAffiliateCaptain\Payouts\PayoutService;
use WPAffiliateCaptain\Payouts\ProfileRepository;
use WPAffiliateCaptain\Payouts\Statement;

echo "R13 a1: aborted transactions, order lock, small races\n";

global $wpdb;

/** Second connection ("another request"). */
function r13a1_conn() {
	$host = getenv( 'WPAC_TEST_DB_HOST' ) ?: 'localhost';
	$port = null;
	if ( false !== strpos( $host, ':' ) ) {
		list( $host, $port ) = explode( ':', $host, 2 );
		$port                = (int) $port;
	}
	$c = new mysqli( $host, getenv( 'WPAC_TEST_DB_USER' ) ?: 'wpac', getenv( 'WPAC_TEST_DB_PASS' ) ?: 'wpac', getenv( 'WPAC_TEST_DB_NAME' ) ?: 'wpac_test', $port );
	$c->set_charset( 'utf8mb4' );
	return $c;
}
/** Real-wpdb mode and a short lock wait for this connection; restored by r13a1_normal(). */
function r13a1_real( $wait = 1 ) {
	global $wpdb;
	$wpdb->real_errors = true;
	$wpdb->db->query( 'SET SESSION innodb_lock_wait_timeout = ' . (int) $wait );
}
function r13a1_normal() {
	global $wpdb;
	$wpdb->real_errors  = false;
	$wpdb->before_query = null;
	$wpdb->db->query( 'SET SESSION innodb_lock_wait_timeout = 50' );
}
function r13a1_partner( $slug ) {
	global $wpdb;
	$id = AffiliateRepository::create( array( 'name' => 'R13A1 ' . $slug, 'email' => 'r13a1-' . $slug . '@example.de', 'status' => 'active' ) );
	ProfileRepository::save( $id, array( 'tax_status' => 'private', 'legal_name' => 'R13A1 ' . $slug, 'address' => "Kai 4\n24103 Kiel", 'country' => 'DE', 'account_holder' => 'R13A1 ' . $slug, 'iban' => 'DE89370400440532013000' ) );
	$wpdb->query( 'UPDATE ' . t( 'payout_profiles' ) . " SET updated_at = '2020-01-01 00:00:00' WHERE affiliate_id = " . (int) $id );
	return $id;
}
/** Approved sale of a partner (one commission per line, 10 %). */
function r13a1_sale( $aff, $order, array $nets ) {
	$items = array();
	foreach ( $nets as $i => $net ) {
		$items[] = array( 'item_id' => (string) ( $i + 1 ), 'product_id' => 10 + $i, 'name' => 'Kurs ' . ( $i + 1 ), 'net_minor' => $net );
	}
	$r = wpac_record_sale( array( 'source' => 'test', 'order_id' => (string) $order, 'currency' => 'EUR', 'partner_code' => CodeRepository::main_code( $aff ), 'items' => $items, 'customer' => array( 'user_id' => 0, 'email' => 'k-' . $order . '@example.de' ) ) );
	foreach ( (array) $r['commission_ids'] as $cid ) {
		Ledger::transition( (int) $cid, 'approved' );
	}
	return array_map( 'intval', (array) $r['commission_ids'] );
}
function r13a1_open( $order ) {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(amount_minor),0) FROM ' . t( 'commissions' ) . " WHERE source_order_id = %s AND status <> 'rejected'", (string) $order ) );
}
function r13a1_rows( $order, $kind ) {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . t( 'commissions' ) . ' WHERE source_order_id = %s AND kind = %s', (string) $order, $kind ) );
}
function r13a1_entries() {
	global $wpdb;
	return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . t( 'balance_entries' ) );
}
function r13a1_fired( $tag, $arg0 = null ) {
	$n = 0;
	foreach ( $GLOBALS['test_fired'] as $f ) {
		if ( $tag === $f[0] && ( null === $arg0 || ( isset( $f[1][0] ) && $f[1][0] == $arg0 ) ) ) {
			++$n;
		}
	}
	return $n;
}
/** Job of a refund. */
function r13a1_job( $order, $refund_id ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . t( 'jobs' ) . ' WHERE idempotency_key = %s', 'refund:test:' . $order . ':' . $refund_id ) );
}
function r13a1_run_job( $job ) {
	global $wpdb;
	$wpdb->query( $wpdb->prepare( 'UPDATE ' . t( 'jobs' ) . " SET next_attempt_at = '2000-01-01 00:00:00' WHERE id = %d", (int) $job->id ) );
	return Jobs::run( (int) $job->id );
}
/** Rows (padding) that make the other transaction heavier, so InnoDB picks this connection as the deadlock victim. */
function r13a1_pad( mysqli $o, $tag ) {
	global $wpdb;
	$values = array();
	for ( $i = 0; $i < 300; $i++ ) {
		$values[] = "('r13a1pad-{$tag}-{$i}', 'x', 'off')";
	}
	$o->query( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES " . implode( ',', $values ) );
}
$r13ct = t( 'commissions' );

/* ---------------------------------------------------------------------------------------------
 * R13-K1: test double in real-wpdb mode.
 * ------------------------------------------------------------------------------------------- */
r13a1_real();
check( 'R13-K1 Test_WPDB real mode: get_row() on an error returns null', null, $wpdb->get_row( 'SELECT * FROM r13a1_no_such_table' ) );
check( 'R13-K1 Test_WPDB real mode: error kept in last_error', true, false !== strpos( $wpdb->last_error, 'r13a1_no_such_table' ) );
check( 'R13-K1 Test_WPDB real mode: get_col() on an error returns []', array(), $wpdb->get_col( 'SELECT x FROM r13a1_no_such_table' ) );
r13a1_normal();

/* ---------------------------------------------------------------------------------------------
 * R13-K1: Db::transaction() – a callback that ignores a lock wait timeout does not commit.
 * ------------------------------------------------------------------------------------------- */
$wpdb->query( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES ('r13a1_held', '0', 'off')" );
$o = r13a1_conn();
$o->query( 'BEGIN' );
$o->query( "SELECT * FROM {$wpdb->options} WHERE option_name = 'r13a1_held' FOR UPDATE" );
r13a1_real();
$e = null;
$ret = null;
try {
	$ret = Db::transaction(
		static function () use ( $wpdb ) {
			$wpdb->query( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES ('r13a1_x', '1', 'off')" );
			$wpdb->query( "UPDATE {$wpdb->options} SET option_value = '1' WHERE option_name = 'r13a1_held'" ); // Times out; result ignored.
			$wpdb->query( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES ('r13a1_y', '1', 'off')" );
			return 'ok';
		}
	);
} catch ( IntegrationException $e ) {
}
r13a1_normal();
$o->query( 'ROLLBACK' );
check( 'R13-K1 lock wait timeout ignored by the callback: no success reported', null, $ret );
check( 'R13-K1 lock wait timeout: IntegrationException db_deadlock', 'db_deadlock', $e ? $e->error_code : '' );
check( 'R13-K1 lock wait timeout: nothing before the timeout committed', null, $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'r13a1_x'" ) );
check( 'R13-K1 lock wait timeout: nothing after the timeout written (queries refused)', null, $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'r13a1_y'" ) );
check( 'R13-K1 next transaction works again', 'fine', Db::transaction( static function () { return 'fine'; } ) );

/* Lost connection, reconnected by wpdb (new session without a transaction): the savepoint is gone. */
$e   = null;
$ret = null;
try {
	$ret = Db::transaction(
		static function () use ( $wpdb ) {
			$wpdb->query( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES ('r13a1_r1', '1', 'off')" );
			$old       = $wpdb->db;
			$wpdb->db  = r13a1_conn(); // What wpdb::check_connection() does after "server has gone away".
			$wpdb->dbh = $wpdb->db;
			$old->close();
			$wpdb->query( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES ('r13a1_r2', '1', 'off')" );
			return 'ok';
		}
	);
} catch ( IntegrationException $e ) {
}
check( 'R13-K1 reconnect inside a transaction: no success reported', array( null, 'db_deadlock' ), array( $ret, $e ? $e->error_code : '' ) );
check( 'R13-K1 reconnect: the part before the connection loss is not committed', null, $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'r13a1_r1'" ) );

/* ---------------------------------------------------------------------------------------------
 * R13-K1: record_refund() – lock wait timeout on line 2 (t3 "timeout").
 * ------------------------------------------------------------------------------------------- */
$k1a = r13a1_partner( 'k1a' );
list( $c1, $c2 ) = r13a1_sale( $k1a, 'r13k1-1', array( 100000, 100000 ) );
$entries = r13a1_entries();
$adj     = r13a1_fired( 'wpac_commission_adjusted' );
$o->query( 'BEGIN' );
$o->query( "SELECT id FROM {$r13ct} WHERE id = {$c2} FOR UPDATE" );
r13a1_real();
$res = wpac_record_refund( array( 'source' => 'test', 'order_id' => 'r13k1-1', 'refund_id' => 'wc-1', 'type' => 'refund', 'full' => true, 'reason' => 'test' ) );
r13a1_normal();
$o->query( 'ROLLBACK' );
check( 'R13-K1 refund with lock wait timeout: queued, not "adjusted"', 'wpac_queued', is_wp_error( $res ) ? $res->get_error_code() : json_encode( $res ) );
check( 'R13-K1 refund with lock wait timeout: line 1 rolled back as well', 0, r13a1_rows( 'r13k1-1', 'refund' ) );
check( 'R13-K1 refund with lock wait timeout: no account booking', $entries, r13a1_entries() );
check( 'R13-K1 refund with lock wait timeout: no wpac_commission_adjusted', $adj, r13a1_fired( 'wpac_commission_adjusted' ) );
$job = r13a1_job( 'r13k1-1', 'wc-1' );
check( 'R13-K1 refund with lock wait timeout: retry job with code db_deadlock', array( 'queued', 'db_deadlock' ), $job ? array( $job->status, $job->last_error_code ) : null );
check( 'R13-K1 retry after the lock is gone books both lines', true, $job && r13a1_run_job( $job ) );
check( 'R13-K1 retry: commission of the order fully reversed', 0, r13a1_open( 'r13k1-1' ) );
check( 'R13-K1 retry: two adjustments', 2, r13a1_rows( 'r13k1-1', 'refund' ) );

/* R13-K1: record_refund() – deadlock on line 2 (t3 "deadlock"): this connection is the victim. */
list( $c1, $c2 ) = r13a1_sale( $k1a, 'r13k1-2', array( 100000, 100000 ) );
$entries = r13a1_entries();
$adj     = r13a1_fired( 'wpac_commission_adjusted' );
$o->query( 'BEGIN' );
r13a1_pad( $o, 'k1' );
$o->query( "SELECT id FROM {$r13ct} WHERE id = {$c2} FOR UPDATE" );
$sent = false;
r13a1_real( 20 );
$wpdb->before_query = static function ( $sql ) use ( $o, $c1, $c2, &$sent ) {
	if ( ! $sent && false !== strpos( $sql, "WHERE id = {$c2} FOR UPDATE" ) ) {
		$sent = true;
		$o->query( "SELECT id FROM " . t( 'commissions' ) . " WHERE id = {$c1} FOR UPDATE", MYSQLI_ASYNC ); // Waits for line 1 (ours).
		usleep( 300000 );
	}
};
$res = wpac_record_refund( array( 'source' => 'test', 'order_id' => 'r13k1-2', 'refund_id' => 'wc-2', 'type' => 'refund', 'full' => true, 'reason' => 'test' ) );
r13a1_normal();
$other_got_lock = $sent && $o->reap_async_query() instanceof mysqli_result;
$o->query( 'ROLLBACK' );
check( 'R13-K1 deadlock: the interleaving happened and the other transaction won', true, $other_got_lock );
check( 'R13-K1 deadlock: queued, not "adjusted" with a phantom ID', 'wpac_queued', is_wp_error( $res ) ? $res->get_error_code() : json_encode( $res ) );
check( 'R13-K1 deadlock: no adjustment left (InnoDB rolled back line 1, nothing autocommitted after it)', 0, r13a1_rows( 'r13k1-2', 'refund' ) );
check( 'R13-K1 deadlock: no account booking', $entries, r13a1_entries() );
check( 'R13-K1 deadlock: no wpac_commission_adjusted', $adj, r13a1_fired( 'wpac_commission_adjusted' ) );
$job = r13a1_job( 'r13k1-2', 'wc-2' );
check( 'R13-K1 deadlock: retry job with code db_deadlock', 'db_deadlock', $job ? $job->last_error_code : '' );
check( 'R13-K1 deadlock: the retry books the refund', true, $job && r13a1_run_job( $job ) );
check( 'R13-K1 deadlock: commission of the order fully reversed after the retry', 0, r13a1_open( 'r13k1-2' ) );
check( 'R13-K1 accounts consistent after the aborted refunds', array(), Balance::verify_all() );

/* R13-K1: record_increase() – the locking read of the commission times out: exception, not "skipped". */
list( $c1 ) = r13a1_sale( $k1a, 'r13k1-3', array( 100000 ) );
$o->query( 'BEGIN' );
$o->query( "SELECT id FROM {$r13ct} WHERE id = {$c1} FOR UPDATE" );
r13a1_real();
$e = null;
try {
	Ledger::record_increase( array( 'source' => 'test', 'order_id' => 'r13k1-3', 'currency' => 'EUR', 'items' => array( array( 'item_id' => '1', 'product_id' => 10, 'name' => 'Kurs 1', 'net_minor' => 150000, 'quantity' => 1 ) ), 'reason' => 'raised' ) );
} catch ( IntegrationException $e ) {
}
r13a1_normal();
$o->query( 'ROLLBACK' );
check( 'R13-K1 record_increase with lock wait timeout throws (retry) instead of skipping the line', 'db_deadlock', $e ? $e->error_code : '' );
check( 'R13-K1 record_increase with lock wait timeout: no increase row', 0, r13a1_rows( 'r13k1-3', 'increase' ) );
$ri = Ledger::record_increase( array( 'source' => 'test', 'order_id' => 'r13k1-3', 'currency' => 'EUR', 'items' => array( array( 'item_id' => '1', 'product_id' => 10, 'name' => 'Kurs 1', 'net_minor' => 150000, 'quantity' => 1 ) ), 'reason' => 'raised' ) );
check( 'R13-K1 record_increase without the lock books the increase', 'booked', $ri['status'] );

/* R13-K1: full refund – the open increases cannot be locked (reject_open_increases()): exception. */
$inc = (int) $wpdb->get_var( "SELECT id FROM {$r13ct} WHERE source_order_id = 'r13k1-3' AND kind = 'increase'" );
$o->query( 'BEGIN' );
$o->query( "SELECT id FROM {$r13ct} WHERE id = {$inc} FOR UPDATE" );
r13a1_real();
$e = null;
try {
	Ledger::record_refund( array( 'source' => 'test', 'order_id' => 'r13k1-3', 'refund_id' => 'status-cancellation', 'type' => 'cancellation', 'full' => true, 'reason' => 'storniert', 'items' => array() ) );
} catch ( IntegrationException $e ) {
}
r13a1_normal();
$o->query( 'ROLLBACK' );
check( 'R13-K1 full reversal: open increases not lockable -> exception, not a reversal that keeps the increase', 'db_deadlock', $e ? $e->error_code : '' );
check( 'R13-K1 full reversal: nothing booked', array( 0, 'pending' ), array( r13a1_rows( 'r13k1-3', 'cancellation' ), (string) $wpdb->get_var( "SELECT status FROM {$r13ct} WHERE id = {$inc}" ) ) );

/* R13-K1: payout of a partner – the payable rows cannot be locked: exception, not "nothing payable". */
$k1b = r13a1_partner( 'k1b' );
list( $cb ) = r13a1_sale( $k1b, 'r13k1-4', array( 500000 ) );
$o->query( 'BEGIN' );
$o->query( "SELECT id FROM {$r13ct} WHERE id = {$cb} FOR UPDATE" );
r13a1_real();
$e   = null;
$pid = null;
try {
	$pid = PayoutService::create_for_affiliate( $k1b, gmdate( 'Y-m-d' ), 'EUR' );
} catch ( IntegrationException $e ) {
}
r13a1_normal();
$o->query( 'ROLLBACK' );
check( 'R13-K1 payout with locked commissions: exception instead of 0 ("nothing payable")', array( null, 'db_deadlock' ), array( $pid, $e ? $e->error_code : '' ) );

/* ---------------------------------------------------------------------------------------------
 * R13-K1: cancel() – lock wait timeout on the commissions (t4 "timeout").
 * ------------------------------------------------------------------------------------------- */
$pid = PayoutService::create_for_affiliate( $k1b, gmdate( 'Y-m-d' ), 'EUR' );
check( 'R13-K1 payout created', true, $pid > 0 );
$cancelled = r13a1_fired( 'wpac_payout_cancelled', $pid );
$entries   = r13a1_entries();
$o->query( 'BEGIN' );
$o->query( "SELECT id FROM {$r13ct} WHERE id = {$cb} FOR UPDATE" );
r13a1_real();
$res = PayoutService::cancel( $pid, 'IBAN falsch' );
r13a1_normal();
$o->query( 'ROLLBACK' );
$p = PayoutService::find( $pid );
check( 'R13-K1 cancel with lock wait timeout: error, not true', 'wpac_db_deadlock', is_wp_error( $res ) ? $res->get_error_code() : var_export( $res, true ) );
check( 'R13-K1 cancel with lock wait timeout: payout still pending, no cancellation number', array( 'pending', '' ), array( (string) $p->status, (string) $p->storno_number ) );
check( 'R13-K1 cancel with lock wait timeout: commission still in the payout, item active', array( $pid, 1 ), array( (int) $wpdb->get_var( "SELECT payout_id FROM {$r13ct} WHERE id = {$cb}" ), (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . t( 'payout_items' ) . " WHERE payout_id = {$pid} AND released_at IS NULL" ) ) );
check( 'R13-K1 cancel with lock wait timeout: no account booking, no hook', array( $entries, $cancelled ), array( r13a1_entries(), r13a1_fired( 'wpac_payout_cancelled', $pid ) ) );
check( 'R13-K1 cancel with lock wait timeout: no audit entry payout.cancelled', 0, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . t( 'audit' ) . " WHERE action = 'payout.cancelled' AND object_id = {$pid}" ) );

/* Lock order: commissions are locked before the partner account (as in record_refund()). */
$log                = array();
$wpdb->before_query = static function ( $sql ) use ( &$log ) {
	$log[] = $sql;
};
$res                = PayoutService::cancel( $pid, 'IBAN falsch' );
$wpdb->before_query = null;
$at_c               = null;
$at_b               = null;
foreach ( $log as $i => $sql ) {
	if ( null === $at_c && false !== strpos( $sql, 'UPDATE ' . $r13ct . ' SET payout_id = 0' ) ) {
		$at_c = $i;
	}
	if ( null === $at_b && false !== strpos( $sql, t( 'balances' ) ) && false !== strpos( $sql, 'FOR UPDATE' ) ) {
		$at_b = $i;
	}
}
check( 'R13-K1 cancel after the lock is gone succeeds', true, $res );
check( 'R13-K1 cancel locks the commissions before the partner account', true, null !== $at_c && null !== $at_b && $at_c < $at_b );
check( 'R13-K1 cancel: commission back in the pool', 0, (int) $wpdb->get_var( "SELECT payout_id FROM {$r13ct} WHERE id = {$cb}" ) );

/* The natural cycle of t5: a refund (commission -> account) running next to cancel() no longer deadlocks. */
$mysql_cli = trim( (string) shell_exec( 'command -v mysql 2>/dev/null' ) );
if ( '' !== $mysql_cli ) {
	$pid2 = PayoutService::create_for_affiliate( $k1b, gmdate( 'Y-m-d' ), 'EUR' );
	$host = getenv( 'WPAC_TEST_DB_HOST' ) ?: 'localhost';
	$port = '';
	if ( false !== strpos( $host, ':' ) ) {
		list( $host, $port ) = explode( ':', $host, 2 );
	}
	$log_file = sys_get_temp_dir() . '/r13a1-cycle.log';
	$sql      = "BEGIN; SELECT id FROM {$r13ct} WHERE id = {$cb} FOR UPDATE; SELECT SLEEP(1.5); SELECT balance_minor FROM " . t( 'balances' ) . " WHERE affiliate_id = {$k1b} FOR UPDATE; SELECT SLEEP(0.3); COMMIT;";
	$cmd      = escapeshellarg( $mysql_cli ) . ' -h' . escapeshellarg( $host ) . ( '' !== $port ? ' -P' . escapeshellarg( $port ) : '' ) . ' -u' . escapeshellarg( getenv( 'WPAC_TEST_DB_USER' ) ?: 'wpac' ) . ' -p' . escapeshellarg( getenv( 'WPAC_TEST_DB_PASS' ) ?: 'wpac' ) . ' ' . escapeshellarg( getenv( 'WPAC_TEST_DB_NAME' ) ?: 'wpac_test' ) . ' -e ' . escapeshellarg( $sql );
	exec( $cmd . ' > ' . escapeshellarg( $log_file ) . ' 2>&1 &' );
	usleep( 600000 );
	$res = PayoutService::cancel( (int) $pid2, 'neu' );
	sleep( 2 );
	$other_log = (string) @file_get_contents( $log_file );
	check( 'R13-K1 cancel next to a refund-like transaction (commission, then account): cancel succeeds', true, $res );
	check( 'R13-K1 cancel next to a refund-like transaction: no deadlock on the other side either', false, false !== stripos( $other_log, 'deadlock' ) );
	@unlink( $log_file );
}
check( 'R13-K1 accounts consistent after the cancellations', array(), Balance::verify_all() );

/* ---------------------------------------------------------------------------------------------
 * R13-M1: order lock and refunds the ledger does not know yet (WooCommerce stand-ins of r8-g3).
 * ------------------------------------------------------------------------------------------- */
class R13A1_Refund extends G3_Refund {
	public $parent = 0;
	public $created;
	public function get_parent_id() { return $this->parent; }
	public function get_date_created() { return new DateTimeImmutable( '@' . ( $this->created ? $this->created : time() ) ); }
}
$m1a  = AffiliateRepository::create( array( 'name' => 'R13A1 m1', 'email' => 'r13a1-m1@example.de', 'status' => 'active' ) ); // No payout profile (shared IBAN would put the orders under review).
$m1   = array( '_wpac_partner_code' => CodeRepository::main_code( $m1a ) );
$m1_lock = static function ( $order ) {
	return Jobs::inflight_lock( 'wpac_order_' . md5( 'woocommerce:' . $order ) );
};
$m1_free = static function ( $order ) use ( $o, $m1_lock ) {
	$r = $o->query( "SELECT GET_LOCK('" . $o->real_escape_string( $m1_lock( $order ) ) . "', 0) AS l" )->fetch_assoc();
	if ( 1 === (int) $r['l'] ) {
		$o->query( "SELECT RELEASE_LOCK('" . $o->real_escape_string( $m1_lock( $order ) ) . "')" );
		return true;
	}
	return false;
};
$m1_sum = static function ( $order ) use ( $wpdb, $r13ct ) {
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount_minor),0) FROM {$r13ct} WHERE source = 'woocommerce' AND source_order_id = %s AND status <> 'rejected'", (string) $order ) );
};
$m1_order = static function ( $id ) use ( $m1 ) {
	$o = new G3_Order( $id, array( 1 => new G3_Item( '100.00' ) ), '100.00', $m1 );
	$GLOBALS['za_orders'][ $id ] = $o;
	OrderSync::sync_order( $id );
	return $o;
};
$m1_refund = static function ( $o, $id, $amount ) {
	$r         = new R13A1_Refund( $id, $amount, array( new G3_Item( '-' . $amount, '0.00', array( '_refunded_item_id' => 1 ) ) ) );
	$r->parent = $o->id;
	$GLOBALS['za_orders'][ $id ] = $r;
	return $r;
};

/* Flow 2 of the report (t1, order 2002): WooCommerce stored the refund (gateway call running),
 * woocommerce_order_refunded has not fired yet, the admin saves the order items meanwhile. */
$o2002 = $m1_order( 1302002 );
check( 'R13-M1 sale booked (10 % of 100 €)', 1000, $m1_sum( 1302002 ) );
$rf = $m1_refund( $o2002, 1302078, '40.00' );
OrderSync::refund_creating( $rf ); // woocommerce_create_refund: this request holds the order now.
$o2002->refunds[] = $rf;           // Stored, gateway call running.
check( 'R13-M1 refund being created: another request cannot take the order lock', false, $m1_free( 1302002 ) );
OrderSync::items_changed( 1302002 ); // Same request (re-entrant) – e.g. a hook saving the items.
check( 'R13-M1 items saved while the refund is in flight: no "edit" deduction of the unbooked refund', 1000, $m1_sum( 1302002 ) );
OrderSync::sync_refund( 1302002, 1302078 ); // woocommerce_order_refunded.
check( 'R13-M1 refund booked once: 6 € (not 2 €)', 600, $m1_sum( 1302002 ) );
check( 'R13-M1 order lock released after woocommerce_order_refunded', true, $m1_free( 1302002 ) );
OrderSync::items_changed( 1302002 );
check( 'R13-M1 saving the items afterwards changes nothing', 600, $m1_sum( 1302002 ) );

/* The refund is in the order but was never reported (another request, outside the order lock). */
$o2003 = $m1_order( 1302003 );
$o2003->refunds[] = $m1_refund( $o2003, 1302079, '40.00' );
OrderSync::items_changed( 1302003 );
check( 'R13-M1 recent unreported refund: items_changed does not deduct it', 1000, $m1_sum( 1302003 ) );
OrderSync::sync_refund( 1302003, 1302079 );
check( 'R13-M1 recent unreported refund: deducted once by its own report', 600, $m1_sum( 1302003 ) );
/* An old refund that was never reported (refund created without WooCommerce's hooks) is deducted by an edit as before. */
$o2004 = $m1_order( 1302004 );
$old   = $m1_refund( $o2004, 1302080, '40.00' );
$old->created     = time() - 2 * DAY_IN_SECONDS;
$o2004->refunds[] = $old;
OrderSync::items_changed( 1302004 );
check( 'R13-M1 old unreported refund: still deducted by saving the items', 600, $m1_sum( 1302004 ) );

/* Gateway refused the refund: WooCommerce deletes it without woocommerce_order_refunded. */
$o2005 = $m1_order( 1302005 );
$rf    = $m1_refund( $o2005, 1302081, '10.00' );
OrderSync::refund_creating( $rf );
check( 'R13-M1 refund being created: order locked', false, $m1_free( 1302005 ) );
OrderSync::refund_creation_ended(); // woocommerce_delete_order_refund / shutdown.
check( 'R13-M1 refund deleted again: order lock released', true, $m1_free( 1302005 ) );

/* Flow 1 (t1, order 2001): while the edit runs (payload read, booking not done), a refund of
 * another request has to wait for the order lock instead of booking in between. */
$o2001   = $m1_order( 1302001 );
$o2001->items[1] = new G3_Item( '120.00' );
$o2001->total    = '120.00';
$seen_locked     = null;
$wpdb->before_query = static function ( $sql ) use ( &$seen_locked, $m1_free, $r13ct ) {
	if ( null === $seen_locked && false !== strpos( $sql, "FROM {$r13ct} WHERE id = " ) && false !== strpos( $sql, 'FOR UPDATE' ) ) {
		$seen_locked = ! $m1_free( 1302001 ); // The parallel sync_refund would wait here.
	}
};
OrderSync::items_changed( 1302001 );
$wpdb->before_query = null;
check( 'R13-M1 edit: a parallel refund cannot book between reading the order and booking', true, $seen_locked );
check( 'R13-M1 edit: increase booked (12 €)', 1200, $m1_sum( 1302001 ) );
check( 'R13-M1 edit: order lock released afterwards', true, $m1_free( 1302001 ) );

/* Locked by another request for longer than the wait: the edit is not checked, the order says so. */
$o->query( "SELECT GET_LOCK('" . $o->real_escape_string( $m1_lock( 1302001 ) ) . "', 0)" );
check( 'R13-M1 lock held by another request: not acquired within the wait', false, Jobs::lock_order( 'woocommerce', '1302001', 1 ) );
$o->query( "SELECT RELEASE_LOCK('" . $o->real_escape_string( $m1_lock( 1302001 ) ) . "')" );
// Wait up to 5 s for the release by the other connection above (seen racing under load); the nested call is re-entrant.
check( 'R13-M1 re-entrant order lock (nested calls in one request)', array( true, true ), array( Jobs::lock_order( 'woocommerce', '1302001', 5 ), Jobs::lock_order( 'woocommerce', '1302001', 0 ) ) );
Jobs::unlock_order( 'woocommerce', '1302001' );
check( 'R13-M1 still held after the inner release', false, $m1_free( 1302001 ) );
Jobs::unlock_order( 'woocommerce', '1302001' );
check( 'R13-M1 released after the outer release', true, $m1_free( 1302001 ) );
check( 'R13-M1 hooks registered (refund creation, deletion)', array( true, true ), array(
	false !== strpos( (string) file_get_contents( WPAC_DIR . 'src/Integrations/WooCommerce.php' ), "add_action( 'woocommerce_create_refund', array( OrderSync::class, 'refund_creating' ), 10, 1 );" ),
	false !== strpos( (string) file_get_contents( WPAC_DIR . 'src/Integrations/WooCommerce.php' ), "add_action( 'woocommerce_delete_order_refund', array( OrderSync::class, 'refund_creation_ended' ), 10, 0 );" ),
) );

/* ---------------------------------------------------------------------------------------------
 * R13-N9: PDF lock busy, cancel during generation.
 * ------------------------------------------------------------------------------------------- */
$n9a = r13a1_partner( 'n9' );
r13a1_sale( $n9a, 'r13n9-1', array( 500000 ) );
$async = new ReflectionProperty( PayoutService::class, 'async_statements' );
$async->setAccessible( true );
$async->setValue( null, true );
$pid = PayoutService::create_for_affiliate( $n9a, gmdate( 'Y-m-d' ), 'EUR' );
$async->setValue( null, false );
check( 'R13-N9 payout of a run: credit note left to the job queue', '', (string) PayoutService::find( $pid )->statement_file );
$other = Db::lock( 'wpac_stmt_' . $pid, 600 ); // The job queue is writing it right now.
add_filter( 'wpac_statement_lock_wait', static function () { return 1; } );
$res = PayoutService::mark_paid( $pid );
check( 'R13-N9 busy credit note: "being generated", not "folder not writable"', 'wpac_statement_busy', is_wp_error( $res ) ? $res->get_error_code() : 'ok' );
check( 'R13-N9 busy credit note: no write failure / back-off recorded', false, isset( get_option( Statement::RETRY_OPTION, array() )[ $pid ] ) );
/* The other request finishes while we wait: the caller goes on without an error. */
$tries              = 0;
$wpdb->before_query = static function ( $sql ) use ( $wpdb, $pid, &$tries ) {
	if ( false !== strpos( $sql, "'wpac_stmt_{$pid}'" ) && 0 === strpos( $sql, 'INSERT IGNORE' ) && ++$tries === 3 ) {
		$wpdb->db->query( "DELETE FROM {$wpdb->options} WHERE option_name = 'wpac_stmt_{$pid}'" );
	}
};
remove_all_filters( 'wpac_statement_lock_wait' );
add_filter( 'wpac_statement_lock_wait', static function () { return 5; } );
check( 'R13-N9 lock released while waiting: statement written, no error', null, PayoutService::statement_missing( $pid ) );
$wpdb->before_query = null;
check( 'R13-N9 waited for the lock (several attempts)', true, $tries >= 3 && '' !== (string) PayoutService::find( $pid )->statement_file );

/* Cancel while the job queue writes the credit note: no hour of back-off for a busy lock. */
r13a1_sale( $n9a, 'r13n9-2', array( 500000 ) );
$async->setValue( null, true );
$pid = PayoutService::create_for_affiliate( $n9a, gmdate( 'Y-m-d' ), 'EUR' );
$async->setValue( null, false );
$other = Db::lock( 'wpac_stmt_' . $pid, 600 );
remove_all_filters( 'wpac_statement_lock_wait' );
add_filter( 'wpac_statement_lock_wait', static function () { return 1; } );
$res = PayoutService::cancel( $pid, 'falscher Betrag' );
check( 'R13-N9 cancel during the generation of the credit note succeeds', true, $res );
check( 'R13-N9 busy lock: no back-off for the cancellation document', false, isset( get_option( Statement::STORNO_RETRY_OPTION, array() )[ $pid ] ) );
Db::unlock( 'wpac_stmt_' . $pid, $other );
Statement::generate_missing_stornos();
$p = PayoutService::find( $pid );
check( 'R13-N9 next cron run writes credit note and cancellation document', array( true, true ), array( '' !== (string) $p->statement_file, '' !== (string) $p->storno_file ) );
remove_all_filters( 'wpac_statement_lock_wait' );

/* ---------------------------------------------------------------------------------------------
 * R13-N9: payout request "execute" vs "reject".
 * ------------------------------------------------------------------------------------------- */
$n9r = r13a1_partner( 'n9r' );
r13a1_sale( $n9r, 'r13n9-3', array( 500000 ) );
$rq = t( 'payout_requests' );
$wpdb->insert( $rq, array( 'affiliate_id' => $n9r, 'currency' => 'EUR', 'amount_minor' => 50000, 'status' => 'requested', 'created_at' => Db::now() ) );
$req = (int) $wpdb->insert_id;
/* process() read the request as open, then the other request rejected it (committed): no payout. */
$before              = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . t( 'payouts' ) . " WHERE affiliate_id = {$n9r}" );
$wpdb->before_query  = static function ( $sql ) use ( $o, $rq, $req ) {
	static $done = false;
	if ( ! $done && false !== strpos( $sql, "FROM {$rq} WHERE id = {$req} FOR UPDATE" ) ) {
		$done = true;
		$o->query( "UPDATE {$rq} SET status = 'rejected', note = 'nein' WHERE id = {$req} AND status = 'requested'" ); // B rejects before the lock.
	}
};
$res = PayoutRequests::process( $req );
$wpdb->before_query = null;
check( 'R13-N9 request rejected meanwhile: execute refuses', 'wpac_state', is_wp_error( $res ) ? $res->get_error_code() : 'payout ' . $res );
check( 'R13-N9 request rejected meanwhile: no payout created', $before, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . t( 'payouts' ) . " WHERE affiliate_id = {$n9r}" ) );
/* Execute first: the reject of the other request waits for the row lock and then finds it processed. */
$wpdb->query( "UPDATE {$rq} SET status = 'requested', note = '' WHERE id = {$req}" );
$sent               = false;
$wpdb->before_query = static function ( $sql ) use ( $o, $rq, $req, &$sent ) {
	if ( ! $sent && 0 === strpos( $sql, 'INSERT INTO `' . t( 'payouts' ) . '`' ) ) {
		$sent = true;
		$o->query( "UPDATE {$rq} SET status = 'rejected', note = 'nein' WHERE id = {$req} AND status = 'requested'", MYSQLI_ASYNC );
		usleep( 200000 );
	}
};
$res = PayoutRequests::process( $req );
$wpdb->before_query = null;
$o->reap_async_query();
check( 'R13-N9 execute: payout created', true, is_int( $res ) && $res > 0 );
check( 'R13-N9 parallel reject waited and changed nothing', 0, $sent ? (int) $o->affected_rows : -1 );
check( 'R13-N9 request processed by the payout, not rejected', array( 'processed', (int) $res ), array( (string) PayoutRequests::find( $req )->status, (int) PayoutRequests::find( $req )->payout_id ) );

/* ---------------------------------------------------------------------------------------------
 * R13-N9: Jobs::run() owner check, enqueue() of a running job.
 * ------------------------------------------------------------------------------------------- */
$jt = t( 'jobs' );
$GLOBALS['r13a1_job'] = null;
add_filter(
	'wpac_run_job',
	static function ( $handled, $type, $payload ) use ( $wpdb, $jt ) {
		if ( 'r13a1' !== $type ) {
			return $handled;
		}
		$GLOBALS['r13a1_job'] = call_user_func( $GLOBALS['r13a1_during'], $payload );
		return true;
	},
	10,
	3
);
/* Lease expired during the run, another runner claimed the job: the first run must not close it. */
Jobs::enqueue( 'r13a1', 'lease', array( 'n' => 1 ), '', '', 0 );
$jid = (int) $wpdb->get_var( "SELECT id FROM {$jt} WHERE idempotency_key = 'r13a1:lease'" );
$GLOBALS['r13a1_during'] = static function () use ( $wpdb, $jt, $jid ) {
	$wpdb->query( "UPDATE {$jt} SET status = 'queued' WHERE id = {$jid}" ); // run_due(): lease expired.
	$wpdb->query( "UPDATE {$jt} SET status = 'running', attempts = attempts + 1 WHERE id = {$jid}" ); // Runner 2 claimed it.
	return null;
};
Jobs::run( $jid );
check( 'R13-N9 run after the lease expired: the job of the second runner stays running', 'running', (string) Jobs::find( $jid )->status );
/* enqueue() while the job runs: status and lease are kept, the new payload makes it run once more. */
Jobs::enqueue( 'r13a1', 'requeue', array( 'n' => 1 ), '', '', 0 );
$jid = (int) $wpdb->get_var( "SELECT id FROM {$jt} WHERE idempotency_key = 'r13a1:requeue'" );
$GLOBALS['r13a1_during'] = static function () use ( $jid ) {
	Jobs::enqueue( 'r13a1', 'requeue', array( 'n' => 2 ), 'x', 'changed', 300 );
	return (string) Jobs::find( $jid )->status;
};
Jobs::run( $jid );
$j = Jobs::find( $jid );
check( 'R13-N9 enqueue() of a running job keeps it running', 'running', $GLOBALS['r13a1_job'] );
check( 'R13-N9 changed payload during the run: queued again (not done) with the new payload', array( 'queued', array( 'n' => 2 ) ), array( (string) $j->status, json_decode( (string) $j->payload, true ) ) );
check( 'R13-N9 changed payload: runs again at once', true, strtotime( $j->next_attempt_at . ' UTC' ) <= time() );
$GLOBALS['r13a1_during'] = static function () { return 'second'; };
check( 'R13-N9 second run closes the job', array( true, 'done' ), array( Jobs::run( $jid ), (string) Jobs::find( $jid )->status ) );
/* Unchanged: done as before. */
Jobs::enqueue( 'r13a1', 'plain', array( 'n' => 1 ), '', '', 0 );
$jid = (int) $wpdb->get_var( "SELECT id FROM {$jt} WHERE idempotency_key = 'r13a1:plain'" );
check( 'R13-N9 a normal run closes the job', array( true, 'done' ), array( Jobs::run( $jid ), (string) Jobs::find( $jid )->status ) );

$o->close();
check( 'R13 a1 accounts consistent', array(), Balance::verify_all() );
