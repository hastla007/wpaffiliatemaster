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
		$table  = Db::table( 'audit' );
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
			$order    = Db::cut( (string) $args['order'], 64 );
			$like     = '%' . $wpdb->esc_like( '"order":"' . $order . '"' ) . '%';
			$older    = '%' . $wpdb->esc_like( '"order_id":"' . $order . '"' ) . '%'; // Older entries.
			$where   .= " AND ( ( object_type = 'order' AND object_id = %d ) OR details LIKE %s OR details LIKE %s OR ( object_type = 'commission' AND object_id IN ( SELECT id FROM " . Db::table( 'commissions' ) . ' WHERE source_order_id = %s ) ) )';
			$params[] = ctype_digit( $order ) ? (int) $order : -1;
			$params[] = $like;
			$params[] = $older;
			$params[] = $order;
		}
		$params[] = isset( $args['limit'] ) ? (int) $args['limit'] : 50;
		$params[] = isset( $args['offset'] ) ? (int) $args['offset'] : 0;
		// $where holds only placeholders (built above); all values are in $params.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d", $params ) );
	}
}
