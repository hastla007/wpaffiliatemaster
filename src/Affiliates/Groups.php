<?php
/**
 * Partner groups (e.g. "training partner", "content partner").
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Affiliates;

use WPAffiliateCaptain\Core\Audit;
use WPAffiliateCaptain\Core\Db;

defined( 'ABSPATH' ) || exit;

final class Groups {

	/**
	 * All groups.
	 *
	 * @return array
	 */
	public static function all() {
		global $wpdb;
		$t = Db::table( 'partner_groups' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( "SELECT * FROM {$t} ORDER BY name ASC" );
	}

	/**
	 * id => name.
	 *
	 * @return array
	 */
	public static function options() {
		$out = array();
		foreach ( self::all() as $g ) {
			$out[ (int) $g->id ] = $g->name;
		}
		return $out;
	}

	/**
	 * Create a group.
	 *
	 * @param string $name              Name.
	 * @param string $description       Description.
	 * @param string $agreement_version Optional group specific terms version.
	 * @return int
	 */
	public static function create( $name, $description = '', $agreement_version = '' ) {
		global $wpdb;
		$wpdb->insert(
			Db::table( 'partner_groups' ),
			array(
				'name'              => sanitize_text_field( $name ),
				'description'       => sanitize_textarea_field( $description ),
				'agreement_version' => sanitize_text_field( $agreement_version ),
				'status'            => 'active',
				'created_at'        => Db::now(),
			)
		);
		$id = (int) $wpdb->insert_id;
		Audit::log( 'group.created', 'group', $id );
		return $id;
	}

	/**
	 * Delete a group (affiliates fall back to no group).
	 *
	 * @param int $id ID.
	 */
	public static function delete( $id ) {
		global $wpdb;
		$wpdb->delete( Db::table( 'partner_groups' ), array( 'id' => (int) $id ) );
		$wpdb->update( Db::table( 'affiliates' ), array( 'group_id' => 0 ), array( 'group_id' => (int) $id ) );
		Audit::log( 'group.deleted', 'group', (int) $id );
	}
}
