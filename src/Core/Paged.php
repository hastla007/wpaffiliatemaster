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
	 * @param array    $args     limit (0 = all), offset, orderby, order; count (false: no
	 *                           count; 'cached': an unfiltered count – no placeholders – comes
	 *                           from cached_total(), filtered counts stay exact); ties (orderby => further
	 *                           columns sorted in the same direction, from the whitelist) and
	 *                           tie_same (true: the ID tie-breaker follows the direction), so a
	 *                           sort can run along an index instead of a filesort.
	 * @param string[] $sortable Columns allowed for orderby (whitelist).
	 * @return array [ rows, total ]
	 */
	public static function fetch( $table, $where, array $params, array $args, array $sortable = array( 'id' ) ) {
		global $wpdb;
		$orderby = isset( $args['orderby'] ) && in_array( $args['orderby'], $sortable, true ) ? $args['orderby'] : 'id';
		$order   = isset( $args['order'] ) && 'asc' === strtolower( (string) $args['order'] ) ? 'ASC' : 'DESC';
		if ( ! empty( $args['before_id'] ) ) {
			// Keyset paging (each()): next batch below the last ID, no OFFSET scan.
			$where   .= ' AND id < %d';
			$params[] = (int) $args['before_id'];
			$orderby  = 'id';
			$order    = 'DESC';
		}
		$total = -1;
		if ( isset( $args['count'] ) && 'cached' === $args['count'] && ! $params ) {
			// Unfiltered list (no placeholders): the count of the whole table is cached briefly.
			$total = self::cached_total( $table, $where, $params, 'wpac_list_totals' );
		} elseif ( ! isset( $args['count'] ) || false !== $args['count'] ) {
			$count = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$total = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count, $params ) ) : $wpdb->get_var( $count ) );
		}
		$limit = isset( $args['limit'] ) ? (int) $args['limit'] : 50;
		$by    = "{$orderby} {$order}";
		if ( 'id' !== $orderby ) {
			$ties = isset( $args['ties'][ $orderby ] ) ? (array) $args['ties'][ $orderby ] : array();
			foreach ( $ties as $tie ) {
				if ( in_array( $tie, $sortable, true ) || preg_match( '/^[a-z_]+$/', (string) $tie ) ) {
					$by .= ', ' . $tie . ' ' . $order;
				}
			}
			$by .= ', id ' . ( empty( $args['tie_same'] ) ? 'DESC' : $order );
		}
		$sql   = "SELECT * FROM {$table} WHERE {$where} ORDER BY {$by}";
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
	 * Number of rows for a filter, cached for a few minutes (COUNT(*) scans the whole table on
	 * large tables). The newest ID is part of the key, so a new row counts at once; only
	 * deletions of old rows show up late. One transient holds the last few counts.
	 *
	 * @param string $table     Full table name (with an auto-increment "id").
	 * @param string $where     WHERE clause with placeholders.
	 * @param array  $params    Placeholder values.
	 * @param string $transient Transient that holds the counts.
	 * @return int
	 */
	public static function cached_total( $table, $where, array $params, $transient ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$max   = (int) $wpdb->get_var( "SELECT MAX(id) FROM {$table}" );
		$key   = md5( $table . '|' . $where . '|' . wp_json_encode( $params ) . '|' . $max );
		$cache = get_transient( $transient );
		$cache = is_array( $cache ) ? $cache : array();
		if ( isset( $cache[ $key ] ) ) {
			return (int) $cache[ $key ];
		}
		$count = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$total = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count, $params ) ) : $wpdb->get_var( $count ) );
		$cache = array_slice( array( $key => $total ) + $cache, 0, 10, true );
		set_transient( $transient, $cache, 5 * MINUTE_IN_SECONDS );
		return $total;
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
