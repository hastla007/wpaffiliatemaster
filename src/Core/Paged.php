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
	 * @param string   $default  ORDER BY used when no allowed orderby is requested (fixed SQL from the
	 *                           calling code, never from the request); '' = id DESC.
	 * @return array [ rows, total ]
	 */
	public static function fetch( $table, $where, array $params, array $args, array $sortable = array( 'id' ), $default = '' ) {
		global $wpdb;
		$allowed = isset( $args['orderby'] ) && in_array( $args['orderby'], $sortable, true );
		$orderby = $allowed ? $args['orderby'] : 'id';
		$order   = isset( $args['order'] ) && 'asc' === strtolower( (string) $args['order'] ) ? 'ASC' : 'DESC';
		if ( ! empty( $args['before_id'] ) ) {
			// Keyset paging (each()): next batch below the last ID, no OFFSET scan.
			$where   .= ' AND id < %d';
			$params[] = (int) $args['before_id'];
			$orderby  = 'id';
			$order    = 'DESC';
		}
		$total = -1;
		if ( ! isset( $args['count'] ) || false !== $args['count'] ) {
			$count = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$total = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count, $params ) ) : $wpdb->get_var( $count ) );
		}
		$limit = isset( $args['limit'] ) ? (int) $args['limit'] : 50;
		$sort  = "{$orderby} {$order}" . ( 'id' !== $orderby ? ", id {$order}" : '' ); // Ties in the same direction.
		if ( ! $allowed && '' !== (string) $default && empty( $args['before_id'] ) ) {
			$sort = (string) $default;
		}
		$sql   = "SELECT * FROM {$table} WHERE {$where} ORDER BY {$sort}";
		if ( $limit > 0 ) {
			$sql     .= ' LIMIT %d OFFSET %d';
			$params[] = $limit;
			$params[] = isset( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $params ? $wpdb->get_results( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_results( $sql );
		return array( $rows, $total );
	}

	/**
	 * All rows of a list query in ID batches (newest first), for streamed exports: only one
	 * batch is in memory, no COUNT and no OFFSET per batch.
	 *
	 * @param callable $query Repository query (e.g. Ledger::query): args => [ rows, total ].
	 * @param array    $args  Filters of that query.
	 * @param int      $batch Rows per batch.
	 * @return \Generator Rows.
	 */
	public static function each( callable $query, array $args = array(), $batch = 500 ) {
		$before = 0;
		do {
			list( $rows ) = call_user_func(
				$query,
				array_merge(
					$args,
					array(
						'limit'     => max( 1, (int) $batch ),
						'offset'    => 0,
						'before_id' => $before,
						'count'     => false,
						'orderby'   => 'id',
						'order'     => 'desc',
					)
				)
			);
			$rows = (array) $rows;
			foreach ( $rows as $row ) {
				$before = (int) $row->id;
				yield $row;
			}
		} while ( count( $rows ) >= $batch && $before > 1 );
	}
}
