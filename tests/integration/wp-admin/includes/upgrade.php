<?php
/**
 * Test stub: dbDelta() creates missing tables and, like WordPress, adds missing columns and
 * indexes to existing ones (so upgrades from an older schema can be tested). Existing column
 * definitions are not changed. $GLOBALS['test_dbdelta_create_only'] = true simulates a dbDelta
 * that leaves existing tables unchanged (incomplete migration).
 *
 * @param string $sql CREATE TABLE statement.
 */
function dbDelta( $sql ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName
	global $wpdb;
	if ( ! empty( $GLOBALS['test_dbdelta_create_only'] ) || ! preg_match( '/CREATE TABLE\s+`?([^\s`(]+)`?\s*\((.*)\)[^)]*$/is', $sql, $m ) || ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $m[1] ) ) ) {
		$wpdb->query( $sql );
		return;
	}
	$table   = $m[1];
	$columns = array_map( 'strtolower', (array) $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`" ) );
	$indexes = array_map( 'strtolower', (array) $wpdb->get_col( "SHOW INDEX FROM `{$table}`", 2 ) );
	foreach ( preg_split( '/,\s*\n/', trim( $m[2] ) ) as $line ) {
		$line = trim( $line );
		if ( preg_match( '/^PRIMARY\s+KEY/i', $line ) ) {
			continue;
		}
		if ( preg_match( '/^(?:UNIQUE\s+|FULLTEXT\s+)?(?:KEY|INDEX)\s+`?(\w+)`?/i', $line, $k ) ) {
			if ( ! in_array( strtolower( $k[1] ), $indexes, true ) ) {
				$wpdb->query( "ALTER TABLE `{$table}` ADD {$line}" );
			}
			continue;
		}
		if ( preg_match( '/^`?(\w+)`?\s/', $line, $c ) && ! in_array( strtolower( $c[1] ), $columns, true ) ) {
			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN {$line}" );
		}
	}
}
