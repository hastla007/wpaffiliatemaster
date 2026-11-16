<?php
/**
 * Audit log. Stores who did what – never full sensitive payloads.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Core;

defined( 'ABSPATH' ) || exit;

final class Audit {

	/** Keys that are never written into the audit log. */
	const REDACT = array( 'iban', 'iban_encrypted', 'password', 'email', 'billing_email', 'customer_email', 'address' );

	/**
	 * Action prefixes of the change log of booking-relevant data (commissions, payouts,
	 * statements, payout details and requests, balances, tiers): kept as long as the statements
	 * (GoBD) by Cron::purge_audit() and not emptied by a partner erasure within that period.
	 */
	const FINANCIAL = array( 'payout.', 'statement.', 'payout_profile.', 'payout_request.', 'commission.', 'balance.', 'tier.' );

	/**
	 * SQL condition "the entry is booking-relevant" (see FINANCIAL), for use inside
	 * $wpdb->prepare() (the LIKE wildcards are escaped as %%). Constant text, no input.
	 *
	 * @return string
	 */
	public static function financial_sql() {
		$like = array();
		foreach ( self::FINANCIAL as $prefix ) {
			$like[] = "action LIKE '" . $prefix . "%%'";
		}
		return '( ' . implode( ' OR ', $like ) . ' )';
	}

	/**
	 * Write an audit entry.
	 *
	 * @param string $action      Action slug, e.g. affiliate.approved.
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 * @param array  $details     Small context array.
	 */
	public static function log( $action, $object_type = '', $object_id = 0, array $details = array() ) {
		global $wpdb;
		foreach ( self::REDACT as $key ) {
			if ( array_key_exists( $key, $details ) ) {
				$details[ $key ] = '[redacted]';
			}
		}
		// Order events in one form (object "order", the order number as ID, details "order"), so
		// the log can be searched by order: some callers passed 0 and the number as "order_id".
		if ( isset( $details['order_id'] ) && ! isset( $details['order'] ) ) {
			$details['order'] = (string) $details['order_id'];
			unset( $details['order_id'] );
		}
		if ( 'order' === $object_type && ! (int) $object_id && isset( $details['order'] ) && ctype_digit( (string) $details['order'] ) ) {
			$object_id = (int) $details['order'];
		}
		$wpdb->insert(
			Db::table( 'audit' ),
			array(
				'actor_id'    => get_current_user_id(),
				'action'      => Db::cut( (string) $action, 60 ),
				'object_type' => Db::cut( (string) $object_type, 40 ),
				'object_id'   => (int) $object_id,
				'details'     => $details ? wp_json_encode( $details ) : null,
				'created_at'  => Db::now(),
			),
			array( '%d', '%s', '%s', '%d', '%s', '%s' )
		);
	}

	/**
	 * Latest entries.
	 *
	 * @param array $args object_type, object_id, action (exact, or an area ending in "."),
	 *                    from/to (Y-m-d, UTC), order (order number), limit, offset.
	 * @return array
	 */
	public static function query( array $args = array() ) {
		global $wpdb;
		$table                  = Db::table( 'audit' );
		list( $where, $params ) = self::where( $args );
		$params[]               = isset( $args['limit'] ) ? (int) $args['limit'] : 50;
		$params[]               = isset( $args['offset'] ) ? (int) $args['offset'] : 0;
		// $where holds only placeholders (built in where()); all values are in $params.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d", $params ) );
	}

	/**
	 * Columns the admin list may sort by (whitelist for ORDER BY).
	 *
	 * @return string[]
	 */
	public static function sortable() {
		return array( 'id', 'created_at', 'action', 'object_type' );
	}

	/**
	 * One page of entries with the total, for the admin list.
	 *
	 * @param array $args Filters of query() ("order" stays the order number), plus search (action,
	 *                    details, object ID), orderby (see sortable(); anything else sorts by ID),
	 *                    sort (asc|desc), limit, offset.
	 * @return array [ rows, total ]
	 */
	public static function page( array $args = array() ) {
		list( $where, $params ) = self::where( $args );
		$paging                 = array(
			'orderby'  => isset( $args['orderby'] ) ? (string) $args['orderby'] : 'id',
			'order'    => isset( $args['sort'] ) ? (string) $args['sort'] : 'desc',
			'limit'    => isset( $args['limit'] ) ? (int) $args['limit'] : 50,
			'offset'   => isset( $args['offset'] ) ? (int) $args['offset'] : 0,
			// Sorts along the indexes (action,created_at) and (object_type,object_id) – with the
			// ID in the same direction – instead of a filesort over the whole table.
			'ties'     => array(
				'action'      => array( 'created_at' ),
				'object_type' => array( 'object_id' ),
			),
			'tie_same' => true,
			'count'    => false,
		);
		list( $rows ) = Paged::fetch( Db::table( 'audit' ), $where, $params, $paging, self::sortable() );
		return array( $rows, self::total( $where, $params ) );
	}

	/**
	 * Number of entries for a filter, cached for a few minutes (COUNT(*) scans the whole table
	 * on large logs). The newest ID is part of the key, so a new entry counts at once; only
	 * the clean-up of old entries shows up late. One transient holds the last few counts.
	 *
	 * @param string $where  WHERE clause with placeholders.
	 * @param array  $params Placeholder values.
	 * @return int
	 */
	private static function total( $where, array $params ) {
		return Paged::cached_total( Db::table( 'audit' ), $where, $params, 'wpac_audit_totals' );
	}

	/**
	 * IDs of the entries about one order, each from an indexed query: order events (object
	 * "order" with the number as ID), events of its commissions (commissions.source_order_id),
	 * and – searched in the details only among the few rows that can carry it – order events
	 * with a non-numeric number and partner flags of the order.
	 *
	 * @param string $order Order number.
	 * @return int[]
	 */
	private static function order_ids( $order ) {
		global $wpdb;
		$table = Db::table( 'audit' );
		$cap   = 2000;
		$ids   = array();
		$like  = '%' . $wpdb->esc_like( '"order":"' . $order . '"' ) . '%';
		$older = '%' . $wpdb->esc_like( '"order_id":"' . $order . '"' ) . '%'; // Older entries.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		if ( ctype_digit( $order ) ) {
			$ids = array_merge( $ids, (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE object_type = 'order' AND object_id = %d LIMIT %d", (int) $order, $cap ) ) );
		}
		$com = Db::table( 'commissions' );
		$ids = array_merge( $ids, (array) $wpdb->get_col( $wpdb->prepare( "SELECT a.id FROM {$table} a INNER JOIN {$com} c ON a.object_type = 'commission' AND a.object_id = c.id WHERE c.source_order_id = %s LIMIT %d", $order, $cap ) ) );
		$ids = array_merge( $ids, (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE object_type = 'order' AND object_id = 0 AND ( details LIKE %s OR details LIKE %s ) LIMIT %d", $like, $older, $cap ) ) );
		// Actions about another object that carry the order number in their details (index
		// action_created): partner flags of the order, a refund after a correction credit note.
		foreach ( self::order_detail_actions() as $action ) {
			$ids = array_merge( $ids, (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE action = %s AND ( details LIKE %s OR details LIKE %s ) LIMIT %d", $action, $like, $older, $cap ) ) );
		}
		// phpcs:enable
		return array_values( array_unique( array_map( 'intval', $ids ) ) );
	}

	/**
	 * Actions logged on another object (partner, payout) whose details name the order.
	 *
	 * @return string[]
	 */
	private static function order_detail_actions() {
		return array( 'attribution.flagged', 'payout.refund_after_correction' );
	}

	/**
	 * Days the free-text search covers when no "from" date is set (the LIKE over the details
	 * cannot use an index; without a bound it scanned millions of rows, R13-N12).
	 *
	 * @return int 0 = the whole log.
	 */
	public static function search_days() {
		/**
		 * Days the audit log search covers when no start date is given.
		 *
		 * @since 0.13.0
		 * @param int $days Default 90; 0 = the whole log.
		 */
		return max( 0, (int) apply_filters( 'wpac_audit_search_days', 90 ) );
	}

	/**
	 * WHERE clause (placeholders only) and its values for the filters.
	 *
	 * @param array $args See query() and page().
	 * @return array [ where, params ]
	 */
	private static function where( array $args ) {
		global $wpdb;
		$where  = '1=1';
		$params = array();
		if ( ! empty( $args['object_type'] ) ) {
			$where   .= ' AND object_type = %s';
			$params[] = $args['object_type'];
		}
		if ( ! empty( $args['object_id'] ) ) {
			$where   .= ' AND object_id = %d';
			$params[] = (int) $args['object_id'];
		}
		if ( ! empty( $args['action'] ) ) {
			$action = (string) $args['action'];
			if ( '.' === substr( $action, -1 ) ) {
				$where   .= ' AND action LIKE %s'; // Whole area, e.g. "commission.".
				$params[] = $wpdb->esc_like( $action ) . '%';
			} else {
				$where   .= ' AND action = %s';
				$params[] = $action;
			}
		}
		if ( ! empty( $args['from'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $args['from'] ) ) {
			$where   .= ' AND created_at >= %s';
			$params[] = $args['from'] . ' 00:00:00';
		}
		if ( ! empty( $args['to'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $args['to'] ) ) {
			$where   .= ' AND created_at <= %s';
			$params[] = $args['to'] . ' 23:59:59';
		}
		if ( isset( $args['order'] ) && '' !== (string) $args['order'] ) {
			// Everything about one order: order events, and events of its commissions.
			// The IDs come from indexed queries; a LIKE over all details took seconds on big logs.
			$ids = self::order_ids( Db::cut( (string) $args['order'], 64 ) );
			if ( $ids ) {
				$where .= ' AND id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')';
				$params = array_merge( $params, $ids );
			} else {
				$where .= ' AND 1=0';
			}
		}
		$search = isset( $args['search'] ) ? trim( (string) $args['search'] ) : '';
		if ( '' !== $search ) {
			// Free text in the action key and the details; a number also finds the object ID.
			$days = self::search_days();
			if ( $days > 0 && empty( $args['from'] ) && empty( $args['order'] ) && empty( $args['object_id'] ) ) {
				$where   .= ' AND created_at >= %s'; // Index created_at bounds the scan.
				$params[] = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
			}
			$like     = '%' . $wpdb->esc_like( Db::cut( $search, 100 ) ) . '%';
			$where   .= ' AND ( action LIKE %s OR details LIKE %s' . ( ctype_digit( $search ) ? ' OR object_id = %d' : '' ) . ' )';
			$params[] = $like;
			$params[] = $like;
			if ( ctype_digit( $search ) ) {
				$params[] = (int) $search;
			}
		}
		return array( $where, $params );
	}
}
