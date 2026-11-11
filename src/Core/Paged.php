<?php
/**
 * Paged list queries (count + page) for repository list methods.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Core;

defined( 'ABSPATH' ) || exit;

final class Paged {

	/**
	 * Run a paged query.
	 *
	 * @param string   $table    Full table name.
	 * @param string   $where    WHERE clause with placeholders.
	 * @param array    $params   Placeholder values.
	 * @param array    $args     limit (0 = all), offset, orderby, order.
	 * @param string[] $sortable Columns allowed for orderby (whitelist).
	 * @return array [ rows, total ]
	 */
	public static function fetch( $table, $where, array $params, array $args, array $sortable = array( 'id' ) ) {
		global $wpdb;
		$orderby = isset( $args['orderby'] ) && in_array( $args['orderby'], $sortable, true ) ? $args['orderby'] : 'id';
		$order   = isset( $args['order'] ) && 'asc' === strtolower( (string) $args['order'] ) ? 'ASC' : 'DESC';
		$count   = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$total = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count, $params ) ) : $wpdb->get_var( $count ) );
		$limit = isset( $args['limit'] ) ? (int) $args['limit'] : 50;
		$sql   = "SELECT * FROM {$table} WHERE {$where} ORDER BY {$orderby} {$order}" . ( 'id' !== $orderby ? ', id DESC' : '' );
		if ( $limit > 0 ) {
			$sql     .= ' LIMIT %d OFFSET %d';
			$params[] = $limit;
			$params[] = isset( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $params ? $wpdb->get_results( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_results( $sql );
		return array( $rows, $total );
	}
}
