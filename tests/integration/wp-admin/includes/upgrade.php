<?php
/**
 * Test stub: dbDelta() creates tables directly (fresh database per run).
 *
 * @param string $sql CREATE TABLE statement.
 */
function dbDelta( $sql ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName
	global $wpdb;
	$wpdb->query( $sql );
}
