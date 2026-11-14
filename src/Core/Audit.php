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
	 * @param array $args object_type, object_id, limit, offset.
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
		$params[] = isset( $args['limit'] ) ? (int) $args['limit'] : 50;
		$params[] = isset( $args['offset'] ) ? (int) $args['offset'] : 0;
		// $where holds only placeholders (built above); all values are in $params.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d", $params ) );
	}
}
