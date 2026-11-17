<?php
/**
 * MariaDB 11.6.2+ (12.3): innodb_snapshot_isolation is ON by default and turns "read, then lock"
 * into error 1020 with a rollback of the whole transaction. Db::transaction() switches it off
 * for the session, and error 1020 counts as an abort (retry) instead of a silent partial write.
 *
 * @package WPAffiliateCaptain
 */

use WPAffiliateCaptain\Core\Db;

echo "R17 MariaDB snapshot isolation\n";

global $wpdb;
$s17_info = (string) $wpdb->db_server_info();
$s17_has  = null !== $wpdb->db->query( "SHOW VARIABLES LIKE 'innodb_snapshot_isolation'" )->fetch_row();
if ( false !== stripos( $s17_info, 'mariadb' ) && $s17_has ) {
	$wpdb->db->query( 'SET SESSION innodb_snapshot_isolation = ON' );
	$s17_reflect = new ReflectionProperty( Db::class, 'isolation_dbh' );
	$s17_reflect->setValue( null, null );
	$s17_in = Db::transaction(
		static function () use ( $wpdb ) {
			return (string) $wpdb->db->query( 'SELECT @@SESSION.innodb_snapshot_isolation' )->fetch_row()[0];
		}
	);
	check( 'R17 MariaDB: Db::transaction() switches innodb_snapshot_isolation off for the session', '0', $s17_in );
} else {
	echo "  (no MariaDB with innodb_snapshot_isolation: switch not testable here)\n";
}

$s17_err          = $wpdb->last_error;
$wpdb->last_error = 'Record has changed since last read in table \'wp_wpac_commissions\'';
check( 'R17 error 1020 "Record has changed since last read" counts as an aborted transaction', true, '' !== Db::fatal_error() );
$wpdb->last_error = $s17_err;
check( 'R17 1020 is in Db::FATAL_ERRNO', true, in_array( 1020, Db::FATAL_ERRNO, true ) );
